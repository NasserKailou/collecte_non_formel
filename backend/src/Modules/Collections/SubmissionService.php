<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;
use App\Http\UploadedFile;
use App\Modules\Campaigns\CampaignService;
use App\Modules\Structures\StructureService;
use App\Questionnaire\QuestionnaireEngine;

/**
 * Réception des collectes envoyées par les tablettes (docs/api/README.md §3.2, docs/phase1/03 §6).
 *
 * Garanties :
 * - idempotence : renvoyer exactement la même soumission (même empreinte, même révision de base)
 *   renvoie l'accusé initial, sans doublon, même si la campagne a été clôturée entre-temps ;
 * - concurrence : verrou sur la structure ciblée (SELECT … FOR UPDATE) ; révision de base ≠ révision
 *   serveur ⇒ 409 REVISION_CONFLICT ; une seule collecte par structure et par campagne ;
 * - seul l'agent affecté actif soumet, seulement pendant que la campagne est OUVERTE ;
 * - une collecte remise (SOUMISE, EN_CONTROLE, VALIDEE) n'est plus modifiable par l'agent (423) ;
 * - tous les contrôles du questionnaire sont rejoués par le serveur (QuestionnaireEngine) ; les champs
 *   d'identité verrouillés sont toujours repris du registre, jamais de la tablette ;
 * - chaque soumission acceptée est conservée intégralement et n'est jamais modifiée.
 */
final class SubmissionService
{
    public const MAX_PAYLOAD_BYTES = 1_000_000;
    public const ATTACHMENT_MAX_BYTES = 3 * 1024 * 1024;
    private const EDITABLE_STATUSES = ['INVALIDEE', 'REOUVERTE'];
    /** Canaux admis selon le mode de collecte de la campagne (la saisie web directe reste possible en mode tablette). */
    public const CHANNELS_BY_MODE = ['TABLETTE' => ['MOBILE', 'WEB'], 'PAPIER' => ['PAPIER'], 'MIXTE' => ['MOBILE', 'WEB', 'PAPIER']];
    /** Champs d'identité verrouillés (prefill) : la valeur du registre fait foi. */
    private const LOCKED_PREFILL = [
        'Q1_1' => 'name', 'Q1_2' => 'official_code', 'Q1_6_1' => 'region_id', 'Q1_6_2' => 'department_id', 'Q1_6_3' => 'commune_id',
    ];
    private const SIMPLE_TYPES = ['text', 'phone', 'yesno', 'integer', 'date', 'single', 'multi', 'geo'];
    private const DURATIONS = ['LE3', 'M4_9', 'GT9'];
    private const MEASURES = ['INSCRITS', 'PLACES', 'OUTILLES'];
    private const CATEGORIES = ['HANDICAPES', 'REFUGIES', 'RETOURNES', 'DEPLACES'];
    private const PROMOS = ['p1_m', 'p1_f', 'p2_m', 'p2_f', 'p3_m', 'p3_f'];

    /** @var array<int, array{engine:QuestionnaireEngine, definition:array}> */
    private array $engines = [];
    private readonly CampaignService $campaigns;

    public function __construct(private readonly Database $db, private readonly string $storageDir)
    {
        $this->campaigns = new CampaignService($db);
    }

    // ===================================================================== Soumission

    /**
     * @param array{id:int, device_id:?int} $user
     * @return array{status:int, body:array} 201 (nouvelle révision) ou 200 (rejeu)
     */
    public function submit(array $user, string $uuid, array $in, ?string $appVersion, int $requestBytes): array
    {
        $t0 = microtime(true);
        try {
            $result = $this->doSubmit($user, $uuid, $in, $appVersion);
            $this->syncLog($user, $uuid, $result['status'], $result['status'] === 200 ? 'REJOUE' : 'ACCEPTE', null, $t0, $requestBytes);
            return $result;
        } catch (HttpException $e) {
            $outcome = match (true) {
                $e->status === 409 => 'CONFLIT',
                $e->status >= 500 => 'ERREUR',
                default => 'REFUSE',
            };
            $this->syncLog($user, $uuid, $e->status, $outcome, $e->errorCode . ' : ' . $e->getMessage(), $t0, $requestBytes);
            throw $e;
        }
    }

    private function doSubmit(array $user, string $uuid, array $in, ?string $appVersion): array
    {
        $uuid = strtolower($uuid);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Identifiant de collecte invalide (UUID v4 attendu).');
        }
        $csId = $in['campaign_structure_id'] ?? null;
        $version = $in['questionnaire_version'] ?? null;
        $base = $in['base_revision'] ?? null;
        $data = $in['data'] ?? null;
        if (!is_int($csId) || !is_string($version) || !is_int($base) || $base < 0 || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(422, 'VALIDATION_FAILED',
                'Champs obligatoires : campaign_structure_id (entier), questionnaire_version, base_revision (entier ≥ 0), data (objet).');
        }
        unset($data['_ctx']); // le contexte d'évaluation est toujours calculé par le serveur
        $started = $this->deviceTime($in['device_started_at'] ?? null, 'device_started_at');
        $completed = $this->deviceTime($in['device_completed_at'] ?? null, 'device_completed_at');
        $hash = hash('sha256', self::canonicalJson(['campaign_structure_id' => $csId, 'questionnaire_version' => $version, 'data' => $data]));
        [$channel, $paper] = $this->channel($user, $in);

        return $this->db->transaction(function (Database $db) use ($user, $uuid, $csId, $version, $base, $data, $hash, $started, $completed, $appVersion, $channel, $paper): array {
            $cs = $db->fetchOne(
                'SELECT cs.id, cs.campaign_id, cs.structure_id, c.questionnaire_version_id, c.opens_on, c.collection_mode, v.version_code
                   FROM campaign_structures cs JOIN campaigns c ON c.id = cs.campaign_id JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id
                  WHERE cs.id = ? FOR UPDATE', [$csId]);
            if ($cs === null) {
                throw new HttpException(403, 'OUT_OF_SCOPE', 'Cette structure ne vous est pas affectée.');
            }
            $col = $db->fetchOne('SELECT * FROM collections WHERE campaign_structure_id = ? FOR UPDATE', [$csId]);
            $byUuid = ($col !== null && $col['uuid'] === $uuid) ? $col : $db->fetchOne('SELECT id, campaign_structure_id FROM collections WHERE uuid = ?', [$uuid]);
            if ($byUuid !== null && (int) $byUuid['campaign_structure_id'] !== $csId) {
                throw new HttpException(409, 'CONFLICT', 'Cet identifiant de collecte appartient à une autre structure.');
            }

            // 1. Rejeu exact d'une soumission déjà acceptée : accusé initial, rien n'est réécrit.
            if ($col !== null && $col['uuid'] === $uuid) {
                $prev = $db->fetchOne('SELECT revision, received_at, validation_report FROM collection_submissions
                                        WHERE collection_id = ? AND payload_sha256 = ? AND revision = ? AND submitted_by = ?',
                    [$col['id'], $hash, $base + 1, $user['id']]);
                if ($prev !== null) {
                    $report = json_decode((string) $prev['validation_report'], true) ?? [];
                    return ['status' => 200, 'body' => $this->ack($uuid, $col['status'], (int) $prev['revision'], $prev['received_at'], $report['warnings'] ?? [], true)];
                }
            }

            // 2. Seul l'agent affecté actif soumet.
            $assignee = $db->fetchValue('SELECT agent_id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$csId]);
            if ($assignee === null || (int) $assignee !== $user['id']) {
                throw new HttpException(403, 'OUT_OF_SCOPE', 'Cette structure ne vous est pas (ou plus) affectée.');
            }
            // 3. Campagne ouverte, bonne version du questionnaire.
            $this->campaigns->assertAcceptsSubmissions((int) $cs['campaign_id']);
            // Version admise : celle de la campagne, ou celle déjà enregistrée pour cette collecte (correction d'une collecte
            // commencée avant une montée de version de la campagne : chaque collecte garde sa version).
            $versionId = (int) $cs['questionnaire_version_id'];
            if ($version !== $cs['version_code']) {
                $own = $col === null ? null : $db->fetchOne('SELECT id, version_code FROM questionnaire_versions WHERE id = ?', [$col['questionnaire_version_id']]);
                if ($own === null || $own['version_code'] !== $version) {
                    throw new HttpException(409, 'QUESTIONNAIRE_VERSION_MISMATCH',
                        "Cette campagne utilise la version {$cs['version_code']} du questionnaire. Synchroniser ou mettre l'application à jour.", ['expected' => $cs['version_code']]);
                }
                $versionId = (int) $own['id'];
            }
            // 3 bis. Canal autorisé par le mode de collecte de la campagne.
            $allowed = self::CHANNELS_BY_MODE[$cs['collection_mode']] ?? ['MOBILE', 'WEB'];
            if (!in_array($channel, $allowed, true)) {
                throw new HttpException(422, 'CHANNEL_NOT_ALLOWED', match ($cs['collection_mode']) {
                    'PAPIER' => 'Cette campagne se fait sur questionnaire papier : saisir la fiche dans le back-office (saisie papier).',
                    default => "Ce mode de saisie n'est pas prévu pour cette campagne.",
                }, ['channel' => $channel, 'allowed' => $allowed]);
            }
            // 4. Une seule collecte par structure ; révision ; état modifiable.
            if ($col !== null && $col['uuid'] !== $uuid) {
                throw new HttpException(409, 'COLLECTION_EXISTS', 'Une collecte existe déjà pour cette structure. Synchroniser la tablette pour la récupérer.',
                    ['uuid' => $col['uuid'], 'status' => $col['status'], 'revision' => (int) $col['revision']]);
            }
            $serverRevision = $col === null ? 0 : (int) $col['revision'];
            if ($base !== $serverRevision) {
                throw new HttpException(409, 'REVISION_CONFLICT', 'La collecte a changé sur le serveur depuis votre dernière synchronisation.',
                    ['server_revision' => $serverRevision, 'status' => $col['status'] ?? null]);
            }
            if ($col !== null && !in_array($col['status'], self::EDITABLE_STATUSES, true)) {
                throw new HttpException(423, 'LOCKED', 'Cette collecte est en attente de contrôle ou validée : elle ne peut plus être modifiée.',
                    ['status' => $col['status']]);
            }

            // 5. Contrôles du questionnaire rejoués par le serveur.
            $structure = (new StructureService($db))->detail((int) $cs['structure_id']);
            [$engine, $definition] = $this->engine($versionId);
            $overrides = [];
            foreach (self::LOCKED_PREFILL as $q => $field) {
                if (array_key_exists($q, $data) && $data[$q] !== $structure[$field]) {
                    $overrides[] = $q;
                }
                $data[$q] = $structure[$field];
            }
            $gps = is_array($data['GPS'] ?? null) ? $data['GPS'] : [];
            $distance = $this->gpsDistanceKm((int) $structure['commune_id'], $gps);
            $result = $engine->validate($data + ['_ctx' => $this->context((string) $cs['opens_on'], $distance)]);
            if (!$result['valid']) {
                throw new HttpException(422, 'VALIDATION_FAILED', count($result['errors']) . ' erreur(s) bloquante(s) : la collecte doit être corrigée.',
                    ['errors' => $result['errors'], 'warnings' => $result['warnings']]);
            }

            // 6. Écriture.
            $clean = $result['data'];
            $revision = $serverRevision + 1;
            $fields = [
                'questionnaire_version_id' => $versionId, 'agent_id' => $user['id'], 'status' => 'SOUMISE', 'review_level' => 1,
                'revision' => $revision, 'region_id' => $structure['region_id'], 'department_id' => $structure['department_id'], 'commune_id' => $structure['commune_id'],
                'locality_label' => self::str($clean['Q1_6_4'] ?? null, 150),
                'latitude' => self::num($gps['lat'] ?? null), 'longitude' => self::num($gps['lng'] ?? null), 'gps_accuracy_m' => self::num($gps['accuracy_m'] ?? null),
                'gps_captured_at' => $this->deviceTime($gps['captured_at'] ?? null, 'GPS.captured_at'),
                'gps_status' => in_array($gps['status'] ?? null, ['OK', 'MANUEL', 'INDISPONIBLE', 'REFUSE'], true) ? $gps['status'] : 'INDISPONIBLE',
                'gps_note' => self::str($gps['note'] ?? null, 255), 'gps_distance_km' => $distance === null ? null : round($distance, 2),
                'filled_on' => is_string($clean['Q5_DATE'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean['Q5_DATE']) === 1 ? $clean['Q5_DATE'] : null,
                'device_started_at' => $started, 'device_completed_at' => $completed,
                'claimed_by' => null, 'claimed_at' => null, 'validated_by' => null, 'validated_at' => null,
                'warnings_count' => min(65535, count($result['warnings'])), 'paper_form_signed' => ($clean['Q5_PAPER_SIGNED'] ?? false) === true ? 1 : 0,
                'payload_sha256' => $hash,
                'channel' => $channel, 'paper_form_ref' => $paper['form_ref'] ?? null, 'paper_interview_date' => $paper['interview_date'] ?? null,
                'paper_enumerator' => $paper['enumerator'] ?? null,
            ];
            if ($col === null) {
                $fields += ['uuid' => $uuid, 'campaign_structure_id' => $csId, 'campaign_id' => (int) $cs['campaign_id'], 'structure_id' => (int) $cs['structure_id']];
                $cols = array_keys($fields);
                $collectionId = $db->insert('INSERT INTO collections (' . implode(', ', $cols) . ', first_submitted_at, last_submitted_at) VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ', NOW(), NOW())', array_values($fields));
                $from = null;
            } else {
                $collectionId = (int) $col['id'];
                $db->execute('UPDATE collections SET ' . implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields))) . ', last_submitted_at = NOW() WHERE id = ?',
                    [...array_values($fields), $collectionId]);
                $from = $col['status'];
            }
            $this->persistChildren($collectionId, $clean, $result['computed'], $definition);
            $report = ['warnings' => $result['warnings'], 'cleared' => $result['cleared'], 'locked_overrides' => $overrides];
            $db->execute('INSERT INTO collection_submissions (collection_id, revision, payload_json, payload_sha256, validation_report, device_id, app_version, channel, submitted_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$collectionId, $revision, json_encode(['campaign_structure_id' => $csId, 'questionnaire_version' => $version, 'data' => $data]
                    + ($paper === null ? [] : ['paper' => $paper]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    $hash, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $user['device_id'],
                    $appVersion === null ? null : mb_substr($appVersion, 0, 20), $channel, $user['id']]);
            // Brouillon de saisie web de cette structure : remplacé par la collecte soumise.
            $db->execute('DELETE FROM collection_drafts WHERE campaign_structure_id = ?', [$csId]);
            $db->execute('INSERT INTO collection_status_history (collection_id, from_status, to_status, review_level, revision, user_id, note) VALUES (?, ?, ?, 1, ?, ?, ?)',
                [$collectionId, $from, 'SOUMISE', $revision, $user['id'], $from === null ? 'Première soumission' : 'Nouvelle soumission après ' . $from]);
            $receivedAt = (string) $db->fetchValue('SELECT received_at FROM collection_submissions WHERE collection_id = ? AND revision = ?', [$collectionId, $revision]);
            return ['status' => 201, 'body' => $this->ack($uuid, 'SOUMISE', $revision, $receivedAt, $result['warnings'], false)];
        });
    }

    /**
     * Contrôle « à blanc » pour la saisie web : mêmes règles, même contexte et mêmes champs verrouillés que la
     * soumission, sans aucune écriture. L'autorisation (affectation) est vérifiée par l'appelant.
     *
     * @return array{valid:bool, errors:list<array>, warnings:list<array>, cleared:list<string>, computed:array}
     */
    public function dryRun(int $csId, array $data): array
    {
        $cs = $this->db->fetchOne(
            'SELECT cs.structure_id, c.questionnaire_version_id, c.opens_on FROM campaign_structures cs JOIN campaigns c ON c.id = cs.campaign_id WHERE cs.id = ?', [$csId]);
        if ($cs === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Structure introuvable dans cette campagne.');
        }
        unset($data['_ctx']);
        $structure = (new StructureService($this->db))->detail((int) $cs['structure_id']);
        foreach (self::LOCKED_PREFILL as $q => $field) {
            $data[$q] = $structure[$field];
        }
        [$engine] = $this->engine((int) $cs['questionnaire_version_id']);
        $gps = is_array($data['GPS'] ?? null) ? $data['GPS'] : [];
        $r = $engine->validate($data + ['_ctx' => $this->context((string) $cs['opens_on'], $this->gpsDistanceKm((int) $structure['commune_id'], $gps))]);
        return ['valid' => $r['valid'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'cleared' => $r['cleared'], 'computed' => $r['computed']];
    }

    // ===================================================================== Pièces jointes

    /** Photo de la fiche papier signée (JPEG/PNG ≤ 3 Mo, type vérifié sur le contenu). Idempotent par empreinte. */
    public function addAttachment(array $user, string $uuid, UploadedFile $file, string $kind): array
    {
        $col = $this->db->fetchOne('SELECT id, agent_id, campaign_structure_id FROM collections WHERE uuid = ?', [strtolower($uuid)]);
        $assignee = $col === null ? null
            : $this->db->fetchValue('SELECT agent_id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$col['campaign_structure_id']]);
        if ($col === null || ((int) $col['agent_id'] !== $user['id'] && (int) $assignee !== $user['id'])) {
            throw new HttpException(404, 'NOT_FOUND', "Collecte introuvable : la soumettre avant d'y joindre une photo.");
        }
        if (!in_array($kind, ['FICHE_SIGNEE', 'AUTRE'], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Type de pièce jointe : FICHE_SIGNEE ou AUTRE.');
        }
        $file->assertValid(['jpg', 'jpeg', 'png'], self::ATTACHMENT_MAX_BYTES);
        $info = @getimagesize($file->path);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new HttpException(422, 'UNSUPPORTED_FILE', "Le fichier n'est pas une image JPEG ou PNG valide.");
        }
        $sha = (string) hash_file('sha256', $file->path);
        $existing = $this->db->fetchValue('SELECT a.id FROM collection_attachments a JOIN files f ON f.id = a.file_id WHERE a.collection_id = ? AND f.sha256 = ?',
            [$col['id'], $sha]);
        if ($existing !== null) {
            return ['id' => (int) $existing, 'replayed' => true];
        }
        $fileUuid = Text::uuid4();
        $relative = 'attachments/' . strtolower($uuid) . '/' . $fileUuid . ($mime === 'image/png' ? '.png' : '.jpg');
        $file->moveTo($this->storageDir . '/' . $relative);
        $id = $this->db->transaction(function (Database $db) use ($user, $file, $relative, $mime, $sha, $fileUuid, $col, $kind): int {
            $fid = $db->insert("INSERT INTO files (uuid, owner_id, purpose, original_name, stored_path, mime_type, size_bytes, sha256) VALUES (?, ?, 'ATTACHMENT', ?, ?, ?, ?, ?)",
                [$fileUuid, $user['id'], mb_substr($file->clientName, 0, 255), $relative, $mime, filesize($this->storageDir . '/' . $relative), $sha]);
            return $db->insert('INSERT INTO collection_attachments (collection_id, file_id, kind) VALUES (?, ?, ?)', [$col['id'], $fid, $kind]);
        });
        return ['id' => $id, 'replayed' => false];
    }

    // ===================================================================== Moteur et contexte

    /** @return array{0:QuestionnaireEngine, 1:array} */
    public function engine(int $versionId): array
    {
        if (!isset($this->engines[$versionId])) {
            $def = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?', [$versionId]), true, 512, JSON_THROW_ON_ERROR);
            $this->engines[$versionId] = ['engine' => new QuestionnaireEngine($def), 'definition' => $def];
        }
        return [$this->engines[$versionId]['engine'], $this->engines[$versionId]['definition']];
    }

    /**
     * Contexte d'évaluation (_ctx) calculé par le serveur : date du jour (heure du Niger), paramètres,
     * listes de référence des métiers actifs par secteur (+ « AUTRE »).
     */
    public function context(string $campaignOpensOn, ?float $gpsDistanceKm): array
    {
        $shared = (new EvaluationContext($this->db))->shared();
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey'));
        return [
            'today' => $today->format('Y-m-d'),
            'current_year' => (int) $today->format('Y'),
            'campaign_opens_on' => $campaignOpensOn,
            'gps_distance_km' => $gpsDistanceKm === null ? null : round($gpsDistanceKm, 2),
            'gps_max_km' => $shared['gps_max_km'],
            'max_apprentices_per_trainer' => $shared['max_apprentices_per_trainer'],
            'options' => $shared['options'],
        ];
    }

    private function gpsDistanceKm(int $communeId, array $gps): ?float
    {
        if (!in_array($gps['status'] ?? null, ['OK', 'MANUEL'], true) || !is_numeric($gps['lat'] ?? null) || !is_numeric($gps['lng'] ?? null)) {
            return null;
        }
        $c = $this->db->fetchOne('SELECT centroid_lat, centroid_lng FROM communes WHERE id = ?', [$communeId]);
        if ($c === null || $c['centroid_lat'] === null || $c['centroid_lng'] === null) {
            return null; // centroïdes non fournis par le RENACOM : contrôle de distance non applicable
        }
        return StructureService::distanceMeters((float) $gps['lat'], (float) $gps['lng'], (float) $c['centroid_lat'], (float) $c['centroid_lng']) / 1000;
    }

    // ===================================================================== Ventilation dans les tables d'analyse

    /** Remplace les données ventilées de la collecte (réponses simples et calculées, formateurs, métiers, « dont », jeunes accompagnés). */
    private function persistChildren(int $id, array $data, array $computed, array $definition): void
    {
        foreach (['collection_answers', 'collection_trainers', 'collection_trades', 'collection_sector_specials', 'collection_accompanied'] as $t) {
            $this->db->execute("DELETE FROM $t WHERE collection_id = ?", [$id]);
        }
        $answers = [];
        foreach ($definition['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (!in_array($item['type'], self::SIMPLE_TYPES, true) || str_contains($item['code'], '.')) {
                    continue;
                }
                $v = $data[$item['code']] ?? null;
                if ($v === null || $v === '' || $v === []) {
                    continue;
                }
                $q = $item['code'];
                switch ($item['type']) {
                    case 'multi':
                        foreach ((array) $v as $opt) {
                            $answers[] = [$q, (string) $opt, null, null, null];
                        }
                        break;
                    case 'single':
                        $answers[] = [$q, (string) $v, (string) $v, null, null];
                        break;
                    case 'yesno':
                        $answers[] = [$q, '', null, $v === true ? 1 : 0, null];
                        break;
                    case 'integer':
                    case 'geo':
                        $answers[] = [$q, '', null, is_numeric($v) ? $v : null, null];
                        break;
                    case 'date':
                        $full = is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
                        $answers[] = [$q, '', $full ? null : (string) $v, null, $full ? $v : null];
                        break;
                    default:
                        $answers[] = [$q, '', mb_substr((string) $v, 0, 5000), null, null];
                }
            }
        }
        foreach (self::flatten($computed) as $code => $value) {
            if (is_numeric($value) && strlen($code) <= 40) {
                $answers[] = [$code, '', null, $value, null];
            }
        }
        foreach ($answers as [$q, $opt, $text, $num, $date]) {
            $this->db->execute('INSERT INTO collection_answers (collection_id, question_code, option_code, value_text, value_number, value_date) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $q, $opt, $text, $num, $date]);
        }

        foreach (self::DURATIONS as $d) {
            foreach (self::MEASURES as $m) {
                $cell = $data['accompanied'][$d][$m] ?? [];
                $this->db->execute('INSERT INTO collection_accompanied (collection_id, duration_class, measure, male, female) VALUES (?, ?, ?, ?, ?)',
                    [$id, $d, $m, self::count($cell['M'] ?? 0), self::count($cell['F'] ?? 0)]);
            }
        }

        foreach (array_values($data['trainers'] ?? []) as $i => $t) {
            $certified = ($t['certified'] ?? false) === true;
            $this->db->execute(
                'INSERT INTO collection_trainers (collection_id, line_no, full_name, sex, grade, initial_training, continuing_trainings_count, certified,
                                                  cert_name, cert_level, cert_body, cert_year, nationality, pedagogical_followup)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $i + 1, mb_substr((string) $t['full_name'], 0, 150), $t['sex'], $t['grade'], ($t['initial_training'] ?? false) === true ? 1 : 0,
                    min(255, (int) ($t['continuing_trainings_count'] ?? 0)), $certified ? 1 : 0,
                    $certified ? self::str($t['cert_name'] ?? null, 150) : null, $certified ? self::str($t['cert_level'] ?? null, 100) : null,
                    $certified ? self::str($t['cert_body'] ?? null, 150) : null, $certified && is_int($t['cert_year'] ?? null) ? $t['cert_year'] : null,
                    $t['nationality'], ($t['pedagogical_followup'] ?? false) === true ? 1 : 0]);
        }

        foreach ($data['sectors'] ?? [] as $key => $sector) {
            if (preg_match('/^s(\d+)$/', (string) $key, $m) !== 1 || !is_array($sector)) {
                continue;
            }
            $sectorId = (int) $m[1];
            foreach (array_values($sector['trades'] ?? []) as $i => $row) {
                $isOther = ($row['trade'] ?? null) === 'AUTRE';
                $this->db->execute(
                    'INSERT INTO collection_trades (collection_id, sector_id, line_no, trade_id, trade_other_label, duration_class, p1_m, p1_f, p2_m, p2_f, p3_m, p3_f)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $sectorId, $i + 1, $isOther ? null : (int) $row['trade'], $isOther ? self::str($row['trade_other'] ?? null, 150) : null, $row['duration'],
                        ...array_map(static fn($p) => self::count($row[$p] ?? 0), self::PROMOS)]);
            }
            foreach (self::CATEGORIES as $cat) {
                $cell = $sector['specials'][$cat] ?? [];
                $this->db->execute(
                    'INSERT INTO collection_sector_specials (collection_id, sector_id, category, p1_m, p1_f, p2_m, p2_f, p3_m, p3_f) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $sectorId, $cat, ...array_map(static fn($p) => self::count($cell[$p] ?? 0), self::PROMOS)]);
            }
        }
    }

    // ===================================================================== Utilitaires

    private function ack(string $uuid, string $status, int $revision, string $receivedAt, array $warnings, bool $replayed): array
    {
        return ['uuid' => $uuid, 'status' => $status, 'revision' => $revision, 'received_at' => $receivedAt, 'replayed' => $replayed,
            'warnings' => array_map(static fn($w) => ['code' => $w['code'], 'target' => $w['target'], 'message' => $w['message']], $warnings)];
    }

    private function syncLog(array $user, string $uuid, int $status, string $outcome, ?string $detail, float $t0, int $bytes): void
    {
        try {
            $this->db->execute("INSERT INTO sync_logs (user_id, device_id, direction, endpoint, collection_uuid, http_status, outcome, detail, duration_ms, request_bytes)
                                VALUES (?, ?, 'PUSH', 'POST /collections/{uuid}/submissions', ?, ?, ?, ?, ?, ?)",
                [$user['id'], $user['device_id'], preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 ? strtolower($uuid) : null, $status, $outcome,
                    $detail === null ? null : mb_substr($detail, 0, 500), (int) round((microtime(true) - $t0) * 1000), $bytes]);
        } catch (\Throwable) {
            // le journal de synchronisation ne doit jamais faire échouer la réponse à la tablette
        }
    }

    /** Horodatage ISO 8601 de l'appareil → DATETIME à l'heure du Niger ; null si absent. */
    /**
     * Canal de la soumission. Tablette (jeton porteur) ⇒ MOBILE ; back-office (session web) ⇒ WEB (saisie directe) ou
     * PAPIER (saisie d'une fiche papier, avec ses références obligatoires).
     *
     * @return array{0: string, 1: ?array{form_ref: string, interview_date: string, enumerator: string}}
     */
    private function channel(array $user, array $in): array
    {
        if (empty($user['via_cookie'])) {
            return ['MOBILE', null];
        }
        if (($in['channel'] ?? 'WEB') !== 'PAPIER') {
            return ['WEB', null];
        }
        $p = is_array($in['paper'] ?? null) ? $in['paper'] : [];
        $ref = is_string($p['form_ref'] ?? null) ? trim($p['form_ref']) : '';
        $date = is_string($p['interview_date'] ?? null) ? $p['interview_date'] : '';
        $enum = is_string($p['enumerator'] ?? null) ? trim($p['enumerator']) : '';
        $errors = [];
        if ($ref === '' || mb_strlen($ref) > 50) {
            $errors[] = ['field' => 'paper.form_ref', 'message' => 'Numéro de la fiche papier obligatoire (50 caractères au plus).'];
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey')))->format('Y-m-d');
        if ($d === false || $d->format('Y-m-d') !== $date || $date > $today) {
            $errors[] = ['field' => 'paper.interview_date', 'message' => "Date de l'entretien invalide (AAAA-MM-JJ, au plus aujourd'hui)."];
        }
        if (mb_strlen($enum) < 2 || mb_strlen($enum) > 150) {
            $errors[] = ['field' => 'paper.enumerator', 'message' => "Nom de l'enquêteur qui a rempli la fiche obligatoire."];
        }
        if ($errors !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Références de la fiche papier incomplètes.', ['errors' => $errors]);
        }
        return ['PAPIER', ['form_ref' => $ref, 'interview_date' => $date, 'enumerator' => $enum]];
    }

    private function deviceTime(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $v) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Horodatage « $field » invalide (ISO 8601 avec fuseau attendu).");
        }
        return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('Africa/Niamey'))->format('Y-m-d H:i:s');
    }

    /** JSON canonique (clés des objets triées récursivement) : base de l'empreinte d'idempotence. */
    public static function canonicalJson(mixed $v): string
    {
        $sort = static function (mixed $x) use (&$sort): mixed {
            if (!is_array($x)) {
                return $x;
            }
            if (!array_is_list($x)) {
                ksort($x, SORT_STRING);
            }
            return array_map($sort, $x);
        };
        return json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    private static function count(mixed $v): int
    {
        if ($v === null) {
            return 0;
        }
        if (!is_int($v) || $v < 0 || $v > 65535) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Effectif invalide (entier de 0 à 65 535 attendu).');
        }
        return $v;
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? (float) $v : null;
    }

    private static function str(mixed $v, int $max): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
    }
}
