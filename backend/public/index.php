<?php

declare(strict_types=1);

/** Contrôleur frontal de l'API /api/v1. Seul ce dossier doit être exposé par le serveur web. */

['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';

$app = new App\Core\App($config, $db);
(require __DIR__ . '/../config/routes.php')($app);

try {
    $request = App\Http\Request::fromGlobals($config['app']['base_path']);
    $response = $app->handle($request);
} catch (App\Http\HttpException $e) {
    $response = App\Http\Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details);
}
$response->send();
