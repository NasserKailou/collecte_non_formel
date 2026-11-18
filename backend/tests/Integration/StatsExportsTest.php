<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Text;
use App\Core\XlsxStreamWriter;
use App\Modules\Stats\ExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Statistiques et exports (lot L6), sur des collectes réelles soumises et validées par l'API.
 * Jeu : 3 structures (2 en commune 10101, 1 en 20201), collectées avec la collecte de référence
 * (30 apprentis, 2 formateurs chacune) ; 2 validées, 1 laissée soumise.
 */
final class StatsExportsTest extends ApiTestCase
{
    private static string $adminToken;
    private static array $campaign;
    private static array $base;
    private static int $code = 4000000000;
    private static int $year = 2300;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach ([[1, 'PRIMAIRE', 'Primaire'], [2, 'SECONDAIRE', 'Secondaire'], [3, 'TERTIAIRE', 'Tertiaire']] as [$id, $code, $label]) {
            self::$db->execute('INSERT IGNORE INTO sectors (id, code, label, sort) VALUES (?, ?, ?, ?)', [$id, $code, $label, $id]);
        }
        self::$db->execute("INSERT IGNORE INTO trades (id, sector_id, label) VALUES (41, 3, 'Couture')");
        $fixture = json_decode((string) file_get_contents(self::$config['paths']['shared'] . '/rules-fixtures/eftp-nf-2025.1.json'), true);
        self::$base = $fixture['base'];
        unset(self::$base['_ctx']);
        self::$base['Q5_DATE'] = date('Y-m-d');
        self::$base['GPS']['captured_at'] = date('c');
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
        self::$campaign = $t->collectedCampaign(['10101', '10101', '20201'], 2, '=CMD|calc');
    }

    /** Campagne ouverte (validation à 1 niveau), collectes soumises ; les $validate premières validées. */
    private function collectedCampaign(array $communes, int $validate, ?string $firstName = null): array
    {
        $y = ++self::$year;
        $yearId = $this->call('POST', '/academic-years', ['code' => "$y-" . ($y + 1), 'start_date' => "$y-10-01", 'end_date' => ($y + 1) . '-09-30'], self::$adminToken)->body['data']['id'];
        $campaign = $this->call('POST', '/campaigns', ['academic_year_id' => $yearId, 'code' => 'ST-' . bin2hex(random_bytes(3)), 'name' => 'Campagne statistique',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+30 days')), 'validation_levels' => 1], self::$adminToken)->body['data'];
        $structures = [];
        foreach ($communes as $i => $commune) {
            $r = $this->call('POST', '/structures', ['official_code' => (string) (++self::$code), 'name' => $i === 0 && $firstName !== null ? $firstName : 'Atelier ' . bin2hex(random_bytes(3)),
                'type_code' => 'c', 'commune_id' => self::communeId($commune), 'confirm_duplicates' => true], self::$adminToken);
            $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
            $structures[] = $r->body['data']['uuid'];
        }
        $this->call('POST', "/campaigns/{$campaign['id']}/structures", ['structure_uuids' => $structures], self::$adminToken);
        $this->call('POST', "/campaigns/{$campaign['id']}/transition", ['to' => 'OUVERTE'], self::$adminToken);
        foreach ($structures as $i => $s) {
            $agent = $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId($communes[$i])]]);
            $this->call('POST', "/campaigns/{$campaign['id']}/assignments", ['agent_uuid' => $this->uuidOf($agent), 'structure_uuids' => [$s]], self::$adminToken);
            $cs = (int) self::$db->fetchValue('SELECT cs.id FROM campaign_structures cs JOIN structures st ON st.id = cs.structure_id WHERE st.uuid = ? AND cs.campaign_id = ?', [$s, $campaign['id']]);
            $uuid = Text::uuid4();
            $r = $this->call('POST', "/collections/$uuid/submissions", ['campaign_structure_id' => $cs, 'questionnaire_version' => '2025.1', 'base_revision' => 0,
                'data' => self::$base], $this->tokenFor($agent));
            $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
            if ($i < $validate) {
                $this->assertSame('VALIDEE', $this->call('POST', "/collections/$uuid/validate", [], self::$adminToken)->body['data']['status']);
            }
        }
        return $campaign;
    }

    private function cid(): int
    {
        return self::$campaign['id'];
    }

    // ------------------------------------------------------------------ Statistiques

    public function testProgressByRegionWithTotals(): void
    {
        $r = $this->call('GET', '/stats/progress', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'level' => 'region']);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $d = $r->body['data'];
        $this->assertCount(2, $d['rows']);
        $this->assertSame([3, 3, 3, 2, 1, 0, 100.0], [$d['total']['targeted'], $d['total']['assigned'], $d['total']['collected'],
            $d['total']['validee'], $d['total']['soumise'], $d['total']['not_collected'], $d['total']['coverage_rate']]);
        $this->assertSame(422, $this->call('GET', '/stats/progress', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'level' => 'village'])->status);
        $this->assertSame(422, $this->call('GET', '/stats/progress', token: self::$adminToken)->status, 'campaign_id obligatoire');
    }

    public function testIndicatorsDependOnTheBasis(): void
    {
        $validated = $this->call('GET', '/stats/indicators', token: self::$adminToken,
            query: ['campaign_id' => (string) $this->cid(), 'basis' => 'validated', 'group_by' => 'region'])->body['data'];
        $this->assertSame(2, $validated['structures']['total']);
        $this->assertSame(60, $validated['apprentices']['total'], '30 apprentis par collecte de référence');
        $this->assertSame(4, $validated['trainers']['total']);
        $this->assertSame(15.0, $validated['trainers']['apprentices_per_trainer']);
        $this->assertSame(['LE3', 'M4_9'], array_column($validated['apprentices']['by_duration'], 'key'));
        $this->assertSame('Couture', $validated['apprentices']['top_trades'][0]['label']);
        $this->assertNotEmpty($validated['yes_no']);
        $q110 = array_values(array_filter($validated['multiple_choice'], static fn($q) => $q['code'] === 'Q1_10'))[0];
        $this->assertSame(100.0, $q110['options'][0]['rate']);
        $this->assertSame([2], array_column($validated['by_territory'], 'structures'), 'Les 2 validées sont dans REGION TEST');

        $submitted = $this->call('GET', '/stats/indicators', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'basis' => 'submitted'])->body['data'];
        $this->assertSame(3, $submitted['structures']['total']);
        $this->assertSame(90, $submitted['apprentices']['total']);
        $this->assertSame(422, $this->call('GET', '/stats/indicators', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'basis' => 'tout'])->status);
    }

    public function testSteeringOverview(): void
    {
        $r = $this->call('GET', '/stats/overview', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid()]);
        $this->assertSame(200, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $o = $r->body['data'];
        $this->assertSame([3, 3, 100.0, 1, 66.7], [$o['targeted'], $o['collected'], $o['coverage_rate'], $o['pending'], $o['validated_rate']]);
        $this->assertSame(['SOUMISE' => 1, 'EN_CONTROLE' => 0, 'VALIDEE' => 2, 'INVALIDEE' => 0, 'REOUVERTE' => 0], $o['by_status']);
        $this->assertSame(0, $o['pace']['remaining']);
        $this->assertSame(3, $o['pace']['last7'], "Les 3 collectes ont été reçues aujourd'hui");
        $this->assertCount(30, $o['pace']['daily']);
        $this->assertSame(0.0, $o['review']['first_pass_rejection_rate'], 'Aucun refus au premier contrôle');
        $this->assertSame(['MOBILE' => 3, 'WEB' => 0, 'PAPIER' => 0], $o['channels']);
        $this->assertSame(['REGION DEUX', 'REGION TEST'], array_column($o['regions'], 'name'));
        // Périmètre : un superviseur départemental ne voit que son département.
        $sup = $this->tokenFor($this->createUser('SUP_DEP', scopes: [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]));
        $mine = $this->call('GET', '/stats/overview', token: $sup, query: ['campaign_id' => (string) $this->cid()])->body['data'];
        $this->assertSame([1, 1], [$mine['targeted'], $mine['collected']]);
        $this->assertSame(403, $this->call('GET', '/stats/overview', token: $this->tokenFor($this->createUser('AGENT')), query: ['campaign_id' => (string) $this->cid()])->status);

        // Répartition des structures par zone et statut juridique (questions 1.7 et 1.8, libellés du questionnaire).
        $i = $this->call('GET', '/stats/indicators', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'basis' => 'submitted'])->body['data'];
        $this->assertSame(3, array_sum(array_column($i['structures']['by_zone'], 'count')));
        $this->assertNotSame($i['structures']['by_legal_status'][0]['code'], $i['structures']['by_legal_status'][0]['label'], 'Libellé et non code');
    }

    public function testStatisticsRespectTheTerritorialScope(): void
    {
        $sup = $this->tokenFor($this->createUser('SUP_DEP', scopes: [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]));
        $i = $this->call('GET', '/stats/indicators', token: $sup, query: ['campaign_id' => (string) $this->cid(), 'basis' => 'submitted'])->body['data'];
        $this->assertSame(1, $i['structures']['total'], 'Seule la structure du département 202');
        $p = $this->call('GET', '/stats/progress', token: $sup, query: ['campaign_id' => (string) $this->cid()])->body['data'];
        $this->assertSame(['REGION DEUX'], array_column($p['rows'], 'name'));
        $this->assertSame(403, $this->call('GET', '/stats/indicators', token: $this->tokenFor($this->createUser('AGENT')), query: ['campaign_id' => (string) $this->cid()])->status);
    }

    public function testAgentsTimelineAndComparison(): void
    {
        $agents = $this->call('GET', '/stats/agents', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid()])->body['data'];
        $this->assertCount(3, $agents);
        $this->assertSame(100.0, $agents[0]['completion_rate']);
        $timeline = $this->call('GET', '/stats/timeline', token: self::$adminToken, query: ['campaign_id' => (string) $this->cid(), 'granularity' => 'week'])->body['data'];
        $last = end($timeline['series']);
        $this->assertSame([3, 2], [$last['submitted_cumulative'], $last['validated_cumulative']]);

        $other = $this->collectedCampaign(['10102'], 1);
        $cmp = $this->call('GET', '/stats/compare', token: self::$adminToken, query: ['campaign_ids' => "{$this->cid()},{$other['id']}", 'basis' => 'validated'])->body['data'];
        $this->assertTrue($cmp['comparable']);
        $this->assertSame([2, 1], array_column($cmp['campaigns'], 'structures'));
        $this->assertSame([60, 30], array_column($cmp['campaigns'], 'apprentices'));
        $this->assertSame(422, $this->call('GET', '/stats/compare', token: self::$adminToken, query: ['campaign_ids' => (string) $this->cid()])->status);
        $this->assertSame(422, $this->call('GET', '/stats/compare', token: self::$adminToken, query: ['campaign_ids' => '1,x'])->status);
    }

    // ------------------------------------------------------------------ Exports

    private function export(string $dataset, string $format, array $filters = [], ?string $token = null): array
    {
        $r = $this->call('POST', '/exports', ['dataset' => $dataset, 'format' => $format, 'filters' => ['campaign_id' => $this->cid()] + $filters], $token ?? self::$adminToken);
        $this->assertSame(202, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        return $r->body['data'];
    }

    private function downloaded(array $job, ?string $token = null): string
    {
        $this->assertSame('TERMINE', $job['status'], (string) $job['error']);
        $r = $this->call('GET', '/files/' . $job['file']['uuid'], token: $token ?? self::$adminToken);
        $this->assertSame(200, $r->status);
        return $r->filePath;
    }

    public function testMicrodataExcelExportExcludesPersonalData(): void
    {
        $job = $this->export('collections', 'xlsx', ['basis' => 'validated']);
        $this->assertSame(2, $job['rows']);
        $path = $this->downloaded($job);
        $book = IOFactory::load($path);
        $names = $book->getSheetNames();
        $this->assertSame('donnees_generale_centre', $names[0], 'Feuille principale renommée');
        foreach (['T01_centres_type', 'T03_apprentis_sexe', 'T06_apprentis_metier', 'T08_formateurs', 'T10_equipements', 'T11_financement','formateurs', 'metiers_apprentis', 'dictionnaire_variables', 'lisez_moi'] as $sheet) {
            $this->assertContains($sheet, $names);
        }
        $rows = $book->getSheet(0)->toArray();
        $this->assertCount(3, $rows, 'En-tête + 2 collectes validées');
        $header = $rows[0];
        $funding = array_values(array_filter($header, static fn($h) => str_starts_with((string) $h, '1.10 ')))[0] ?? null;
        $this->assertNotNull($funding, 'Colonnes nommées en clair (numéro + libellé de la question)');
        $this->assertContains('Nom du centre', $header);
        $this->assertContains('Total apprentis (section IV)', $header);
        foreach (['Q1_10', 'Q5_REP_NOM', 'Q5_REP_TEL', 'Q5_CHEF_NOM', 'Q5_CHEF_TEL'] as $code) {
            $this->assertNotContains($code, $header);
        }
        $this->assertSame([], array_filter($header, static fn($h) => str_starts_with((string) $h, '5.') && preg_match('/nom|t[ée]l[ée]phone/i', (string) $h)),
            'Noms et téléphones des répondants exclus');
        $row = array_combine($header, $rows[1]);
        $this->assertStringContainsString(' ; ', (string) $row[$funding], 'Plusieurs modalités en clair');
        $this->assertDoesNotMatchRegularExpression('/^[a-z](\|[a-z])*$/', (string) $row[$funding], 'Libellés et non codes');
        $this->assertEquals(30, $row['Total apprentis (section IV)']);
        // Feuille principale déclarée comme « tableau Excel » (source de TCD).
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertStringContainsString('displayName="donnees_generale_centre"', (string) $zip->getFromName('xl/tables/table1.xml'));
        $zip->close();
        // Tableaux d'annuaire : ligne Total et cohérence avec les données (60 apprentis validés).
        // Chaque tableau porte Région / Département / Commune, avec sous-totaux département et région.
        foreach ($names as $name) {
            if (preg_match('/^T\d\d_/', $name) === 1) {
                $this->assertSame(['Région', 'Département', 'Commune'], array_slice($book->getSheetByName($name)->toArray()[0], 0, 3), $name);
            }
        }
        $t03 = $book->getSheetByName('T03_apprentis_sexe')->toArray();
        $this->assertSame('Total', end($t03)[0]);
        $this->assertEquals(60, end($t03)[6], 'Total des apprentis validés');
        $communeRows = array_filter(array_slice($t03, 1), static fn($r) => $r[0] !== 'Total' && $r[1] !== 'Ensemble de la région' && $r[2] !== 'Ensemble du département');
        $this->assertNotEmpty($communeRows);
        $this->assertEquals(60, array_sum(array_column($communeRows, 6)), 'Somme des communes = total');
        $regionRows = array_filter($t03, static fn($r) => $r[1] === 'Ensemble de la région');
        $this->assertEquals(60, array_sum(array_column($regionRows, 6)), 'Somme des régions = total');
        $this->assertContains('Ensemble du département', array_column($t03, 2));
        $trainers = $book->getSheetByName('formateurs')->toArray();
        $this->assertCount(5, $trainers, 'En-tête + 4 formateurs');
        $this->assertContains('Homme', array_column($trainers, 4));
    }

    public function testCsvExportNeutralisesFormulas(): void
    {
        $content = (string) file_get_contents($this->downloaded($this->export('collections', 'csv', ['basis' => 'validated'])));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 avec BOM pour Excel');
        $this->assertStringContainsString(";'=CMD|calc;", $content, 'Formule neutralisée par une apostrophe');
        $this->assertStringNotContainsString(';=CMD', $content);
    }

    public function testPdfAndProgressExports(): void
    {
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($this->downloaded($this->export('indicators', 'pdf'))));
        $progress = IOFactory::load($this->downloaded($this->export('progress', 'xlsx', ['level' => 'department'])))->getSheet(0)->toArray();
        $this->assertSame('Total', end($progress)[0]);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($this->downloaded($this->export('progress', 'pdf'))));
        $agents = trim((string) file_get_contents($this->downloaded($this->export('agents', 'csv'))));
        $this->assertSame(4, substr_count($agents, "\n") + 1, 'En-tête + 3 agents');
        $this->assertSame(422, $this->call('POST', '/exports', ['dataset' => 'collections', 'format' => 'pdf', 'filters' => ['campaign_id' => $this->cid()]], self::$adminToken)->status);
        $this->assertSame(404, $this->call('POST', '/exports', ['dataset' => 'progress', 'format' => 'xlsx', 'filters' => ['campaign_id' => 999999]], self::$adminToken)->status);
    }

    public function testExportFilesBelongToTheirRequesterAndExpire(): void
    {
        $job = $this->export('agents', 'xlsx');
        $other = $this->tokenFor($this->createUser('ADMIN'));
        $this->assertSame(404, $this->call('GET', '/files/' . $job['file']['uuid'], token: $other)->status, 'Un autre utilisateur ne télécharge pas le fichier');
        $this->assertSame(404, $this->call('GET', "/jobs/{$job['id']}", token: $other)->status);
        self::$db->execute('UPDATE files SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE uuid = ?', [$job['file']['uuid']]);
        $this->assertSame(410, $this->call('GET', '/files/' . $job['file']['uuid'], token: self::$adminToken)->status);
        $this->assertNull($this->call('GET', "/jobs/{$job['id']}", token: self::$adminToken)->body['data']['file'], 'Plus de lien une fois expiré');
        $this->assertContains($job['id'], array_column($this->call('GET', '/exports', token: self::$adminToken)->body['data'], 'id'));
    }

    public function testQueuedExportsAreProcessedByTheWorkerOnlyOnce(): void
    {
        $config = self::$config;
        $config['exports']['inline'] = false;
        $service = new ExportService(self::$db, $config);
        $adminId = $this->userId($this->createUser('ADMIN'));
        $job = $service->request($adminId, ['dataset' => 'progress', 'format' => 'csv', 'filters' => ['campaign_id' => $this->cid()]], '127.0.0.1');
        $this->assertSame('EN_ATTENTE', $job['status']);
        $this->assertGreaterThanOrEqual(1, $service->runPending());
        $this->assertSame('TERMINE', $service->job($adminId, $job['id'])['status']);
        $this->assertFalse($service->process($job['id']), 'Une tâche terminée ne se retraite pas');

        // Compte désactivé entre la demande et le traitement : export refusé.
        $uid = $this->userId($this->createUser('VIEWER'));
        $queued = $service->request($uid, ['dataset' => 'agents', 'format' => 'csv', 'filters' => ['campaign_id' => $this->cid()]], '127.0.0.1');
        self::$db->execute("UPDATE users SET status = 'INACTIF' WHERE id = ?", [$uid]);
        $service->runPending();
        $failed = $service->job($uid, $queued['id']);
        $this->assertSame('ECHEC', $failed['status']);
        $this->assertNull($failed['file']);
    }

    public function testStreamingWriterColumnsAndValidWorkbook(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA'], array_map([XlsxStreamWriter::class, 'column'], [0, 25, 26, 51, 52, 701, 702]));
        $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        $w = new XlsxStreamWriter($path);
        $w->addSheet('Feuille: 1/2', ['texte', 'nombre', 'vide', 'booléen']);
        $w->addRow(["=1+1 <b>&\"é\x01", 3.5, null, true]);
        $w->close();
        $rows = IOFactory::load($path)->getSheet(0)->toArray();
        $this->assertSame('=1+1 <b>&"é', $rows[1][0], 'Texte conservé tel quel (jamais interprété comme formule), caractères de contrôle retirés');
        $this->assertEquals(3.5, $rows[1][1]);
        @unlink($path);
    }
}
