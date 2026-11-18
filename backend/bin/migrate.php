<?php

declare(strict_types=1);

/**
 * Usage :
 *   php bin/migrate.php            applique les migrations en attente
 *   php bin/migrate.php --fresh    supprime toutes les tables puis réapplique tout (interdit en production)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';

$migrator = new App\Core\Migrator($db, $config['paths']['migrations']);
$out = static fn(string $m) => fwrite(STDOUT, $m . PHP_EOL);

try {
    if (in_array('--fresh', $argv, true)) {
        $out("Suppression de toutes les tables de « {$db->databaseName()} »…");
        $migrator->dropAll($config['app']['env']);
    }
    $done = $migrator->migrate($out);
    $out($done === [] ? 'Aucune migration en attente.' : count($done) . ' migration(s) appliquée(s).');
} catch (Throwable $e) {
    fwrite(STDERR, 'ÉCHEC : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
