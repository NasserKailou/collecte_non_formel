<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;

/**
 * Contrôle et validation des collectes (docs/api/README.md §4, UC-09, UC-10).
 *
 * Circuit : SOUMISE → (prise en charge) EN_CONTROLE → validation de niveau 1 (départemental) →
 * [niveau 2 (régional) si la campagne en compte deux] → VALIDEE ; ou INVALIDEE (motif obligatoire),
 * que l'agent corrige et resoumet. Réouverture exceptionnelle d'une collecte validée par l'administrateur.
 *
 * - Niveau 1 : superviseur départemental (ou régional, ou administrateur) dont le périmètre couvre la commune.
 * - Niveau 2 : superviseur régional (ou administrateur) dont le périmètre couvre la commune.
 * - Un même utilisateur ne valide pas les deux niveaux d'une même révision.
 * - Un seul contrôleur à la fois (prise en charge exclusive) ; toute décision est historisée.
 */
final class ReviewService
{
    public const LEVEL_ROLES = [1 => ['SUP_DEP', 'SUP_REG', 'ADMIN'], 2 => ['SUP_REG', 'ADMIN']];
    /** Codes des modalités du questionnaire ↔ valeurs du registre (questions 1.7 et 1.8). */
    public const ZONE_CODES = ['URBAINE' => 'a', 'RURALE' => 'b'];
    public const LEGAL_CODES = ['PUBLIC' => 'a', 'PRIVE' => 'b'];

    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    // ===================================================================== Lecture

    /** @return array{0:list<array>, 1:int} */
    public function list(array $actor, array $f, array $page): array
    {
        [$scopeSql, $params] = $actor['scope']->communeClause('c.commune_id');
        $where = [$scopeSql];
        foreach (['campaign_id' => 'c.campaign_id', 'status' => 'c.status', 'review_level' => 'c.review_level', 'region_id' => 'c.region_id',
                     'department_id' => 'c.department_id', 'commune_id' => 'c.commune_id', 'academic_year_id' => 'cp.academic_year_id',
                     'agent_uuid' => 'ag.uuid', 'type_code' => 't.code'] as $k => $col) {
            if (($v = $f[$k] ?? null) !== null) {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        if (($v = $f['q'] ?? null) !== null) {
            $where[] = '(s.name_normalized LIKE ? OR s.official_code LIKE ?)';
            $params[] = '%' . addcslashes(Text::normalize($v), '%_\\') . '%';
            $params[] = addcslashes($v, '%_\\') . '%';
        }
        if (($f['awaiting_me'] ?? false) === true) {
            $levels = $this->eligibleLevels($actor);
            if ($levels === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = "(c.status = 'SOUMISE' OR (c.status = 'EN_CONTROLE' AND c.claimed_by = ?))";
                $params[] = $actor['id'];
                $where[] = 'c.review_level IN (' . implode(',', $levels) . ') AND c.review_level <= cp.validation_levels';
            }
        }
        $from = 'FROM collections c JOIN structures s ON s.id = c.structure_id JOIN structure_types t ON t.id = s.structure_type_id
                 JOIN campaigns cp ON cp.id = c.campaign_id JOIN users ag ON ag.id = c.agent_id
                 JOIN communes co ON co.id = c.commune_id JOIN departments d ON d.id = c.department_id JOIN regions r ON r.id = c.region_id
                 LEFT JOIN users cl ON cl.id = c.claimed_by';
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) $from WHERE $whereSql", $params);
        $rows = $this->db->fetchAll(
            "SELECT c.uuid, c.status, c.review_level, c.revision, c.warnings_count, c.last_submitted_at, c.validated_at, cp.code AS campaign_code, cp.validation_levels,
                    s.uuid AS structure_uuid, s.official_code, s.name AS structure_name, t.code AS type_code,
                    co.name AS commune, d.name AS department, r.name AS region,
                    ag.matricule AS agent_matricule, CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
                    CONCAT(cl.first_name, ' ', cl.last_name) AS claimed_by_name
               $from WHERE $whereSql ORDER BY c.last_submitted_at, c.id LIMIT {$page['per_page']} OFFSET {$page['offset']}", $params);
        return [array_map(static fn(array $r) => [
            'uuid' => $r['uuid'], 'status' => $r['status'], 'review_level' => (int) $r['review_level'], 'validation_levels' => (int) $r['validation_levels'],
            'revision' => (int) $r['revision'], 'warnings_count' => (int) $r['warnings_count'], 'campaign' => $r['campaign_code'],
            'last_submitted_at' => $r['last_submitted_at'], 'validated_at' => $r['validated_at'],
            'structure' => ['uuid' => $r['structure_uuid'], 'official_code' => $r['official_code'], 'name' => $r['structure_name'], 'type_code' => $r['type_code']],
            'commune' => $r['commune'], 'department' => $r['department'], 'region' => $r['region'],
            'agent' => ['matricule' => $r['agent_matricule'], 'name' => $r['agent_name']],
            'claimed_by' => $r['claimed_by_name'],
        ], $rows), $total];
    }

    public function get(array $actor, string $uuid): array
    {
        $c = $this->findVisible($actor, $uuid);
        $sub = $this->db->fetchOne('SELECT payload_json, validation_report FROM collection_submissions WHERE collection_id = ? AND revision = ?',
            [$c['id'], $c['revision']]);
        $payload = json_decode((string) ($sub['payload_json'] ?? ''), true) ?? [];
        $report = json_decode((string) ($sub['validation_report'] ?? ''), true) ?? [];
        $def = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?', [$c['questionnaire_version_id']]), true);
        $computedCodes = array_column($def['computed'] ?? [], 'code');
        $computed = [];
        if ($computedCodes !== []) {
            foreach ($this->db->fetchAll('SELECT question_code, value_number FROM collection_answers WHERE collection_id = ? AND question_code IN ('
                . implode(',', array_fill(0, count($computedCodes), '?')) . ')', [$c['id'], ...$computedCodes]) as $a) {
                $computed[$a['question_code']] = $a['value_number'] === null ? null : 0 + $a['value_number'];
            }
        }
        $data = $payload['data'] ?? [];
        $differences = [];
        $registry = ['Q1_6_4' => $c['s_locality'], 'Q1_7' => self::ZONE_CODES[$c['s_zone']] ?? null,
            'Q1_8' => self::LEGAL_CODES[$c['s_legal']] ?? null, 'Q1_9' => $c['s_type']];
        foreach ($registry as $q => $value) {
            if (array_key_exists($q, $data) && $value !== null && $data[$q] !== $value) {
                $differences[] = ['question' => $q, 'registry' => $value, 'collected' => $data[$q]];
            }
        }
        $float = static fn($v) => $v === null ? null : (float) $v;
        return [
            'uuid' => $c['uuid'], 'status' => $c['status'], 'review_level' => (int) $c['review_level'], 'revision' => (int) $c['revision'],
            'campaign' => ['id' => (int) $c['campaign_id'], 'code' => $c['campaign_code'], 'status' => $c['campaign_status'], 'validation_levels' => (int) $c['validation_levels']],
            'questionnaire_version' => $c['version_code'],
            'structure' => ['uuid' => $c['s_uuid'], 'official_code' => $c['s_code'], 'name' => $c['s_name'], 'type_code' => $c['s_type']],
            'territory' => ['region' => $c['region'], 'department' => $c['department'], 'commune' => $c['commune'], 'locality' => $c['locality_label']],
            'agent' => ['uuid' => $c['agent_uuid'], 'matricule' => $c['agent_matricule'], 'name' => $c['agent_name']],
            'gps' => ['lat' => $float($c['latitude']), 'lng' => $float($c['longitude']), 'accuracy_m' => $float($c['gps_accuracy_m']),
                'status' => $c['gps_status'], 'note' => $c['gps_note'], 'distance_km' => $float($c['gps_distance_km'])],
            'claimed_by' => $c['claimed_by'] === null ? null : ['id' => (int) $c['claimed_by'], 'name' => $c['claimer_name'], 'at' => $c['claimed_at']],
            'first_submitted_at' => $c['first_submitted_at'], 'last_submitted_at' => $c['last_submitted_at'], 'validated_at' => $c['validated_at'],
            'device_started_at' => $c['device_started_at'], 'device_completed_at' => $c['device_completed_at'],
            'paper_form_signed' => (bool) $c['paper_form_signed'],
            'data' => $data,
            'computed' => $computed,
            'warnings' => $report['warnings'] ?? [],
            'locked_overrides' => $report['locked_overrides'] ?? [],
            'identity_differences' => $differences,
            'reviews' => $this->db->fetchAll(
                "SELECT r.revision, r.level, r.decision, rr.code AS reason_code, rr.label AS reason, r.comment, r.created_at,
                        u.matricule AS reviewer_matricule, CONCAT(u.first_name, ' ', u.last_name) AS reviewer_name
                   FROM collection_reviews r JOIN users u ON u.id = r.reviewer_id LEFT JOIN rejection_reasons rr ON rr.id = r.reason_id
                  WHERE r.collection_id = ? ORDER BY r.id", [$c['id']]),
            'status_history' => $this->db->fetchAll(
                'SELECT h.from_status, h.to_status, h.review_level, h.revision, h.note, h.created_at, u.matricule AS user_matricule
                   FROM collection_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.collection_id = ? ORDER BY h.id', [$c['id']]),
            'attachments' => $this->db->fetchAll(
                'SELECT a.id, a.kind, f.mime_type, f.size_bytes, a.created_at FROM collection_attachments a JOIN files f ON f.id = a.file_id
                  WHERE a.collection_id = ? ORDER BY a.id', [$c['id']]),
            'allowed_actions' => $this->allowedActions($actor, $c),
        ];
    }

    public function submissions(array $actor, string $uuid): array
    {
        $c = $this->findVisible($actor, $uuid);
        return array_map(static function (array $s): array {
            $payload = json_decode($s['payload_json'], true);
            $report = json_decode((string) $s['validation_report'], true) ?? [];
            return ['revision' => (int) $s['revision'], 'received_at' => $s['received_at'], 'submitted_by' => $s['submitted_by'],
                'app_version' => $s['app_version'], 'payload_sha256' => $s['payload_sha256'], 'warnings' => $report['warnings'] ?? [], 'data' => $payload['data'] ?? []];
        }, $this->db->fetchAll(
            'SELECT s.revision, s.received_at, s.payload_json, s.payload_sha256, s.validation_report, s.app_version, u.matricule AS submitted_by
               FROM collection_submissions s JOIN users u ON u.id = s.submitted_by WHERE s.collection_id = ? ORDER BY s.revision', [$c['id']]));
    }

    /** Collecte de la même structure lors de la campagne précédente (comparaison N-1), ou null. */
    public function previous(array $actor, string $uuid): ?array
    {
        $c = $this->findVisible($actor, $uuid);
        $p = $this->db->fetchOne(
            'SELECT pc.id, pc.uuid, pc.status, pc.revision, cp.code AS campaign_code
               FROM collections pc JOIN campaigns cp ON cp.id = pc.campaign_id
              WHERE pc.structure_id = ? AND pc.id <> ? AND cp.opens_on < (SELECT opens_on FROM campaigns WHERE id = ?)
              ORDER BY cp.opens_on DESC LIMIT 1', [$c['structure_id'], $c['id'], $c['campaign_id']]);
        if ($p === null) {
            return null;
        }
        $numbers = [];
        foreach ($this->db->fetchAll("SELECT question_code, value_number FROM collection_answers WHERE collection_id = ? AND option_code = '' AND value_number IS NOT NULL",
            [$p['id']]) as $a) {
            $numbers[$a['question_code']] = 0 + $a['value_number'];
        }
        return ['uuid' => $p['uuid'], 'campaign' => $p['campaign_code'], 'status' => $p['status'], 'revision' => (int) $p['revision'], 'numbers' => $numbers];
    }

    /** @return array{0:string, 1:string, 2:string} chemin, nom, type MIME */
    public function attachment(array $actor, string $uuid, int $attachmentId, string $storageDir): array
    {
        $c = $this->findVisible($actor, $uuid);
        $f = $this->db->fetchOne('SELECT f.stored_path, f.mime_type, a.kind FROM collection_attachments a JOIN files f ON f.id = a.file_id WHERE a.id = ? AND a.collection_id = ?',
            [$attachmentId, $c['id']]);
        if ($f === null || !is_file($storageDir . '/' . $f['stored_path'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Pièce jointe introuvable.');
        }
        return [$storageDir . '/' . $f['stored_path'], strtolower($f['kind']) . '-' . $attachmentId . ($f['mime_type'] === 'image/png' ? '.png' : '.jpg'), $f['mime_type']];
    }

    // ===================================================================== Décisions

    public function claim(array $actor, string $uuid, string $ip): array
    {
        return $this->act($actor, $uuid, function (array $c) use ($actor, $ip): void {
            $this->assertCanReview($actor, $c);
            if ($c['status'] === 'EN_CONTROLE' && (int) $c['claimed_by'] === $actor['id']) {
                return; // déjà pris en charge par l'appelant
            }
            $this->assertStatus($c, ['SOUMISE']);
            $this->db->execute("UPDATE collections SET status = 'EN_CONTROLE', claimed_by = ?, claimed_at = NOW() WHERE id = ?", [$actor['id'], $c['id']]);
            $this->history($c, 'EN_CONTROLE', (int) $c['review_level'], $actor['id'], 'Prise en charge');
            $this->audit->log($actor['id'], 'COLLECTION_CLAIM', 'collection', $c['uuid'], null, null, null, $ip);
        });
    }

    public function release(array $actor, string $uuid, string $ip): array
    {
        return $this->act($actor, $uuid, function (array $c) use ($actor, $ip): void {
            $this->assertStatus($c, ['EN_CONTROLE'], true);
            if ((int) $c['claimed_by'] !== $actor['id'] && !in_array('ADMIN', $actor['roles'], true)) {
                throw new HttpException(403, 'FORBIDDEN', "Seul le contrôleur qui a pris en charge la collecte (ou l'administrateur) peut la libérer.");
            }
            $this->db->execute("UPDATE collections SET status = 'SOUMISE', claimed_by = NULL, claimed_at = NULL WHERE id = ?", [$c['id']]);
            $this->history($c, 'SOUMISE', (int) $c['review_level'], $actor['id'], 'Libération');
            $this->audit->log($actor['id'], 'COLLECTION_RELEASE', 'collection', $c['uuid'], null, null, null, $ip);
        });
    }

    public function validate(array $actor, string $uuid, array $in, string $ip): array
    {
        $comment = $this->comment($in['comment'] ?? null, false);
        return $this->act($actor, $uuid, function (array $c) use ($actor, $comment, $ip): void {
            $this->assertCanDecide($actor, $c);
            $level = (int) $c['review_level'];
            if ($level > 1 && $this->db->fetchValue(
                "SELECT 1 FROM collection_reviews WHERE collection_id = ? AND revision = ? AND decision = 'VALIDEE' AND reviewer_id = ? AND level < ? LIMIT 1",
                [$c['id'], $c['revision'], $actor['id'], $level]) !== null) {
                throw new HttpException(403, 'SAME_REVIEWER', 'Vous avez validé le niveau précédent : la validation régionale doit être faite par une autre personne.');
            }
            $this->db->execute("INSERT INTO collection_reviews (collection_id, revision, level, reviewer_id, decision, comment) VALUES (?, ?, ?, ?, 'VALIDEE', ?)",
                [$c['id'], $c['revision'], $level, $actor['id'], $comment]);
            if ($level < (int) $c['validation_levels']) {
                $this->db->execute("UPDATE collections SET status = 'SOUMISE', review_level = ?, claimed_by = NULL, claimed_at = NULL WHERE id = ?", [$level + 1, $c['id']]);
                $this->history($c, 'SOUMISE', $level + 1, $actor['id'], "Validée au niveau $level, transmise au niveau " . ($level + 1));
            } else {
                $this->db->execute("UPDATE collections SET status = 'VALIDEE', claimed_by = NULL, claimed_at = NULL, validated_by = ?, validated_at = NOW() WHERE id = ?",
                    [$actor['id'], $c['id']]);
                $this->history($c, 'VALIDEE', $level, $actor['id'], $comment);
            }
            $this->audit->log($actor['id'], 'COLLECTION_VALIDATE', 'collection', $c['uuid'], null, ['level' => $level], $comment, $ip);
        });
    }

    /**
     * Correction par le superviseur : il ouvre le questionnaire, corrige et enregistre une nouvelle révision.
     * Même droit que pour décider (niveau de contrôle, périmètre, collecte SOUMISE ou prise en charge par lui) ;
     * la collecte est prise en charge par le superviseur (EN_CONTROLE) et attend sa décision (valider / renvoyer).
     */
    public function correct(array $actor, string $uuid, array $in, string $ip, SubmissionService $submissions): array
    {
        $comment = $this->comment($in['comment'] ?? null, false);
        $data = $in['data'] ?? null;
        if (!is_array($data)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Champ « data » (réponses du questionnaire) obligatoire.');
        }
        $warnings = [];
        $result = $this->act($actor, $uuid, function (array $c) use ($actor, $data, $comment, $ip, $submissions, &$warnings): void {
            $this->assertCanDecide($actor, $c);
            $r = $submissions->applyReviewerCorrection($c, $data, $actor['id']);
            $warnings = $r['warnings'];
            $this->db->execute("UPDATE collections SET status = 'EN_CONTROLE', claimed_by = ?, claimed_at = COALESCE(claimed_at, NOW()) WHERE id = ?",
                [$actor['id'], $c['id']]);
            $c['revision'] = $r['revision'];
            $this->history($c, 'EN_CONTROLE', (int) $c['review_level'], $actor['id'],
                'Corrigée par le superviseur' . ($comment === null ? '' : ' : ' . $comment));
            $this->audit->log($actor['id'], 'COLLECTION_CORRECT', 'collection', $c['uuid'], null, ['revision' => $r['revision']], $comment, $ip);
        });
        return $result + ['correction_warnings' => $warnings];
    }

    /**
     * Validation groupée (superviseur) : chaque collecte passe par validate(), avec tous ses contrôles (droits, niveau,
     * superviseur différent au niveau 2, journal). Une collecte refusée n'empêche pas les autres.
     * @return array{validated:list<string>, failed:list<array{uuid:string, message:string}>}
     */
    public function validateMany(array $actor, mixed $uuids, array $in, string $ip): array
    {
        if (!is_array($uuids) || $uuids === [] || count($uuids) > 200) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Liste de 1 à 200 collectes attendue (champ « uuids »).');
        }
        $out = ['validated' => [], 'failed' => []];
        foreach (array_values(array_unique(array_filter($uuids, 'is_string'))) as $uuid) {
            try {
                $this->validate($actor, $uuid, $in, $ip);
                $out['validated'][] = $uuid;
            } catch (HttpException $e) {
                $out['failed'][] = ['uuid' => $uuid, 'message' => $e->getMessage()];
            }
        }
        return $out;
    }

    public function invalidate(array $actor, string $uuid, array $in, string $ip): array
    {
        $reason = $this->reason($in);
        $comment = $this->comment($in['comment'] ?? null, $reason['code'] === 'AUTRE');
        return $this->act($actor, $uuid, function (array $c) use ($actor, $reason, $comment, $ip): void {
            $this->assertCanDecide($actor, $c);
            $level = (int) $c['review_level'];
            $this->db->execute("INSERT INTO collection_reviews (collection_id, revision, level, reviewer_id, decision, reason_id, comment) VALUES (?, ?, ?, ?, 'INVALIDEE', ?, ?)",
                [$c['id'], $c['revision'], $level, $actor['id'], $reason['id'], $comment]);
            $this->db->execute("UPDATE collections SET status = 'INVALIDEE', review_level = 1, claimed_by = NULL, claimed_at = NULL WHERE id = ?", [$c['id']]);
            $this->history($c, 'INVALIDEE', $level, $actor['id'], $reason['label'] . ($comment !== null ? ' — ' . $comment : ''));
            $this->audit->log($actor['id'], 'COLLECTION_INVALIDATE', 'collection', $c['uuid'], null, ['level' => $level, 'reason' => $reason['code']], $comment, $ip);
        });
    }

    /** Réouverture exceptionnelle d'une collecte validée (administrateur, motif obligatoire). */
    public function reopen(array $actor, string $uuid, array $in, string $ip): array
    {
        $reason = $this->comment($in['reason'] ?? null, true);
        return $this->act($actor, $uuid, function (array $c) use ($actor, $reason, $ip): void {
            $this->assertStatus($c, ['VALIDEE']);
            $this->db->execute("INSERT INTO collection_reviews (collection_id, revision, level, reviewer_id, decision, comment) VALUES (?, ?, 9, ?, 'REOUVERTURE', ?)",
                [$c['id'], $c['revision'], $actor['id'], $reason]);
            $this->db->execute("UPDATE collections SET status = 'REOUVERTE', review_level = 1, validated_by = NULL, validated_at = NULL WHERE id = ?", [$c['id']]);
            $this->history($c, 'REOUVERTE', 9, $actor['id'], $reason);
            $this->audit->log($actor['id'], 'COLLECTION_REOPEN', 'collection', $c['uuid'], ['status' => 'VALIDEE'], ['status' => 'REOUVERTE'], $reason, $ip);
        });
    }

    // ===================================================================== Règles

    /** Niveaux de contrôle que l'acteur peut exercer d'après ses rôles. @return list<int> */
    public function eligibleLevels(array $actor): array
    {
        return array_values(array_filter([1, 2], static fn(int $l) => array_intersect($actor['roles'], self::LEVEL_ROLES[$l]) !== []));
    }

    private function assertCanReview(array $actor, array $c): void
    {
        if ($c['campaign_status'] === 'ARCHIVEE') {
            throw new HttpException(423, 'LOCKED', "La campagne est archivée : plus aucune décision n'est possible.");
        }
        $level = (int) $c['review_level'];
        if (!in_array($level, $this->eligibleLevels($actor), true)) {
            throw new HttpException(403, 'FORBIDDEN', $level === 2
                ? 'Le niveau 2 (régional) est réservé aux superviseurs régionaux.'
                : 'Vous ne pouvez pas contrôler cette collecte.');
        }
        if ($level > (int) $c['validation_levels']) {
            throw new HttpException(409, 'CONFLICT', 'Niveau de contrôle incohérent avec la campagne.');
        }
    }

    /** Décision : collecte SOUMISE (prise en charge implicite) ou EN_CONTROLE par l'acteur lui-même. */
    private function assertCanDecide(array $actor, array $c): void
    {
        $this->assertCanReview($actor, $c);
        if ($c['status'] === 'EN_CONTROLE' && (int) $c['claimed_by'] !== $actor['id']) {
            throw new HttpException(409, 'ALREADY_CLAIMED', "Collecte en cours de contrôle par {$c['claimer_name']}.");
        }
        $this->assertStatus($c, ['SOUMISE', 'EN_CONTROLE']);
    }

    private function assertStatus(array $c, array $allowed, bool $plain = false): void
    {
        if (in_array($c['status'], $allowed, true)) {
            return;
        }
        if (!$plain && $c['status'] === 'EN_CONTROLE') {
            throw new HttpException(409, 'ALREADY_CLAIMED', "Collecte en cours de contrôle par {$c['claimer_name']}.", ['status' => $c['status']]);
        }
        throw new HttpException(409, 'CONFLICT', "Action impossible : la collecte est au statut {$c['status']}.", ['status' => $c['status']]);
    }

    private function allowedActions(array $actor, array $c): array
    {
        $out = [];
        $canLevel = $c['campaign_status'] !== 'ARCHIVEE' && in_array((int) $c['review_level'], $this->eligibleLevels($actor), true)
            && (int) $c['review_level'] <= (int) $c['validation_levels'];
        $mine = $c['status'] === 'SOUMISE' || ($c['status'] === 'EN_CONTROLE' && (int) $c['claimed_by'] === $actor['id']);
        if ($canLevel && $c['status'] === 'SOUMISE') {
            $out[] = 'claim';
        }
        if ($canLevel && $mine) {
            array_push($out, 'validate', 'invalidate', 'correct');
        }
        if ($c['status'] === 'EN_CONTROLE' && ((int) $c['claimed_by'] === $actor['id'] || in_array('ADMIN', $actor['roles'], true))) {
            $out[] = 'release';
        }
        if ($c['status'] === 'VALIDEE' && in_array('ADMIN', $actor['roles'], true)) {
            $out[] = 'reopen';
        }
        return $out;
    }

    // ===================================================================== Interne

    /** Exécute une décision sur la collecte verrouillée, puis renvoie la fiche à jour. */
    private function act(array $actor, string $uuid, callable $fn): array
    {
        $this->findVisible($actor, $uuid); // 404 si hors périmètre
        $this->db->transaction(function () use ($uuid, $fn): void {
            $this->db->fetchValue('SELECT id FROM collections WHERE uuid = ? FOR UPDATE', [strtolower($uuid)]);
            $fn($this->load($uuid));
        });
        return $this->get($actor, $uuid);
    }

    private function findVisible(array $actor, string $uuid): array
    {
        $c = $this->load($uuid);
        if ($c === null || !$actor['scope']->coversCommune((int) $c['commune_id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Collecte introuvable.');
        }
        return $c;
    }

    private function load(string $uuid): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.*, cp.code AS campaign_code, cp.status AS campaign_status, cp.validation_levels, v.version_code,
                    s.uuid AS s_uuid, s.official_code AS s_code, s.name AS s_name, s.zone AS s_zone, s.legal_status AS s_legal, s.locality_label AS s_locality,
                    t.code AS s_type, co.name AS commune, d.name AS department, r.name AS region,
                    ag.uuid AS agent_uuid, ag.matricule AS agent_matricule, CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
                    CONCAT(cl.first_name, ' ', cl.last_name) AS claimer_name
               FROM collections c JOIN campaigns cp ON cp.id = c.campaign_id JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id
               JOIN structures s ON s.id = c.structure_id JOIN structure_types t ON t.id = s.structure_type_id
               JOIN communes co ON co.id = c.commune_id JOIN departments d ON d.id = c.department_id JOIN regions r ON r.id = c.region_id
               JOIN users ag ON ag.id = c.agent_id LEFT JOIN users cl ON cl.id = c.claimed_by
              WHERE c.uuid = ?", [strtolower($uuid)]);
    }

    private function history(array $c, string $to, int $level, int $userId, ?string $note): void
    {
        $this->db->execute('INSERT INTO collection_status_history (collection_id, from_status, to_status, review_level, revision, user_id, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $c['status'], $to, $level, $c['revision'], $userId, $note === null ? null : mb_substr($note, 0, 500)]);
    }

    /** @return array{id:int, code:string, label:string} */
    private function reason(array $in): array
    {
        $r = null;
        if (isset($in['reason_id']) && (is_int($in['reason_id']) || (is_string($in['reason_id']) && ctype_digit($in['reason_id'])))) {
            $r = $this->db->fetchOne('SELECT id, code, label FROM rejection_reasons WHERE id = ? AND active = 1', [(int) $in['reason_id']]);
        } elseif (is_string($in['reason_code'] ?? null)) {
            $r = $this->db->fetchOne('SELECT id, code, label FROM rejection_reasons WHERE code = ? AND active = 1', [$in['reason_code']]);
        }
        if ($r === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Motif d'invalidation obligatoire (reason_id ou reason_code).",
                ['errors' => [['field' => 'reason_id', 'message' => 'Motif obligatoire.']]]);
        }
        return ['id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label']];
    }

    private function comment(mixed $v, bool $required): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            if ($required) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Commentaire obligatoire (5 caractères au moins).',
                    ['errors' => [['field' => 'comment', 'message' => 'Commentaire obligatoire.']]]);
            }
            return null;
        }
        if ($required && mb_strlen($v) < 5) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Commentaire trop court (5 caractères au moins).');
        }
        return mb_substr($v, 0, 2000);
    }
}
