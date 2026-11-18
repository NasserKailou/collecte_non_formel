<?php

declare(strict_types=1);

namespace App\Modules\Campaigns;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;
use App\Modules\Users\Scope;

/**
 * Années statistiques et campagnes (docs/phase1/02 §2.4, UC-02, UC-12 ; docs/api/README.md §5).
 *
 * - Une campagne fige sa version de questionnaire dès son ouverture.
 * - États : PREPARATION → OUVERTE ⇄ SUSPENDUE → CLOTUREE → ARCHIVEE. Toute autre transition ⇒ 409.
 * - Seule une campagne OUVERTE accepte des soumissions (assertAcceptsSubmissions, utilisé par la collecte).
 * - Une campagne CLOTUREE ou ARCHIVEE est en lecture seule (423 LOCKED).
 * - Ciblage des structures ACTIVES, en masse, et reconduction depuis une campagne précédente.
 */
final class CampaignService
{
    public const TRANSITIONS = [
        'PREPARATION' => ['OUVERTE'],
        'OUVERTE' => ['SUSPENDUE', 'CLOTUREE'],
        'SUSPENDUE' => ['OUVERTE', 'CLOTUREE'],
        'CLOTUREE' => ['ARCHIVEE'],
        'ARCHIVEE' => [],
    ];
    /** Transitions exigeant un motif (journalisé). */
    private const REASON_REQUIRED = ['SUSPENDUE', 'CLOTUREE', 'ARCHIVEE'];
    public const MAX_TARGET_BATCH = 5000;

    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    // ===================================================================== Années statistiques

    public function listYears(): array
    {
        return array_map([$this, 'presentYear'], $this->db->fetchAll(
            'SELECT y.*, (SELECT COUNT(*) FROM campaigns c WHERE c.academic_year_id = y.id) AS campaigns FROM academic_years y ORDER BY y.code DESC'));
    }

    public function createYear(array $actor, array $in, string $ip): array
    {
        $errors = [];
        $code = is_string($in['code'] ?? null) ? trim($in['code']) : '';
        if (preg_match('/^(\d{4})-(\d{4})$/', $code, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            $errors[] = ['field' => 'code', 'message' => 'Code AAAA-AAAA attendu, deux années consécutives (ex. 2026-2027).'];
        } elseif ($this->db->fetchValue('SELECT id FROM academic_years WHERE code = ?', [$code]) !== null) {
            $errors[] = ['field' => 'code', 'message' => "L'année $code existe déjà."];
        }
        [$start, $end] = $this->dateRange($in, 'start_date', 'end_date', $errors, false);
        $label = $this->text($in['label'] ?? null, 100) ?? ('Année statistique ' . $code);
        $this->throwIfErrors($errors);
        $id = $this->db->insert('INSERT INTO academic_years (code, label, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?)',
            [$code, $label, $start, $end, $actor['id']]);
        $this->audit->log($actor['id'], 'YEAR_CREATE', 'academic_year', (string) $id, null,
            ['code' => $code, 'label' => $label, 'start_date' => $start, 'end_date' => $end], null, $ip);
        return $this->year($id);
    }

    /** Libellé, dates, et statut (PREPARATION → ACTIVE → CLOTUREE). */
    public function updateYear(array $actor, int $id, array $in, string $ip): array
    {
        $y = $this->db->fetchOne('SELECT * FROM academic_years WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Année introuvable.');
        if ($y['status'] === 'CLOTUREE') {
            throw new HttpException(423, 'LOCKED', 'Une année clôturée ne peut plus être modifiée.');
        }
        $errors = [];
        [$start, $end] = $this->dateRange($in + ['start_date' => $y['start_date'], 'end_date' => $y['end_date']], 'start_date', 'end_date', $errors, false);
        $label = array_key_exists('label', $in) ? ($this->text($in['label'], 100) ?? $y['label']) : $y['label'];
        $status = $in['status'] ?? $y['status'];
        if ($status !== $y['status']) {
            $allowed = ['PREPARATION' => 'ACTIVE', 'ACTIVE' => 'CLOTUREE'][$y['status']] ?? null;
            if ($status !== $allowed) {
                throw new HttpException(409, 'CONFLICT', "Passage de l'année de {$y['status']} à " . (is_string($status) ? $status : '?') . ' non autorisé.');
            }
            if ($status === 'CLOTUREE' && $this->db->fetchValue(
                "SELECT code FROM campaigns WHERE academic_year_id = ? AND status NOT IN ('CLOTUREE','ARCHIVEE') LIMIT 1", [$id]) !== null) {
                throw new HttpException(409, 'CONFLICT', "Toutes les campagnes de l'année doivent être clôturées avant de clôturer l'année.");
            }
        }
        $this->throwIfErrors($errors);
        $this->db->execute('UPDATE academic_years SET label = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?', [$label, $start, $end, $status, $id]);
        $this->audit->log($actor['id'], 'YEAR_UPDATE', 'academic_year', (string) $id, $this->presentYear($y),
            ['label' => $label, 'start_date' => $start, 'end_date' => $end, 'status' => $status], null, $ip);
        return $this->year($id);
    }

    // ===================================================================== Campagnes : lecture

    public function list(array $actor, array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($v = $f['academic_year_id'] ?? null) !== null) {
            $where[] = 'c.academic_year_id = ?';
            $params[] = $v;
        }
        if (($v = $f['status'] ?? null) !== null) {
            $where[] = 'c.status = ?';
            $params[] = $v;
        }
        $rows = $this->db->fetchAll($this->campaignSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY y.code DESC, c.opens_on DESC, c.id DESC', $params);
        return array_map(fn(array $c) => $this->present($c, $actor), $rows);
    }

    public function get(array $actor, int $id): array
    {
        $out = $this->present($this->find($id), $actor);
        $out['status_history'] = $this->db->fetchAll(
            'SELECT h.from_status, h.to_status, h.reason, h.created_at, u.matricule AS user_matricule
               FROM campaign_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.campaign_id = ? ORDER BY h.id', [$id]);
        return $out;
    }

    /** Ligne brute d'une campagne (404 si absente). */
    public function find(int $id): array
    {
        return $this->db->fetchOne($this->campaignSql() . ' WHERE c.id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
    }

    // ===================================================================== Campagnes : écriture

    public function create(array $actor, array $in, string $ip): array
    {
        $errors = [];
        $code = mb_strtoupper(is_string($in['code'] ?? null) ? trim($in['code']) : '');
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{2,39}$/', $code) !== 1) {
            $errors[] = ['field' => 'code', 'message' => 'Code obligatoire : 3 à 40 caractères (lettres, chiffres, _ . -).'];
        } elseif ($this->db->fetchValue('SELECT id FROM campaigns WHERE code = ?', [$code]) !== null) {
            $errors[] = ['field' => 'code', 'message' => "La campagne $code existe déjà."];
        }
        $name = $this->text($in['name'] ?? null, 200);
        if ($name === null || mb_strlen($name) < 3) {
            $errors[] = ['field' => 'name', 'message' => 'Intitulé obligatoire (3 à 200 caractères).'];
        }
        $yearId = $this->intOrNull($in['academic_year_id'] ?? null);
        $year = $yearId === null ? null : $this->db->fetchOne('SELECT id, status FROM academic_years WHERE id = ?', [$yearId]);
        if ($year === null) {
            $errors[] = ['field' => 'academic_year_id', 'message' => 'Année statistique obligatoire.'];
        } elseif ($year['status'] === 'CLOTUREE') {
            $errors[] = ['field' => 'academic_year_id', 'message' => 'Cette année statistique est clôturée.'];
        }
        $versionId = $this->publishedVersion($in['questionnaire_version'] ?? null, $errors);
        [$opens, $closes] = $this->dateRange($in, 'opens_on', 'closes_on', $errors, true);
        $levels = $in['validation_levels'] ?? 2;
        if (!in_array($levels, [1, 2], true)) {
            $errors[] = ['field' => 'validation_levels', 'message' => 'Niveaux de validation : 1 (départemental) ou 2 (départemental puis régional).'];
        }
        $instructions = $this->text($in['instructions'] ?? null, 10000, false);
        $mode = $this->collectionMode($in['collection_mode'] ?? 'TABLETTE', $errors);
        $this->throwIfErrors($errors);

        $id = $this->db->transaction(function (Database $db) use ($yearId, $code, $name, $versionId, $opens, $closes, $levels, $mode, $instructions, $actor, $ip): int {
            $id = $db->insert(
                'INSERT INTO campaigns (academic_year_id, code, name, questionnaire_version_id, opens_on, closes_on, validation_levels, collection_mode, instructions, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$yearId, $code, $name, $versionId, $opens, $closes, $levels, $mode, $instructions, $actor['id']]);
            $db->execute("INSERT INTO campaign_status_history (campaign_id, from_status, to_status, user_id) VALUES (?, NULL, 'PREPARATION', ?)", [$id, $actor['id']]);
            $this->audit->log($actor['id'], 'CAMPAIGN_CREATE', 'campaign', (string) $id, null,
                ['code' => $code, 'name' => $name, 'questionnaire_version_id' => $versionId, 'opens_on' => $opens, 'closes_on' => $closes, 'validation_levels' => $levels], null, $ip);
            return $id;
        });
        return $this->get($actor, $id);
    }

    /** Intitulé, dates, consignes ; version du questionnaire et niveaux de validation seulement en préparation. */
    public function update(array $actor, int $id, array $in, string $ip): array
    {
        $c = $this->find($id);
        $this->assertModifiable($c);
        if (array_key_exists('validation_levels', $in) && $c['status'] !== 'PREPARATION') {
            throw new HttpException(409, 'CONFLICT', "Les niveaux de validation sont figés dès l'ouverture de la campagne.");
        }
        $errors = [];
        $name = array_key_exists('name', $in) ? $this->text($in['name'], 200) : $c['name'];
        if ($name === null || mb_strlen($name) < 3) {
            $errors[] = ['field' => 'name', 'message' => 'Intitulé obligatoire (3 à 200 caractères).'];
        }
        [$opens, $closes] = $this->dateRange($in + ['opens_on' => $c['opens_on'], 'closes_on' => $c['closes_on']], 'opens_on', 'closes_on', $errors, true);
        $instructions = array_key_exists('instructions', $in) ? $this->text($in['instructions'], 10000, false) : $c['instructions'];
        $versionId = (int) $c['questionnaire_version_id'];
        $levels = (int) $c['validation_levels'];
        if (array_key_exists('questionnaire_version', $in)) {
            $versionId = (int) $this->publishedVersion($in['questionnaire_version'], $errors);
            // Version du questionnaire : libre tant qu'aucune collecte n'a été reçue (ni soumission ni brouillon web) ;
            // ensuite seulement une version PLUS RÉCENTE du même questionnaire. Chaque collecte déjà reçue garde sa version
            // (ses corrections restent acceptées) ; les tablettes basculent leurs brouillons non envoyés sur la nouvelle.
            if ($versionId > 0 && $versionId !== (int) $c['questionnaire_version_id'] && $c['status'] !== 'PREPARATION' && $this->hasCollections($id)) {
                $old = $this->db->fetchOne('SELECT questionnaire_id, version_code FROM questionnaire_versions WHERE id = ?', [$c['questionnaire_version_id']]);
                $new = $this->db->fetchOne('SELECT questionnaire_id, version_code FROM questionnaire_versions WHERE id = ?', [$versionId]);
                if ($old['questionnaire_id'] !== $new['questionnaire_id'] || version_compare($new['version_code'], $old['version_code'], '<=')) {
                    throw new HttpException(409, 'CONFLICT', "Des collectes ont déjà été reçues : seule une version plus récente du même questionnaire peut remplacer la version {$old['version_code']}.");
                }
            }
        }
        if (array_key_exists('validation_levels', $in)) {
            if (!in_array($in['validation_levels'], [1, 2], true)) {
                $errors[] = ['field' => 'validation_levels', 'message' => 'Niveaux de validation : 1 ou 2.'];
            } else {
                $levels = $in['validation_levels'];
            }
        }
        // Le mode de collecte reste modifiable pendant la campagne (ex. tablettes indisponibles : passage en MIXTE).
        $mode = array_key_exists('collection_mode', $in) ? $this->collectionMode($in['collection_mode'], $errors) : $c['collection_mode'];
        $this->throwIfErrors($errors);
        $this->db->execute('UPDATE campaigns SET name = ?, opens_on = ?, closes_on = ?, instructions = ?, questionnaire_version_id = ?, validation_levels = ?, collection_mode = ? WHERE id = ?',
            [$name, $opens, $closes, $instructions, $versionId, $levels, $mode, $id]);
        $this->audit->log($actor['id'], 'CAMPAIGN_UPDATE', 'campaign', (string) $id,
            ['name' => $c['name'], 'opens_on' => $c['opens_on'], 'closes_on' => $c['closes_on'],
                'questionnaire_version_id' => (int) $c['questionnaire_version_id'], 'validation_levels' => (int) $c['validation_levels'], 'collection_mode' => $c['collection_mode']],
            ['name' => $name, 'opens_on' => $opens, 'closes_on' => $closes, 'questionnaire_version_id' => $versionId, 'validation_levels' => $levels, 'collection_mode' => $mode],
            null, $ip);
        return $this->get($actor, $id);
    }

    public function transition(array $actor, int $id, array $in, string $ip): array
    {
        $to = is_string($in['to'] ?? null) ? $in['to'] : '';
        $reason = $this->text($in['reason'] ?? null, 500);
        $this->db->transaction(function (Database $db) use ($id, $to, $reason, $actor, $ip): void {
            $c = $db->fetchOne('SELECT c.*, v.status AS version_status, y.status AS year_status FROM campaigns c
                                  JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id JOIN academic_years y ON y.id = c.academic_year_id
                                 WHERE c.id = ? FOR UPDATE', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
            if (!in_array($to, self::TRANSITIONS[$c['status']], true)) {
                throw new HttpException(409, 'CONFLICT', "Transition {$c['status']} → " . ($to !== '' ? $to : '?') . ' non autorisée.',
                    ['allowed' => self::TRANSITIONS[$c['status']]]);
            }
            if (in_array($to, self::REASON_REQUIRED, true) && ($reason === null || mb_strlen($reason) < 3)) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Motif obligatoire pour cette transition.',
                    ['errors' => [['field' => 'reason', 'message' => 'Motif obligatoire.']]]);
            }
            if ($to === 'OUVERTE') {
                $problems = [];
                if ($c['version_status'] !== 'PUBLIEE') {
                    $problems[] = "La version du questionnaire n'est pas publiée.";
                }
                if ($c['year_status'] === 'CLOTUREE') {
                    $problems[] = "L'année statistique est clôturée.";
                }
                if ($c['closes_on'] < date('Y-m-d')) {
                    $problems[] = "La date de fin de la campagne est dépassée : la prolonger d'abord.";
                }
                // Pas d'exigence de structures ciblées : en recensement de terrain, les agents découvrent les centres et les
                // inscrivent eux-mêmes (POST /campaigns/{id}/field-structures), dans leur zone de travail.
                if ($problems !== []) {
                    throw new HttpException(409, 'CONFLICT', 'La campagne ne peut pas être ouverte.', ['problems' => $problems]);
                }
            }
            $db->execute('UPDATE campaigns SET status = ? WHERE id = ?', [$to, $id]);
            $db->execute('INSERT INTO campaign_status_history (campaign_id, from_status, to_status, user_id, reason) VALUES (?, ?, ?, ?, ?)',
                [$id, $c['status'], $to, $actor['id'], $reason]);
            $this->audit->log($actor['id'], 'CAMPAIGN_TRANSITION', 'campaign', (string) $id, ['status' => $c['status']], ['status' => $to], $reason, $ip);
        });
        return $this->get($actor, $id);
    }

    /** Garde utilisée par la soumission des collectes : seule une campagne OUVERTE accepte des données. */
    public function assertAcceptsSubmissions(int $campaignId): array
    {
        $c = $this->find($campaignId);
        match ($c['status']) {
            'OUVERTE' => null,
            'SUSPENDUE' => throw new HttpException(423, 'CAMPAIGN_SUSPENDED',
                'La campagne est suspendue : les soumissions reprendront à sa réouverture. Vos données restent sur la tablette.'),
            'PREPARATION' => throw new HttpException(423, 'CAMPAIGN_NOT_OPEN', "La campagne n'est pas encore ouverte."),
            default => throw new HttpException(423, 'CAMPAIGN_CLOSED', "La campagne est clôturée : aucune soumission n'est acceptée."),
        };
        return $c;
    }

    /** Une campagne clôturée ou archivée est en lecture seule. */
    public function assertModifiable(array $c): void
    {
        if (in_array($c['status'], ['CLOTUREE', 'ARCHIVEE'], true)) {
            throw new HttpException(423, 'LOCKED', 'La campagne est clôturée : elle est en lecture seule.');
        }
    }

    // ===================================================================== Ciblage

    /**
     * Cible des structures ACTIVES : liste d'UUID et/ou filtre territorial {region_id|department_id|commune_id}
     * (ou {all: true} pour tout le périmètre de l'appelant). Idempotent.
     * @return array{added:int, already_targeted:int, ignored:list<array{uuid:string, reason:string}>}
     */
    public function target(array $actor, int $id, array $in, string $ip): array
    {
        $this->assertModifiable($this->find($id));
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('s.commune_id');
        $ids = [];
        $ignored = [];

        $uuids = $in['structure_uuids'] ?? [];
        if (!is_array($uuids) || !array_is_list($uuids) || count($uuids) > self::MAX_TARGET_BATCH) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'structure_uuids : liste de ' . self::MAX_TARGET_BATCH . ' identifiants au plus.');
        }
        $uuids = array_values(array_unique(array_filter($uuids, 'is_string')));
        if ($uuids !== []) {
            $rows = $this->db->fetchAll("SELECT s.id, s.uuid, s.status, ($scopeSql) AS in_scope FROM structures s WHERE s.deleted_at IS NULL AND s.uuid IN ("
                . implode(',', array_fill(0, count($uuids), '?')) . ')', [...$scopeParams, ...$uuids]);
            $byUuid = array_column($rows, null, 'uuid');
            foreach ($uuids as $u) {
                $r = $byUuid[$u] ?? null;
                if ($r === null || !(int) $r['in_scope']) {
                    $ignored[] = ['uuid' => $u, 'reason' => 'Structure introuvable ou hors de votre périmètre.'];
                } elseif ($r['status'] !== 'ACTIVE') {
                    $ignored[] = ['uuid' => $u, 'reason' => "Structure au statut {$r['status']} (seules les structures actives sont ciblées)."];
                } else {
                    $ids[(int) $r['id']] = true;
                }
            }
        }
        $filter = $in['filter'] ?? null;
        if ($filter !== null) {
            if (!is_array($filter)) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre de ciblage invalide.');
            }
            $where = ["s.status = 'ACTIVE'", 's.deleted_at IS NULL', $scopeSql];
            $params = $scopeParams;
            $any = false;
            foreach (['commune_id' => 's.commune_id', 'department_id' => 'c.department_id', 'region_id' => 'd.region_id'] as $k => $col) {
                if (isset($filter[$k])) {
                    $where[] = "$col = ?";
                    $params[] = $this->intOrNull($filter[$k]) ?? 0;
                    $any = true;
                }
            }
            if (!$any && ($filter['all'] ?? false) !== true) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre de ciblage : region_id, department_id, commune_id ou all = true.');
            }
            foreach ($this->db->fetchAll('SELECT s.id FROM structures s JOIN communes c ON c.id = s.commune_id JOIN departments d ON d.id = c.department_id WHERE '
                . implode(' AND ', $where), $params) as $r) {
                $ids[(int) $r['id']] = true;
            }
        }
        if ($ids === [] && $ignored === [] && $filter === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Aucune structure à cibler : fournir structure_uuids ou un filtre.');
        }
        $added = $this->db->transaction(function (Database $db) use ($ids, $id, $actor): int {
            $n = 0;
            foreach (array_keys($ids) as $sid) {
                $n += $db->execute('INSERT IGNORE INTO campaign_structures (campaign_id, structure_id, added_by) VALUES (?, ?, ?)', [$id, $sid, $actor['id']]);
            }
            return $n;
        });
        if ($added > 0) {
            $this->audit->log($actor['id'], 'CAMPAIGN_TARGET', 'campaign', (string) $id, null, ['added' => $added], null, $ip);
        }
        return ['added' => $added, 'already_targeted' => count($ids) - $added, 'ignored' => $ignored];
    }

    /** Retire une structure du ciblage (impossible si une collecte existe). Ses affectations sont supprimées. */
    public function untarget(array $actor, int $id, string $structureUuid, string $ip): void
    {
        $this->assertModifiable($this->find($id));
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('s.commune_id');
        $cs = $this->db->fetchOne("SELECT cs.id FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id
                                    WHERE cs.campaign_id = ? AND s.uuid = ? AND $scopeSql", [$id, $structureUuid, ...$scopeParams])
            ?? throw new HttpException(404, 'NOT_FOUND', "Cette structure n'est pas ciblée par la campagne.");
        if ($this->db->fetchValue('SELECT 1 FROM collections WHERE campaign_structure_id = ? LIMIT 1', [$cs['id']]) !== null) {
            throw new HttpException(409, 'CONFLICT', 'Une collecte existe déjà pour cette structure : elle ne peut plus être retirée du ciblage.');
        }
        $this->db->transaction(function (Database $db) use ($cs, $id, $actor, $structureUuid, $ip): void {
            $db->execute('DELETE FROM assignments WHERE campaign_structure_id = ?', [$cs['id']]);
            $db->execute('DELETE FROM campaign_structures WHERE id = ?', [$cs['id']]);
            $this->audit->log($actor['id'], 'CAMPAIGN_UNTARGET', 'campaign', (string) $id, ['structure' => $structureUuid], null, null, $ip);
        });
    }

    /**
     * Reconduction : copie dans une campagne en préparation les structures (encore ACTIVES) ciblées par
     * une campagne précédente et, au choix, ses affectations actives et zones d'agents encore valables
     * (agent actif dont le périmètre couvre toujours la commune). La campagne source n'est pas modifiée.
     */
    public function cloneFrom(array $actor, int $id, int $sourceId, array $in, string $ip): array
    {
        $c = $this->find($id);
        $this->find($sourceId);
        if ($id === $sourceId) {
            throw new HttpException(409, 'CONFLICT', 'Une campagne ne peut pas être reconduite sur elle-même.');
        }
        if ($c['status'] !== 'PREPARATION') {
            throw new HttpException(409, 'CONFLICT', 'La reconduction se fait vers une campagne en préparation.');
        }
        $copyAssignments = ($in['copy_assignments'] ?? false) === true;
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('s.commune_id');

        return $this->db->transaction(function (Database $db) use ($id, $sourceId, $copyAssignments, $scopeSql, $scopeParams, $actor, $ip): array {
            $rows = $db->fetchAll("SELECT cs.id AS old_cs, s.id AS structure_id, s.status, s.commune_id FROM campaign_structures cs
                                     JOIN structures s ON s.id = cs.structure_id WHERE cs.campaign_id = ? AND $scopeSql", [$sourceId, ...$scopeParams]);
            $result = ['structures_copied' => 0, 'structures_skipped' => 0, 'assignments_copied' => 0, 'assignments_skipped' => 0, 'areas_copied' => 0];
            $agentScopes = [];
            $agentOk = function (int $agentId, int $communeId) use ($db, &$agentScopes): bool {
                if (!array_key_exists($agentId, $agentScopes)) {
                    $u = $db->fetchOne("SELECT u.status FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                                         WHERE u.id = ? AND r.code = 'AGENT' AND u.deleted_at IS NULL", [$agentId]);
                    $agentScopes[$agentId] = ($u !== null && $u['status'] === 'ACTIF') ? Scope::forUser($db, $agentId) : null;
                }
                return $agentScopes[$agentId] !== null && $agentScopes[$agentId]->coversCommune($communeId);
            };
            foreach ($rows as $r) {
                if ($r['status'] !== 'ACTIVE') {
                    $result['structures_skipped']++;
                    continue;
                }
                $result['structures_copied'] += $db->execute('INSERT IGNORE INTO campaign_structures (campaign_id, structure_id, added_by) VALUES (?, ?, ?)',
                    [$id, $r['structure_id'], $actor['id']]);
                if (!$copyAssignments) {
                    continue;
                }
                $old = $db->fetchOne('SELECT agent_id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$r['old_cs']]);
                if ($old === null) {
                    continue;
                }
                $newCs = (int) $db->fetchValue('SELECT id FROM campaign_structures WHERE campaign_id = ? AND structure_id = ?', [$id, $r['structure_id']]);
                if (!$agentOk((int) $old['agent_id'], (int) $r['commune_id'])
                    || $db->fetchValue('SELECT 1 FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$newCs]) !== null) {
                    $result['assignments_skipped']++;
                    continue;
                }
                $db->execute('INSERT INTO assignments (campaign_structure_id, agent_id, assigned_by) VALUES (?, ?, ?)', [$newCs, $old['agent_id'], $actor['id']]);
                $result['assignments_copied']++;
            }
            if ($copyAssignments) {
                foreach ($db->fetchAll('SELECT agent_id, commune_id FROM agent_areas WHERE campaign_id = ?', [$sourceId]) as $a) {
                    if ($actor['scope']->coversCommune((int) $a['commune_id']) && $agentOk((int) $a['agent_id'], (int) $a['commune_id'])) {
                        $result['areas_copied'] += $db->execute('INSERT IGNORE INTO agent_areas (campaign_id, agent_id, commune_id, assigned_by) VALUES (?, ?, ?, ?)',
                            [$id, $a['agent_id'], $a['commune_id'], $actor['id']]);
                    }
                }
            }
            $this->audit->log($actor['id'], 'CAMPAIGN_CLONE', 'campaign', (string) $id, null, ['source' => $sourceId] + $result, null, $ip);
            return $result;
        });
    }

    // ===================================================================== Interne

    private function campaignSql(): string
    {
        return 'SELECT c.*, y.code AS year_code, v.version_code, v.status AS version_status
                  FROM campaigns c JOIN academic_years y ON y.id = c.academic_year_id JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id';
    }

    /** Présentation d'une campagne, avec l'avancement du ciblage dans le périmètre de l'appelant. */
    private function present(array $c, array $actor): array
    {
        [$scopeSql, $params] = $actor['scope']->communeClause('s.commune_id');
        $counts = $this->db->fetchOne(
            "SELECT COUNT(*) AS targeted, COUNT(a.id) AS assigned FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id
               LEFT JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
              WHERE cs.campaign_id = ? AND $scopeSql", [$c['id'], ...$params]);
        return [
            'id' => (int) $c['id'],
            'code' => $c['code'],
            'name' => $c['name'],
            'academic_year_id' => (int) $c['academic_year_id'],
            'academic_year' => $c['year_code'],
            'questionnaire_version' => $c['version_code'],
            'status' => $c['status'],
            'opens_on' => $c['opens_on'],
            'closes_on' => $c['closes_on'],
            'validation_levels' => (int) $c['validation_levels'],
            'collection_mode' => $c['collection_mode'],
            'instructions' => $c['instructions'],
            'accepts_submissions' => $c['status'] === 'OUVERTE',
            'allowed_transitions' => self::TRANSITIONS[$c['status']],
            'targeted' => (int) $counts['targeted'],
            'assigned' => (int) $counts['assigned'],
            'unassigned' => (int) $counts['targeted'] - (int) $counts['assigned'],
            'created_at' => $c['created_at'],
        ];
    }

    /** Mode de collecte : TABLETTE (application mobile), PAPIER (fiches saisies dans le back-office) ou MIXTE. */
    private function collectionMode(mixed $v, array &$errors): string
    {
        if (!in_array($v, ['TABLETTE', 'PAPIER', 'MIXTE'], true)) {
            $errors[] = ['field' => 'collection_mode', 'message' => 'Mode de collecte : TABLETTE, PAPIER ou MIXTE.'];
            return 'TABLETTE';
        }
        return $v;
    }

    private function year(int $id): array
    {
        return $this->presentYear($this->db->fetchOne(
            'SELECT y.*, (SELECT COUNT(*) FROM campaigns c WHERE c.academic_year_id = y.id) AS campaigns FROM academic_years y WHERE y.id = ?', [$id]));
    }

    private function presentYear(array $y): array
    {
        return ['id' => (int) $y['id'], 'code' => $y['code'], 'label' => $y['label'], 'start_date' => $y['start_date'], 'end_date' => $y['end_date'],
            'status' => $y['status'], 'campaigns' => (int) ($y['campaigns'] ?? 0)];
    }

    private function hasCollections(int $campaignId): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM collections WHERE campaign_id = ?)', [$campaignId])
            || (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM collection_drafts d JOIN campaign_structures cs ON cs.id = d.campaign_structure_id WHERE cs.campaign_id = ?)', [$campaignId]);
    }

    /** Version publiée désignée par son code (« 2025.1 ») ; sinon erreur de validation. */
    private function publishedVersion(mixed $code, array &$errors): ?int
    {
        $v = is_string($code) ? $this->db->fetchOne('SELECT id, status FROM questionnaire_versions WHERE version_code = ?', [$code]) : null;
        if ($v === null) {
            $errors[] = ['field' => 'questionnaire_version', 'message' => 'Version de questionnaire obligatoire (ex. 2025.1).'];
            return null;
        }
        if ($v['status'] !== 'PUBLIEE') {
            $errors[] = ['field' => 'questionnaire_version', 'message' => "La version $code n'est pas publiée."];
            return null;
        }
        return (int) $v['id'];
    }

    /** @return array{0:?string, 1:?string} */
    private function dateRange(array $in, string $from, string $to, array &$errors, bool $allowEqual): array
    {
        $out = [];
        foreach ([$from, $to] as $k) {
            $v = is_string($in[$k] ?? null) ? trim($in[$k]) : '';
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($d === false || $d->format('Y-m-d') !== $v) {
                $errors[] = ['field' => $k, 'message' => 'Date obligatoire au format AAAA-MM-JJ.'];
                $out[] = null;
            } else {
                $out[] = $v;
            }
        }
        if ($out[0] !== null && $out[1] !== null && ($allowEqual ? $out[1] < $out[0] : $out[1] <= $out[0])) {
            $errors[] = ['field' => $to, 'message' => 'La date de fin doit suivre la date de début.'];
        }
        return $out;
    }

    private function text(mixed $v, int $max, bool $clean = true): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = $clean ? Text::clean($v) : trim($v);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Texte trop long ($max caractères au plus).");
        }
        return $v;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    }

    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Certaines informations sont invalides.', ['errors' => $errors]);
        }
    }
}
