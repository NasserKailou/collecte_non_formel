<?php

declare(strict_types=1);

namespace App\Modules\Stats;

use App\Core\Database;

/**
 * Points de la carte des centres pour une campagne, dans le périmètre de l'utilisateur.
 *
 * - Centres collectés : position relevée à la collecte (GPS de la tablette, ou coordonnées reportées d'une fiche papier).
 * - Centres ciblés non encore collectés : position du registre si elle est connue.
 * Les centres sans aucune coordonnée sont comptés (pour ne pas les faire disparaître de l'analyse) mais non placés.
 */
final class MapService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function points(array $actor, int $campaignId): array
    {
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('s.commune_id');
        $rows = $this->db->fetchAll(
            "SELECT s.uuid, s.official_code, s.name, s.legal_status, s.zone, t.code AS type_code, t.label AS type_label,
                    s.latitude AS reg_lat, s.longitude AS reg_lng, r.name AS region, d.name AS department, co.name AS commune, s.locality_label,
                    c.uuid AS collection_uuid, c.status, c.channel, c.latitude, c.longitude, c.gps_status, c.gps_accuracy_m, c.first_submitted_at,
                    (SELECT a.value_number FROM collection_answers a WHERE a.collection_id = c.id AND a.question_code = 'APPRENTICES_TOTAL' LIMIT 1) AS apprentices,
                    (SELECT COUNT(*) FROM collection_trainers tr WHERE tr.collection_id = c.id) AS trainers
               FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id JOIN structure_types t ON t.id = s.structure_type_id
               JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id JOIN regions r ON r.id = d.region_id
               LEFT JOIN collections c ON c.campaign_structure_id = cs.id
              WHERE cs.campaign_id = ? AND $scopeSql", [$campaignId, ...$scopeParams]);

        $points = [];
        $unplaced = ['collected' => 0, 'not_collected' => 0];
        foreach ($rows as $r) {
            $collected = $r['collection_uuid'] !== null;
            [$lat, $lng, $source] = $collected && $r['latitude'] !== null
                ? [(float) $r['latitude'], (float) $r['longitude'], $r['gps_status'] === 'OK' ? 'GPS' : 'SAISIE']
                : ($r['reg_lat'] !== null ? [(float) $r['reg_lat'], (float) $r['reg_lng'], 'REGISTRE'] : [null, null, null]);
            if ($lat === null) {
                $unplaced[$collected ? 'collected' : 'not_collected']++;
                continue;
            }
            $points[] = [
                'lat' => $lat, 'lng' => $lng, 'source' => $source,
                'name' => $r['name'], 'code' => $r['official_code'], 'legal' => $r['legal_status'] ?? 'INCONNU', 'zone' => $r['zone'],
                'type' => $r['type_code'], 'type_label' => $r['type_label'],
                'region' => $r['region'], 'department' => $r['department'], 'commune' => $r['commune'], 'locality' => $r['locality_label'],
                'status' => $collected ? $r['status'] : 'NON_COLLECTEE', 'channel' => $r['channel'],
                'accuracy_m' => $r['gps_accuracy_m'] === null ? null : (float) $r['gps_accuracy_m'],
                'apprentices' => $r['apprentices'] === null ? null : (int) $r['apprentices'], 'trainers' => $collected ? (int) $r['trainers'] : null,
                'collection_uuid' => $r['collection_uuid'], 'collected_at' => $r['first_submitted_at'],
            ];
        }
        $types = $this->db->fetchAll('SELECT code, label FROM structure_types WHERE active = 1 ORDER BY sort');
        return ['points' => $points, 'unplaced' => $unplaced, 'total' => count($rows), 'types' => $types];
    }
}
