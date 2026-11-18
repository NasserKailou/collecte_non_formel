<?php

declare(strict_types=1);

/**
 * Amorçage commun (web, CLI, tests) : autoload, configuration, fuseau horaire, base.
 * @return array{config: array, db: \App\Core\Database}
 */

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);
if ($config['app']['env'] === 'prod') {
    // Production : aucune erreur PHP affichée (même fatale, avant le gestionnaire de l'application) ; journal seulement.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
mb_internal_encoding('UTF-8');

return [
    'config' => $config,
    'db' => new \App\Core\Database($config['db']),
];
