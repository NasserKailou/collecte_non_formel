<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Database;
use App\Http\HttpException;

/**
 * Téléchargement incrémental pour la tablette (docs/api/README.md §3.1, UC-05).
 *
 * Renvoie à l'agent : ses campagnes, ses affectations actives (avec l'identité de la structure,
 * codée comme dans le questionnaire), les affectations retirées, et l'état de ses collectes
 * (avec le retour du superviseur et, pour une collecte à corriger, les données de la dernière révision).
 *
 * Curseur : horodatage serveur au début de la requête ; la comparaison est inclusive (>=) pour ne rien
 * manquer dans la même seconde. Un élément peut donc être renvoyé deux fois : le mobile l'applique de
 * façon idempotente (remplacement par clé).
 */
final class SyncService
{
    private const NEEDS_CORRECTION = ['INVALIDEE', 'REOUVERTE'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array{id:int, device_id:?int} $user */
    public function pull(array $user, ?string $since, ?int $campaignId): array
    {
        $t0 = microtime(true);
        $sinceDb = $this->parseCursor($since);
        $now = (string) $this->db->fetchValue('SELECT NOW()');

        $campaignFilter = $campaignId !== null ? ' AND c.id = ?' : '';
        // Campagnes de l'agent : toute campagne ouverte (ou suspendue) — l'agent y recense de nouveaux centres dans
        // son périmètre sans attendre d'affectation (l'affectation ne sert qu'aux centres déjà connus du registre) —,
        // plus les campagnes clôturées où il a des affectations ou une zone de travail (corrections, historique).
        $campaigns = $this->db->fetchAll(
            "SELECT c.id, c.code, c.name, c.status, c.opens_on, c.closes_on, c.validation_levels, c.collection_mode, c.instructions, v.version_code AS questionnaire_version
               FROM campaigns c JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id
              WHERE c.status <> 'ARCHIVEE' $campaignFilter AND (
                    c.status IN ('OUVERTE', 'SUSPENDUE')
                 OR EXISTS (SELECT 1 FROM campaign_structures cs JOIN assignments a ON a.campaign_structure_id = cs.id
                             WHERE cs.campaign_id = c.id AND a.agent_id = ?)
                 OR EXISTS (SELECT 1 FROM agent_areas aa WHERE aa.campaign_id = c.id AND aa.agent_id = ?))
              ORDER BY c.opens_on DESC", [...($campaignId !== null ? [$campaignId] : []), $user['id'], $user['id']]);
        $in = $campaigns === [] ? '0' : implode(',', array_map(static fn($c) => (int) $c['id'], $campaigns));

        $changed = $sinceDb === null ? '' : ' AND (a.assigned_at >= ? OR s.updated_at >= ? OR col.updated_at >= ?)';
        $assignments = $this->db->fetchAll(
            "SELECT cs.id AS campaign_structure_id, cs.campaign_id, s.uuid, s.official_code, s.name, s.commune_id, co.department_id, d.region_id,
                    t.code AS type_code, s.zone, s.legal_status, s.locality_label, s.latitude, s.longitude, s.status AS structure_status,
                    col.uuid AS collection_uuid
               FROM assignments a JOIN campaign_structures cs ON cs.id = a.campaign_structure_id JOIN structures s ON s.id = cs.structure_id
               JOIN structure_types t ON t.id = s.structure_type_id JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id
               LEFT JOIN collections col ON col.campaign_structure_id = cs.id
              WHERE a.agent_id = ? AND a.ended_at IS NULL AND cs.campaign_id IN ($in) $changed
              ORDER BY cs.id", $sinceDb === null ? [$user['id']] : [$user['id'], $sinceDb, $sinceDb, $sinceDb]);

        $removed = [];
        if ($sinceDb !== null) {
            $removed = array_map('intval', array_column($this->db->fetchAll(
                'SELECT DISTINCT a.campaign_structure_id FROM assignments a
                  WHERE a.agent_id = ? AND a.ended_at >= ?
                    AND NOT EXISTS (SELECT 1 FROM assignments b WHERE b.campaign_structure_id = a.campaign_structure_id AND b.agent_id = a.agent_id AND b.ended_at IS NULL)',
                [$user['id'], $sinceDb]), 'campaign_structure_id'));
        }

        $colChanged = $sinceDb === null ? '' : ' AND col.updated_at >= ?';
        $collections = $this->db->fetchAll(
            "SELECT col.id, col.uuid, col.campaign_structure_id, col.campaign_id, col.status, col.review_level, col.revision, col.updated_at
               FROM collections col
              WHERE col.campaign_id IN ($in) $colChanged
                AND (col.agent_id = ? OR EXISTS (SELECT 1 FROM assignments a WHERE a.campaign_structure_id = col.campaign_structure_id AND a.agent_id = ? AND a.ended_at IS NULL))
              ORDER BY col.id", $sinceDb === null ? [$user['id'], $user['id']] : [$sinceDb, $user['id'], $user['id']]);

        $out = [
            'cursor' => (new \DateTimeImmutable($now, new \DateTimeZone('Africa/Niamey')))->format('Y-m-d\TH:i:sP'),
            'full' => $sinceDb === null,
            'campaigns' => array_map(static fn($c) => ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'status' => $c['status'],
                'accepts_submissions' => $c['status'] === 'OUVERTE', 'opens_on' => $c['opens_on'], 'closes_on' => $c['closes_on'],
                'validation_levels' => (int) $c['validation_levels'], 'collection_mode' => $c['collection_mode'], 'instructions' => $c['instructions'], 'questionnaire_version' => $c['questionnaire_version']], $campaigns),
            'assignments' => array_map(static fn($a) => [
                'campaign_structure_id' => (int) $a['campaign_structure_id'], 'campaign_id' => (int) $a['campaign_id'], 'active' => true,
                'collection_uuid' => $a['collection_uuid'],
                'structure' => [
                    'uuid' => $a['uuid'], 'official_code' => $a['official_code'], 'name' => $a['name'], 'status' => $a['structure_status'],
                    'region_id' => (int) $a['region_id'], 'department_id' => (int) $a['department_id'], 'commune_id' => (int) $a['commune_id'],
                    'type_code' => $a['type_code'], 'zone' => ReviewService::ZONE_CODES[$a['zone']] ?? null,
                    'legal_status' => ReviewService::LEGAL_CODES[$a['legal_status']] ?? null, 'locality_label' => $a['locality_label'],
                    'latitude' => $a['latitude'] === null ? null : (float) $a['latitude'], 'longitude' => $a['longitude'] === null ? null : (float) $a['longitude'],
                ],
            ], $assignments),
            'removed_assignments' => $removed,
            // Contexte de contrôle partagé (seuils, métiers admis) : la tablette applique les mêmes règles hors ligne.
            'context' => (new EvaluationContext($this->db))->shared(),
            'collections' => array_map(fn($c) => $this->presentCollection($c), $collections),
        ];
        try {
            $this->db->execute("INSERT INTO sync_logs (user_id, device_id, direction, endpoint, http_status, outcome, detail, duration_ms)
                                VALUES (?, ?, 'PULL', 'GET /sync/pull', 200, 'OK', ?, ?)",
                [$user['id'], $user['device_id'], sprintf('%d affectation(s), %d retrait(s), %d collecte(s)', count($assignments), count($removed), count($collections)),
                    (int) round((microtime(true) - $t0) * 1000)]);
        } catch (\Throwable) {
            // journal non bloquant
        }
        return $out;
    }

    private function presentCollection(array $c): array
    {
        $review = $this->db->fetchOne(
            'SELECT r.decision, r.level, rr.code AS reason_code, rr.label AS reason, r.comment, r.created_at
               FROM collection_reviews r LEFT JOIN rejection_reasons rr ON rr.id = r.reason_id WHERE r.collection_id = ? ORDER BY r.id DESC LIMIT 1', [$c['id']]);
        $out = [
            'uuid' => $c['uuid'], 'campaign_structure_id' => (int) $c['campaign_structure_id'], 'campaign_id' => (int) $c['campaign_id'],
            'status' => $c['status'], 'review_level' => (int) $c['review_level'], 'revision' => (int) $c['revision'],
            'editable' => in_array($c['status'], self::NEEDS_CORRECTION, true), 'updated_at' => $c['updated_at'],
            'review' => $review === null ? null : ['decision' => $review['decision'], 'level' => (int) $review['level'], 'reason_code' => $review['reason_code'],
                'reason' => $review['reason'], 'comment' => $review['comment'], 'at' => $review['created_at']],
        ];
        if ($out['editable']) {
            // Données de la dernière révision : correction possible, y compris sur une autre tablette ou par un agent réaffecté.
            $payload = $this->db->fetchValue('SELECT payload_json FROM collection_submissions WHERE collection_id = ? AND revision = ?', [$c['id'], $c['revision']]);
            $out['data'] = json_decode((string) $payload, true)['data'] ?? null;
        }
        return $out;
    }

    private function parseCursor(?string $since): ?string
    {
        if ($since === null || $since === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $since) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Curseur de synchronisation invalide : reprendre une synchronisation complète.');
        }
        return (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('Africa/Niamey'))->format('Y-m-d H:i:s');
    }
}
