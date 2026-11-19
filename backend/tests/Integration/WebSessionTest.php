<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Response;

/**
 * Session du back-office web (lot L5) : cookie HttpOnly, jeton anti-CSRF, déconnexion ;
 * paramètres modifiables et journaux.
 */
final class WebSessionTest extends ApiTestCase
{
    private function webLogin(string $matricule): Response
    {
        return $this->call('POST', '/auth/login', ['identifier' => $matricule, 'password' => self::PASSWORD, 'session' => true], ip: $this->ip());
    }

    private function cookieOf(Response $r): string
    {
        preg_match('/^eftp_session=([a-f0-9]{64})/', $r->headers['Set-Cookie'] ?? '', $m);
        $this->assertNotEmpty($m, 'Cookie de session attendu');
        return $m[1];
    }

    public function testWebLoginSetsAnHttpOnlyCookieAndNeverExposesTokens(): void
    {
        $r = $this->webLogin($this->createUser('ADMIN'));
        $this->assertSame(200, $r->status);
        $cookie = $r->headers['Set-Cookie'];
        foreach (['HttpOnly', 'SameSite=Strict', 'Path=/', 'Max-Age=43200'] as $attr) {
            $this->assertStringContainsString($attr, $cookie);
        }
        $this->assertArrayNotHasKey('access_token', $r->body['data']);
        $this->assertArrayNotHasKey('refresh_token', $r->body['data']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->body['data']['csrf_token']);
        $this->assertSame(['ADMIN'], $r->body['data']['user']['roles']);
    }

    public function testModifyingRequestsRequireTheCsrfToken(): void
    {
        $login = $this->webLogin($this->createUser('ADMIN'));
        $session = $this->cookieOf($login);
        $csrf = $login->body['data']['csrf_token'];
        $headers = ['cookie' => "autre=1; eftp_session=$session"];

        $me = $this->call('GET', '/me', headers: $headers);
        $this->assertSame(200, $me->status, 'Lecture sans jeton CSRF autorisée');
        $this->assertSame($csrf, $me->body['data']['csrf_token'], 'Le jeton CSRF est retrouvé après rechargement de la page');

        $this->assertSame('CSRF_FAILED', $this->call('PATCH', '/settings', ['gps_max_km' => 20], headers: $headers)->body['error']['code']);
        $this->assertSame('CSRF_FAILED', $this->call('PATCH', '/settings', ['gps_max_km' => 20], headers: $headers + ['x-csrf-token' => str_repeat('0', 64)])->body['error']['code']);
        $ok = $this->call('PATCH', '/settings', ['gps_max_km' => 20], headers: $headers + ['x-csrf-token' => $csrf]);
        $this->assertSame(200, $ok->status, json_encode($ok->body));

        // Les clients mobiles (jeton porteur) ne sont pas concernés.
        $this->assertSame(200, $this->call('PATCH', '/settings', ['gps_max_km' => 15], $this->tokenFor($this->createUser('ADMIN')))->status);

        // Un jeton CSRF d'une autre session ne vaut rien.
        $other = $this->webLogin($this->createUser('ADMIN'))->body['data']['csrf_token'];
        $this->assertSame('CSRF_FAILED', $this->call('PATCH', '/settings', ['gps_max_km' => 20], headers: $headers + ['x-csrf-token' => $other])->body['error']['code']);
    }

    public function testLogoutClearsTheCookieAndRevokesTheSession(): void
    {
        $login = $this->webLogin($this->createUser('SUP_DEP'));
        $headers = ['cookie' => 'eftp_session=' . $this->cookieOf($login), 'x-csrf-token' => $login->body['data']['csrf_token']];
        $out = $this->call('POST', '/auth/logout', headers: $headers);
        $this->assertSame(204, $out->status);
        $this->assertStringContainsString('Max-Age=0', $out->headers['Set-Cookie']);
        $this->assertSame(401, $this->call('GET', '/me', headers: $headers)->status);
        $this->assertSame(401, $this->call('GET', '/me', headers: ['cookie' => 'eftp_session=pas-un-jeton'])->status);
    }

    public function testPasswordChangeIsPossibleInSessionMode(): void
    {
        $login = $this->webLogin($this->createUser('SUP_DEP', mustChange: true));
        $this->assertTrue($login->body['data']['must_change_password']);
        $headers = ['cookie' => 'eftp_session=' . $this->cookieOf($login), 'x-csrf-token' => $login->body['data']['csrf_token']];
        $this->assertSame(204, $this->call('POST', '/auth/password', ['current_password' => self::PASSWORD, 'new_password' => 'Nouveau2026web'], headers: $headers)->status);
        $this->assertSame(200, $this->call('GET', '/structures', headers: $headers)->status, 'La session continue après le changement');
    }

    public function testSettingsValidation(): void
    {
        $token = $this->tokenFor($this->createUser('ADMIN'));
        $r = $this->call('PATCH', '/settings', ['gps_max_km' => 'loin', 'inconnu' => 1, 'stats_basis' => 'tout'], $token);
        $this->assertSame(422, $r->status);
        $this->assertEqualsCanonicalizing(['gps_max_km', 'inconnu', 'stats_basis'], array_column($r->body['error']['details']['errors'], 'field'));
        $ok = $this->call('PATCH', '/settings', ['max_apprentices_per_trainer' => 50, 'stats_basis' => 'submitted'], $token);
        $values = array_column($ok->body['data'], 'value', 'key');
        $this->assertSame(['50', 'submitted'], [$values['max_apprentices_per_trainer'], $values['stats_basis']]);
        $this->assertSame(403, $this->call('PATCH', '/settings', ['gps_max_km' => 1], $this->tokenFor($this->createUser('SUP_REG')))->status);
    }

    public function testLogsAreReservedToAuditorsAndFilterable(): void
    {
        $admin = $this->tokenFor($this->createUser('ADMIN'));
        $this->call('PATCH', '/settings', ['gps_max_km' => 16], $admin);
        $audit = $this->call('GET', '/audit-logs', token: $admin, query: ['action' => 'SETTING_UPDATE', 'from' => date('Y-m-d')]);
        $this->assertSame(200, $audit->status);
        $this->assertGreaterThanOrEqual(1, $audit->body['meta']['total']);
        $this->assertSame('SETTING_UPDATE', $audit->body['data'][0]['action']);
        $this->assertSame(200, $this->call('GET', '/login-logs', token: $admin, query: ['channel' => 'MOBILE'])->status);
        $this->assertSame(200, $this->call('GET', '/sync-logs', token: $admin)->status);
        $this->assertSame(422, $this->call('GET', '/audit-logs', token: $admin, query: ['from' => 'hier'])->status);
        $this->assertSame(403, $this->call('GET', '/audit-logs', token: $this->tokenFor($this->createUser('SUP_REG')))->status);
    }
    public function testSupervisorTabletOpensTheBackOfficeWithAOneTimeTicket(): void
    {
        $sup = $this->createUser('SUP_REG');
        $token = $this->tokenFor($sup);
        $r = $this->call('POST', '/auth/web-ticket', [], $token);
        $this->assertSame(200, $r->status, json_encode($r->body));
        $ticket = $r->body['data']['ticket'];

        $s = $this->call('GET', '/auth/web-session', query: ['ticket' => $ticket]);
        $this->assertSame(302, $s->status);
        $this->assertStringEndsWith('/app/', $s->headers['Location']);
        $session = $this->cookieOf($s);
        $me = $this->call('GET', '/me', headers: ['cookie' => "eftp_session=$session"]);
        $this->assertSame(200, $me->status);
        $this->assertSame(['SUP_REG'], $me->body['data']['roles']);
        $this->assertNotEmpty($me->body['data']['csrf_token'], 'Session web complète (jeton CSRF)');

        // Usage unique ; le ticket ne vaut pas jeton d'accès après usage ; la session de la tablette reste valide.
        $again = $this->call('GET', '/auth/web-session', query: ['ticket' => $ticket]);
        $this->assertSame(302, $again->status);
        $this->assertArrayNotHasKey('Set-Cookie', $again->headers);
        $this->assertSame(401, $this->call('GET', '/me', token: $ticket)->status);
        $this->assertSame(200, $this->call('GET', '/me', token: $token)->status);

        // Un jeton ordinaire de la tablette ne peut pas servir de ticket (et n'est pas révoqué pour autant).
        $this->assertArrayNotHasKey('Set-Cookie', $this->call('GET', '/auth/web-session', query: ['ticket' => $token])->headers);
        $this->assertSame(200, $this->call('GET', '/me', token: $token)->status);

        // Réservé aux comptes qui contrôlent : un agent recenseur n'obtient pas de ticket.
        $this->assertSame(403, $this->call('POST', '/auth/web-ticket', [], $this->tokenFor($this->createUser('AGENT')))->status);
    }
}
