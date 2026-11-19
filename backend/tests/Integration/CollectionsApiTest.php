<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Text;
use App\Http\Response;
use App\Http\UploadedFile;
use App\Modules\Collections\SubmissionService;

/**
 * Soumission idempotente, synchronisation et validation à deux niveaux (lot L4).
 * Les données soumises reprennent la collecte de référence des vecteurs de test partagés
 * (shared/rules-fixtures/eftp-nf-2025.1.json), contrôlée à l'identique par le serveur.
 */
final class CollectionsApiTest extends ApiTestCase
{
    private static string $adminToken;
    private static array $base;
    private static int $code = 3000000000;
    private static int $year = 2200;

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
        $base['GPS']['captured_at'] = date('c');
        self::$base = $base;
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    // ------------------------------------------------------------------ Mise en place

    /** Campagne ouverte avec une structure en commune 10101 affectée à un nouvel agent. */
    private function setUpCollection(int $levels = 2): array
    {
        $y = ++self::$year;
        $yearId = $this->call('POST', '/academic-years', ['code' => "$y-" . ($y + 1), 'start_date' => "$y-10-01", 'end_date' => ($y + 1) . '-09-30'], self::$adminToken)->body['data']['id'];
        $structure = $this->call('POST', '/structures', ['official_code' => (string) (++self::$code), 'name' => 'Atelier ' . bin2hex(random_bytes(4)),
            'type_code' => 'c', 'zone' => 'URBAINE', 'legal_status' => 'PRIVE', 'commune_id' => self::communeId('10101')], self::$adminToken)->body['data'];
        $campaign = $this->call('POST', '/campaigns', ['academic_year_id' => $yearId, 'code' => 'L4-' . bin2hex(random_bytes(3)), 'name' => 'Collecte test',
            'questionnaire_version' => '2025.1', 'opens_on' => date('Y-m-d'), 'closes_on' => date('Y-m-d', strtotime('+30 days')), 'validation_levels' => $levels],
            self::$adminToken)->body['data'];
        $this->call('POST', "/campaigns/{$campaign['id']}/structures", ['structure_uuids' => [$structure['uuid']]], self::$adminToken);
        $this->assertSame(200, $this->call('POST', "/campaigns/{$campaign['id']}/transition", ['to' => 'OUVERTE'], self::$adminToken)->status);
        $agent = $this->createUser('AGENT');
        $this->call('POST', "/campaigns/{$campaign['id']}/assignments", ['agent_uuid' => $this->uuidOf($agent), 'structure_uuids' => [$structure['uuid']]], self::$adminToken);
        $csId = (int) self::$db->fetchValue('SELECT cs.id FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id WHERE s.uuid = ?', [$structure['uuid']]);
        return ['campaign' => $campaign, 'structure' => $structure, 'agent' => $agent, 'token' => $this->tokenFor($agent), 'cs' => $csId, 'uuid' => Text::uuid4()];
    }

    private function submit(array $ctx, array $data = [], int $base = 0, ?string $uuid = null, ?string $token = null, string $version = '2025.1'): Response
    {
        return $this->call('POST', '/collections/' . ($uuid ?? $ctx['uuid']) . '/submissions', [
            'campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => $version, 'base_revision' => $base,
            'device_started_at' => date('c', time() - 3600), 'device_completed_at' => date('c'),
            'data' => $data + self::$base,
        ], $token ?? $ctx['token'], ['x-app-version' => '1.0.0']);
    }

    // ------------------------------------------------------------------ Soumission

    public function testSubmissionIsValidatedStoredAndIdempotent(): void
    {
        $ctx = $this->setUpCollection();
        $r = $this->submit($ctx, ['Q1_1' => 'Nom modifié sur la tablette']);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['uuid' => $ctx['uuid'], 'status' => 'SOUMISE', 'revision' => 1], array_intersect_key($r->body['data'], array_flip(['uuid', 'status', 'revision'])));
        $this->assertFalse($r->body['data']['replayed']);

        $id = (int) self::$db->fetchValue('SELECT id FROM collections WHERE uuid = ?', [$ctx['uuid']]);
        $this->assertSame(2, (int) self::$db->fetchValue('SELECT COUNT(*) FROM collection_trainers WHERE collection_id = ?', [$id]));
        $this->assertSame(2, (int) self::$db->fetchValue('SELECT COUNT(*) FROM collection_trades WHERE collection_id = ?', [$id]));
        $this->assertSame('Teinture traditionnelle', self::$db->fetchValue('SELECT trade_other_label FROM collection_trades WHERE collection_id = ? AND trade_id IS NULL', [$id]));
        $this->assertSame(9, (int) self::$db->fetchValue('SELECT COUNT(*) FROM collection_accompanied WHERE collection_id = ?', [$id]));
        $this->assertSame(12, (int) self::$db->fetchValue('SELECT COUNT(*) FROM collection_sector_specials WHERE collection_id = ?', [$id]));
        $this->assertEqualsCanonicalizing(['c', 'g'], array_column(self::$db->fetchAll(
            "SELECT option_code FROM collection_answers WHERE collection_id = ? AND question_code = 'Q1_10'", [$id]), 'option_code'));
        $this->assertSame(30.0, (float) self::$db->fetchValue("SELECT value_number FROM collection_answers WHERE collection_id = ? AND question_code = 'APPRENTICES_TOTAL'", [$id]),
            'Les variables calculées sont enregistrées (30 apprentis dans la collecte de référence).');
        $this->assertSame(30, (int) self::$db->fetchValue('SELECT SUM(total) FROM v_q2_1_apprentices WHERE collection_id = ?', [$id]), 'La vue Q2.1 donne le même total.');

        // Le nom vient toujours du registre, jamais de la tablette ; l'écart est tracé.
        $stored = json_decode((string) self::$db->fetchValue('SELECT payload_json FROM collection_submissions WHERE collection_id = ?', [$id]), true);
        $this->assertSame($ctx['structure']['name'], $stored['data']['Q1_1']);
        $this->assertSame($ctx['structure']['official_code'], $stored['data']['Q1_2']);
        $report = json_decode((string) self::$db->fetchValue('SELECT validation_report FROM collection_submissions WHERE collection_id = ?', [$id]), true);
        $this->assertContains('Q1_1', $report['locked_overrides']);

        // Rejeu (accusé perdu sur le réseau) : 200, accusé initial, aucune nouvelle révision.
        $again = $this->submit($ctx, ['Q1_1' => 'Nom modifié sur la tablette']);
        $this->assertSame(200, $again->status);
        $this->assertTrue($again->body['data']['replayed']);
        $this->assertSame(1, $again->body['data']['revision']);
        $this->assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM collection_submissions WHERE collection_id = ?', [$id]));
        $this->assertSame(['ACCEPTE', 'REJOUE'], array_column(self::$db->fetchAll('SELECT outcome FROM sync_logs WHERE collection_uuid = ? ORDER BY id', [$ctx['uuid']]), 'outcome'));
    }

    /** Montée de version en cours de campagne : chaque collecte garde sa version ; les nouvelles prennent la plus récente. */
    public function testCampaignUpgradeKeepsOldCollectionsCorrectableAndUnblocksNewOnes(): void
    {
        $ctx = $this->setUpCollection();
        $this->assertSame(201, $this->submit($ctx)->status);
        $this->assertSame(200, $this->call('PATCH', "/campaigns/{$ctx['campaign']['id']}", ['questionnaire_version' => '2025.3'], self::$adminToken)->status);

        // Correction d'une collecte déjà reçue (renvoyée par le superviseur) : sa version 2025.1 reste acceptée.
        self::$db->execute("UPDATE collections SET status = 'INVALIDEE' WHERE uuid = ?", [$ctx['uuid']]);
        $fix = $this->submit($ctx, ['Q1_6_4' => 'Quartier corrigé'], 1);
        $this->assertSame(201, $fix->status, json_encode($fix->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('2025.1', self::$db->fetchValue(
            'SELECT v.version_code FROM collections c JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id WHERE c.uuid = ?', [$ctx['uuid']]));

        // Nouvelle structure d'une campagne passée en 2025.3 : version 2025.3 exigée, et plus aucune règle de cohérence bloquante.
        $other = $this->setUpCollection();
        $this->call('PATCH', "/campaigns/{$other['campaign']['id']}", ['questionnaire_version' => '2025.3'], self::$adminToken);
        $this->assertSame(409, $this->submit($other)->status, 'Version périmée pour une nouvelle collecte.');
        $r = $this->submit($other, ['sectors' => ['s1' => ['trades' => []], 's2' => ['trades' => []], 's3' => ['trades' => []]], 'Q5_OBS' => null], 0, null, null, '2025.3');
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $this->assertContains('R_NO_APPRENTICE', array_column($r->body['data']['warnings'], 'code'), 'Aucun apprenti : avertissement, plus un blocage.');
    }

    public function testMissingAnswerIsAcceptedWithAWarning(): void
    {
        $ctx = $this->setUpCollection();
        $r = $this->submit($ctx, ['Q1_4' => null]);
        $this->assertSame(201, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $warnings = array_filter($r->body['data']['warnings'], static fn($w) => $w['code'] === 'REQUIRED');
        $this->assertSame(['Q1_4'], array_values(array_column($warnings, 'target')), 'La question vide est signalée au contrôleur.');
    }

    public function testInvalidDataIsRefusedWithTheEngineErrors(): void
    {
        $ctx = $this->setUpCollection();
        $r = $this->submit($ctx, ['Q1_4' => null, 'Q2_11_1' => -3]);
        $this->assertSame(422, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $targets = array_column($r->body['error']['details']['errors'], 'target');
        $this->assertContains('Q2_11_1', $targets, 'Valeur invalide : refusée.');
        $this->assertNotContains('Q1_4', $targets, 'Réponse manquante : simple avertissement, jamais bloquante.');
        $this->assertContains('Q1_4', array_column(array_filter($r->body['error']['details']['warnings'] ?? [], static fn($w) => $w['code'] === 'REQUIRED'), 'target'));
        $this->assertNull(self::$db->fetchValue('SELECT id FROM collections WHERE uuid = ?', [$ctx['uuid']]), "Rien n'est enregistré.");
        $this->assertSame('REFUSE', self::$db->fetchValue('SELECT outcome FROM sync_logs WHERE collection_uuid = ?', [$ctx['uuid']]));

        // Un métier hors de la nomenclature est refusé (liste de référence fournie par le serveur).
        $bad = self::$base;
        $bad['sectors']['s3']['trades'][0]['trade'] = 999;
        $this->assertSame(422, $this->call('POST', "/collections/{$ctx['uuid']}/submissions", ['campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => '2025.1',
            'base_revision' => 0, 'data' => $bad], $ctx['token'])->status);
    }

    public function testOnlyTheAssignedAgentSubmitsWhileTheCampaignIsOpen(): void
    {
        $ctx = $this->setUpCollection();
        $other = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame('OUT_OF_SCOPE', $this->submit($ctx, token: $other)->body['error']['code']);
        $this->assertSame('QUESTIONNAIRE_VERSION_MISMATCH', $this->submit($ctx, version: '2024.1')->body['error']['code']);

        $this->call('POST', "/campaigns/{$ctx['campaign']['id']}/transition", ['to' => 'SUSPENDUE', 'reason' => 'Intempéries'], self::$adminToken);
        $this->assertSame('CAMPAIGN_SUSPENDED', $this->submit($ctx)->body['error']['code']);
        $this->call('POST', "/campaigns/{$ctx['campaign']['id']}/transition", ['to' => 'OUVERTE'], self::$adminToken);
        $this->assertSame(201, $this->submit($ctx)->status);

        // Après clôture : aucune nouvelle soumission, mais le rejeu d'une soumission acceptée reçoit toujours son accusé.
        $this->call('POST', "/campaigns/{$ctx['campaign']['id']}/transition", ['to' => 'CLOTUREE', 'reason' => 'Fin'], self::$adminToken);
        $this->assertSame(200, $this->submit($ctx)->status);
        $this->assertSame('CAMPAIGN_CLOSED', $this->submit($ctx, ['Q2_20_H' => 5], 1)->body['error']['code']);
    }

    public function testConflictsAndLockedCollections(): void
    {
        $ctx = $this->setUpCollection();
        $this->assertSame(201, $this->submit($ctx)->status);
        $changed = ['Q2_20_H' => 3];
        $this->assertSame('REVISION_CONFLICT', $this->submit($ctx, $changed, 0)->body['error']['code'], 'Révision de base périmée');
        $locked = $this->submit($ctx, $changed, 1);
        $this->assertSame(423, $locked->status, "Collecte remise : plus modifiable par l'agent");
        $this->assertSame('LOCKED', $locked->body['error']['code']);
        $dup = $this->submit($ctx, uuid: Text::uuid4());
        $this->assertSame('COLLECTION_EXISTS', $dup->body['error']['code'], 'Une seule collecte par structure');
        $this->assertSame($ctx['uuid'], $dup->body['error']['details']['uuid']);
    }

    public function testCanonicalHashIgnoresKeyOrder(): void
    {
        $this->assertSame(SubmissionService::canonicalJson(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]),
            SubmissionService::canonicalJson(['a' => ['x' => [3, 1], 'y' => 2], 'b' => 1]));
        $this->assertNotSame(SubmissionService::canonicalJson(['a' => [1, 3]]), SubmissionService::canonicalJson(['a' => [3, 1]]), "L'ordre des listes compte.");
    }

    // ------------------------------------------------------------------ Validation à deux niveaux

    public function testTwoLevelValidationWithDistinctReviewers(): void
    {
        $ctx = $this->setUpCollection(2);
        $this->submit($ctx);
        $u = $ctx['uuid'];
        $supDep = $this->tokenFor($this->createUser('SUP_DEP'));
        $supDep2 = $this->tokenFor($this->createUser('SUP_DEP'));
        $supReg = $this->tokenFor($this->createUser('SUP_REG'));

        $awaiting = $this->call('GET', '/collections', token: $supDep, query: ['awaiting_me' => '1'])->body['data'];
        $this->assertContains($u, array_column($awaiting, 'uuid'));

        $claimed = $this->call('POST', "/collections/$u/claim", token: $supDep);
        $this->assertSame('EN_CONTROLE', $claimed->body['data']['status'], json_encode($claimed->body, JSON_UNESCAPED_UNICODE));
        $this->assertContains('validate', $claimed->body['data']['allowed_actions']);
        $this->assertSame('ALREADY_CLAIMED', $this->call('POST', "/collections/$u/validate", [], $supDep2)->body['error']['code'], 'Un seul contrôleur à la fois');

        $l1 = $this->call('POST', "/collections/$u/validate", ['comment' => 'RAS'], $supDep);
        $this->assertSame(['SOUMISE', 2], [$l1->body['data']['status'], $l1->body['data']['review_level']]);
        $this->assertSame(403, $this->call('POST', "/collections/$u/validate", [], $supDep2)->status, 'Le niveau 2 est régional');

        $l2 = $this->call('POST', "/collections/$u/validate", [], $supReg);
        $this->assertSame('VALIDEE', $l2->body['data']['status']);
        $this->assertSame([1, 2], array_map('intval', array_column($l2->body['data']['reviews'], 'level')));
        $this->assertSame(['SOUMISE', 'EN_CONTROLE', 'SOUMISE', 'VALIDEE'], array_column($l2->body['data']['status_history'], 'to_status'));
    }

    public function testSameUserCannotValidateBothLevels(): void
    {
        $ctx = $this->setUpCollection(2);
        $this->submit($ctx);
        $supReg = $this->tokenFor($this->createUser('SUP_REG'));
        $this->assertSame(2, $this->call('POST', "/collections/{$ctx['uuid']}/validate", [], $supReg)->body['data']['review_level']);
        $this->assertSame('SAME_REVIEWER', $this->call('POST', "/collections/{$ctx['uuid']}/validate", [], $supReg)->body['error']['code']);
    }

    public function testBatchValidationAppliesTheSameRulesAndReportsRefusals(): void
    {
        $a = $this->setUpCollection(1);
        $b = $this->setUpCollection(1);
        $this->submit($a);
        $this->submit($b);
        $supDep = $this->tokenFor($this->createUser('SUP_DEP'));
        $other = $this->tokenFor($this->createUser('SUP_DEP'));
        $this->call('POST', "/collections/{$b['uuid']}/claim", token: $other); // prise en charge par un autre contrôleur

        $r = $this->call('POST', '/collections/validate-batch', ['uuids' => [$a['uuid'], $b['uuid'], 'inconnue'], 'comment' => 'Contrôle groupé'], $supDep);
        $this->assertSame(200, $r->status, json_encode($r->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$a['uuid']], $r->body['data']['validated']);
        $this->assertEqualsCanonicalizing([$b['uuid'], 'inconnue'], array_column($r->body['data']['failed'], 'uuid'), 'Refus détaillés, sans bloquer les autres.');
        $this->assertSame('VALIDEE', self::$db->fetchValue('SELECT status FROM collections WHERE uuid = ?', [$a['uuid']]));
        $this->assertSame('Contrôle groupé', self::$db->fetchValue(
            'SELECT r.comment FROM collection_reviews r JOIN collections c ON c.id = r.collection_id WHERE c.uuid = ?', [$a['uuid']]));
        $this->assertSame(422, $this->call('POST', '/collections/validate-batch', ['uuids' => []], $supDep)->status);
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(403, $this->call('POST', '/collections/validate-batch', ['uuids' => [$a['uuid']]], $agent)->status);
    }

    public function testSingleLevelCampaignValidatesAtDepartmentLevel(): void
    {
        $ctx = $this->setUpCollection(1);
        $this->submit($ctx);
        $supDep = $this->tokenFor($this->createUser('SUP_DEP'));
        $this->assertSame('VALIDEE', $this->call('POST', "/collections/{$ctx['uuid']}/validate", [], $supDep)->body['data']['status']);
    }

    public function testRegionalSupervisorOpensCorrectsThenValidates(): void
    {
        $ctx = $this->setUpCollection(1); // circuit à un niveau : superviseur régional
        $this->submit($ctx);
        $u = $ctx['uuid'];
        $supReg = $this->tokenFor($this->createUser('SUP_REG'));
        $other = $this->tokenFor($this->createUser('SUP_REG'));

        $view = $this->call('GET', "/collections/$u/edit", token: $supReg);
        $this->assertSame(200, $view->status, json_encode($view->body, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($view->body['data']['editable']);
        $data = $view->body['data']['data'];
        $data['Q5_REP_NOM'] = 'Nom corrigé par le superviseur';
        $this->assertArrayHasKey('errors', $this->call('POST', "/collections/$u/check", ['data' => $data], $supReg)->body['data']['validation']);

        $fixed = $this->call('PUT', "/collections/$u/data", ['data' => $data, 'comment' => 'Nom du répondant'], $supReg);
        $this->assertSame(200, $fixed->status, json_encode($fixed->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['EN_CONTROLE', 2], [$fixed->body['data']['status'], $fixed->body['data']['revision']]);
        $this->assertSame('Nom corrigé par le superviseur', $fixed->body['data']['data']['Q5_REP_NOM']);
        $this->assertSame($ctx['agent'], self::$db->fetchValue('SELECT u.matricule FROM collections c JOIN users u ON u.id = c.agent_id WHERE c.uuid = ?', [$u]),
            "L'agent auteur ne change pas");
        $this->assertSame('ALREADY_CLAIMED', $this->call('PUT', "/collections/$u/data", ['data' => $data], $other)->body['error']['code']);
        $this->assertSame(403, $this->call('PUT', "/collections/$u/data", ['data' => $data], $ctx['token'])->status, "L'agent ne corrige pas par cette voie");

        $this->assertSame('VALIDEE', $this->call('POST', "/collections/$u/validate", [], $supReg)->body['data']['status']);
        $this->assertFalse($this->call('GET', "/collections/$u/edit", token: $supReg)->body['data']['editable'], 'Validée : lecture seule');
    }

    public function testInvalidationCorrectionAndResubmission(): void
    {
        $ctx = $this->setUpCollection(1);
        $this->submit($ctx);
        $u = $ctx['uuid'];
        $supDep = $this->tokenFor($this->createUser('SUP_DEP'));

        $this->assertSame(422, $this->call('POST', "/collections/$u/invalidate", ['comment' => 'Pas de motif'], $supDep)->status, 'Motif obligatoire');
        $this->assertSame(422, $this->call('POST', "/collections/$u/invalidate", ['reason_code' => 'AUTRE'], $supDep)->status, 'Motif « Autre » : commentaire obligatoire');
        $inv = $this->call('POST', "/collections/$u/invalidate", ['reason_code' => 'EFFECTIFS', 'comment' => 'Vérifier les effectifs de la couture'], $supDep);
        $this->assertSame('INVALIDEE', $inv->body['data']['status']);

        // La tablette reçoit le retour du superviseur et les données à corriger.
        $pull = $this->call('GET', '/sync/pull', token: $ctx['token'])->body['data'];
        $col = array_values(array_filter($pull['collections'], static fn($c) => $c['uuid'] === $u))[0];
        $this->assertTrue($col['editable']);
        $this->assertSame('EFFECTIFS', $col['review']['reason_code']);
        $this->assertSame('Vérifier les effectifs de la couture', $col['review']['comment']);
        $this->assertSame(2, $col['data']['Q2_20_H']);

        $fix = $this->submit($ctx, ['Q2_20_H' => 1], 1);
        $this->assertSame(201, $fix->status, json_encode($fix->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['SOUMISE', 2], [$fix->body['data']['status'], $fix->body['data']['revision']]);
        // Rejouer l'ancienne soumission renvoie son accusé d'origine sans rien écraser.
        $old = $this->submit($ctx, [], 0);
        $this->assertSame([200, 1], [$old->status, $old->body['data']['revision']]);
        $this->assertSame(2, (int) self::$db->fetchValue('SELECT revision FROM collections WHERE uuid = ?', [$u]));
        $this->assertCount(2, $this->call('GET', "/collections/$u/submissions", token: $supDep)->body['data']);
    }

    public function testReopeningIsReservedToTheAdministrator(): void
    {
        $ctx = $this->setUpCollection(1);
        $this->submit($ctx);
        $supDep = $this->tokenFor($this->createUser('SUP_DEP'));
        $this->call('POST', "/collections/{$ctx['uuid']}/validate", [], $supDep);
        $this->assertSame(403, $this->call('POST', "/collections/{$ctx['uuid']}/reopen", ['reason' => 'Erreur de saisie signalée'], $supDep)->status);
        $this->assertSame(422, $this->call('POST', "/collections/{$ctx['uuid']}/reopen", [], self::$adminToken)->status);
        $r = $this->call('POST', "/collections/{$ctx['uuid']}/reopen", ['reason' => 'Erreur de saisie signalée par la structure'], self::$adminToken);
        $this->assertSame('REOUVERTE', $r->body['data']['status']);
        $this->assertSame(201, $this->submit($ctx, ['Q2_20_F' => 4], 1)->status, "L'agent peut resoumettre une collecte rouverte.");
    }

    public function testReviewersOnlySeeTheirTerritory(): void
    {
        $ctx = $this->setUpCollection();
        $this->submit($ctx);
        $outside = $this->tokenFor($this->createUser('SUP_DEP', scopes: [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]));
        $this->assertSame(404, $this->call('GET', "/collections/{$ctx['uuid']}", token: $outside)->status);
        $this->assertSame(404, $this->call('POST', "/collections/{$ctx['uuid']}/validate", [], $outside)->status);
        $this->assertNotContains($ctx['uuid'], array_column($this->call('GET', '/collections', token: $outside)->body['data'], 'uuid'));
        $this->assertSame(403, $this->call('GET', '/collections', token: $ctx['token'])->status, "Un agent n'accède pas au back-office des collectes.");
        $viewer = $this->tokenFor($this->createUser('VIEWER'));
        $this->assertSame(200, $this->call('GET', "/collections/{$ctx['uuid']}", token: $viewer)->status);
        $this->assertSame(403, $this->call('POST', "/collections/{$ctx['uuid']}/claim", token: $viewer)->status);
    }

    // ------------------------------------------------------------------ Synchronisation

    public function testIncrementalPullReturnsChangesAndRemovedAssignments(): void
    {
        $ctx = $this->setUpCollection();
        $full = $this->call('GET', '/sync/pull', token: $ctx['token']);
        $this->assertSame(200, $full->status, json_encode($full->body, JSON_UNESCAPED_UNICODE));
        $d = $full->body['data'];
        $this->assertTrue($d['full']);
        $this->assertSame([$ctx['campaign']['id']], array_column($d['campaigns'], 'id'));
        $this->assertSame('a', $d['assignments'][0]['structure']['zone'], 'Identité codée comme dans le questionnaire (1.7 a = urbaine)');
        $this->assertSame('b', $d['assignments'][0]['structure']['legal_status']);
        $this->assertSame($ctx['cs'], $d['assignments'][0]['campaign_structure_id']);
        // Contexte de contrôle transmis à la tablette (mêmes seuils et métiers admis que le serveur).
        $this->assertIsFloat($d['context']['gps_max_km']);
        $this->assertIsInt($d['context']['max_apprentices_per_trainer']);
        $this->assertContains('AUTRE', $d['context']['options']['trades.1']);

        // Rien de nouveau depuis un curseur futur ; la réaffectation est signalée comme retrait.
        $future = date('c', time() + 3600);
        $this->assertSame([], $this->call('GET', '/sync/pull', token: $ctx['token'], query: ['since' => $future])->body['data']['assignments']);
        $cursor = $d['cursor'];
        $other = $this->uuidOf($this->createUser('AGENT'));
        $this->call('POST', "/campaigns/{$ctx['campaign']['id']}/assignments", ['agent_uuid' => $other, 'structure_uuids' => [$ctx['structure']['uuid']]], self::$adminToken);
        $inc = $this->call('GET', '/sync/pull', token: $ctx['token'], query: ['since' => $cursor])->body['data'];
        $this->assertFalse($inc['full']);
        $this->assertSame([$ctx['cs']], $inc['removed_assignments']);
        $this->assertSame([], $inc['assignments']);
        $this->assertSame(422, $this->call('GET', '/sync/pull', token: $ctx['token'], query: ['since' => 'hier'])->status);
        $viewer = $this->tokenFor($this->createUser('VIEWER'));
        $this->assertSame(403, $this->call('GET', '/sync/pull', token: $viewer)->status, 'Réservé aux profils de collecte (collection.submit)');
    }

    // ------------------------------------------------------------------ Concurrence réelle (processus parallèles)

    public function testConcurrentIdenticalSubmissionsCreateASingleRevision(): void
    {
        $ctx = $this->setUpCollection();
        $results = $this->parallel($ctx, array_fill(0, 6, []));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 200, 200, 200, 200, 201], $statuses, json_encode($results));
        $this->assertSame(1, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM collection_submissions s JOIN collections c ON c.id = s.collection_id WHERE c.uuid = ?', [$ctx['uuid']]));
    }

    public function testConcurrentDifferentSubmissionsOneWinsTheOtherGetsAConflict(): void
    {
        $ctx = $this->setUpCollection();
        $results = $this->parallel($ctx, [['Q2_20_H' => 1], ['Q2_20_H' => 2]]);
        $codes = array_map(static fn($r) => $r['status'] . ($r['code'] !== null ? ' ' . $r['code'] : ''), $results);
        sort($codes);
        $this->assertSame(['201', '409 REVISION_CONFLICT'], $codes, json_encode($results));
    }

    /**
     * Lance une soumission par élément de $variants dans des processus PHP distincts, démarrés ensemble.
     * @return list<array{status:int, code:?string, revision:?int}>
     */
    private function parallel(array $ctx, array $variants): array
    {
        $dir = sys_get_temp_dir() . '/eftp_conc_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $barrier = "$dir/go";
        $procs = [];
        $pipesList = [];
        foreach ($variants as $i => $over) {
            $file = "$dir/p$i.json";
            file_put_contents($file, json_encode(['uuid' => $ctx['uuid'], 'body' => ['campaign_structure_id' => $ctx['cs'], 'questionnaire_version' => '2025.1',
                'base_revision' => 0, 'data' => $over + self::$base]], JSON_UNESCAPED_UNICODE));
            $cmd = [PHP_BINARY, '-d', 'display_startup_errors=0', '-d', 'display_errors=0', __DIR__ . '/../Support/submit_worker.php',
                $ctx['token'], $file, self::$storage, $barrier];
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $pipesList[] = $pipes;
        }
        usleep(800_000); // laisser chaque processus initialiser sa connexion
        touch($barrier);
        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipesList[$i][1]);
            $stderr = stream_get_contents($pipesList[$i][2]);
            proc_close($p);
            $decoded = json_decode((string) $stdout, true);
            $this->assertIsArray($decoded, "Processus $i : " . $stdout . $stderr);
            $out[] = $decoded;
        }
        self::removeDir($dir);
        return $out;
    }

    // ------------------------------------------------------------------ Pièce jointe

    public function testSignedFormPhotoUpload(): void
    {
        $ctx = $this->setUpCollection();
        $png = (string) tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $upload = fn(string $path, string $name) => $this->call('POST', "/collections/{$ctx['uuid']}/attachments", token: $ctx['token'],
            files: ['file' => new UploadedFile($name, $path, (int) filesize($path))]);
        $this->assertSame(404, $upload($png, 'fiche.png')->status, "Collecte à soumettre d'abord");
        $this->submit($ctx);
        $first = $upload($png, 'fiche.png');
        $this->assertSame(201, $first->status, json_encode($first->body));
        $this->assertSame(200, $upload($png, 'fiche.png')->status, 'Même photo renvoyée : pas de doublon');

        $fake = (string) tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($fake, '<?php echo "pas une image";');
        $this->assertSame('UNSUPPORTED_FILE', $upload($fake, 'fiche.jpg')->body['error']['code']);

        $detail = $this->call('GET', "/collections/{$ctx['uuid']}", token: self::$adminToken)->body['data'];
        $this->assertCount(1, $detail['attachments']);
        $file = $this->call('GET', "/collections/{$ctx['uuid']}/attachments/{$detail['attachments'][0]['id']}", token: self::$adminToken);
        $this->assertSame('image/png', $file->headers['Content-Type']);
        @unlink($png);
        @unlink($fake);
    }
}
