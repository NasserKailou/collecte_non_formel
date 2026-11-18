<?php

declare(strict_types=1);

/**
 * Configuration. Ordre de priorité (du plus faible au plus fort) :
 *  1. valeurs par défaut ci-dessous ;
 *  2. variables d'environnement EFTP_* (lues comme valeurs par défaut) ;
 *  3. config/local.php (non versionné), qui renvoie un tableau fusionné par-dessus.
 * Aucun secret de production ne doit figurer dans ce fichier.
 */

$env = static fn(string $k, mixed $default = null): mixed => ($v = getenv($k)) !== false ? $v : $default;

$config = [
    'app' => [
        'env' => $env('EFTP_ENV', 'dev'),              // dev | test | prod
        'name' => 'Plateforme EFTP non formel',
        'timezone' => 'Africa/Niamey',
        'base_path' => $env('EFTP_BASE_PATH', ''),      // ex. /non_formel/backend/public sous XAMPP
        'debug' => $env('EFTP_DEBUG', '1') === '1',
    ],
    'db' => [
        'host' => $env('EFTP_DB_HOST', '127.0.0.1'),
        'port' => (int) $env('EFTP_DB_PORT', 3306),
        'name' => $env('EFTP_DB_NAME', 'eftp_nf'),
        'user' => $env('EFTP_DB_USER', 'root'),
        'pass' => $env('EFTP_DB_PASS', ''),
    ],
    'auth' => [
        'access_ttl' => 12 * 3600,           // secondes
        'refresh_ttl' => 30 * 86400,
        'max_failures_per_identifier' => 5,  // sur la fenêtre ci-dessous ⇒ verrouillage du compte
        'lock_minutes' => 15,
        'max_failures_per_ip' => 20,
        'failure_window_minutes' => 15,
        'password_min_length' => 10,
        'offline_policy' => ['max_offline_days' => 30, 'max_unlock_failures' => 10],
        // Back-office web : session par cookie HttpOnly + jeton anti-CSRF (docs/api/README.md §2).
        'session_cookie' => 'eftp_session',
        'cookie_secure' => $env('EFTP_COOKIE_SECURE', '0') === '1', // obligatoire en production (HTTPS)
        // Clé de signature des jetons CSRF. Absente : générée une fois dans storage/app.key (hors racine web).
        'app_key' => $env('EFTP_APP_KEY'),
    ],
    'exports' => [
        // true : l'export est produit pendant la requête (développement). En production : false, et
        // « php bin/worker.php » lancé en tâche planifiée traite la file (exports lourds hors requête HTTP).
        'inline' => $env('EFTP_EXPORTS_INLINE', '1') === '1',
        'ttl_days' => 7,
    ],
    'mobile' => [
        'min_app_version' => $env('EFTP_MIN_APP_VERSION', '1.0.0'),
    ],
    'paths' => [
        'root' => dirname(__DIR__, 2),
        'storage' => dirname(__DIR__) . '/storage',
        'migrations' => dirname(__DIR__, 2) . '/database/migrations',
        'shared' => dirname(__DIR__, 2) . '/shared',
    ],
];

$local = __DIR__ . '/local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
