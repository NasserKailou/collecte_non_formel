<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Response;

/**
 * Saisie multicanal (lot L6b) : saisie directe dans le back-office, saisie de fiches papier, mode de collecte de la
 * campagne, brouillons serveur. Même circuit de soumission que la tablette (contrôles, révisions, rejeu).
 */
final class EntryApiTest extends ApiTestCase
{
    private static string $adminToken;
    private static array $base;
    private static int $code = 3500000000;
    private static int $year = 2400;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([[1, 'PRIMAIRE', 'Primaire'], [2, 'SECONDAIRE', 'Secondaire'], [3, 'TERTIAIRE', 'Tertiaire']] as [$id, $code, $label]) {
            self::$db->execute('INSERT IGNORE INTO sectors (id, code, label, sort) VALUES (?, ?, ?, ?)', [$id, $code, $label, $id]);
        }
        self::$db->execute("INSERT IGNORE INTO trades (id, sector_id, label) VALUES (41, 3, 'Couture'), (1, 1, 'Maraîchage'), (15, 2, 'Soudure')");
        $fixture = json_decode((string) file_get_contents(self::$config['paths']['shared'] . '/rules-fixtures/eftp-nf-2025.1.json'), true);
        $base = $fixture['base'];
        unset($base['_ctx']);
        $base['Q5_DATE'] = date('Y-m-d');
        $base['GPS'] = ['lat' => null, 'lng' => null, 'accuracy_m' => null, 'captured_at' => null, 'status' => 'INDISPONIBLE', 'note' => 'Saisie au bureau, sans relevé'];
        self::$base = $base;
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    /** Campagne ouverte (mode donné), une structure affectée à un nouvel agent, session web de l'agent. */
    private function setUpEntry(string $mode = 'TABLETTE'): array
    {
        $y = ++self::$year;
        $yearId = $this->call('POST', '/academic-years', ['code' => "$y-" . ($y + 1), 'start_date' => "$y-10-01", 'end_date' => ($y + 1) . '-09-30'], self::$adminToken)->body['data']['id'];
        $structure = $this->call('POST', '/structures', ['official_code' => (string) (++self::$code), 'name' => 'Atelier ' . bin2hex(random_bytes(4)),
            'type_code' => 'c', 'zone' => 'URBAINE', 'legal_status' => 'PRIVE', 'commune_id' => self::communeId('10101')], self::$adminToken)->body['data'];
        $campaign = $this->call('POST', '/campaigns', ['academic_year_id' => $yearId, 'code' => 'L6B-' . bin2hex(random_bytes(3)), 'name' => 'Collecte test',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+30 days')), 'collection_mode' => $mode],
            self::$adminToken);
        $this->assertSame(201, $campaign->status, json_encode($campaign->body, JSON_UNESCAPED_UNICODE));
        $campaign = $campaign->body['data'];
        $this->assertSame($mode, $campaign['collection_mode']);
        $this->call('POST', "/campaigns/{$campaign['id']}/structures", ['structure_uuids' => [$structure['uuid']]], self::$adminToken);
        $this->assertSame(200, $this->call('POST', "/campaigns/{$campaign['id']}/transition", ['to' => 'OUVERTE'], self::$adminToken)->status);
        $agent = $this->createUser('AGENT');
        $this->call('POST', "/campaigns/{$campaign['id']}/assignments", ['agent_uuid' => $this->uuidOf($agent), 'structure_uuids' => [$structure['uuid']]], self::$adminToken);
        $csId = (int) self::$db->fetchValue('SELECT cs.id FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id WHERE s.uuid = ?', [$structure['uuid']]);

        $login = $this->call('POST', '/auth/login', ['identifier' => $agent, 'password' => self::PASSWORD, 'session' => true], ip: $this->ip());
        preg_match('/^eftp_session=([a-f0-9]{64})/', $login->headers['Set-Cookie'] ?? '', $m);
        $this->assertNotEmpty($m, "Session web de l'agent");
        $web = ['cookie' => "eftp_session={$m[1]}", 'x-csrf-token' => $login->body['data']['csrf_token']];
        return ['campaign' => $campaign, 'structure' => $structure, 'agent' => $agent, 'cs' => $csId, 'web' => $web, 'token' => $this->tokenFor($agent)];
    }

    private function webSubmit(array $ctx, string $uuid, array $extra = [], int $base = 0): Response
    {
        return $this->call('POST', "/collections/$uuid/submissions", [
            'campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => '2025.1', 'base_revision' => $base, 'data' => self::$base,
        ] + $extra, headers: $ctx['web']);
    }

    public function testAgentEntersDirectlyInTheBackOffice(): void
    {
        $ctx = $this->setUpEntry();
        $list = $this->call('GET', '/entry/assignments', headers: $ctx['web']);
        $this->assertSame(200, $list->status, json_encode($list->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('A_SAISIR', $list->body['data'][0]['state']);
        $this->assertSame(['MOBILE', 'WEB'], $list->body['data'][0]['allowed_channels']);

        $open = $this->call('GET', "/entry/{$ctx['cs']}", headers: $ctx['web'])->body['data'];
        $this->assertSame('PREREMPLISSAGE', $open['source']);
        $this->assertSame($ctx['structure']['name'], $open['data']['Q1_1'], 'Pré-remplissage depuis le registre');
        $this->assertSame('2025.1', $open['definition']['version']);
        $this->assertFalse($open['validation']['valid'], 'Contrôle immédiat : questionnaire encore vide');
        $this->assertSame('Couture', $open['trades']['trades.3'][0]['label']);

        // Brouillon (sauvegarde automatique) : contrôle à blanc renvoyé, état « brouillon ».
        $draft = $this->call('PUT', "/entry/{$ctx['cs']}/draft", ['data' => self::$base, 'channel' => 'WEB'], headers: $ctx['web']);
        $this->assertSame(200, $draft->status, json_encode($draft->body, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($draft->body['data']['validation']['valid'], json_encode($draft->body['data']['validation']['errors'], JSON_UNESCAPED_UNICODE));
        $uuid = $draft->body['data']['uuid'];
        $this->assertSame('BROUILLON', $this->call('GET', '/entry/assignments', headers: $ctx['web'])->body['data'][0]['state']);
        $this->assertSame('BROUILLON', $this->call('GET', "/entry/{$ctx['cs']}", headers: $ctx['web'])->body['data']['source']);

        // Soumission par le circuit commun : canal WEB, brouillon remplacé.
        $r = $this->webSubmit($ctx, $uuid);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $row = self::$db->fetchOne('SELECT c.channel, s.channel AS sub_channel, s.device_id FROM collections c JOIN collection_submissions s ON s.collection_id = c.id WHERE c.uuid = ?', [$uuid]);
        $this->assertSame(['channel' => 'WEB', 'sub_channel' => 'WEB', 'device_id' => null], $row);
        $this->assertNull(self::$db->fetchValue('SELECT uuid FROM collection_drafts WHERE campaign_structure_id = ?', [$ctx['cs']]));
        $this->assertSame('SOUMISE', $this->call('GET', '/entry/assignments', headers: $ctx['web'])->body['data'][0]['state']);
        $this->assertSame(200, $this->webSubmit($ctx, $uuid)->status, 'Rejeu identique : accusé initial, sans doublon');

        // Collecte remise : plus de brouillon possible.
        $this->assertSame(423, $this->call('PUT', "/entry/{$ctx['cs']}/draft", ['data' => self::$base], headers: $ctx['web'])->status);
    }

    public function testPaperCampaignRequiresPaperReferencesAndRefusesTablets(): void
    {
        $ctx = $this->setUpEntry('PAPIER');
        $uuid = $this->call('GET', "/entry/{$ctx['cs']}", headers: $ctx['web'])->body['data']['uuid'];

        // La tablette ne peut pas soumettre une campagne papier.
        $mobile = $this->call('POST', "/collections/$uuid/submissions", ['campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => '2025.1',
            'base_revision' => 0, 'data' => self::$base], $ctx['token'], ['x-app-version' => '1.0.0']);
        $this->assertSame(422, $mobile->status);
        $this->assertSame('CHANNEL_NOT_ALLOWED', $mobile->body['error']['code']);
        // Saisie web directe non plus : la fiche papier doit être référencée.
        $this->assertSame('CHANNEL_NOT_ALLOWED', $this->webSubmit($ctx, $uuid)->body['error']['code']);

        $missing = $this->webSubmit($ctx, $uuid, ['channel' => 'PAPIER', 'paper' => ['form_ref' => '', 'interview_date' => '2099-01-01']]);
        $this->assertSame(422, $missing->status);
        $this->assertEqualsCanonicalizing(['paper.form_ref', 'paper.interview_date', 'paper.enumerator'], array_column($missing->body['error']['details']['errors'], 'field'));

        $paper = ['form_ref' => 'NY-0042', 'interview_date' => date('Y-m-d', strtotime('-3 days')), 'enumerator' => 'Enquêteur fictif'];
        $ok = $this->webSubmit($ctx, $uuid, ['channel' => 'PAPIER', 'paper' => $paper]);
        $this->assertSame(201, $ok->status, json_encode($ok->body, JSON_UNESCAPED_UNICODE));
        $row = self::$db->fetchOne('SELECT channel, paper_form_ref, paper_interview_date, paper_enumerator FROM collections WHERE uuid = ?', [$uuid]);
        $this->assertSame(['channel' => 'PAPIER', 'paper_form_ref' => 'NY-0042', 'paper_interview_date' => $paper['interview_date'], 'paper_enumerator' => 'Enquêteur fictif'], $row);
        $payload = json_decode((string) self::$db->fetchValue('SELECT s.payload_json FROM collection_submissions s JOIN collections c ON c.id = s.collection_id WHERE c.uuid = ?', [$uuid]), true);
        $this->assertSame($paper, $payload['paper'], 'Références de la fiche conservées avec la révision');
    }

    public function testMapShowsCollectedCentresWithTheirCoordinatesAndLegalStatus(): void
    {
        $ctx = $this->setUpEntry();
        $uuid = $this->call('GET', "/entry/{$ctx['cs']}", headers: $ctx['web'])->body['data']['uuid'];
        $gps = ['lat' => 13.5137, 'lng' => 2.1098, 'accuracy_m' => null, 'captured_at' => null, 'status' => 'MANUEL', 'note' => null];
        $r = $this->call('POST', "/collections/$uuid/submissions", ['campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => '2025.1',
            'base_revision' => 0, 'data' => ['GPS' => $gps] + self::$base], headers: $ctx['web']);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));

        $map = $this->call('GET', '/stats/map', token: self::$adminToken, query: ['campaign_id' => (string) $ctx['campaign']['id']]);
        $this->assertSame(200, $map->status, json_encode($map->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $map->body['data']['total']);
        $p = $map->body['data']['points'][0];
        $this->assertSame([13.5137, 2.1098, 'SAISIE', 'PRIVE', 'SOUMISE', 'WEB'], [$p['lat'], $p['lng'], $p['source'], $p['legal'], $p['status'], $p['channel']]);
        $this->assertSame(403, $this->call('GET', '/stats/map', token: $ctx['token'], query: ['campaign_id' => (string) $ctx['campaign']['id']])->status, 'Agent : pas de carte');
    }

    public function testModeCanChangeDuringTheCampaignAndEntryIsLimitedToTheAssignedAgent(): void
    {
        $ctx = $this->setUpEntry();
        $patch = $this->call('PATCH', "/campaigns/{$ctx['campaign']['id']}", ['collection_mode' => 'MIXTE'], self::$adminToken);
        $this->assertSame(200, $patch->status, json_encode($patch->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('MIXTE', $patch->body['data']['collection_mode'], 'Mode modifiable après ouverture (ex. tablettes en panne)');
        $this->assertSame(422, $this->call('PATCH', "/campaigns/{$ctx['campaign']['id']}", ['collection_mode' => 'FAX'], self::$adminToken)->status);

        $other = $this->setUpEntry();
        $this->assertSame(404, $this->call('GET', "/entry/{$ctx['cs']}", headers: $other['web'])->status, "Structure d'un autre agent : introuvable");
        $this->assertSame(404, $this->call('PUT', "/entry/{$ctx['cs']}/draft", ['data' => []], headers: $other['web'])->status);
        $this->assertSame(403, $this->call('GET', '/entry/assignments', token: $this->tokenFor($this->createUser('VIEWER')))->status, 'Lecteur : pas de saisie');
    }
}
