<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;

/**
 * Saisie dans le back-office web : saisie directe par l'agent ou saisie d'une fiche papier.
 *
 * Les brouillons sont conservés sur le serveur (collection_drafts) ; la soumission passe par le même circuit que la
 * tablette (SubmissionService : contrôles rejoués, révisions, rejeu idempotent). Seul l'agent affecté actif saisit,
 * pendant que la campagne accepte des soumissions.
 */
final class EntryService
{
    public function __construct(private readonly Database $db, private readonly SubmissionService $submissions)
    {
    }

    /** Structures affectées à l'agent dans les campagnes ouvertes, avec l'état de la collecte et du brouillon. */
    public function assignments(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT cs.id AS campaign_structure_id, c.id AS campaign_id, c.code AS campaign_code, c.name AS campaign_name, c.collection_mode,
                    s.name, s.official_code, co.name AS commune, d.name AS department, s.locality_label,
                    col.uuid AS collection_uuid, col.status AS collection_status, col.revision, col.channel,
                    dr.updated_at AS draft_updated_at, dr.channel AS draft_channel, dr.errors_count AS draft_errors
               FROM assignments a JOIN campaign_structures cs ON cs.id = a.campaign_structure_id JOIN campaigns c ON c.id = cs.campaign_id
               JOIN structures s ON s.id = cs.structure_id JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id
               LEFT JOIN collections col ON col.campaign_structure_id = cs.id
               LEFT JOIN collection_drafts dr ON dr.campaign_structure_id = cs.id
              WHERE a.agent_id = ? AND a.ended_at IS NULL AND c.status = 'OUVERTE'
              ORDER BY c.opens_on DESC, s.name", [$userId]);
        return array_map(static function (array $r): array {
            $editable = $r['collection_status'] === null || in_array($r['collection_status'], ['INVALIDEE', 'REOUVERTE'], true);
            return $r + [
                'editable' => $editable,
                'allowed_channels' => SubmissionService::CHANNELS_BY_MODE[$r['collection_mode']] ?? ['MOBILE', 'WEB'],
                'state' => match (true) {
                    $r['collection_status'] !== null && !$editable => 'SOUMISE',
                    $r['draft_updated_at'] !== null => 'BROUILLON',
                    $r['collection_status'] !== null => 'A_CORRIGER',
                    default => 'A_SAISIR',
                },
            ];
        }, $rows);
    }

    /**
     * Ouverture d'une saisie : définition du questionnaire, identité de la structure, données de départ
     * (brouillon, sinon dernière révision à corriger, sinon pré-remplissage du registre).
     */
    public function open(int $userId, int $csId): array
    {
        $a = $this->assertAssigned($userId, $csId);
        $definition = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?',
            [$a['questionnaire_version_id']]), true, 512, JSON_THROW_ON_ERROR);
        $col = $this->db->fetchOne('SELECT id, uuid, status, revision, channel FROM collections WHERE campaign_structure_id = ?', [$csId]);
        $draft = $this->db->fetchOne('SELECT uuid, channel, data_json, paper_json FROM collection_drafts WHERE campaign_structure_id = ?', [$csId]);
        $editable = $col === null || in_array($col['status'], ['INVALIDEE', 'REOUVERTE'], true);

        $source = 'PREREMPLISSAGE';
        $paper = null;
        if ($draft !== null) {
            $data = json_decode((string) $draft['data_json'], true) ?? [];
            $paper = $draft['paper_json'] === null ? null : json_decode((string) $draft['paper_json'], true);
            $source = 'BROUILLON';
        } elseif ($col !== null) {
            $payload = json_decode((string) $this->db->fetchValue(
                'SELECT payload_json FROM collection_submissions WHERE collection_id = ? AND revision = ?', [$col['id'], $col['revision']]), true) ?? [];
            $data = $payload['data'] ?? [];
            $paper = $payload['paper'] ?? null;
            $source = 'DERNIERE_REVISION';
        } else {
            $data = $this->prefill($definition, $a);
        }
        $review = $col === null ? null : $this->db->fetchOne(
            'SELECT rr.label AS reason, r.comment, r.created_at FROM collection_reviews r LEFT JOIN rejection_reasons rr ON rr.id = r.reason_id
              WHERE r.collection_id = ? ORDER BY r.id DESC LIMIT 1', [$col['id']]);

        return [
            'campaign_structure_id' => $csId,
            'campaign' => ['id' => (int) $a['campaign_id'], 'code' => $a['campaign_code'], 'name' => $a['campaign_name'], 'collection_mode' => $a['collection_mode'],
                'questionnaire_version' => $a['version_code'], 'allowed_channels' => SubmissionService::CHANNELS_BY_MODE[$a['collection_mode']] ?? ['MOBILE', 'WEB']],
            'structure' => $this->structure($a),
            'uuid' => $col['uuid'] ?? $draft['uuid'] ?? Text::uuid4(),
            'base_revision' => $col === null ? 0 : (int) $col['revision'],
            'collection' => $col === null ? null : ['status' => $col['status'], 'revision' => (int) $col['revision'], 'channel' => $col['channel'], 'review' => $review],
            'editable' => $editable,
            'source' => $source,
            'channel' => $draft['channel'] ?? (($a['collection_mode'] === 'PAPIER') ? 'PAPIER' : 'WEB'),
            'paper' => $paper,
            'data' => $data,
            'definition' => $definition,
            'trades' => $this->trades(),
            'validation' => $editable ? $this->submissions->dryRun($csId, $data) : null,
        ];
    }

    /** Enregistre le brouillon (sauvegarde automatique) et renvoie le contrôle à blanc. */
    public function saveDraft(int $userId, int $csId, array $in): array
    {
        $this->assertAssigned($userId, $csId);
        $col = $this->db->fetchOne('SELECT uuid, status FROM collections WHERE campaign_structure_id = ?', [$csId]);
        if ($col !== null && !in_array($col['status'], ['INVALIDEE', 'REOUVERTE'], true)) {
            throw new HttpException(423, 'LOCKED', 'Cette collecte est en contrôle ou validée : elle ne peut plus être modifiée.');
        }
        $data = $in['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Champ « data » (objet) attendu.');
        }
        unset($data['_ctx']);
        $channel = ($in['channel'] ?? 'WEB') === 'PAPIER' ? 'PAPIER' : 'WEB';
        $paper = is_array($in['paper'] ?? null) ? array_intersect_key($in['paper'], array_flip(['form_ref', 'interview_date', 'enumerator'])) : null;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > SubmissionService::MAX_PAYLOAD_BYTES) {
            throw new HttpException(413, 'PAYLOAD_TOO_LARGE', 'Saisie trop volumineuse (1 Mo au plus).');
        }
        $check = $this->submissions->dryRun($csId, $data);
        $uuid = $col['uuid'] ?? $this->db->fetchValue('SELECT uuid FROM collection_drafts WHERE campaign_structure_id = ?', [$csId]) ?? Text::uuid4();
        $this->db->execute(
            'INSERT INTO collection_drafts (campaign_structure_id, uuid, user_id, channel, data_json, paper_json, errors_count, warnings_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), channel = VALUES(channel), data_json = VALUES(data_json), paper_json = VALUES(paper_json),
                                     errors_count = VALUES(errors_count), warnings_count = VALUES(warnings_count)',
            [$csId, $uuid, $userId, $channel, $json, $paper === null ? null : json_encode($paper, JSON_UNESCAPED_UNICODE),
                min(65535, count($check['errors'])), min(65535, count($check['warnings']))]);
        return ['uuid' => $uuid, 'saved_at' => date('c'), 'validation' => $check];
    }

    /**
     * Questionnaire d'une collecte ouvert par son superviseur (lecture, ou correction si $editable) : même forme
     * que open(), pour réutiliser le formulaire web. Le périmètre est vérifié par l'appelant (ReviewService::get).
     */
    public function reviewView(string $uuid, bool $editable): array
    {
        $col = $this->db->fetchOne('SELECT id, uuid, status, revision, channel, campaign_structure_id FROM collections WHERE uuid = ?', [strtolower($uuid)])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Collecte introuvable.');
        $a = $this->db->fetchOne(
            "SELECT cs.id, c.id AS campaign_id, c.code AS campaign_code, c.name AS campaign_name, c.collection_mode,
                    col.questionnaire_version_id, v.version_code, s.uuid, s.official_code, s.name, s.commune_id, co.department_id, d.region_id,
                    t.code AS type_code, s.zone, s.legal_status, s.locality_label, co.name AS commune, d.name AS department, r.name AS region
               FROM collections col JOIN campaign_structures cs ON cs.id = col.campaign_structure_id JOIN campaigns c ON c.id = cs.campaign_id
               JOIN questionnaire_versions v ON v.id = col.questionnaire_version_id JOIN structures s ON s.id = cs.structure_id
               JOIN structure_types t ON t.id = s.structure_type_id JOIN communes co ON co.id = s.commune_id
               JOIN departments d ON d.id = co.department_id JOIN regions r ON r.id = d.region_id
              WHERE col.id = ?", [$col['id']]);
        $definition = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?',
            [$a['questionnaire_version_id']]), true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode((string) $this->db->fetchValue(
            'SELECT payload_json FROM collection_submissions WHERE collection_id = ? AND revision = ?', [$col['id'], $col['revision']]), true) ?? [];
        $data = $payload['data'] ?? [];
        $csId = (int) $col['campaign_structure_id'];
        return [
            'campaign_structure_id' => $csId,
            'campaign' => ['id' => (int) $a['campaign_id'], 'code' => $a['campaign_code'], 'name' => $a['campaign_name'], 'collection_mode' => $a['collection_mode'],
                'questionnaire_version' => $a['version_code'], 'allowed_channels' => ['WEB']],
            'structure' => $this->structure($a),
            'uuid' => $col['uuid'],
            'base_revision' => (int) $col['revision'],
            'collection' => ['status' => $col['status'], 'revision' => (int) $col['revision'], 'channel' => $col['channel'], 'review' => null],
            'editable' => $editable,
            'source' => 'DERNIERE_REVISION',
            'channel' => 'WEB',
            'paper' => null,
            'data' => $data,
            'definition' => $definition,
            'trades' => $this->trades(),
            'validation' => $this->submissions->dryRun($csId, $data),
        ];
    }

    /** Contrôle à blanc d'une correction en cours (superviseur), sans écriture. */
    public function reviewCheck(string $uuid, array $data): array
    {
        $csId = (int) ($this->db->fetchValue('SELECT campaign_structure_id FROM collections WHERE uuid = ?', [strtolower($uuid)])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Collecte introuvable.'));
        if ($data !== [] && array_is_list($data)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Champ « data » (objet) attendu.');
        }
        unset($data['_ctx']);
        return ['validation' => $this->submissions->dryRun($csId, $data)];
    }

    public function discardDraft(int $userId, int $csId): void
    {
        $this->assertAssigned($userId, $csId);
        $this->db->execute('DELETE FROM collection_drafts WHERE campaign_structure_id = ?', [$csId]);
    }

    // -------------------------------------------------------------------------

    private function assertAssigned(int $userId, int $csId): array
    {
        $a = $this->db->fetchOne(
            "SELECT cs.id, c.id AS campaign_id, c.code AS campaign_code, c.name AS campaign_name, c.status AS campaign_status, c.collection_mode,
                    c.questionnaire_version_id, v.version_code, s.uuid, s.official_code, s.name, s.commune_id, co.department_id, d.region_id,
                    t.code AS type_code, s.zone, s.legal_status, s.locality_label, co.name AS commune, d.name AS department, r.name AS region
               FROM assignments a JOIN campaign_structures cs ON cs.id = a.campaign_structure_id JOIN campaigns c ON c.id = cs.campaign_id
               JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id JOIN structures s ON s.id = cs.structure_id
               JOIN structure_types t ON t.id = s.structure_type_id JOIN communes co ON co.id = s.commune_id
               JOIN departments d ON d.id = co.department_id JOIN regions r ON r.id = d.region_id
              WHERE a.campaign_structure_id = ? AND a.agent_id = ? AND a.ended_at IS NULL", [$csId, $userId]);
        if ($a === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Structure introuvable ou non affectée.');
        }
        if ($a['campaign_status'] !== 'OUVERTE') {
            throw new HttpException(423, 'CAMPAIGN_NOT_OPEN', "La campagne n'accepte pas de saisie (état : {$a['campaign_status']}).");
        }
        return $a;
    }

    /** Identité de la structure, codée comme dans le questionnaire (comme /sync/pull). */
    private function structure(array $a): array
    {
        return [
            'uuid' => $a['uuid'], 'official_code' => $a['official_code'], 'name' => $a['name'],
            'region_id' => (int) $a['region_id'], 'department_id' => (int) $a['department_id'], 'commune_id' => (int) $a['commune_id'],
            'region' => $a['region'], 'department' => $a['department'], 'commune' => $a['commune'],
            'type_code' => $a['type_code'], 'zone' => ReviewService::ZONE_CODES[$a['zone']] ?? null,
            'legal_status' => ReviewService::LEGAL_CODES[$a['legal_status']] ?? null, 'locality_label' => $a['locality_label'],
        ];
    }

    /** Pré-remplissage déclaré dans la définition (`prefill`), identique à la tablette. */
    private function prefill(array $definition, array $a): array
    {
        $structure = $this->structure($a);
        $data = [];
        foreach ($definition['sections'] as $s) {
            foreach ($s['items'] as $item) {
                $src = $item['prefill'] ?? null;
                if (!is_string($src)) {
                    continue;
                }
                $v = str_starts_with($src, 'structure.') ? ($structure[substr($src, 10)] ?? null)
                    : ($src === 'ctx.today' ? (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey')))->format('Y-m-d') : null);
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = &$data;
                foreach (explode('.', $item['code']) as $seg) {
                    if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                        $ref[$seg] = [];
                    }
                    $ref = &$ref[$seg];
                }
                $ref = $v;
                unset($ref);
            }
        }
        return $data;
    }

    /** Métiers actifs par secteur (libellés de la liste « Métier »). */
    private function trades(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, sector_id, label FROM trades WHERE active = 1 AND alias_of_id IS NULL AND is_other = 0 ORDER BY label') as $t) {
            $out['trades.' . $t['sector_id']][] = ['code' => (int) $t['id'], 'label' => $t['label']];
        }
        return $out;
    }
}
