<?php

declare(strict_types=1);

/**
 * Processus de test : envoie une soumission à l'API (en mémoire, base eftp_nf_test) dès que la
 * « barrière » existe, pour provoquer de vraies requêtes concurrentes (CollectionsApiTest).
 * Arguments : <jeton> <fichier JSON {uuid, body}> <dossier de stockage> <fichier barrière>
 */

use App\Core\App;
use App\Core\Database;
use App\Http\Request;

require __DIR__ . '/../../vendor/autoload.php';

[, $token, $payloadFile, $storage, $barrier] = $argv;
$config = require __DIR__ . '/../../config/config.php';
$config['app']['env'] = 'test';
$config['db']['name'] = 'eftp_nf_test';
$config['paths']['storage'] = $storage;
$app = new App($config, new Database($config['db']));
(require __DIR__ . '/../../config/routes.php')($app);
$payload = json_decode((string) file_get_contents($payloadFile), true, 512, JSON_THROW_ON_ERROR);

$deadline = microtime(true) + 20;
while (!file_exists($barrier) && microtime(true) < $deadline) {
    usleep(500);
}
$r = $app->handle(new Request('POST', App::PREFIX . '/collections/' . $payload['uuid'] . '/submissions', [], $payload['body'],
    ['authorization' => 'Bearer ' . $token], '127.0.0.1'));
echo json_encode(['status' => $r->status, 'code' => $r->body['error']['code'] ?? null, 'revision' => $r->body['data']['revision'] ?? null]);
