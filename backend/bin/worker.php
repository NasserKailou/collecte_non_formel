<?php

declare(strict_types=1);

/**
 * Traitement des tâches de fond (exports) et purge des fichiers expirés.
 *
 * Usage :
 *   php bin/worker.php --once        traite les tâches en attente puis s'arrête (tâche planifiée toutes les minutes)
 *   php bin/worker.php               boucle (service), pause de 5 s quand la file est vide
 *
 * En production : EFTP_EXPORTS_INLINE=0 côté serveur web, et ce script en tâche planifiée.
 */

use App\Modules\Stats\ExportService;

['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';

$once = in_array('--once', $argv, true);
ini_set('memory_limit', '1024M');
$service = new ExportService($db, $config);

do {
    $done = $service->runPending(20);
    $purged = $service->purgeExpired();
    if ($done > 0 || $purged > 0) {
        fwrite(STDOUT, sprintf("[%s] %d export(s) traité(s), %d fichier(s) expiré(s) supprimé(s)\n", date('c'), $done, $purged));
    }
    if (!$once && $done === 0) {
        sleep(5);
    }
} while (!$once);
