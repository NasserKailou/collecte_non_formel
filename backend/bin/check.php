<?php

declare(strict_types=1);

/**
 * Vérification avant mise en service (serveur de production, ex. Plesk) : PHP, extensions, droits d'écriture,
 * réglages de production, base de données, migrations, questionnaires, compte administrateur.
 * Ne modifie rien (crée seulement les sous-dossiers de stockage manquants).
 *
 * Usage : php bin/check.php        (code de sortie 1 si un point bloquant est trouvé)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ok = $warn = $fail = 0;
$line = static function (string $state, string $text) use (&$ok, &$warn, &$fail): void {
    match ($state) { 'ok' => $ok++, 'warn' => $warn++, default => $fail++ };
    fwrite(STDOUT, ['ok' => '  [OK]        ', 'warn' => '  [ATTENTION] ', 'fail' => '  [BLOQUANT]  '][$state] . $text . PHP_EOL);
};
$title = static fn(string $t) => fwrite(STDOUT, PHP_EOL . $t . PHP_EOL);

$title('PHP');
version_compare(PHP_VERSION, '8.2.0', '>=') ? $line('ok', 'PHP ' . PHP_VERSION) : $line('fail', 'PHP ' . PHP_VERSION . ' : 8.2 ou plus exigé');
foreach (['pdo_mysql', 'mbstring', 'json', 'zip', 'gd', 'dom', 'xml', 'xmlwriter', 'simplexml', 'fileinfo', 'iconv', 'openssl', 'ctype'] as $ext) {
    extension_loaded($ext) ? $line('ok', "extension $ext") : $line('fail', "extension $ext manquante (à activer dans Plesk › PHP)");
}
extension_loaded('zip') && defined('ZipArchive::EM_AES_256')
    ? $line('ok', 'archives chiffrées AES-256 (mots de passe provisoires des imports)')
    : $line('fail', 'ZipArchive sans chiffrement AES : libzip trop ancienne (import des agents impossible)');
defined('PASSWORD_ARGON2ID') ? $line('ok', 'hachage Argon2id') : $line('warn', 'Argon2id indisponible : bcrypt sera utilisé (acceptable)');
$bytes = static function (string $v): int {
    $n = (int) $v;
    return match (strtoupper(substr(trim($v), -1))) { 'G' => $n * 1024 ** 3, 'M' => $n * 1024 ** 2, 'K' => $n * 1024, default => $n };
};
$mem = (string) ini_get('memory_limit');
$mem === '-1' || $bytes($mem) >= 256 * 1024 ** 2 ? $line('ok', "memory_limit $mem") : $line('warn', "memory_limit $mem : 256M conseillés (exports Excel)");
min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))) >= 300 * 1024 ** 2
    ? $line('ok', 'envoi de fichiers ≥ 300 Mo (publication de l\'APK)')
    : $line('warn', 'upload_max_filesize / post_max_size < 300M : la publication de l\'APK depuis le back-office échouera (réglage PHP de l\'abonnement)');

$root = dirname(__DIR__);
if (!is_file("$root/vendor/autoload.php")) {
    $line('fail', 'vendor/ absent : le paquet de déploiement est incomplet');
    exit(1);
}
require "$root/vendor/autoload.php";
$config = require "$root/config/config.php";

$title('Configuration');
is_file("$root/config/local.php") ? $line('ok', 'config/local.php présent') : $line('fail', 'config/local.php absent (copier deploy/local.php.example)');
$config['app']['env'] === 'prod' ? $line('ok', 'mode production') : $line('warn', "mode « {$config['app']['env']} » (prod attendu)");
!$config['app']['debug'] ? $line('ok', 'débogage désactivé') : $line('warn', 'débogage activé : à désactiver en production');
$config['auth']['cookie_secure'] ? $line('ok', 'cookie de session réservé au HTTPS') : $line('fail', 'cookie_secure désactivé : obligatoire en HTTPS');
!$config['exports']['inline'] ? $line('ok', 'exports en tâche de fond (bin/worker.php)') : $line('warn', 'exports produits pendant la requête : mettre exports.inline à false et planifier bin/worker.php');
$config['app']['base_path'] === '' ? $line('ok', 'racine web = backend/public (base_path vide)') : $line('warn', "base_path « {$config['app']['base_path']} » : vide attendu si la racine du domaine pointe sur backend/public");
is_dir($config['paths']['shared'] . '/questionnaire') ? $line('ok', 'dossier shared/ présent') : $line('fail', 'dossier shared/ absent (définitions du questionnaire)');
is_dir($config['paths']['migrations']) ? $line('ok', 'dossier database/migrations présent') : $line('fail', 'dossier database/migrations absent');

$title('Stockage (hors racine web)');
$storage = $config['paths']['storage'];
foreach (['', '/exports', '/imports', '/templates', '/releases', '/logs'] as $d) {
    $p = $storage . $d;
    if (!is_dir($p) && !@mkdir($p, 0770, true)) {
        $line('fail', "impossible de créer $p");
    } elseif (!is_writable($p)) {
        $line('fail', "$p non accessible en écriture (propriétaire : utilisateur système de l'abonnement Plesk)");
    } else {
        $line('ok', "écriture possible : storage$d");
    }
}
$config['auth']['app_key'] || is_file("$storage/app.key")
    ? $line('ok', 'clé de l\'application présente')
    : $line('warn', 'clé de l\'application absente : elle sera créée au premier appel (storage/app.key) ; la sauvegarder ensuite');

$title('Base de données');
try {
    $db = new \App\Core\Database($config['db']);
    $line('ok', 'connexion ' . $config['db']['user'] . '@' . $config['db']['host'] . '/' . $config['db']['name'] . ' — ' . $db->fetchValue('SELECT VERSION()'));
    $applied = array_column($db->fetchAll('SELECT version FROM schema_migrations'), 'version');
    $files = array_map(static fn($f) => basename($f, '.sql'), glob($config['paths']['migrations'] . '/*.sql'));
    $missing = array_diff($files, $applied);
    $missing === [] ? $line('ok', count($files) . ' migration(s) appliquée(s)') : $line('fail', 'migrations à appliquer : ' . implode(', ', $missing) . ' (php bin/migrate.php)');
    $versions = array_column($db->fetchAll("SELECT version_code FROM questionnaire_versions WHERE status = 'PUBLIEE'"), 'version_code');
    $versions !== [] ? $line('ok', 'questionnaires publiés : ' . implode(', ', $versions)) : $line('fail', 'aucun questionnaire publié (php bin/seed.php)');
    (int) $db->fetchValue('SELECT COUNT(*) FROM communes') > 0 ? $line('ok', 'référentiel territorial chargé') : $line('fail', 'territoire vide (php bin/seed.php)');
    (int) $db->fetchValue("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'ADMIN' AND u.deleted_at IS NULL") > 0
        ? $line('ok', 'au moins un administrateur national')
        : $line('fail', 'aucun administrateur (php bin/create-admin.php)');
    (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE matricule LIKE 'DEMO-%' OR matricule = 'ADM-DEMO'") === 0
        ? $line('ok', 'aucun compte de démonstration')
        : $line('warn', 'comptes de démonstration présents : ne pas lancer seed-demo.php en production');
} catch (\Throwable $e) {
    $line('fail', 'base inaccessible : ' . $e->getMessage());
}

fwrite(STDOUT, PHP_EOL . "Résultat : $ok OK, $warn attention, $fail bloquant(s)." . PHP_EOL);
exit($fail > 0 ? 1 : 0);
