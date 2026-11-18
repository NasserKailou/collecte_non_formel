<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\HttpException;
use App\Http\Response;
use App\Modules\Campaigns\CampaignService;

/**
 * Années, campagnes, transitions d'état, ciblage, reconduction, affectations (lot L3).
 * Critères de recette du lot : la clôture bloque les soumissions ; unicité des affectations.
 */
final class CampaignsApiTest extends ApiTestCase
{
    private static string $adminToken;
    private static int $yearSeq = 2100;
    private static int $code = 2000000000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    // ------------------------------------------------------------------ Outils

    private function year(): int
    {
        $y = ++self::$yearSeq;
        $r = $this->call('POST', '/academic-years', ['code' => "$y-" . ($y + 1), 'start_date' => "$y-10-01", 'end_date' => ($y + 1) . '-09-30'], self::$adminToken);
        $this->assertSame(201, $r->status, json_encode($r->body));
        return $r->body['data']['id'];
    }

    private function campaign(array $over = []): array
    {
        $r = $this->call('POST', '/campaigns', $over + [
            'academic_year_id' => $this->year(), 'code' => 'C-' . bin2hex(random_bytes(3)), 'name' => 'Recensement test',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+60 days')),
        ], self::$adminToken);
        $this->assertSame(201, $r->status, json_encode($r->body));
        return $r->body['data'];
    }

    private function structure(string $communeCode = '10101'): string
    {
        $r = $this->call('POST', '/structures', ['official_code' => (string) (++self::$code), 'name' => 'Structure ' . bin2hex(random_bytes(4)),
            'type_code' => 'c', 'commune_id' => self::communeId($communeCode)], self::$adminToken);
        $this->assertSame(201, $r->status, json_encode($r->body));
        return $r->body['data']['uuid'];
    }

    private function transition(int $id, string $to, ?string $reason = null): Response
    {
        return $this->call('POST', "/campaigns/$id/transition", ['to' => $to] + ($reason !== null ? ['reason' => $reason] : []), self::$adminToken);
    }

    private function openCampaign(array $structureUuids): array
    {
        $c = $this->campaign();
        $this->call('POST', "/campaigns/{$c['id']}/structures", ['structure_uuids' => $structureUuids], self::$adminToken);
        $r = $this->transition($c['id'], 'OUVERTE');
        $this->assertSame(200, $r->status, json_encode($r->body));
        return $c;
    }

    private function csId(int $campaignId, string $structureUuid): int
    {
        return (int) self::$db->fetchValue('SELECT cs.id FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id WHERE cs.campaign_id = ? AND s.uuid = ?',
            [$campaignId, $structureUuid]);
    }

    private function guardCode(CampaignService $s, int $id): string
    {
        try {
            $s->assertAcceptsSubmissions($id);
            return 'OK';
        } catch (HttpException $e) {
            $this->assertSame(423, $e->status);
            return $e->errorCode;
        }
    }

    // ------------------------------------------------------------------ Années

    public function testAcademicYearValidationAndLifecycle(): void
    {
        $this->assertSame(422, $this->call('POST', '/academic-years', ['code' => '2030-2032', 'start_date' => '2030-10-01', 'end_date' => '2031-09-30'], self::$adminToken)->status);
        $this->assertSame(422, $this->call('POST', '/academic-years', ['code' => '2030-2031', 'start_date' => '2031-10-01', 'end_date' => '2030-09-30'], self::$adminToken)->status);
        $id = $this->year();
        $dup = self::$yearSeq . '-' . (self::$yearSeq + 1);
        $this->assertSame(422, $this->call('POST', '/academic-years', ['code' => $dup, 'start_date' => '2030-10-01', 'end_date' => '2031-09-30'], self::$adminToken)->status, 'Code en double');

        $c = $this->campaign(['academic_year_id' => $id]);
        $this->assertSame('ACTIVE', $this->call('PATCH', "/academic-years/$id", ['status' => 'ACTIVE'], self::$adminToken)->body['data']['status']);
        $this->assertSame(409, $this->call('PATCH', "/academic-years/$id", ['status' => 'CLOTUREE'], self::$adminToken)->status,
            "Une campagne non clôturée bloque la clôture de l'année");
        self::$db->execute("UPDATE campaigns SET status = 'CLOTUREE' WHERE id = ?", [$c['id']]);
        $this->assertSame('CLOTUREE', $this->call('PATCH', "/academic-years/$id", ['status' => 'CLOTUREE'], self::$adminToken)->body['data']['status']);
        $this->assertSame(423, $this->call('PATCH', "/academic-years/$id", ['label' => 'X'], self::$adminToken)->status);
        $this->assertSame(422, $this->call('POST', '/campaigns', ['academic_year_id' => $id, 'code' => 'APRES-CLOTURE', 'name' => 'Test',
            'questionnaire_version' => '2025.1', 'opens_on' => '2030-01-01', 'closes_on' => '2030-02-01'], self::$adminToken)->status);
    }

    // ------------------------------------------------------------------ Campagnes et transitions

    public function testCampaignCreationValidation(): void
    {
        $r = $this->call('POST', '/campaigns', ['code' => 'x', 'name' => '', 'academic_year_id' => 999, 'questionnaire_version' => '1999.9',
            'opens_on' => '2030-02-01', 'closes_on' => '2030-01-01', 'validation_levels' => 3], self::$adminToken);
        $this->assertSame(422, $r->status);
        $fields = array_column($r->body['error']['details']['errors'], 'field');
        foreach (['code', 'name', 'academic_year_id', 'questionnaire_version', 'closes_on', 'validation_levels'] as $f) {
            $this->assertContains($f, $fields);
        }
        $c = $this->campaign(['validation_levels' => 1]);
        $this->assertSame('PREPARATION', $c['status']);
        $this->assertSame(1, $c['validation_levels']);
        $this->assertSame(['OUVERTE'], $c['allowed_transitions']);
        $this->assertSame(422, $this->call('POST', '/campaigns', ['code' => $c['code'], 'academic_year_id' => $c['academic_year_id'], 'name' => 'Doublon',
            'questionnaire_version' => '2025.1', 'opens_on' => '2030-01-01', 'closes_on' => '2030-02-01'], self::$adminToken)->status);
        $sup = $this->tokenFor($this->createUser('SUP_REG'));
        $this->assertSame(403, $this->call('POST', '/campaigns', ['code' => 'NON'], $sup)->status, "Réservé à l'administrateur.");
        $this->assertSame(200, $this->call('GET', '/campaigns', token: $sup)->status, 'La liste est consultable par tout utilisateur connecté.');
    }

    public function testOpeningRequiresAValidEndDateButNoPreTargetedStructures(): void
    {
        // Recensement de terrain : une campagne sans liste préalable de centres peut s'ouvrir (les agents les découvrent).
        $empty = $this->campaign();
        $this->assertSame(200, $this->transition($empty['id'], 'OUVERTE')->status);

        $c = $this->campaign();
        $this->call('POST', "/campaigns/{$c['id']}/structures", ['structure_uuids' => [$this->structure()]], self::$adminToken);
        self::$db->execute('UPDATE campaigns SET opens_on = ?, closes_on = ? WHERE id = ?', [date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day')), $c['id']]);
        $this->assertStringContainsString('date de fin', implode(' ', $this->transition($c['id'], 'OUVERTE')->body['error']['details']['problems']));

        $this->call('PATCH', "/campaigns/{$c['id']}", ['closes_on' => date('Y-m-d', strtotime('+10 days'))], self::$adminToken);
        $open = $this->transition($c['id'], 'OUVERTE');
        $this->assertSame(200, $open->status, json_encode($open->body));
        $this->assertTrue($open->body['data']['accepts_submissions']);
        $this->assertSame(['PREPARATION', 'OUVERTE'], array_column($open->body['data']['status_history'], 'to_status'));
    }

    public function testStateMachineAndClosureBlocksSubmissions(): void
    {
        $service = new CampaignService(self::$db);
        $id = $this->openCampaign([$this->structure()])['id'];
        $this->assertSame('OK', $this->guardCode($service, $id));

        $this->assertSame(409, $this->transition($id, 'PREPARATION')->status, 'Retour en préparation interdit');
        $this->assertSame(422, $this->transition($id, 'SUSPENDUE')->status, 'Motif obligatoire');
        $this->assertSame('SUSPENDUE', $this->transition($id, 'SUSPENDUE', 'Fête nationale')->body['data']['status']);
        $this->assertSame('CAMPAIGN_SUSPENDED', $this->guardCode($service, $id));
        $this->assertSame('OUVERTE', $this->transition($id, 'OUVERTE')->body['data']['status']);
        $this->assertSame('CLOTUREE', $this->transition($id, 'CLOTUREE', 'Fin de la collecte')->body['data']['status']);

        // Critère de recette : après clôture, aucune soumission n'est acceptée, et la campagne est en lecture seule.
        $this->assertSame('CAMPAIGN_CLOSED', $this->guardCode($service, $id));
        $this->assertSame(409, $this->transition($id, 'OUVERTE')->status, 'Une campagne clôturée ne se rouvre pas');
        $this->assertSame(423, $this->call('PATCH', "/campaigns/$id", ['name' => 'Modifiée'], self::$adminToken)->status);
        $this->assertSame(423, $this->call('POST', "/campaigns/$id/structures", ['structure_uuids' => [$this->structure()]], self::$adminToken)->status);
        $this->assertSame('ARCHIVEE', $this->transition($id, 'ARCHIVEE', 'Publication des résultats')->body['data']['status']);
        $this->assertSame('CAMPAIGN_CLOSED', $this->guardCode($service, $id));

        $this->assertSame('CAMPAIGN_NOT_OPEN', $this->guardCode($service, $this->campaign()['id']));
        $this->assertSame(5, (int) self::$db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'CAMPAIGN_TRANSITION' AND entity_id = ?", [(string) $id]));
    }

    public function testQuestionnaireVersionOnlyMovesForwardOnceCollectionsArrive(): void
    {
        $c = $this->openCampaign([$this->structure()]);
        $this->assertSame(409, $this->call('PATCH', "/campaigns/{$c['id']}", ['validation_levels' => 1], self::$adminToken)->status);
        $this->assertSame(200, $this->call('PATCH', "/campaigns/{$c['id']}", ['questionnaire_version' => '2025.1'], self::$adminToken)->status,
            'Campagne ouverte sans aucune collecte : la version reste modifiable (tablettes mises à jour au pull).');
        self::$db->execute(
            "INSERT INTO collections (uuid, campaign_structure_id, campaign_id, structure_id, questionnaire_version_id, agent_id, region_id, department_id, commune_id, payload_sha256, status)
             SELECT UUID(), cs.id, cs.campaign_id, cs.structure_id, c.questionnaire_version_id, (SELECT MIN(id) FROM users), d.region_id, co.department_id, s.commune_id, REPEAT('0', 64), 'SOUMISE'
               FROM campaign_structures cs JOIN campaigns c ON c.id = cs.campaign_id JOIN structures s ON s.id = cs.structure_id
               JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id WHERE cs.campaign_id = ? LIMIT 1", [$c['id']]);
        $this->assertSame(200, $this->call('PATCH', "/campaigns/{$c['id']}", ['questionnaire_version' => '2025.3'], self::$adminToken)->status,
            'Avec des collectes reçues : montée vers une version plus récente admise (chaque collecte garde la sienne).');
        $this->assertSame('2025.1', self::$db->fetchValue(
            'SELECT v.version_code FROM collections x JOIN questionnaire_versions v ON v.id = x.questionnaire_version_id WHERE x.campaign_id = ?', [$c['id']]));
        $this->assertSame(409, $this->call('PATCH', "/campaigns/{$c['id']}", ['questionnaire_version' => '2025.1'], self::$adminToken)->status,
            'Retour à une version plus ancienne refusé.');
        $this->assertSame(200, $this->call('PATCH', "/campaigns/{$c['id']}", ['closes_on' => date('Y-m-d', strtotime('+90 days'))], self::$adminToken)->status,
            'La date de fin reste modifiable (prolongation).');
    }

    // ------------------------------------------------------------------ Ciblage

    public function testTargetingByListAndByTerritoryIsIdempotent(): void
    {
        $c = $this->campaign();
        $a = $this->structure('20201');
        $b = $this->structure('20202');
        $closed = $this->structure('20201');
        $this->call('POST', "/structures/$closed/close", ['reason' => 'Fermée'], self::$adminToken);

        $r = $this->call('POST', "/campaigns/{$c['id']}/structures", ['structure_uuids' => [$a, $closed, '00000000-0000-4000-8000-000000000000']], self::$adminToken);
        $this->assertSame(1, $r->body['data']['added']);
        $this->assertCount(2, $r->body['data']['ignored']);

        $r = $this->call('POST', "/campaigns/{$c['id']}/structures", ['filter' => ['department_id' => self::departmentId('202')]], self::$adminToken);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $this->assertGreaterThanOrEqual(1, $r->body['data']['added']);
        $this->assertSame(1, $r->body['data']['already_targeted'], "La structure déjà ciblée n'est pas dupliquée.");
        $this->assertSame(0, $this->csId($c['id'], $closed), "Une structure fermée n'est pas ciblée.");
        $this->assertGreaterThan(0, $this->csId($c['id'], $b));

        $this->assertSame(204, $this->call('DELETE', "/campaigns/{$c['id']}/structures/$b", token: self::$adminToken)->status);
        $this->assertSame(0, $this->csId($c['id'], $b));
        $this->assertSame(404, $this->call('DELETE', "/campaigns/{$c['id']}/structures/$b", token: self::$adminToken)->status);
    }

    // ------------------------------------------------------------------ Affectations

    public function testOneActiveAgentPerStructureWithReassignmentHistory(): void
    {
        $s1 = $this->structure('10101');
        $s2 = $this->structure('10102');
        $c = $this->openCampaign([$s1, $s2]);
        $dept = [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('101')]];
        $agentA = $this->uuidOf($this->createUser('AGENT', scopes: $dept));
        $agentB = $this->uuidOf($this->createUser('AGENT', scopes: $dept));

        $r = $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agentA, 'structure_uuids' => [$s1, $s2]], self::$adminToken);
        $this->assertSame(['assigned' => 2, 'reassigned' => 0, 'unchanged' => 0], $r->body['data'], json_encode($r->body));
        $again = $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agentA, 'structure_uuids' => [$s1]], self::$adminToken);
        $this->assertSame(1, $again->body['data']['unchanged'], 'Réaffecter au même agent ne change rien.');

        $cs = $this->csId($c['id'], $s1);
        $re = $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agentB, 'campaign_structure_ids' => [$cs]], self::$adminToken);
        $this->assertSame(1, $re->body['data']['reassigned']);
        $this->assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$cs]));
        $this->assertSame('Réaffectation', self::$db->fetchValue('SELECT end_reason FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NOT NULL', [$cs]),
            "L'historique de l'affectation précédente est conservé.");

        // Critère de recette : la base elle-même refuse un second agent actif sur la même structure.
        $this->expectException(\PDOException::class);
        self::$db->execute('INSERT INTO assignments (campaign_structure_id, agent_id) VALUES (?, ?)', [$cs, $this->userId($this->createUser('AGENT'))]);
    }

    public function testAssignmentChecksScopesAndIsAllOrNothing(): void
    {
        $inDept = $this->structure('10102');
        $otherRegion = $this->structure('20101');
        $c = $this->openCampaign([$inDept, $otherRegion]);
        $agent = $this->uuidOf($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10102')]]));

        $r = $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agent, 'structure_uuids' => [$inDept, $otherRegion]], self::$adminToken);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString("zone de travail de l'agent", implode(' ', $r->body['error']['details']['errors']));
        $this->assertSame(0, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM assignments a JOIN campaign_structures cs ON cs.id = a.campaign_structure_id WHERE cs.campaign_id = ?', [$c['id']]),
            'Tout-ou-rien : aucune affectation créée.');

        // Une zone de travail de campagne étend le terrain de l'agent.
        $areas = $this->call('PUT', "/campaigns/{$c['id']}/agents/$agent/areas", ['commune_ids' => [self::communeId('20101')]], self::$adminToken);
        $this->assertSame('COMMUNE TEST 4', $areas->body['data'][0]['commune']);
        $ok = $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agent, 'structure_uuids' => [$inDept, $otherRegion]], self::$adminToken);
        $this->assertSame(2, $ok->body['data']['assigned'], json_encode($ok->body));

        // Superviseur départemental : seulement les structures de son département.
        $sup = $this->tokenFor($this->createUser('SUP_DEP'));
        $list = $this->call('GET', "/campaigns/{$c['id']}/assignments", token: $sup)->body['data'];
        $this->assertSame([$inDept], array_column(array_column($list, 'structure'), 'uuid'));
        $local = $this->uuidOf($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10102')]]));
        $this->assertSame(1, $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $local, 'structure_uuids' => [$inDept]], $sup)->body['data']['reassigned']);
        $this->assertSame(422, $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $local, 'structure_uuids' => [$otherRegion]], $sup)->status);
        $outsider = $this->uuidOf($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('20101')]]));
        $this->assertSame(422, $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $outsider, 'structure_uuids' => [$inDept]], $sup)->status,
            "Un agent hors du périmètre du superviseur n'est pas utilisable.");
    }

    public function testUnassignedViewEndAssignmentAndDeliveredDataProtection(): void
    {
        $s1 = $this->structure('20201');
        $s2 = $this->structure('20201');
        $c = $this->openCampaign([$s1, $s2]);
        $agent = $this->uuidOf($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('20201')]]));
        $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agent, 'structure_uuids' => [$s1]], self::$adminToken);

        $unassigned = $this->call('GET', "/campaigns/{$c['id']}/assignments", token: self::$adminToken, query: ['unassigned' => '1']);
        $this->assertSame([$s2], array_column(array_column($unassigned->body['data'], 'structure'), 'uuid'));
        $detail = $this->call('GET', "/campaigns/{$c['id']}", token: self::$adminToken)->body['data'];
        $this->assertSame([2, 1, 1], [$detail['targeted'], $detail['assigned'], $detail['unassigned']]);

        // Une collecte déjà remise protège l'affectation et le ciblage.
        $cs = $this->csId($c['id'], $s1);
        $assignmentId = (int) self::$db->fetchValue('SELECT id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$cs]);
        self::$db->execute(
            "INSERT INTO collections (uuid, campaign_structure_id, campaign_id, structure_id, questionnaire_version_id, agent_id, region_id, department_id, commune_id, payload_sha256, status)
             SELECT UUID(), cs.id, cs.campaign_id, cs.structure_id, c.questionnaire_version_id, a.agent_id, d.region_id, co.department_id, s.commune_id, REPEAT('0', 64), 'SOUMISE'
               FROM campaign_structures cs JOIN campaigns c ON c.id = cs.campaign_id JOIN structures s ON s.id = cs.structure_id
               JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id
               JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL WHERE cs.id = ?", [$cs]);
        $this->assertSame(409, $this->call('DELETE', "/assignments/$assignmentId", ['reason' => 'Test'], self::$adminToken)->status);
        $this->assertSame(409, $this->call('DELETE', "/campaigns/{$c['id']}/structures/$s1", token: self::$adminToken)->status);
        $other = $this->uuidOf($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('20201')]]));
        $this->assertSame(422, $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $other, 'structure_uuids' => [$s1]], self::$adminToken)->status);

        // Sans collecte : retrait possible, la structure redevient non affectée.
        $this->call('POST', "/campaigns/{$c['id']}/assignments", ['agent_uuid' => $agent, 'structure_uuids' => [$s2]], self::$adminToken);
        $id2 = (int) self::$db->fetchValue('SELECT id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL', [$this->csId($c['id'], $s2)]);
        $this->assertSame(204, $this->call('DELETE', "/assignments/$id2", ['reason' => 'Agent malade'], self::$adminToken)->status);
        $this->assertSame('Agent malade', self::$db->fetchValue('SELECT end_reason FROM assignments WHERE id = ?', [$id2]));
    }

    // ------------------------------------------------------------------ Reconduction

    public function testCloneCopiesActiveStructuresAndValidAssignmentsOnly(): void
    {
        $keep = $this->structure('10201');
        $toClose = $this->structure('10201');
        $source = $this->openCampaign([$keep, $toClose]);
        $agent = $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10201')]]);
        $this->call('POST', "/campaigns/{$source['id']}/assignments", ['agent_uuid' => $this->uuidOf($agent), 'structure_uuids' => [$keep, $toClose]], self::$adminToken);
        $this->transition($source['id'], 'CLOTUREE', 'Fin');
        $this->call('POST', "/structures/$toClose/close", ['reason' => 'Fermée depuis'], self::$adminToken);

        $target = $this->campaign();
        $r = $this->call('POST', "/campaigns/{$target['id']}/clone-from/{$source['id']}", ['copy_assignments' => true], self::$adminToken);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $this->assertSame(1, $r->body['data']['structures_copied']);
        $this->assertSame(1, $r->body['data']['structures_skipped']);
        $this->assertSame(1, $r->body['data']['assignments_copied']);
        $this->assertSame($this->userId($agent), (int) self::$db->fetchValue('SELECT agent_id FROM assignments WHERE campaign_structure_id = ? AND ended_at IS NULL',
            [$this->csId($target['id'], $keep)]));
        $this->assertSame(2, (int) self::$db->fetchValue('SELECT COUNT(*) FROM campaign_structures WHERE campaign_id = ?', [$source['id']]), 'La source est intacte.');

        $replay = $this->call('POST', "/campaigns/{$target['id']}/clone-from/{$source['id']}", ['copy_assignments' => true], self::$adminToken);
        $this->assertSame(0, $replay->body['data']['structures_copied'], 'Rejouer la reconduction ne duplique rien.');
        $this->assertSame(409, $this->call('POST', "/campaigns/{$source['id']}/clone-from/{$target['id']}", [], self::$adminToken)->status);
    }
}
