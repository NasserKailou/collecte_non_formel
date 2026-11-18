<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Questionnaires\QuestionnairePublisher;

/**
 * Tests d'intégration de l'authentification et du contrôle d'accès, sur une base dédiée
 * (eftp_nf_test) recréée à chaque exécution. Aucune donnée de la base de travail n'est touchée.
 */
final class AuthApiTest extends ApiTestCase
{
    // ------------------------------------------------------------------ Connexion

    public function testSuccessfulMobileLoginReturnsTokensAndProfile(): void
    {
        $m = $this->createUser('AGENT');
        $r = $this->login($m);
        $this->assertSame(200, $r->status);
        $d = $r->body['data'];
        $this->assertSame(64, strlen($d['access_token']));
        $this->assertNotSame($d['access_token'], $d['refresh_token']);
        $this->assertSame(['AGENT'], $d['user']['roles']);
        $this->assertContains('collection.submit', $d['user']['permissions']);
        $this->assertFalse($d['must_change_password']);
        $this->assertSame(30, $d['offline_policy']['max_offline_days']);
        // Le jeton n'est jamais stocké en clair.
        $this->assertSame(0, (int) self::$db->fetchValue('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [$d['access_token']]));
        $this->assertSame(1, (int) self::$db->fetchValue('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [hash('sha256', $d['access_token'])]));
    }

    public function testWrongPasswordAndUnknownIdentifierGiveTheSameAnswer(): void
    {
        $m = $this->createUser('AGENT');
        $wrong = $this->login($m, 'Mauvais12345');
        $unknown = $this->login('INCONNU-' . $m);
        $this->assertSame(401, $wrong->status);
        $this->assertSame($wrong->body, $unknown->body, "Les deux réponses doivent être indiscernables (pas d'énumération des comptes).");
        $this->assertSame('INVALID_CREDENTIALS', $wrong->body['error']['code']);
    }

    public function testAccountIsLockedAfterFiveFailuresEvenWithTheRightPassword(): void
    {
        $m = $this->createUser('AGENT');
        $ip = $this->ip();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('INVALID_CREDENTIALS', $this->login($m, 'Mauvais12345', $ip)->body['error']['code']);
        }
        $r = $this->login($m, self::PASSWORD, $ip);
        $this->assertSame(401, $r->status);
        $this->assertSame('ACCOUNT_LOCKED', $r->body['error']['code']);
    }

    public function testIpIsRateLimitedAfterTwentyFailures(): void
    {
        $ip = $this->ip();
        for ($i = 0; $i < 20; $i++) {
            $this->login('PERSONNE-' . $i, 'x', $ip);
        }
        $r = $this->login('PERSONNE-21', 'x', $ip);
        $this->assertSame(429, $r->status);
        $this->assertArrayHasKey('Retry-After', $r->headers);
    }

    public function testDisabledAccountCannotLogIn(): void
    {
        $m = $this->createUser('AGENT', status: 'INACTIF');
        $this->assertSame('ACCOUNT_DISABLED', $this->login($m)->body['error']['code']);
    }

    public function testRevokedDeviceCannotLogIn(): void
    {
        $m = $this->createUser('AGENT');
        $this->assertSame(200, $this->login($m)->status);
        self::$db->execute('UPDATE devices d JOIN users u ON u.id = d.user_id SET d.revoked_at = NOW() WHERE u.matricule = ?', [$m]);
        $this->assertSame('DEVICE_REVOKED', $this->login($m)->body['error']['code']);
    }

    public function testSqlInjectionInIdentifierIsHarmless(): void
    {
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM users');
        $r = $this->login("' OR '1'='1' -- ");
        $this->assertSame('INVALID_CREDENTIALS', $r->body['error']['code']);
        $this->assertSame($before, (int) self::$db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    // ------------------------------------------------------------------ Jetons

    public function testRequestsWithoutOrWithInvalidTokenAreRejected(): void
    {
        $this->assertSame(401, $this->call('GET', '/me')->status);
        $this->assertSame(401, $this->call('GET', '/me', token: str_repeat('a', 64))->status);
    }

    public function testRefreshRotatesTokensAndDetectsReuse(): void
    {
        $m = $this->createUser('AGENT');
        $first = $this->login($m)->body['data'];

        $second = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->body['data'];
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertSame(401, $this->call('GET', '/me', token: $first['access_token'])->status, "L'ancien jeton d'accès doit être révoqué.");
        $this->assertSame(200, $this->call('GET', '/me', token: $second['access_token'])->status);

        // Rejeu de l'ancien jeton de rafraîchissement : toute la famille est révoquée.
        $replay = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']]);
        $this->assertSame('TOKEN_REUSED', $replay->body['error']['code']);
        $this->assertSame(401, $this->call('GET', '/me', token: $second['access_token'])->status);
        $this->assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $second['refresh_token']])->status);
        // Deux tentatives suspectes journalisées : le rejeu de l'ancien jeton, puis l'usage du jeton révoqué avec sa famille.
        $this->assertSame(2, (int) self::$db->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'TOKEN_REUSE' AND entity_id = (SELECT family_id FROM api_tokens WHERE token_hash = ?)",
            [hash('sha256', $first['refresh_token'])]));
    }

    public function testLogoutRevokesTheSession(): void
    {
        $token = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(204, $this->call('POST', '/auth/logout', token: $token)->status);
        $this->assertSame(401, $this->call('GET', '/me', token: $token)->status);
    }

    // ------------------------------------------------------------------ Mot de passe initial

    public function testInitialPasswordMustBeChangedBeforeAnyOtherAction(): void
    {
        $m = $this->createUser('AGENT', mustChange: true);
        $login = $this->login($m)->body['data'];
        $this->assertTrue($login['must_change_password']);
        $token = $login['access_token'];

        $this->assertSame(200, $this->call('GET', '/me', token: $token)->status);
        $blocked = $this->call('GET', '/referentials/territory', token: $token);
        $this->assertSame('PASSWORD_CHANGE_REQUIRED', $blocked->body['error']['code']);

        $weak = $this->call('POST', '/auth/password', ['current_password' => self::PASSWORD, 'new_password' => 'court'], $token);
        $this->assertSame('WEAK_PASSWORD', $weak->body['error']['code']);

        $ok = $this->call('POST', '/auth/password', ['current_password' => self::PASSWORD, 'new_password' => 'NouveauMdp2026'], $token);
        $this->assertSame(204, $ok->status);
        $this->assertSame(200, $this->call('GET', '/referentials/territory', token: $token)->status);
        $this->assertSame(200, $this->login($m, 'NouveauMdp2026')->status);
    }

    // ------------------------------------------------------------------ Autorisations

    public function testPermissionsAreEnforcedServerSide(): void
    {
        $agent = $this->tokenFor($this->createUser('AGENT'));
        $admin = $this->tokenFor($this->createUser('ADMIN'));
        $this->assertSame('FORBIDDEN', $this->call('GET', '/settings', token: $agent)->body['error']['code']);
        $r = $this->call('GET', '/settings', token: $admin);
        $this->assertSame(200, $r->status);
        $this->assertContains('gps_max_km', array_column($r->body['data'], 'key'));
    }

    public function testOutdatedMobileAppIsRefused(): void
    {
        $r = $this->call('GET', '/me', headers: ['x-app-version' => '0.9.0']);
        $this->assertSame(426, $r->status);
        $this->assertSame('UPGRADE_REQUIRED', $r->body['error']['code']);
    }

    // ------------------------------------------------------------------ Référentiels

    public function testReferentialsUseEtagsToSaveBandwidth(): void
    {
        $token = $this->tokenFor($this->createUser('AGENT'));
        $r = $this->call('GET', '/referentials/territory', token: $token);
        $this->assertSame(200, $r->status);
        $this->assertSame('COMMUNE TEST', $r->body['data']['communes'][0]['name']);
        $again = $this->call('GET', '/referentials/territory', token: $token, headers: ['if-none-match' => $r->headers['ETag']]);
        $this->assertSame(304, $again->status);
        $this->assertNull($again->body);
    }

    public function testPublishedQuestionnaireIsServedAndImmutable(): void
    {
        $token = $this->tokenFor($this->createUser('AGENT'));
        $r = $this->call('GET', '/questionnaires/2025.1', token: $token);
        $this->assertSame(200, $r->status);
        $this->assertSame('2025.1', $r->body['data']['version']);
        $this->assertStringContainsString('immutable', $r->headers['Cache-Control']);
        $this->assertSame(404, $this->call('GET', '/questionnaires/1999.1', token: $token)->status);

        $this->expectException(\RuntimeException::class);
        $def = $r->body['data'];
        $def['title'] = 'Modifié';
        (new QuestionnairePublisher(self::$db))->publish($def, (string) json_encode($def));
    }

    public function testUnknownRouteAndWrongMethod(): void
    {
        $this->assertSame(404, $this->call('GET', '/inexistant')->status);
        $this->assertSame(405, $this->call('GET', '/auth/login')->status);
    }
}
