<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Text;

/**
 * Centre découvert sur le terrain : l'agent le crée (structure proposée), il est inscrit dans la campagne et affecté
 * à l'agent, qui soumet aussitôt sa collecte. Idempotent par l'UUID de la structure (création hors ligne).
 */
final class FieldStructureTest extends ApiTestCase
{
    private static string $adminToken;
    private static array $base;
    private static int $year = 2700;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([[1, 'PRIMAIRE', 'Primaire'], [2, 'SECONDAIRE', 'Secondaire'], [3, 'TERTIAIRE', 'Tertiaire']] as [$id, $code, $label]) {
            self::$db->execute('INSERT IGNORE INTO sectors (id, code, label, sort) VALUES (?, ?, ?, ?)', [$id, $code, $label, $id]);
        }
        self::$db->execute("INSERT IGNORE INTO trades (id, sector_id, label) VALUES (41, 3, 'Couture'), (1, 1, 'Maraîchage'), (15, 2, 'Soudure')");
        $fixture = json_decode((string) file_get_contents(self::$config['paths']['shared'] . '/rules-fixtures/eftp-nf-2025.1.json'), true);
        $base = $fixture['base'];
        unset($base['_ctx'], $base['Q1_2']);
        $base['Q5_DATE'] = date('Y-m-d');
        $base['GPS']['captured_at'] = date('c');
        self::$base = $base;
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    private function openCampaign(): array
    {
        $y = ++self::$year;
        $yearId = $this->call('POST', '/academic-years', ['code' => "$y-" . ($y + 1), 'start_date' => "$y-10-01", 'end_date' => ($y + 1) . '-09-30'], self::$adminToken)->body['data']['id'];
        $c = $this->call('POST', '/campaigns', ['academic_year_id' => $yearId, 'code' => 'TERRAIN-' . bin2hex(random_bytes(3)), 'name' => 'Recensement de terrain',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+30 days'))], self::$adminToken)->body['data'];
        $this->assertSame(200, $this->call('POST', "/campaigns/{$c['id']}/transition", ['to' => 'OUVERTE'], self::$adminToken)->status);
        return $c;
    }

    private function newCentre(string $name, string $commune = '10101'): array
    {
        return ['uuid' => Text::uuid4(), 'name' => $name, 'type_code' => 'c', 'legal_status' => 'PRIVE', 'zone' => 'URBAINE',
            'commune_id' => self::communeId($commune), 'locality_label' => 'Quartier Talladjé', 'latitude' => 13.5137, 'longitude' => 2.1098, 'gps_accuracy_m' => 6];
    }

    public function testAgentCreatesACentreAndSubmitsRightAway(): void
    {
        $campaign = $this->openCampaign();
        $agent = $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]]);
        $token = $this->tokenFor($agent);
        $centre = $this->newCentre('Atelier de couture découvert ' . bin2hex(random_bytes(3)));

        $r = $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $centre, $token);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $d = $r->body['data'];
        $this->assertSame('PROPOSEE', $d['structure']['status'], 'Codification et décision : superviseur');
        $this->assertSame(['a', 'b', 'c'], [$d['structure']['zone'], $d['structure']['legal_status'], $d['structure']['type_code']], 'Codé comme dans le questionnaire');
        $cs = $d['campaign_structure_id'];

        // Renvoi identique (réponse perdue hors ligne) : même inscription, pas de doublon.
        $again = $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $centre, $token);
        $this->assertSame(200, $again->status);
        $this->assertSame($cs, $again->body['data']['campaign_structure_id']);
        $this->assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM campaign_structures WHERE campaign_id = ?', [$campaign['id']]));

        // La collecte peut être soumise aussitôt ; l'identité verrouillée est reprise de la structure proposée.
        $uuid = Text::uuid4();
        $sub = $this->call('POST', "/collections/$uuid/submissions", ['campaign_structure_id' => $cs, 'questionnaire_version' => '2025.1', 'base_revision' => 0,
            'data' => ['Q1_1' => $centre['name']] + self::$base], $token, ['x-app-version' => '1.0.0']);
        $this->assertSame(201, $sub->status, json_encode($sub->body, JSON_UNESCAPED_UNICODE));

        // Le centre apparaît dans la synchronisation de l'agent.
        $pull = $this->call('GET', '/sync/pull', token: $token)->body['data'];
        $this->assertContains($cs, array_column($pull['assignments'], 'campaign_structure_id'));

        // Un second centre au même endroit et au nom proche est signalé comme doublon possible, sans blocage.
        $twin = $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", ['uuid' => Text::uuid4()] + $centre, $token);
        $this->assertSame(201, $twin->status, json_encode($twin->body, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($twin->body['data']['possible_duplicates']);
    }

    /** Un agent tout juste créé (périmètre seul, aucune affectation ni zone de campagne) reçoit la campagne ouverte. */
    public function testNewAgentWithoutAssignmentReceivesOpenCampaigns(): void
    {
        $campaign = $this->openCampaign();
        $token = $this->tokenFor($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]]));
        $pull = $this->call('GET', '/sync/pull', token: $token)->body['data'];
        $this->assertContains($campaign['id'], array_column($pull['campaigns'], 'id'), 'Sans affectation, la campagne ouverte doit arriver sur la tablette');
        $this->assertSame([], $pull['assignments'], 'Aucun centre du registre ne lui est montré sans affectation');

        $r = $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $this->newCentre('Premier centre ' . bin2hex(random_bytes(3))), $token);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
    }

    public function testCentreMustBeInTheAgentAreaAndTheCampaignOpen(): void
    {
        $campaign = $this->openCampaign();
        $token = $this->tokenFor($this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]]));
        $this->assertSame(403, $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $this->newCentre('Hors zone ' . bin2hex(random_bytes(2)), '20201'), $token)->status);
        $missing = $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", ['uuid' => Text::uuid4(), 'name' => 'X', 'commune_id' => self::communeId('10101')], $token);
        $this->assertSame(422, $missing->status, 'Nom trop court, type et statut manquants');

        $this->call('POST', "/campaigns/{$campaign['id']}/transition", ['to' => 'SUSPENDUE', 'reason' => 'Test de suspension'], self::$adminToken);
        $this->assertSame(423, $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $this->newCentre('Après suspension ' . bin2hex(random_bytes(2))), $token)->status);
        $this->assertSame(403, $this->call('POST', "/campaigns/{$campaign['id']}/field-structures", $this->newCentre('Lecteur'), $this->tokenFor($this->createUser('VIEWER')))->status);
    }
}
