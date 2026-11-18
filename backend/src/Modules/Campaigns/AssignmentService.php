<?php

declare(strict_types=1);

namespace App\Modules\Campaigns;

use App\Core\Audit;
use App\Core\Database;
use App\Http\HttpException;
use App\Modules\Users\Scope;

/**
 * Affectations des structures ciblées aux agents et zones de travail (docs/phase1/02 §2.5, UC-04).
 *
 * - Une structure n'a qu'un seul agent actif par campagne (index unique sur assignments.active_key) ;
 *   une réaffectation clôt l'affectation précédente et conserve l'historique.
 * - L'appelant n'affecte que des structures de son périmètre, à un agent actif dont le périmètre
 *   (ou la zone de travail de la campagne) couvre la commune de la structure.
 * - Réaffectation refusée si une collecte a déjà été remise (soumise, en contrôle, validée) :
 *   les données reçues ne changent pas de propriétaire silencieusement.
 * - Traitement en masse tout-ou-rien : une anomalie ⇒ 422 avec la liste, aucune affectation modifiée.
 */
final class AssignmentService
{
    public const MAX_BATCH = 2000;
    private const DELIVERED = ['SOUMISE', 'EN_CONTROLE', 'VALIDEE'];
    private readonly Audit $audit;
    private readonly CampaignService $campaigns;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
        $this->campaigns = new CampaignService($db);
    }

    /**
     * Structures ciblées (périmètre de l'appelant) avec leur agent actif.
     * Filtres : unassigned (bool), agent_uuid, commune_id, department_id, q.
     * @return array{0:list<array>, 1:int}
     */
    public function list(array $actor, int $campaignId, array $f, array $page): array
    {
        $this->campaigns->find($campaignId);
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('s.commune_id');
        $where = ['cs.campaign_id = ?', $scopeSql];
        $params = [$campaignId, ...$scopeParams];
        if (($f['unassigned'] ?? false) === true) {
            $where[] = 'a.id IS NULL';
        }
        if (($v = $f['agent_uuid'] ?? null) !== null) {
            $where[] = 'u.uuid = ?';
            $params[] = $v;
        }
        if (($v = $f['commune_id'] ?? null) !== null) {
            $where[] = 's.commune_id = ?';
            $params[] = $v;
        }
        if (($v = $f['department_id'] ?? null) !== null) {
            $where[] = 'c.department_id = ?';
            $params[] = $v;
        }
        if (($v = $f['q'] ?? null) !== null) {
            $where[] = '(s.name LIKE ? OR s.official_code LIKE ?)';
            $params[] = '%' . addcslashes($v, '%_\\') . '%';
            $params[] = addcslashes($v, '%_\\') . '%';
        }
        $from = 'FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id JOIN communes c ON c.id = s.commune_id
                 JOIN departments d ON d.id = c.department_id
                 LEFT JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
                 LEFT JOIN users u ON u.id = a.agent_id
                 LEFT JOIN collections col ON col.campaign_structure_id = cs.id';
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) $from WHERE $whereSql", $params);
        $rows = $this->db->fetchAll(
            "SELECT cs.id AS campaign_structure_id, s.uuid AS structure_uuid, s.official_code, s.name, s.status AS structure_status,
                    s.commune_id, c.name AS commune, d.name AS department,
                    a.id AS assignment_id, a.assigned_at, u.uuid AS agent_uuid, u.matricule AS agent_matricule,
                    CONCAT(u.first_name, ' ', u.last_name) AS agent_name, col.status AS collection_status
               $from WHERE $whereSql ORDER BY d.name, c.name, s.name, cs.id LIMIT {$page['per_page']} OFFSET {$page['offset']}", $params);
        return [array_map(static fn(array $r) => [
            'campaign_structure_id' => (int) $r['campaign_structure_id'],
            'structure' => ['uuid' => $r['structure_uuid'], 'official_code' => $r['official_code'], 'name' => $r['name'], 'status' => $r['structure_status'],
                'commune_id' => (int) $r['commune_id'], 'commune' => $r['commune'], 'department' => $r['department']],
            'assignment' => $r['assignment_id'] === null ? null : ['id' => (int) $r['assignment_id'], 'assigned_at' => $r['assigned_at'],
                'agent' => ['uuid' => $r['agent_uuid'], 'matricule' => $r['agent_matricule'], 'name' => $r['agent_name']]],
            'collection_status' => $r['collection_status'],
        ], $rows), $total];
    }

    /**
     * Affecte un lot de structures ciblées à un agent (campaign_structure_ids et/ou structure_uuids).
     * @return array{assigned:int, reassigned:int, unchanged:int}
     */
    public function assign(array $actor, int $campaignId, array $in, string $ip): array
    {
        $this->campaigns->assertModifiable($this->campaigns->find($campaignId));
        [$agentId, $agentScope] = $this->agent($actor, $in['agent_uuid'] ?? null);
        $areas = array_flip(array_map('intval', array_column(
            $this->db->fetchAll('SELECT commune_id FROM agent_areas WHERE campaign_id = ? AND agent_id = ?', [$campaignId, $agentId]), 'commune_id')));

        $csIds = $in['campaign_structure_ids'] ?? [];
        $uuids = $in['structure_uuids'] ?? [];
        if (!is_array($csIds) || !is_array($uuids) || ($csIds === [] && $uuids === []) || count($csIds) + count($uuids) > self::MAX_BATCH) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Fournir campaign_structure_ids ou structure_uuids (' . self::MAX_BATCH . ' au plus).');
        }
        $csIds = array_values(array_unique(array_map('intval', array_filter($csIds, static fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))))));
        $uuids = array_values(array_unique(array_filter($uuids, 'is_string')));

        $found = [];
        foreach ([['cs.id', $csIds], ['s.uuid', $uuids]] as [$col, $values]) {
            if ($values !== []) {
                foreach ($this->db->fetchAll($this->targetSql() . " AND $col IN (" . implode(',', array_fill(0, count($values), '?')) . ')',
                    [$campaignId, ...$values]) as $r) {
                    $found[(int) $r['cs_id']] = $r;
                }
            }
        }
        $errors = [];
        foreach ($csIds as $id) {
            if (!isset($found[$id])) {
                $errors[] = "Structure ciblée n° $id introuvable dans cette campagne.";
            }
        }
        $foundUuids = array_column($found, 'uuid');
        foreach ($uuids as $u) {
            if (!in_array($u, $foundUuids, true)) {
                $errors[] = "Structure $u non ciblée par cette campagne.";
            }
        }
        foreach ($found as $r) {
            $commune = (int) $r['commune_id'];
            $label = "« {$r['name']} »";
            if (!$actor['scope']->coversCommune($commune)) {
                $errors[] = "$label est hors de votre périmètre.";
            } elseif (!$agentScope->coversCommune($commune) && !isset($areas[$commune])) {
                $errors[] = "$label ({$r['commune']}) est hors du périmètre et de la zone de travail de l'agent.";
            } elseif ($r['structure_status'] !== 'ACTIVE') {
                $errors[] = "$label n'est plus active (statut {$r['structure_status']}).";
            } elseif ($r['agent_id'] !== null && (int) $r['agent_id'] !== $agentId && in_array($r['collection_status'], self::DELIVERED, true)) {
                $errors[] = "$label a déjà une collecte remise ({$r['collection_status']}) : réaffectation impossible.";
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Affectation refusée : aucune modification effectuée.', ['errors' => array_slice($errors, 0, 200)]);
        }

        try {
            return $this->db->transaction(function (Database $db) use ($found, $agentId, $actor, $campaignId, $ip): array {
                $result = ['assigned' => 0, 'reassigned' => 0, 'unchanged' => 0];
                foreach ($found as $csId => $r) {
                    if ($r['agent_id'] !== null && (int) $r['agent_id'] === $agentId) {
                        $result['unchanged']++;
                        continue;
                    }
                    if ($r['assignment_id'] !== null) {
                        $db->execute("UPDATE assignments SET ended_at = NOW(), ended_by = ?, end_reason = 'Réaffectation' WHERE id = ? AND ended_at IS NULL",
                            [$actor['id'], $r['assignment_id']]);
                        $result['reassigned']++;
                    } else {
                        $result['assigned']++;
                    }
                    $db->execute('INSERT INTO assignments (campaign_structure_id, agent_id, assigned_by) VALUES (?, ?, ?)', [$csId, $agentId, $actor['id']]);
                }
                $this->audit->log($actor['id'], 'ASSIGNMENT_BATCH', 'campaign', (string) $campaignId, null, ['agent_id' => $agentId] + $result, null, $ip);
                return $result;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'CONFLICT', "Une affectation concurrente vient d'être enregistrée. Recharger la liste et recommencer.");
            }
            throw $e;
        }
    }

    /** Clôture d'une affectation (la structure redevient non affectée ; l'historique est conservé). */
    public function end(array $actor, int $assignmentId, ?string $reason, string $ip): void
    {
        $a = $this->db->fetchOne(
            'SELECT a.id, a.ended_at, cs.campaign_id, s.commune_id, col.status AS collection_status FROM assignments a
               JOIN campaign_structures cs ON cs.id = a.campaign_structure_id JOIN structures s ON s.id = cs.structure_id
               LEFT JOIN collections col ON col.campaign_structure_id = cs.id WHERE a.id = ?', [$assignmentId]);
        if ($a === null || !$actor['scope']->coversCommune((int) $a['commune_id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Affectation introuvable.');
        }
        $this->campaigns->assertModifiable($this->campaigns->find((int) $a['campaign_id']));
        if ($a['ended_at'] !== null) {
            return;
        }
        if (in_array($a['collection_status'], self::DELIVERED, true)) {
            throw new HttpException(409, 'CONFLICT', "Une collecte a déjà été remise pour cette structure : l'affectation ne peut pas être retirée.");
        }
        $this->db->transaction(function (Database $db) use ($a, $actor, $reason, $ip): void {
            $db->execute('UPDATE assignments SET ended_at = NOW(), ended_by = ?, end_reason = ? WHERE id = ?',
                [$actor['id'], $reason === null ? 'Retrait' : mb_substr($reason, 0, 255), $a['id']]);
            $this->audit->log($actor['id'], 'ASSIGNMENT_END', 'assignment', (string) $a['id'], null, null, $reason, $ip);
        });
    }

    /** Remplace les zones de travail (communes) d'un agent pour une campagne, dans le périmètre de l'appelant. */
    public function setAreas(array $actor, int $campaignId, string $agentUuid, mixed $communeIds, string $ip): array
    {
        $this->campaigns->assertModifiable($this->campaigns->find($campaignId));
        [$agentId] = $this->agent($actor, $agentUuid);
        if (!is_array($communeIds) || !array_is_list($communeIds) || count($communeIds) > 300) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'commune_ids : liste de 300 communes au plus.');
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($communeIds, static fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))))));
        $errors = [];
        foreach ($ids as $cid) {
            if ($this->db->fetchValue('SELECT id FROM communes WHERE id = ? AND valid_to IS NULL', [$cid]) === null) {
                $errors[] = "Commune n° $cid introuvable.";
            } elseif (!$actor['scope']->coversCommune($cid)) {
                $errors[] = "Commune n° $cid hors de votre périmètre.";
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Zones refusées.', ['errors' => $errors]);
        }
        $this->db->transaction(function (Database $db) use ($campaignId, $agentId, $ids, $actor, $ip): void {
            // Seules les zones du périmètre de l'appelant sont remplacées ; celles gérées par d'autres superviseurs sont conservées.
            foreach ($db->fetchAll('SELECT id, commune_id FROM agent_areas WHERE campaign_id = ? AND agent_id = ?', [$campaignId, $agentId]) as $a) {
                if ($actor['scope']->coversCommune((int) $a['commune_id']) && !in_array((int) $a['commune_id'], $ids, true)) {
                    $db->execute('DELETE FROM agent_areas WHERE id = ?', [$a['id']]);
                }
            }
            foreach ($ids as $cid) {
                $db->execute('INSERT IGNORE INTO agent_areas (campaign_id, agent_id, commune_id, assigned_by) VALUES (?, ?, ?, ?)',
                    [$campaignId, $agentId, $cid, $actor['id']]);
            }
            $this->audit->log($actor['id'], 'AGENT_AREAS_SET', 'campaign', (string) $campaignId, null, ['agent_id' => $agentId, 'communes' => $ids], null, $ip);
        });
        return $this->db->fetchAll('SELECT a.commune_id, c.name AS commune FROM agent_areas a JOIN communes c ON c.id = a.commune_id
                                     WHERE a.campaign_id = ? AND a.agent_id = ? ORDER BY c.name', [$campaignId, $agentId]);
    }

    // ===================================================================== Interne

    private function targetSql(): string
    {
        return 'SELECT cs.id AS cs_id, s.uuid, s.name, s.status AS structure_status, s.commune_id, c.name AS commune,
                       a.id AS assignment_id, a.agent_id, col.status AS collection_status
                  FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id JOIN communes c ON c.id = s.commune_id
                  LEFT JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
                  LEFT JOIN collections col ON col.campaign_structure_id = cs.id
                 WHERE cs.campaign_id = ?';
    }

    /**
     * Agent visible de l'appelant, actif, de rôle AGENT.
     * @return array{0:int, 1:Scope}
     */
    private function agent(array $actor, mixed $uuid): array
    {
        $u = is_string($uuid) ? $this->db->fetchOne(
            "SELECT u.id, u.status FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.uuid = ? AND r.code = 'AGENT' AND u.deleted_at IS NULL", [$uuid]) : null;
        $scope = $u === null ? null : Scope::forUser($this->db, (int) $u['id']);
        if ($u === null || !$actor['scope']->contains($scope)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Agent introuvable dans votre périmètre.',
                ['errors' => [['field' => 'agent_uuid', 'message' => 'Agent introuvable.']]]);
        }
        if ($u['status'] !== 'ACTIF') {
            throw new HttpException(422, 'VALIDATION_FAILED', "L'agent est désactivé.", ['errors' => [['field' => 'agent_uuid', 'message' => 'Agent désactivé.']]]);
        }
        return [(int) $u['id'], $scope];
    }
}
