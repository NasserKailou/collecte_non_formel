<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\HttpException;
use App\Modules\Auth\AuthService;
use App\Modules\Users\Scope;
use App\Modules\Users\UserService;

/**
 * Gestion des comptes (lot L2) : création, validation, unicité, périmètres, visibilité,
 * élévation de privilèges, superviseurs, désactivation, réinitialisation, appareils.
 */
final class UsersApiTest extends ApiTestCase
{
    private static string $adminToken;
    private static string $admin;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $t = new self('init');
        self::$admin = $t->createUser('ADMIN');
        self::$adminToken = $t->tokenFor(self::$admin);
    }

    private function agentPayload(array $over = []): array
    {
        static $n = 0;
        $n++;
        return $over + [
            'matricule' => 'agt-' . $n . '-' . bin2hex(random_bytes(2)),
            'last_name' => 'Issoufou', 'first_name' => 'Aïcha', 'sex' => 'F',
            'role' => 'AGENT', 'scopes' => [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]],
        ];
    }

    // ------------------------------------------------------------------ Création

    public function testAdminCreatesAnAgentWhoMustChangeTheTemporaryPassword(): void
    {
        $r = $this->call('POST', '/users', $this->agentPayload(['phone' => '90 12 34 56', 'email' => 'Aicha@Example.ne']), self::$adminToken);
        $this->assertSame(201, $r->status, json_encode($r->body));
        $u = $r->body['data']['user'];
        $pwd = $r->body['data']['temporary_password'];
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/', $pwd);
        $this->assertSame('ISSOUFOU', $u['last_name']);
        $this->assertSame('+22790123456', $u['phone']);
        $this->assertSame('aicha@example.ne', $u['email']);
        $this->assertSame('AGENT', $u['role']);
        $this->assertSame('COMMUNE', $u['scopes'][0]['level']);
        $this->assertSame(self::departmentId('101'), $u['scopes'][0]['department_id'], 'Les parents du périmètre sont complétés.');
        $this->assertTrue($u['must_change_password']);

        // Connexion avec le téléphone saisi au format local ; le changement de mot de passe est exigé.
        $login = $this->login('90123456', $pwd);
        $this->assertSame(200, $login->status);
        $this->assertTrue($login->body['data']['must_change_password']);
        // Le hachage provisoire (coût réduit) est refait au coût standard dès la première connexion.
        $hash = (string) self::$db->fetchValue('SELECT password_hash FROM users WHERE uuid = ?', [$u['uuid']]);
        $this->assertFalse(password_needs_rehash($hash, AuthService::algorithm()));
        $this->assertSame(1, (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_CREATE' AND entity_id = (SELECT id FROM users WHERE uuid = ?)", [$u['uuid']]));
    }

    public function testInvalidDataIsRejectedFieldByField(): void
    {
        $r = $this->call('POST', '/users', [
            'matricule' => '!', 'last_name' => '', 'first_name' => 'X', 'sex' => 'H', 'phone' => '123', 'email' => 'pas-un-email',
            'role' => 'SUP_DEP', 'scopes' => [['level' => 'COMMUNE', 'commune_id' => self::communeId('10101')]],
        ], self::$adminToken);
        $this->assertSame(422, $r->status);
        $fields = array_column($r->body['error']['details']['errors'], 'field');
        foreach (['matricule', 'last_name', 'sex', 'phone', 'email', 'scopes.0.level'] as $f) {
            $this->assertContains($f, $fields);
        }
    }

    public function testUnknownTerritoryAndMissingScopeAreRejected(): void
    {
        $r = $this->call('POST', '/users', $this->agentPayload(['scopes' => [['level' => 'COMMUNE', 'commune_id' => 999999]]]), self::$adminToken);
        $this->assertSame('scopes.0', $r->body['error']['details']['errors'][0]['field']);
        $r = $this->call('POST', '/users', $this->agentPayload(['scopes' => []]), self::$adminToken);
        $this->assertSame('scopes', $r->body['error']['details']['errors'][0]['field']);
        $r = $this->call('POST', '/users', $this->agentPayload(['role' => 'ADMIN', 'scopes' => [['level' => 'REGION', 'region_id' => self::regionId('1')]]]), self::$adminToken);
        $this->assertSame(422, $r->status);
    }

    public function testMatriculePhoneAndEmailMustBeUnique(): void
    {
        $first = $this->agentPayload(['phone' => '+227 91 00 00 01', 'email' => 'unique@example.ne']);
        $this->assertSame(201, $this->call('POST', '/users', $first, self::$adminToken)->status);
        $r = $this->call('POST', '/users', $this->agentPayload(['matricule' => strtolower($first['matricule']), 'phone' => '91000001', 'email' => 'UNIQUE@example.ne']), self::$adminToken);
        $this->assertSame(422, $r->status);
        $this->assertEqualsCanonicalizing(['matricule', 'phone', 'email'], array_column($r->body['error']['details']['errors'], 'field'));
    }

    public function testOnlyUserManagersCanCreateAccounts(): void
    {
        $sup = $this->tokenFor($this->createUser('SUP_DEP'));
        $this->assertSame('FORBIDDEN', $this->call('POST', '/users', $this->agentPayload(), $sup)->body['error']['code']);
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame('FORBIDDEN', $this->call('GET', '/users', token: $agent)->body['error']['code']);
    }

    // ------------------------------------------------------------------ Périmètre et visibilité

    public function testSupervisorOnlySeesAccountsInsideItsScope(): void
    {
        $supDep = $this->createUser('SUP_DEP'); // département 101
        $inside = $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10102')]]);
        $outside = $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10201')]]);
        $national = $this->createUser('VIEWER');
        $token = $this->tokenFor($supDep);

        $all = [];
        $page = 1;
        do {
            $r = $this->call('GET', '/users', token: $token, query: ['page' => (string) $page, 'per_page' => '200']);
            $this->assertSame(200, $r->status);
            array_push($all, ...array_column($r->body['data'], 'matricule'));
        } while (count($all) < $r->body['meta']['total'] && ++$page < 50);

        $this->assertContains($inside, $all);
        $this->assertNotContains($outside, $all);
        $this->assertNotContains($national, $all);
        $this->assertNotContains(self::$admin, $all);
        // Un compte hors périmètre est « introuvable » (son existence n'est pas révélée).
        $this->assertSame(404, $this->call('GET', '/users/' . $this->uuidOf($outside), token: $token)->status);
        $this->assertSame(200, $this->call('GET', '/users/' . $this->uuidOf($inside), token: $token)->status);
        $this->assertSame(200, $this->call('GET', '/users/' . $this->uuidOf($supDep), token: $token)->status, 'On voit toujours son propre compte.');
    }

    public function testScopeContainment(): void
    {
        $region = Scope::fromRows(self::$db, [['level' => 'REGION', 'region_id' => self::regionId('1')]]);
        $dept = Scope::fromRows(self::$db, [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('101')]]);
        $commune = Scope::fromRows(self::$db, [['level' => 'COMMUNE', 'commune_id' => self::communeId('10102')]]);
        $otherRegion = Scope::fromRows(self::$db, [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]);
        $national = Scope::fromRows(self::$db, [['level' => 'NATIONAL']]);
        $empty = Scope::fromRows(self::$db, []);

        $this->assertTrue($region->contains($dept));
        $this->assertTrue($region->contains($commune));
        $this->assertTrue($dept->contains($commune));
        $this->assertFalse($commune->contains($dept));
        $this->assertFalse($dept->contains($region));
        $this->assertFalse($region->contains($otherRegion));
        $this->assertFalse($region->contains($national));
        $this->assertFalse($region->contains($empty));
        $this->assertTrue($national->contains($region));
        $this->assertTrue($region->coversCommune(self::communeId('10201')));
        $this->assertFalse($dept->coversCommune(self::communeId('10201')));
        [$sql] = $empty->communeClause('x.commune_id');
        $this->assertSame('1 = 0', $sql, 'Sans périmètre, on ne voit rien.');
    }

    public function testNoPrivilegeEscalationAndNoGrantOutsideOwnScope(): void
    {
        $service = new UserService(self::$db);
        $supReg = $this->createUser('SUP_REG'); // région 1
        $actor = $service->actor(['id' => $this->userId($supReg), 'roles' => ['SUP_REG']]);

        $try = function (string $role, array $scopes) use ($service, $actor): string {
            try {
                $service->create($actor, $this->agentPayload(['role' => $role, 'scopes' => $scopes]), '127.0.0.1');
                return 'OK';
            } catch (HttpException $e) {
                return $e->errorCode;
            }
        };
        $this->assertSame('FORBIDDEN', $try('SUP_REG', [['level' => 'REGION', 'region_id' => self::regionId('1')]]));
        $this->assertSame('OUT_OF_SCOPE', $try('AGENT', [['level' => 'COMMUNE', 'commune_id' => self::communeId('20201')]]));
        $this->assertSame('OK', $try('SUP_DEP', [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('102')]]));
        $this->assertSame('OK', $try('AGENT', [['level' => 'COMMUNE', 'commune_id' => self::communeId('10201')]]));
    }

    // ------------------------------------------------------------------ Superviseurs

    public function testSupervisorMustHaveAnAdmissibleRoleAndCoverTheAccount(): void
    {
        $supDep101 = $this->uuidOf($this->createUser('SUP_DEP'));
        $supDep202 = $this->uuidOf($this->createUser('SUP_DEP', scopes: [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]));
        $agentAsSup = $this->uuidOf($this->createUser('AGENT'));

        $ok = $this->call('POST', '/users', $this->agentPayload(['supervisor_uuid' => $supDep101]), self::$adminToken);
        $this->assertSame(201, $ok->status);
        $this->assertSame($supDep101, $ok->body['data']['user']['supervisor']['uuid']);

        foreach ([$supDep202 => 'périmètre', $agentAsSup => 'rôle', '00000000-0000-4000-8000-000000000000' => 'introuvable'] as $sup => $why) {
            $r = $this->call('POST', '/users', $this->agentPayload(['supervisor_uuid' => $sup]), self::$adminToken);
            $this->assertSame(422, $r->status, "Superviseur refusé ($why)");
            $this->assertSame('supervisor_uuid', $r->body['error']['details']['errors'][0]['field']);
        }
    }

    public function testShrinkingASupervisorScopeCannotOrphanItsAgents(): void
    {
        $sup = $this->createUser('SUP_DEP');
        $this->createUser('AGENT', scopes: [['level' => 'COMMUNE', 'commune_id' => self::communeId('10102')]], supervisorId: $this->userId($sup));
        $r = $this->call('PUT', '/users/' . $this->uuidOf($sup) . '/scopes',
            ['scopes' => [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('102')]]], self::$adminToken);
        $this->assertSame(409, $r->status);
        $this->assertSame('SUBORDINATES_OUT_OF_SCOPE', $r->body['error']['code']);
        $this->assertSame(1, $r->body['error']['details']['count']);
    }

    // ------------------------------------------------------------------ Modification

    public function testUpdateKeepsTheMatriculeImmutableAndIsAudited(): void
    {
        $m = $this->createUser('AGENT');
        $uuid = $this->uuidOf($m);
        $bad = $this->call('PATCH', '/users/' . $uuid, ['matricule' => 'AUTRE-123'], self::$adminToken);
        $this->assertSame(422, $bad->status);

        $r = $this->call('PATCH', '/users/' . $uuid, [
            'first_name' => 'Moussa', 'role' => 'AGENT',
            'scopes' => [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]],
        ], self::$adminToken);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $this->assertSame('Moussa', $r->body['data']['first_name']);
        $this->assertSame('DEPARTMENT', $r->body['data']['scopes'][0]['level']);
        $this->assertSame(1, (int) self::$db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_UPDATE' AND entity_id = ?", [(string) $this->userId($m)]));
    }

    public function testNobodyChangesTheirOwnRoleScopeOrStatus(): void
    {
        $uuid = $this->uuidOf(self::$admin);
        $this->assertSame('FORBIDDEN', $this->call('PATCH', '/users/' . $uuid, ['role' => 'VIEWER'], self::$adminToken)->body['error']['code']);
        $this->assertSame('FORBIDDEN', $this->call('POST', '/users/' . $uuid . '/deactivate', [], self::$adminToken)->body['error']['code']);
        $this->assertSame(200, $this->call('PATCH', '/users/' . $uuid, ['first_name' => 'Admin'], self::$adminToken)->status, 'Son identité reste modifiable.');
    }

    // ------------------------------------------------------------------ Statut, mot de passe, appareils

    public function testDeactivationClosesAllSessionsImmediately(): void
    {
        $m = $this->createUser('AGENT');
        $token = $this->tokenFor($m);
        $this->assertSame(200, $this->call('GET', '/me', token: $token)->status);

        $r = $this->call('POST', '/users/' . $this->uuidOf($m) . '/deactivate', ['reason' => 'Fin de contrat'], self::$adminToken);
        $this->assertSame('INACTIF', $r->body['data']['status']);
        $this->assertSame(401, $this->call('GET', '/me', token: $token)->status);
        $this->assertSame('ACCOUNT_DISABLED', $this->login($m)->body['error']['code']);

        $this->call('POST', '/users/' . $this->uuidOf($m) . '/activate', [], self::$adminToken);
        $this->assertSame(200, $this->login($m)->status);
        $this->assertSame('Fin de contrat', self::$db->fetchValue("SELECT reason FROM audit_logs WHERE action = 'USER_DEACTIVATE' AND entity_id = ?", [(string) $this->userId($m)]));
    }

    public function testPasswordResetIssuesANewTemporaryPasswordAndUnlocks(): void
    {
        $m = $this->createUser('AGENT');
        $token = $this->tokenFor($m);
        self::$db->execute('UPDATE users SET locked_until = NOW() + INTERVAL 1 HOUR WHERE matricule = ?', [$m]);

        $r = $this->call('POST', '/users/' . $this->uuidOf($m) . '/reset-password', [], self::$adminToken);
        $this->assertSame(200, $r->status);
        $pwd = $r->body['data']['temporary_password'];
        $this->assertSame(401, $this->call('GET', '/me', token: $token)->status, 'Les sessions existantes sont fermées.');
        $this->assertSame('INVALID_CREDENTIALS', $this->login($m)->body['error']['code'], "L'ancien mot de passe ne fonctionne plus.");
        $login = $this->login($m, $pwd);
        $this->assertSame(200, $login->status);
        $this->assertTrue($login->body['data']['must_change_password']);
    }

    public function testRevokingADeviceCutsItOff(): void
    {
        $m = $this->createUser('AGENT');
        $token = $this->tokenFor($m);
        $devices = $this->call('GET', '/users/' . $this->uuidOf($m) . '/devices', token: self::$adminToken)->body['data'];
        $this->assertCount(1, $devices);
        $this->assertSame('Tablette test', $devices[0]['model']);

        $this->assertSame(204, $this->call('POST', '/devices/' . $devices[0]['id'] . '/revoke', ['reason' => 'Tablette perdue'], self::$adminToken)->status);
        $this->assertSame(401, $this->call('GET', '/me', token: $token)->status);
        $this->assertSame('DEVICE_REVOKED', $this->login($m)->body['error']['code']);
    }

    // ------------------------------------------------------------------ Liste et territoire

    public function testListFiltersAndPagination(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createUser('VIEWER', scopes: [['level' => 'REGION', 'region_id' => self::regionId('2')]]);
        }
        $r = $this->call('GET', '/users', token: self::$adminToken, query: ['role' => 'VIEWER', 'region_id' => (string) self::regionId('2'), 'per_page' => '2']);
        $this->assertSame(200, $r->status);
        $this->assertCount(2, $r->body['data']);
        $this->assertGreaterThanOrEqual(3, $r->body['meta']['total']);
        $this->assertSame(['VIEWER'], array_values(array_unique(array_column($r->body['data'], 'role'))));
        $this->assertSame(422, $this->call('GET', '/users', token: self::$adminToken, query: ['role' => 'PIRATE'])->status);
        $this->assertSame(422, $this->call('GET', '/users', token: self::$adminToken, query: ['page' => "1' OR 1=1"])->status);
    }

    public function testTerritoryListsForForms(): void
    {
        $token = $this->tokenFor($this->createUser('AGENT'));
        $this->assertCount(2, $this->call('GET', '/regions', token: $token)->body['data']);
        $depts = $this->call('GET', '/departments', token: $token, query: ['region_id' => (string) self::regionId('2')])->body['data'];
        $this->assertSame(['DEPARTEMENT D', 'VILLE DE DEUX'], array_column($depts, 'name'));
        $this->assertCount(2, $this->call('GET', '/communes', token: $token, query: ['department_id' => (string) self::departmentId('101')])->body['data']);
    }
}
