<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Text;

/**
 * Mesure (à la demande) du temps de traitement d'une soumission par le serveur, dans la base de test.
 *
 * Lancement : EFTP_PERF=1 [EFTP_PERF_N=300] php vendor/bin/phpunit --bootstrap vendor/autoload.php --filter SubmissionThroughput tests
 *
 * Ce que la mesure couvre : contrôle complet du questionnaire, écriture de la collecte et de ses tables d'analyse,
 * journalisation — le traitement serveur de bout en bout, appelé en processus (sans Apache ni réseau).
 * Ce qu'elle ne couvre pas : coût HTTP/TLS, concurrence réelle de nombreux clients, volumétrie de production.
 * Ce n'est donc pas un test de charge (prévu au lot L8) ; elle sert à dimensionner et à détecter une régression.
 */
final class SubmissionThroughputTest extends ApiTestCase
{
    public function testMeasureSubmissionProcessingTime(): void
    {
        if (getenv('EFTP_PERF') !== '1') {
            $this->markTestSkipped('Mesure de performance : définir EFTP_PERF=1 pour la lancer.');
        }
        $n = max(10, (int) (getenv('EFTP_PERF_N') ?: 300));
        foreach ([[1, 'PRIMAIRE', 'Primaire'], [2, 'SECONDAIRE', 'Secondaire'], [3, 'TERTIAIRE', 'Tertiaire']] as [$id, $code, $label]) {
            self::$db->execute('INSERT IGNORE INTO sectors (id, code, label, sort) VALUES (?, ?, ?, ?)', [$id, $code, $label, $id]);
        }
        self::$db->execute("INSERT IGNORE INTO trades (id, sector_id, label) VALUES (41, 3, 'Couture'), (1, 1, 'Maraîchage'), (15, 2, 'Soudure')");
        $fixture = json_decode((string) file_get_contents(self::$config['paths']['shared'] . '/rules-fixtures/eftp-nf-2025.1.json'), true);
        $base = $fixture['base'];
        unset($base['_ctx']);
        $base['Q5_DATE'] = date('Y-m-d');
        $base['GPS']['captured_at'] = date('c');

        $admin = $this->tokenFor($this->createUser('ADMIN'));
        $yearId = $this->call('POST', '/academic-years', ['code' => '2600-2601', 'start_date' => '2600-10-01', 'end_date' => '2601-09-30'], $admin)->body['data']['id'];
        $campaign = $this->call('POST', '/campaigns', ['academic_year_id' => $yearId, 'code' => 'PERF-' . bin2hex(random_bytes(3)), 'name' => 'Mesure',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+30 days'))], $admin)->body['data'];

        // Structures et affectations (10 agents) : préparation hors mesure.
        $agents = [];
        for ($a = 0; $a < 10; $a++) {
            $m = $this->createUser('AGENT');
            $agents[] = ['uuid' => $this->uuidOf($m), 'token' => $this->tokenFor($m), 'structures' => []];
        }
        for ($i = 0; $i < $n; $i++) {
            $s = $this->call('POST', '/structures', ['official_code' => (string) (6000000000 + $i), 'name' => "Centre de mesure $i " . bin2hex(random_bytes(3)),
                'type_code' => 'c', 'zone' => 'URBAINE', 'legal_status' => 'PRIVE', 'commune_id' => self::communeId('10101'), 'confirm_duplicates' => true], $admin);
            $this->assertSame(201, $s->status, json_encode($s->body, JSON_UNESCAPED_UNICODE));
            $agents[$i % 10]['structures'][] = $s->body['data']['uuid'];
        }
        $this->call('POST', "/campaigns/{$campaign['id']}/structures", ['structure_uuids' => array_merge(...array_column($agents, 'structures'))], $admin);
        $this->assertSame(200, $this->call('POST', "/campaigns/{$campaign['id']}/transition", ['to' => 'OUVERTE'], $admin)->status);
        $jobs = [];
        foreach ($agents as $ag) {
            $this->call('POST', "/campaigns/{$campaign['id']}/assignments", ['agent_uuid' => $ag['uuid'], 'structure_uuids' => $ag['structures']], $admin);
            foreach ($ag['structures'] as $su) {
                $jobs[] = [(int) self::$db->fetchValue('SELECT cs.id FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id WHERE s.uuid = ?', [$su]), $ag['token']];
            }
        }

        // Mesure : une soumission complète par structure.
        $times = [];
        foreach ($jobs as [$cs, $token]) {
            $t = hrtime(true);
            $r = $this->call('POST', '/collections/' . Text::uuid4() . '/submissions', ['campaign_structure_id' => $cs, 'questionnaire_version' => '2025.1',
                'base_revision' => 0, 'device_started_at' => date('c', time() - 3600), 'device_completed_at' => date('c'), 'data' => $base], $token, ['x-app-version' => '1.0.0']);
            $times[] = (hrtime(true) - $t) / 1e6;
            $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        }
        sort($times);
        $avg = array_sum($times) / count($times);
        $perSecond = 1000 / $avg;
        fwrite(STDERR, sprintf(
            "\n[Mesure] %d soumissions : moyenne %.1f ms, médiane %.1f ms, p95 %.1f ms, max %.1f ms — ≈ %.0f soumissions/s pour un seul processus PHP (≈ %s par heure).\n",
            count($times), $avg, $times[intdiv(count($times), 2)], $times[(int) floor(0.95 * (count($times) - 1))], end($times), $perSecond,
            number_format($perSecond * 3600, 0, ',', ' ')));
        $this->assertGreaterThan(0, $perSecond);
    }
}
