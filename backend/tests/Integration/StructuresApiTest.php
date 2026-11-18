<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Text;
use App\Http\Response;
use App\Modules\Structures\StructureService;

/**
 * Registre des structures (lot L3) : validation, code unique, détection de doublons, périmètre,
 * historique, propositions de terrain (idempotentes), approbation, rejet, fusion, fermeture.
 */
final class StructuresApiTest extends ApiTestCase
{
    private static string $adminToken;
    private static int $code = 1000000000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'official_code' => (string) (++self::$code), 'name' => 'Atelier ' . bin2hex(random_bytes(4)), 'type_code' => 'c',
            'legal_status' => 'PRIVE', 'zone' => 'URBAINE', 'commune_id' => self::communeId('10101'), 'locality_label' => 'Quartier Plateau',
        ];
    }

    private function create(array $over = [], ?string $token = null): Response
    {
        return $this->call('POST', '/structures', $this->payload($over), $token ?? self::$adminToken);
    }

    // ------------------------------------------------------------------ Création et validation

    public function testCreateActiveStructureWithHistoryAndLocality(): void
    {
        $r = $this->create(['name' => 'Garage  Moderne de  Niamey', 'latitude' => 13.5137, 'longitude' => 2.1098, 'gps_accuracy_m' => 8, 'opening_date' => '2015-03-01']);
        $this->assertSame(201, $r->status, json_encode($r->body));
        $s = $r->body['data'];
        $this->assertSame('ACTIVE', $s['status']);
        $this->assertSame('Garage Moderne de Niamey', $s['name'], 'Espaces multiples nettoyés');
        $this->assertSame('COMMUNE TEST', $s['commune']);
        $this->assertSame('REGION TEST', $s['region']);
        $this->assertSame(13.5137, $s['latitude']);
        $this->assertSame('QUARTIER PLATEAU', self::$db->fetchValue('SELECT name_normalized FROM localities WHERE commune_id = ? AND name = ?',
            [self::communeId('10101'), 'Quartier Plateau']), 'Le quartier saisi alimente le référentiel des localités.');
        $h = $this->call('GET', '/structures/' . $s['uuid'] . '/history', token: self::$adminToken)->body['data'];
        $this->assertSame('CREATION', $h[0]['action']);
    }

    public function testInvalidFieldsAreReported(): void
    {
        $r = $this->create(['official_code' => '12345', 'name' => 'AB', 'type_code' => 'z', 'zone' => 'VILLE', 'commune_id' => 999999,
            'latitude' => 48.85, 'longitude' => 2.35, 'opening_date' => '2999-01-01']);
        $this->assertSame(422, $r->status);
        $fields = array_column($r->body['error']['details']['errors'], 'field');
        foreach (['official_code', 'name', 'type_code', 'zone', 'commune_id', 'latitude', 'opening_date'] as $f) {
            $this->assertContains($f, $fields);
        }
        $this->assertContains('other_type_label', array_column($this->create(['type_code' => 'k'])->body['error']['details']['errors'], 'field'),
            '« Autre structure » exige une précision.');
        $this->assertContains('official_code', array_column($this->create(['official_code' => null])->body['error']['details']['errors'], 'field'),
            'Le code est obligatoire pour une structure active.');
    }

    public function testOfficialCodeIsUnique(): void
    {
        $first = $this->create()->body['data'];
        $r = $this->create(['official_code' => $first['official_code'], 'commune_id' => self::communeId('20201')]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('déjà attribué', $r->body['error']['details']['errors'][0]['message']);
    }

    // ------------------------------------------------------------------ Doublons

    public function testSimilarNameInSameCommuneOrNearbyGpsIsFlaggedUntilConfirmed(): void
    {
        $this->assertSame(201, $this->create(['name' => 'Centre de Couture Al Baraka', 'commune_id' => self::communeId('10102'), 'latitude' => 13.6, 'longitude' => 2.2])->status);

        $sameName = $this->create(['name' => 'Centre de couture AL-BARAKA', 'commune_id' => self::communeId('10102')]);
        $this->assertSame(409, $sameName->status);
        $this->assertSame('DUPLICATE_SUSPECTED', $sameName->body['error']['code']);
        $this->assertSame('Même nom dans la même commune', $sameName->body['error']['details']['candidates'][0]['reasons'][0]);

        $this->assertSame(409, $this->create(['name' => 'Couture Al Barakaa', 'commune_id' => self::communeId('10102')])->status, 'Nom très proche');

        $near = $this->create(['name' => 'Menuiserie Sahel', 'commune_id' => self::communeId('20201'), 'latitude' => 13.6004, 'longitude' => 2.2]);
        $this->assertSame(409, $near->status);
        $this->assertLessThanOrEqual(100, $near->body['error']['details']['candidates'][0]['distance_m']);

        // Autre commune, sans GPS : pas d'alerte. Confirmation explicite : création acceptée et tracée.
        $this->assertSame(201, $this->create(['name' => 'Centre de Couture Al Baraka', 'commune_id' => self::communeId('20202')])->status);
        $confirmed = $this->create(['name' => 'Centre de couture AL-BARAKA', 'commune_id' => self::communeId('10102'), 'confirm_duplicates' => true]);
        $this->assertSame(201, $confirmed->status);
        $h = $this->call('GET', '/structures/' . $confirmed->body['data']['uuid'] . '/history', token: self::$adminToken)->body['data'];
        $this->assertStringContainsString('doublon', (string) $h[0]['reason']);
    }

    public function testNameSimilarityAndDistanceHelpers(): void
    {
        $this->assertTrue(StructureService::similarNames(Text::normalize('Atelier de soudure Bonkano'), Text::normalize('Soudure Bonkano')));
        $this->assertTrue(StructureService::similarNames('MENUISERIE ESPOIR', 'MENUISERIE ESPOR'));
        $this->assertFalse(StructureService::similarNames('MENUISERIE ESPOIR', 'COUTURE ESPOIR'));
        $this->assertFalse(StructureService::similarNames('GARAGE', 'ATELIER'));
        $this->assertEqualsWithDelta(111.2, StructureService::distanceMeters(13.0, 2.0, 13.001, 2.0), 0.5);
    }

    // ------------------------------------------------------------------ Périmètre et modification

    public function testSupervisorWorksOnlyInsideItsDepartment(): void
    {
        $sup = $this->tokenFor($this->createUser('SUP_DEP')); // département 101
        $inside = $this->create(['commune_id' => self::communeId('10102')])->body['data'];
        $outside = $this->create(['commune_id' => self::communeId('20101')])->body['data'];

        $uuids = array_column($this->call('GET', '/structures', token: $sup, query: ['per_page' => '200'])->body['data'], 'uuid');
        $this->assertContains($inside['uuid'], $uuids);
        $this->assertNotContains($outside['uuid'], $uuids);
        $this->assertSame(404, $this->call('GET', '/structures/' . $outside['uuid'], token: $sup)->status);
        $this->assertSame('OUT_OF_SCOPE', $this->create(['commune_id' => self::communeId('20101')], $sup)->body['error']['code']);
        $this->assertSame(201, $this->create(['commune_id' => self::communeId('10101')], $sup)->status);
        $this->assertSame('OUT_OF_SCOPE', $this->call('PATCH', '/structures/' . $inside['uuid'], ['commune_id' => self::communeId('20101')], $sup)->body['error']['code'],
            'Déplacer une structure hors de son périmètre est refusé.');
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(403, $this->call('GET', '/structures', token: $agent)->status, "Un agent n'a pas accès au registre.");
    }

    public function testUpdateRecordsBeforeAndAfter(): void
    {
        $s = $this->create()->body['data'];
        $r = $this->call('PATCH', '/structures/' . $s['uuid'], ['zone' => 'RURALE', 'reason' => 'Vérification terrain'], self::$adminToken);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $this->assertSame('RURALE', $r->body['data']['zone']);
        $h = $this->call('GET', '/structures/' . $s['uuid'] . '/history', token: self::$adminToken)->body['data'];
        $last = end($h);
        $this->assertSame('MODIFICATION', $last['action']);
        $this->assertSame(['zone' => ['avant' => 'URBAINE', 'apres' => 'RURALE']], $last['changes']);
        $this->assertSame('Vérification terrain', $last['reason']);
    }

    // ------------------------------------------------------------------ Propositions de terrain

    public function testAgentProposalIsIdempotentAndOnlyInItsArea(): void
    {
        $agent = $this->tokenFor($this->createUser('AGENT')); // commune 10101
        $uuid = Text::uuid4();
        $body = ['uuid' => $uuid, 'name' => 'Atelier découvert', 'type_code' => 'c', 'commune_id' => self::communeId('10101'),
            'locality_label' => 'Village Koira', 'gps' => ['lat' => 13.52, 'lng' => 2.11, 'accuracy' => 12], 'official_code' => '9999999999'];
        $first = $this->call('POST', '/structures/proposals', $body, $agent);
        $this->assertSame(201, $first->status, json_encode($first->body));
        $this->assertSame('PROPOSEE', $first->body['data']['structure']['status']);
        $this->assertNull($first->body['data']['structure']['official_code'], "Le code n'est pas attribué par l'agent.");
        $this->assertSame(13.52, $first->body['data']['structure']['latitude']);

        $replay = $this->call('POST', '/structures/proposals', $body, $agent);
        $this->assertSame(200, $replay->status);
        $this->assertTrue($replay->body['data']['replayed']);
        $this->assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM structures WHERE uuid = ?', [$uuid]));

        $outside = $this->call('POST', '/structures/proposals', ['uuid' => Text::uuid4(), 'commune_id' => self::communeId('20101')] + $body, $agent);
        $this->assertSame('OUT_OF_SCOPE', $outside->body['error']['code']);
        $this->assertSame(422, $this->call('POST', '/structures/proposals', ['uuid' => 'pas-un-uuid'] + $body, $agent)->status);
        $other = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(409, $this->call('POST', '/structures/proposals', $body, $other)->status, 'Le même UUID réutilisé par un autre agent est refusé.');
    }

    public function testProposalReportsPossibleDuplicatesAndIsApprovedWithACode(): void
    {
        $existing = $this->create(['name' => 'Soudure Bonkano', 'commune_id' => self::communeId('10101')])->body['data'];
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $p = $this->call('POST', '/structures/proposals', ['uuid' => Text::uuid4(), 'name' => 'Atelier de soudure Bonkano', 'type_code' => 'c',
            'commune_id' => self::communeId('10101')], $agent)->body['data'];
        $this->assertSame($existing['uuid'], $p['possible_duplicates'][0]['uuid']);

        $sup = $this->tokenFor($this->createUser('SUP_DEP'));
        $uuid = $p['structure']['uuid'];
        $this->assertSame(422, $this->call('POST', "/structures/$uuid/approve", [], $sup)->status, "Code obligatoire à l'approbation");
        $this->assertSame(422, $this->call('POST', "/structures/$uuid/approve", ['official_code' => $existing['official_code']], $sup)->status);
        $ok = $this->call('POST', "/structures/$uuid/approve", ['official_code' => (string) (++self::$code)], $sup);
        $this->assertSame('ACTIVE', $ok->body['data']['status']);
        $this->assertSame(409, $this->call('POST', "/structures/$uuid/approve", ['official_code' => (string) (++self::$code)], $sup)->status);
    }

    public function testRejectRequiresAReason(): void
    {
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $uuid = $this->call('POST', '/structures/proposals', ['uuid' => Text::uuid4(), 'name' => 'Structure fictive', 'type_code' => 'a',
            'commune_id' => self::communeId('10101')], $agent)->body['data']['structure']['uuid'];
        $this->assertSame(422, $this->call('POST', "/structures/$uuid/reject", [], self::$adminToken)->status);
        $r = $this->call('POST', "/structures/$uuid/reject", ['reason' => "N'est pas une structure de formation"], self::$adminToken);
        $this->assertSame('REJETEE', $r->body['data']['status']);
        $this->assertSame(409, $this->call('PATCH', "/structures/$uuid", ['zone' => 'RURALE'], self::$adminToken)->status);
    }

    // ------------------------------------------------------------------ Fusion, fermeture

    public function testMergeMarksTheDuplicateAndIsRefusedWhenTargetedByAnOpenCampaign(): void
    {
        $ref = $this->create()->body['data'];
        $dup = $this->create(['confirm_duplicates' => true])->body['data'];
        $r = $this->call('POST', "/structures/{$dup['uuid']}/merge", ['target_uuid' => $ref['uuid'], 'reason' => 'Même atelier, deux codes'], self::$adminToken);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $this->assertSame('FUSIONNEE', $r->body['data']['status']);
        $this->assertSame($ref['uuid'], $r->body['data']['merged_into_uuid']);
        $this->assertSame(409, $this->call('POST', "/structures/{$dup['uuid']}/merge", ['target_uuid' => $ref['uuid'], 'reason' => 'Encore'], self::$adminToken)->status);

        // Doublon ciblé par une campagne en préparation : fusion refusée tant qu'il n'est pas retiré du ciblage.
        $dup2 = $this->create()->body['data'];
        $yearId = self::$db->insert("INSERT INTO academic_years (code, label, start_date, end_date) VALUES ('2090-2091', 'Test', '2090-10-01', '2091-09-30')");
        $vid = (int) self::$db->fetchValue("SELECT id FROM questionnaire_versions WHERE version_code = '2025.1'");
        $cid = self::$db->insert("INSERT INTO campaigns (academic_year_id, code, name, questionnaire_version_id, opens_on, closes_on)
                                  VALUES (?, 'FUSION-TEST', 'Test', ?, '2090-11-01', '2091-03-31')", [$yearId, $vid]);
        self::$db->execute('INSERT INTO campaign_structures (campaign_id, structure_id) SELECT ?, id FROM structures WHERE uuid = ?', [$cid, $dup2['uuid']]);
        $refused = $this->call('POST', "/structures/{$dup2['uuid']}/merge", ['target_uuid' => $ref['uuid'], 'reason' => 'Doublon'], self::$adminToken);
        $this->assertSame(409, $refused->status);
        $this->assertStringContainsString('FUSION-TEST', $refused->body['error']['message']);
    }

    public function testCloseAndReopen(): void
    {
        $s = $this->create()->body['data'];
        $this->assertSame(422, $this->call('POST', "/structures/{$s['uuid']}/close", [], self::$adminToken)->status);
        $this->assertSame('FERMEE', $this->call('POST', "/structures/{$s['uuid']}/close", ['reason' => 'Activité arrêtée'], self::$adminToken)->body['data']['status']);
        $this->assertSame('ACTIVE', $this->call('POST', "/structures/{$s['uuid']}/reopen", ['reason' => 'Reprise'], self::$adminToken)->body['data']['status']);
        $actions = array_column($this->call('GET', "/structures/{$s['uuid']}/history", token: self::$adminToken)->body['data'], 'action');
        $this->assertSame(['CREATION', 'FERMETURE', 'REOUVERTURE'], $actions);
    }
}
