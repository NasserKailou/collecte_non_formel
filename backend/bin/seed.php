<?php

declare(strict_types=1);

/**
 * Charge les données de référence (idempotent, rejouable) :
 *   1. rôles, permissions, types de structure, motifs, paramètres ;
 *   2. référentiel territorial (renacom.xlsx à la racine du projet) ;
 *   3. secteurs et métiers (listes_metiers.xlsx) ;
 *   4. publication des versions du questionnaire (shared/questionnaire/eftp-nf-*.json : 2025.1, 2025.2…).
 *
 * Usage : php bin/seed.php [--renacom=chemin] [--metiers=chemin]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['renacom::', 'metiers::']);
$root = $config['paths']['root'];
$renacom = $opt['renacom'] ?? "$root/renacom.xlsx";
$metiers = $opt['metiers'] ?? "$root/listes_metiers.xlsx";
$out = static fn(string $m) => fwrite(STDOUT, $m . PHP_EOL);

try {
    (new App\Seed\BaseSeeder($db))->run();
    $out('Données de base : OK (rôles, permissions, types de structure, motifs, paramètres).');

    $t = (new App\Modules\Territory\TerritoryImporter($db))->importFile($renacom);
    $out(sprintf('Territoire : %d régions, %d départements, %d communes (%d créés, %d mis à jour).',
        $t['regions'], $t['departments'], $t['communes'], $t['created'], $t['updated']));

    $m = (new App\Modules\Referentials\TradeImporter($db))->importFile($metiers);
    $out(sprintf('Métiers : %d secteurs, %d métiers actifs, %d doublons importés comme alias, %d métier « autre ».',
        $m['sectors'], $m['active'], $m['aliases'], $m['other']));
    if ($m['provisional'] !== []) {
        $out('  Rattachements PROVISOIRES à faire valider par la DSD :');
        foreach ($m['provisional'] as $p) {
            $out("    - $p");
        }
    }

    $files = glob($config['paths']['shared'] . '/questionnaire/eftp-nf-*.json');
    natsort($files);
    foreach ($files as $file) {
        $q = (new App\Modules\Questionnaires\QuestionnairePublisher($db))->publishFile($file);
        $out('Questionnaire ' . basename($file, '.json') . " : {$q['status']} (version #{$q['version_id']}).");
    }
} catch (App\Core\ImportException $e) {
    fwrite(STDERR, 'IMPORT REJETÉ : ' . $e->getMessage() . PHP_EOL);
    foreach ($e->errors() as $err) {
        fwrite(STDERR, "  - $err" . PHP_EOL);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ÉCHEC : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
