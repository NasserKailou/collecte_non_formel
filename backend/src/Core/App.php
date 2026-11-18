<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthService;

/**
 * Noyau HTTP de l'API /api/v1 : routage, authentification par jeton, permissions,
 * changement de mot de passe obligatoire, version minimale de l'application mobile,
 * conversion des erreurs en JSON (sans fuite d'information en production).
 */
final class App
{
    public const PREFIX = '/api/v1';

    /** @var list<array{method:string, regex:string, params:list<string>, handler:callable, auth:bool, permission:?string, allow_pending:bool}> */
    private array $routes = [];
    public readonly AuthService $auth;

    public function __construct(public readonly array $config, public readonly Database $db)
    {
        $this->auth = new AuthService($db, $config['auth']);
    }

    /**
     * @param callable(Request, array, ?array):Response $handler (requête, paramètres de chemin, utilisateur authentifié)
     * @param array{auth?:bool, permission?:string, allow_pending?:bool} $options
     */
    public function route(string $method, string $pattern, callable $handler, array $options = []): void
    {
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $m);
        $regex = '#^' . preg_replace('/\{[a-z_]+\}/', '([A-Za-z0-9_.\-]+)', $pattern) . '$#';
        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'params' => $m[1],
            'handler' => $handler,
            'auth' => $options['auth'] ?? true,
            'permission' => $options['permission'] ?? null,
            'allow_pending' => $options['allow_pending'] ?? false,
            // Accessible même à une application trop ancienne (annonce et téléchargement de la mise à jour).
            'any_version' => $options['any_version'] ?? false,
        ];
    }

    public function handle(Request $request): Response
    {
        $this->minAppVersion = null; // recalculée à chaque requête (une version obligatoire peut avoir été publiée)
        try {
            return $this->dispatch($request);
        } catch (HttpException $e) {
            return Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers);
        } catch (ImportException $e) {
            return Response::error(422, 'IMPORT_REJECTED', $e->getMessage(), ['errors' => $e->errors()]);
        } catch (\Throwable $e) {
            $this->logError($e, $request);
            $message = ($this->config['app']['debug'] && $this->config['app']['env'] !== 'prod')
                ? get_class($e) . ' : ' . $e->getMessage()
                : 'Erreur interne du serveur. Elle a été enregistrée.';
            return Response::error(500, 'SERVER_ERROR', $message);
        }
    }

    private function dispatch(Request $request): Response
    {
        $path = $request->path;
        if (!str_starts_with($path, self::PREFIX)) {
            throw new HttpException(404, 'NOT_FOUND', 'Ressource introuvable.');
        }
        $path = rtrim(substr($path, strlen(self::PREFIX)), '/') ?: '/';

        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_combine($route['params'], array_slice($m, 1)) ?: [];
            $user = null;

            if (!$route['any_version'] && $request->header('x-app-version') !== null
                && version_compare((string) $request->header('x-app-version'), $this->minAppVersion(), '<')) {
                throw new HttpException(426, 'UPGRADE_REQUIRED', "Cette version de l'application n'est plus acceptée. Installer la dernière version.",
                    ['min_version' => $this->minAppVersion()]);
            }

            if ($route['auth']) {
                // Mobile : jeton porteur. Back-office web : jeton dans un cookie HttpOnly + jeton anti-CSRF.
                $token = $request->bearerToken();
                $viaCookie = false;
                if ($token === null) {
                    $cookie = $request->cookie($this->config['auth']['session_cookie']);
                    if ($cookie !== null && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1) {
                        $token = $cookie;
                        $viaCookie = true;
                    }
                }
                $user = $token !== null ? $this->auth->authenticate($token) : null;
                if ($user === null) {
                    throw new HttpException(401, 'UNAUTHENTICATED', 'Authentification requise ou session expirée.');
                }
                $user['via_cookie'] = $viaCookie;
                if ($viaCookie && !in_array($request->method, ['GET', 'HEAD'], true)
                    && !hash_equals($this->csrfToken($user['family_id']), (string) $request->header('x-csrf-token'))) {
                    throw new HttpException(403, 'CSRF_FAILED', 'Jeton de sécurité absent ou invalide. Recharger la page.');
                }
                if ($user['must_change_password'] && !$route['allow_pending']) {
                    throw new HttpException(403, 'PASSWORD_CHANGE_REQUIRED', 'Vous devez changer votre mot de passe avant de continuer.');
                }
                if ($route['permission'] !== null && !in_array($route['permission'], $user['permissions'], true)) {
                    throw new HttpException(403, 'FORBIDDEN', "Vous n'avez pas le droit d'effectuer cette action.");
                }
            }
            return ($route['handler'])($request, $params, $user);
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Méthode non autorisée.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'NOT_FOUND', 'Ressource introuvable.');
    }

    /** Jeton anti-CSRF d'une session web : HMAC de la famille de jetons (change à chaque connexion). */
    public function csrfToken(string $familyId): string
    {
        return hash_hmac('sha256', 'csrf|' . $familyId, $this->appKey());
    }

    /** En-tête Set-Cookie de la session web (valeur vide et durée nulle pour la supprimer). */
    public function sessionCookie(?string $token): string
    {
        $c = $this->config['auth'];
        $parts = [$c['session_cookie'] . '=' . ($token ?? 'deleted'), 'Path=/', 'HttpOnly', 'SameSite=Strict',
            'Max-Age=' . ($token === null ? 0 : (int) $c['access_ttl'])];
        if ($c['cookie_secure']) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private ?string $minAppVersion = null;

    /**
     * Version minimale de l'application mobile : la plus élevée entre la configuration (EFTP_MIN_APP_VERSION) et la
     * dernière version publiée comme obligatoire depuis l'administration (table app_releases).
     */
    public function minAppVersion(): string
    {
        if ($this->minAppVersion === null) {
            $min = (string) $this->config['mobile']['min_app_version'];
            try {
                foreach ($this->db->fetchAll("SELECT version FROM app_releases WHERE mandatory = 1 AND status = 'PUBLIEE'") as $r) {
                    if (version_compare($r['version'], $min, '>')) {
                        $min = $r['version'];
                    }
                }
            } catch (\Throwable) {
                // table absente (migration 004 non appliquée) : seule la configuration s'applique
            }
            $this->minAppVersion = $min;
        }
        return $this->minAppVersion;
    }

    /** Signature HMAC (clé applicative) : liens de téléchargement à durée limitée. */
    public function sign(string $payload): string
    {
        return hash_hmac('sha256', 'sig|' . $payload, $this->appKey());
    }

    private function appKey(): string
    {
        $key = $this->config['auth']['app_key'] ?? null;
        if (is_string($key) && strlen($key) >= 32) {
            return $key;
        }
        $file = $this->config['paths']['storage'] . '/app.key';
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0770, true);
            }
            $fp = fopen($file, 'c+');
            if ($fp === false || !flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Impossible de créer la clé applicative.');
            }
            if (fstat($fp)['size'] === 0) { // aucun autre processus ne l'a écrite entre-temps
                fwrite($fp, bin2hex(random_bytes(32)));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return trim((string) file_get_contents($file));
    }

    private function logError(\Throwable $e, Request $request): void
    {
        $dir = $this->config['paths']['storage'] . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] %s %s — %s: %s in %s:%d\n%s\n",
            date('c'), $request->method, $request->path, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
