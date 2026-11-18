<?php

declare(strict_types=1);

namespace App\Modules\Collections;

use App\Core\Database;
use App\Http\HttpException;
use App\Modules\Structures\StructureService;

/**
 * Centre découvert sur le terrain par l'agent : la structure est proposée (statut PROPOSEE, codification et décision
 * du superviseur plus tard), inscrite dans la campagne et affectée à l'agent, qui peut alors soumettre sa collecte.
 *
 * Idempotent par l'UUID de la structure (généré sur la tablette, hors ligne) : un renvoi après perte de réponse
 * renvoie la même inscription. Les doublons possibles (nom proche ou point GPS à moins de 100 m dans la commune)
 * sont signalés sans bloquer : le superviseur fusionne si nécessaire.
 */
final class FieldStructureService
{
    public function __construct(private readonly Database $db, private readonly StructureService $structures)
    {
    }

    public function register(array $actor, int $campaignId, array $in, string $ip): array
    {
        $campaign = $this->db->fetchOne('SELECT id, status FROM campaigns WHERE id = ?', [$campaignId])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
        if ($campaign['status'] !== 'OUVERTE') {
            throw new HttpException(423, 'CAMPAIGN_NOT_OPEN', "La campagne n'accepte pas de nouveau centre (état : {$campaign['status']}).");
        }

        // Proposition de la structure (contrôles, zone de travail de l'agent, doublons, idempotence par UUID).
        $proposal = $this->structures->propose($actor, $in, $ip);
        $s = $proposal['structure'];
        $structureId = (int) $this->db->fetchValue('SELECT id FROM structures WHERE uuid = ?', [$s['uuid']]);

        $csId = $this->db->transaction(function (Database $db) use ($campaignId, $structureId, $actor): int {
            $cs = $db->fetchValue('SELECT id FROM campaign_structures WHERE campaign_id = ? AND structure_id = ? FOR UPDATE', [$campaignId, $structureId]);
            if ($cs === null) {
                $cs = $db->insert('INSERT INTO campaign_structures (campaign_id, structure_id, added_by) VALUES (?, ?, ?)', [$campaignId, $structureId, $actor['id']]);
            }
            $assignee = $db->fetchValue('SELECT agent_id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL FOR UPDATE', [$cs]);
            if ($assignee === null) {
                $db->execute('INSERT INTO assignments (campaign_structure_id, agent_id, assigned_by) VALUES (?, ?, ?)', [$cs, $actor['id'], $actor['id']]);
            } elseif ((int) $assignee !== $actor['id']) {
                throw new HttpException(409, 'ASSIGNED_TO_OTHER', 'Ce centre est déjà affecté à un autre agent pour cette campagne.');
            }
            return (int) $cs;
        });

        return [
            'campaign_id' => $campaignId,
            'campaign_structure_id' => $csId,
            'replayed' => $proposal['replayed'],
            'possible_duplicates' => $proposal['possible_duplicates'],
            // Identité codée comme dans le questionnaire (même format que /sync/pull).
            'structure' => [
                'uuid' => $s['uuid'], 'official_code' => $s['official_code'], 'name' => $s['name'], 'status' => $s['status'],
                'region_id' => $s['region_id'], 'department_id' => $s['department_id'], 'commune_id' => $s['commune_id'],
                'type_code' => $s['type_code'], 'zone' => ReviewService::ZONE_CODES[$s['zone']] ?? null,
                'legal_status' => ReviewService::LEGAL_CODES[$s['legal_status']] ?? null, 'locality_label' => $s['locality_label'],
                'latitude' => $s['latitude'], 'longitude' => $s['longitude'],
            ],
        ];
    }
}
