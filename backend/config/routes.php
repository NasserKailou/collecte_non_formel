<?php

declare(strict_types=1);

use App\Core\App;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Campaigns\AssignmentService;
use App\Modules\Campaigns\CampaignService;
use App\Modules\Collections\ReviewService;
use App\Modules\Collections\SubmissionService;
use App\Modules\Collections\SyncService;
use App\Modules\Stats\ExportService;
use App\Modules\Stats\StatsService;
use App\Modules\Structures\StructureService;
use App\Modules\Users\UserImporter;
use App\Modules\Users\UserService;

/**
 * Routes de l'API /api/v1 (contrat : docs/api/README.md).
 * Lot L1 : authentification, profil, référentiels, questionnaire, paramètres.
 */
return static function (App $app): void {
    $db = $app->db;

    /** Réponse avec ETag : 304 si le client possède déjà cette version (économie de données sur le terrain). */
    $cached = static function (Request $req, array $data): Response {
        $etag = '"' . hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '"';
        if ($req->header('if-none-match') === $etag) {
            return new Response(304, null, ['ETag' => $etag]);
        }
        return Response::data($data, 200, null, ['ETag' => $etag, 'Cache-Control' => 'private, must-revalidate']);
    };

    // ---------------------------------------------------------------- Authentification
    $app->route('POST', '/auth/login', static function (Request $req) use ($app): Response {
        $identifier = $req->string('identifier', 190);
        $password = $req->string('password', 200);
        if ($identifier === null || $password === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Identifiant et mot de passe obligatoires.');
        }
        $device = $req->input('device');
        if ($device !== null && !is_array($device)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Champ « device » invalide.');
        }
        $result = $app->auth->login($identifier, $password, $device, $req->ip, $req->header('user-agent'));
        if ($req->input('session') === true && $device === null) {
            // Back-office web : le jeton reste dans un cookie HttpOnly (inaccessible au JavaScript) ; seul le jeton CSRF est renvoyé.
            $familyId = (string) $app->db->fetchValue("SELECT family_id FROM api_tokens WHERE token_hash = ? AND type = 'ACCESS'", [hash('sha256', $result['access_token'])]);
            $cookie = $app->sessionCookie($result['access_token']);
            unset($result['access_token'], $result['refresh_token'], $result['offline_policy']);
            return Response::data($result + ['csrf_token' => $app->csrfToken($familyId)], 200, null, ['Set-Cookie' => $cookie]);
        }
        return Response::data($result);
    }, ['auth' => false]);

    $app->route('POST', '/auth/refresh', static function (Request $req) use ($app): Response {
        $token = $req->string('refresh_token', 200);
        if ($token === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Jeton de rafraîchissement obligatoire.');
        }
        return Response::data($app->auth->refresh($token));
    }, ['auth' => false]);

    $app->route('POST', '/auth/logout', static function (Request $req, array $p, array $user) use ($app): Response {
        $app->auth->logout($user);
        return Response::noContent()->withHeader('Set-Cookie', $app->sessionCookie(null));
    }, ['allow_pending' => true]);

    $app->route('POST', '/auth/password', static function (Request $req, array $p, array $user) use ($app): Response {
        $current = $req->string('current_password', 200);
        $new = $req->string('new_password', 200);
        if ($current === null || $new === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Mot de passe actuel et nouveau mot de passe obligatoires.');
        }
        $app->auth->changePassword($user, $current, $new);
        return Response::noContent();
    }, ['allow_pending' => true]);

    // Test de connexion (bouton « Tester la connexion » de la tablette) : public, sans contrôle de version, lecture seule.
    $app->route('GET', '/health', static function () use ($db, $app): Response {
        $dbOk = true;
        try {
            $db->fetchValue('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        return Response::data(['status' => $dbOk ? 'ok' : 'degraded', 'database' => $dbOk, 'server_time' => date('c'),
            'min_app_version' => $app->minAppVersion()], $dbOk ? 200 : 503);
    }, ['auth' => false, 'any_version' => true]);

    $app->route('GET', '/me', static fn(Request $req, array $p, array $user) => Response::data(
        $app->auth->profile($user['id']) + ($user['via_cookie'] ? ['csrf_token' => $app->csrfToken($user['family_id'])] : [])),
        ['allow_pending' => true]);

    // ---------------------------------------------------------------- Référentiels
    $app->route('GET', '/referentials/territory', static function (Request $req) use ($db, $cached): Response {
        return $cached($req, [
            'regions' => $db->fetchAll('SELECT id, code_renacom AS code, name FROM regions WHERE valid_to IS NULL ORDER BY name'),
            'departments' => $db->fetchAll('SELECT id, region_id, code_renacom AS code, name, is_city FROM departments WHERE valid_to IS NULL ORDER BY name'),
            'communes' => $db->fetchAll('SELECT id, department_id, code_renacom AS code, name, centroid_lat, centroid_lng FROM communes WHERE valid_to IS NULL ORDER BY name'),
        ]);
    });

    $app->route('GET', '/referentials/trades', static function (Request $req) use ($db, $cached): Response {
        return $cached($req, [
            'sectors' => $db->fetchAll('SELECT id, code, label FROM sectors ORDER BY sort'),
            'trades' => $db->fetchAll('SELECT id, sector_id, label FROM trades WHERE active = 1 ORDER BY sector_id, label'),
        ]);
    });

    $app->route('GET', '/referentials/structure-types', static function (Request $req) use ($db, $cached): Response {
        return $cached($req, $db->fetchAll('SELECT code, label, is_other FROM structure_types WHERE active = 1 ORDER BY sort'));
    });

    $app->route('GET', '/questionnaires/{version}', static function (Request $req, array $p) use ($db): Response {
        $row = $db->fetchOne(
            "SELECT definition_json, checksum FROM questionnaire_versions WHERE version_code = ? AND status = 'PUBLIEE'", [$p['version']]);
        if ($row === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Version de questionnaire introuvable.');
        }
        $etag = '"' . $row['checksum'] . '"';
        if ($req->header('if-none-match') === $etag) {
            return new Response(304, null, ['ETag' => $etag]);
        }
        // Version publiée = immuable : mise en cache définitive côté client.
        return Response::data(json_decode($row['definition_json'], true, 512, JSON_THROW_ON_ERROR), 200, null,
            ['ETag' => $etag, 'Cache-Control' => 'private, max-age=31536000, immutable']);
    });

    // ---------------------------------------------------------------- Territoire (listes pour les formulaires)
    $app->route('GET', '/regions', static fn() => Response::data(
        $db->fetchAll('SELECT id, code_renacom AS code, name FROM regions WHERE valid_to IS NULL ORDER BY name')));
    $app->route('GET', '/departments', static function (Request $req) use ($db): Response {
        $rid = $req->queryInt('region_id');
        return Response::data($db->fetchAll(
            'SELECT id, region_id, code_renacom AS code, name, is_city FROM departments WHERE valid_to IS NULL'
            . ($rid !== null ? ' AND region_id = ?' : '') . ' ORDER BY name', $rid !== null ? [$rid] : []));
    });
    $app->route('GET', '/communes', static function (Request $req) use ($db): Response {
        $did = $req->queryInt('department_id');
        return Response::data($db->fetchAll(
            'SELECT id, department_id, code_renacom AS code, name FROM communes WHERE valid_to IS NULL'
            . ($did !== null ? ' AND department_id = ?' : '') . ' ORDER BY name', $did !== null ? [$did] : []));
    });

    // ---------------------------------------------------------------- Utilisateurs (lot L2)
    $users = new UserService($db);
    $importer = new UserImporter($db, $app->config['paths']['storage']);
    $objectBody = static function (Request $req): array {
        if (!is_array($req->body) || ($req->body !== [] && array_is_list($req->body))) {
            throw new HttpException(400, 'BAD_REQUEST', 'Corps JSON (objet) attendu.');
        }
        return $req->body;
    };

    $app->route('GET', '/users', static function (Request $req, array $p, array $user) use ($users): Response {
        $role = $req->queryString('role', 20);
        $status = $req->queryString('status', 10);
        if ($role !== null && !isset(UserService::ROLE_LEVELS[$role])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre « role » invalide.');
        }
        if ($status !== null && !in_array($status, ['ACTIF', 'INACTIF'], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre « status » invalide.');
        }
        $page = $req->pagination();
        [$rows, $total] = $users->list($users->actor($user), [
            'q' => $req->queryString('q', 100), 'role' => $role, 'status' => $status,
            'region_id' => $req->queryInt('region_id'), 'department_id' => $req->queryInt('department_id'),
            'supervisor_uuid' => $req->queryString('supervisor_uuid', 36),
        ], $page);
        return Response::data($rows, 200, ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total]);
    }, ['permission' => 'user.view']);

    $app->route('POST', '/users', static function (Request $req, array $p, array $user) use ($users, $objectBody): Response {
        return Response::data($users->create($users->actor($user), $objectBody($req), $req->ip), 201);
    }, ['permission' => 'user.manage']);

    $app->route('GET', '/users/{uuid}', static fn(Request $req, array $p, array $user) =>
        Response::data($users->get($users->actor($user), $p['uuid'])), ['permission' => 'user.view']);

    $app->route('PATCH', '/users/{uuid}', static function (Request $req, array $p, array $user) use ($users, $objectBody): Response {
        return Response::data($users->update($users->actor($user), $p['uuid'], $objectBody($req), $req->ip));
    }, ['permission' => 'user.manage']);

    $app->route('PUT', '/users/{uuid}/scopes', static function (Request $req, array $p, array $user) use ($users, $objectBody): Response {
        $body = $objectBody($req);
        return Response::data($users->setScopes($users->actor($user), $p['uuid'], $body['scopes'] ?? null, $req->ip));
    }, ['permission' => 'user.manage']);

    foreach (['activate' => true, 'deactivate' => false] as $action => $active) {
        $app->route('POST', "/users/{uuid}/$action", static function (Request $req, array $p, array $user) use ($users, $active): Response {
            return Response::data($users->setStatus($users->actor($user), $p['uuid'], $active, $req->string('reason', 500), $req->ip));
        }, ['permission' => 'user.manage']);
    }

    $app->route('POST', '/users/{uuid}/reset-password', static fn(Request $req, array $p, array $user) =>
        Response::data($users->resetPassword($users->actor($user), $p['uuid'], $req->ip)), ['permission' => 'user.manage']);

    $app->route('GET', '/users/{uuid}/devices', static fn(Request $req, array $p, array $user) =>
        Response::data($users->devices($users->actor($user), $p['uuid'])), ['permission' => 'user.manage']);

    $app->route('POST', '/devices/{id}/revoke', static function (Request $req, array $p, array $user) use ($users): Response {
        if (!ctype_digit($p['id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Appareil introuvable.');
        }
        $users->revokeDevice($users->actor($user), (int) $p['id'], $req->string('reason', 500), $req->ip);
        return Response::noContent();
    }, ['permission' => 'user.manage']);

    // ---------------------------------------------------------------- Import Excel des agents (lot L2)
    $app->route('GET', '/imports/users/template', static function (Request $req, array $p, array $user) use ($importer, $users, $app): Response {
        // Modèle propre à chaque utilisateur : listes déroulantes limitées à son périmètre.
        $path = $importer->writeTemplate($app->config['paths']['storage'] . '/templates/modele-import-agents-' . (int) $user['id'] . '.xlsx',
            $users->actor($user));
        return Response::file($path, 'modele-import-agents.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }, ['permission' => 'user.import']);

    $app->route('POST', '/imports/users', static function (Request $req, array $p, array $user) use ($users, $importer): Response {
        $file = $req->file('file') ?? throw new HttpException(422, 'VALIDATION_FAILED', 'Fichier attendu dans le champ « file » (multipart/form-data).');
        return Response::data($importer->preview($users->actor($user), $file), 201);
    }, ['permission' => 'user.import']);

    $app->route('GET', '/imports/{id}', static fn(Request $req, array $p, array $user) =>
        Response::data($importer->get($users->actor($user), $p['id'])), ['permission' => 'user.import']);

    $app->route('POST', '/imports/{id}/commit', static fn(Request $req, array $p, array $user) =>
        Response::data($importer->commit($users->actor($user), $p['id'], $req->ip)), ['permission' => 'user.import']);

    $app->route('POST', '/imports/{id}/cancel', static fn(Request $req, array $p, array $user) =>
        Response::data($importer->cancel($users->actor($user), $p['id'], $req->ip)), ['permission' => 'user.import']);

    $app->route('GET', '/imports/{id}/report', static function (Request $req, array $p, array $user) use ($users, $importer): Response {
        [$path, $name, $mime] = $importer->report($users->actor($user), $p['id'], $req->ip);
        return Response::file($path, $name, $mime);
    }, ['permission' => 'user.import']);

    // ---------------------------------------------------------------- Structures (lot L3)
    $structures = new StructureService($db);
    $campaigns = new CampaignService($db);
    $assignments = new AssignmentService($db);
    $intParam = static function (string $v, string $what): int {
        if (!ctype_digit($v) || strlen($v) > 9) {
            throw new HttpException(404, 'NOT_FOUND', "$what introuvable.");
        }
        return (int) $v;
    };

    $app->route('GET', '/structures', static function (Request $req, array $p, array $user) use ($users, $structures): Response {
        $status = $req->queryString('status', 20);
        if ($status !== null && !in_array($status, ['ACTIVE', 'PROPOSEE', 'FERMEE', 'REJETEE', 'FUSIONNEE'], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre « status » invalide.');
        }
        $page = $req->pagination();
        [$rows, $total] = $structures->list($users->actor($user), [
            'q' => $req->queryString('q', 100), 'status' => $status, 'type_code' => $req->queryString('type_code', 2),
            'commune_id' => $req->queryInt('commune_id'), 'department_id' => $req->queryInt('department_id'), 'region_id' => $req->queryInt('region_id'),
        ], $page);
        return Response::data($rows, 200, ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total]);
    }, ['permission' => 'structure.view']);

    $app->route('POST', '/structures', static fn(Request $req, array $p, array $user) =>
        Response::data($structures->create($users->actor($user), $objectBody($req), $req->ip), 201), ['permission' => 'structure.manage']);

    $app->route('POST', '/structures/proposals', static function (Request $req, array $p, array $user) use ($users, $structures, $objectBody): Response {
        $r = $structures->propose($users->actor($user), $objectBody($req), $req->ip);
        return Response::data($r, $r['replayed'] ? 200 : 201);
    }, ['permission' => 'structure.propose']);

    $app->route('GET', '/structures/{uuid}', static fn(Request $req, array $p, array $user) =>
        Response::data($structures->get($users->actor($user), $p['uuid'])), ['permission' => 'structure.view']);

    $app->route('GET', '/structures/{uuid}/history', static fn(Request $req, array $p, array $user) =>
        Response::data($structures->history($users->actor($user), $p['uuid'])), ['permission' => 'structure.view']);

    $app->route('PATCH', '/structures/{uuid}', static fn(Request $req, array $p, array $user) =>
        Response::data($structures->update($users->actor($user), $p['uuid'], $objectBody($req), $req->ip)), ['permission' => 'structure.manage']);

    foreach (['approve' => 'approve', 'reject' => 'reject', 'merge' => 'merge'] as $action => $method) {
        $app->route('POST', "/structures/{uuid}/$action", static fn(Request $req, array $p, array $user) =>
            Response::data($structures->$method($users->actor($user), $p['uuid'], is_array($req->body) ? $req->body : [], $req->ip)),
            ['permission' => 'structure.manage']);
    }
    foreach (['close' => true, 'reopen' => false] as $action => $closed) {
        $app->route('POST', "/structures/{uuid}/$action", static fn(Request $req, array $p, array $user) =>
            Response::data($structures->setClosed($users->actor($user), $p['uuid'], $closed, is_array($req->body) ? $req->body : [], $req->ip)),
            ['permission' => 'structure.manage']);
    }

    // ---------------------------------------------------------------- Années et campagnes (lot L3)
    $app->route('GET', '/academic-years', static fn() => Response::data($campaigns->listYears()));
    $app->route('POST', '/academic-years', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->createYear($users->actor($user), $objectBody($req), $req->ip), 201), ['permission' => 'campaign.manage']);
    $app->route('PATCH', '/academic-years/{id}', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->updateYear($users->actor($user), $intParam($p['id'], 'Année'), $objectBody($req), $req->ip)),
        ['permission' => 'campaign.manage']);

    $app->route('GET', '/campaigns', static function (Request $req, array $p, array $user) use ($users, $campaigns): Response {
        $status = $req->queryString('status', 20);
        if ($status !== null && !isset(CampaignService::TRANSITIONS[$status])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre « status » invalide.');
        }
        return Response::data($campaigns->list($users->actor($user), ['academic_year_id' => $req->queryInt('academic_year_id'), 'status' => $status]));
    });
    $app->route('POST', '/campaigns', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->create($users->actor($user), $objectBody($req), $req->ip), 201), ['permission' => 'campaign.manage']);
    $app->route('GET', '/campaigns/{id}', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->get($users->actor($user), $intParam($p['id'], 'Campagne'))));
    $app->route('PATCH', '/campaigns/{id}', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->update($users->actor($user), $intParam($p['id'], 'Campagne'), $objectBody($req), $req->ip)),
        ['permission' => 'campaign.manage']);
    $app->route('POST', '/campaigns/{id}/transition', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->transition($users->actor($user), $intParam($p['id'], 'Campagne'), $objectBody($req), $req->ip)),
        ['permission' => 'campaign.manage']);
    $app->route('POST', '/campaigns/{id}/structures', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->target($users->actor($user), $intParam($p['id'], 'Campagne'), $objectBody($req), $req->ip)),
        ['permission' => 'campaign.manage']);
    $app->route('DELETE', '/campaigns/{id}/structures/{uuid}', static function (Request $req, array $p, array $user) use ($users, $campaigns, $intParam): Response {
        $campaigns->untarget($users->actor($user), $intParam($p['id'], 'Campagne'), $p['uuid'], $req->ip);
        return Response::noContent();
    }, ['permission' => 'campaign.manage']);
    $app->route('POST', '/campaigns/{id}/clone-from/{source}', static fn(Request $req, array $p, array $user) =>
        Response::data($campaigns->cloneFrom($users->actor($user), $intParam($p['id'], 'Campagne'), $intParam($p['source'], 'Campagne source'),
            is_array($req->body) ? $req->body : [], $req->ip)), ['permission' => 'campaign.manage']);

    // ---------------------------------------------------------------- Affectations (lot L3)
    $app->route('GET', '/campaigns/{id}/assignments', static function (Request $req, array $p, array $user) use ($users, $assignments, $intParam): Response {
        $page = $req->pagination();
        [$rows, $total] = $assignments->list($users->actor($user), $intParam($p['id'], 'Campagne'), [
            'unassigned' => ($req->query['unassigned'] ?? '') === '1', 'agent_uuid' => $req->queryString('agent_uuid', 36),
            'commune_id' => $req->queryInt('commune_id'), 'department_id' => $req->queryInt('department_id'), 'q' => $req->queryString('q', 100),
        ], $page);
        return Response::data($rows, 200, ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total]);
    }, ['permission' => 'assignment.manage']);
    $app->route('POST', '/campaigns/{id}/assignments', static fn(Request $req, array $p, array $user) =>
        Response::data($assignments->assign($users->actor($user), $intParam($p['id'], 'Campagne'), $objectBody($req), $req->ip)),
        ['permission' => 'assignment.manage']);
    $app->route('DELETE', '/assignments/{id}', static function (Request $req, array $p, array $user) use ($users, $assignments, $intParam): Response {
        $assignments->end($users->actor($user), $intParam($p['id'], 'Affectation'), $req->string('reason', 255), $req->ip);
        return Response::noContent();
    }, ['permission' => 'assignment.manage']);
    $app->route('PUT', '/campaigns/{id}/agents/{agent}/areas', static fn(Request $req, array $p, array $user) =>
        Response::data($assignments->setAreas($users->actor($user), $intParam($p['id'], 'Campagne'), $p['agent'], $objectBody($req)['commune_ids'] ?? null, $req->ip)),
        ['permission' => 'assignment.manage']);

    // ---------------------------------------------------------------- Collecte et synchronisation (lot L4)
    $submissions = new SubmissionService($db, $app->config['paths']['storage']);
    $reviews = new ReviewService($db);
    $sync = new SyncService($db);
    // via_cookie : session du back-office (saisie web ou papier) ; sinon jeton porteur de la tablette.
    $agentOf = static fn(array $user): array => ['id' => (int) $user['id'], 'device_id' => $user['device_id'], 'via_cookie' => !empty($user['via_cookie'])];

    $app->route('GET', '/sync/pull', static fn(Request $req, array $p, array $user) =>
        Response::data($sync->pull($agentOf($user), $req->queryString('since', 40), $req->queryInt('campaign_id'))), ['permission' => 'collection.submit']);

    $app->route('POST', '/collections/{uuid}/submissions', static function (Request $req, array $p, array $user) use ($submissions, $agentOf): Response {
        $bytes = (int) ($req->header('content-length') ?? strlen((string) json_encode($req->body)));
        if ($bytes > SubmissionService::MAX_PAYLOAD_BYTES) {
            throw new HttpException(413, 'PAYLOAD_TOO_LARGE', 'Collecte trop volumineuse (1 Mo au plus).');
        }
        if (!is_array($req->body) || array_is_list($req->body)) {
            throw new HttpException(400, 'BAD_REQUEST', 'Corps JSON (objet) attendu.');
        }
        $r = $submissions->submit($agentOf($user), $p['uuid'], $req->body, $req->header('x-app-version'), $bytes);
        return Response::data($r['body'], $r['status']);
    }, ['permission' => 'collection.submit']);

    $app->route('POST', '/collections/{uuid}/attachments', static function (Request $req, array $p, array $user) use ($submissions, $agentOf): Response {
        $file = $req->file('file') ?? throw new HttpException(422, 'VALIDATION_FAILED', 'Photo attendue dans le champ « file » (multipart/form-data).');
        $kind = is_string($req->query['kind'] ?? null) ? $req->query['kind'] : 'FICHE_SIGNEE';
        $r = $submissions->addAttachment($agentOf($user), $p['uuid'], $file, $kind);
        return Response::data($r, $r['replayed'] ? 200 : 201);
    }, ['permission' => 'collection.submit']);

    // ---------------------------------------------------------------- Versions de l'application mobile (APK)
    $releases = new \App\Modules\Releases\AppReleaseService($db, $app->config['paths']['storage'], \Closure::fromCallable([$app, 'sign']));
    $app->route('GET', '/app/releases/latest', static fn(Request $req) =>
        Response::data($releases->latest($req->header('x-app-version'), $app->minAppVersion())), ['any_version' => true]);
    $app->route('GET', '/app/releases', static fn() => Response::data($releases->list()), ['permission' => 'settings.manage']);
    $app->route('POST', '/app/releases', static function (Request $req, array $p, array $user) use ($releases): Response {
        $file = $req->file('file') ?? throw new HttpException(422, 'VALIDATION_FAILED', 'Fichier APK attendu dans le champ « file ».');
        return Response::data($releases->publish((int) $user['id'], $file, is_array($req->body) ? $req->body : [], $req->ip), 201);
    }, ['permission' => 'settings.manage']);
    $app->route('POST', '/app/releases/{id}/retire', static fn(Request $req, array $p, array $user) =>
        Response::data($releases->retire((int) $user['id'], (int) $p['id'], $req->ip)), ['permission' => 'settings.manage']);
    $app->route('GET', '/app/releases/{id}/download', static function (Request $req, array $p) use ($releases): Response {
        [$path, $name] = $releases->fileFor((int) $p['id'], (string) ($req->query['expires'] ?? ''), (string) ($req->query['signature'] ?? ''));
        return Response::file($path, $name, 'application/vnd.android.package-archive');
    }, ['auth' => false, 'any_version' => true]);

    // ---------------------------------------------------------------- Centre découvert sur le terrain (tablette ou web)
    $field = new \App\Modules\Collections\FieldStructureService($db, $structures);
    $app->route('POST', '/campaigns/{id}/field-structures', static function (Request $req, array $p, array $user) use ($field, $users, $objectBody): Response {
        $r = $field->register($users->actor($user), (int) $p['id'], $objectBody($req), $req->ip);
        return Response::data($r, $r['replayed'] ? 200 : 201);
    }, ['permission' => 'collection.submit']);

    // ---------------------------------------------------------------- Saisie web (directe ou fiche papier)
    $entry = new \App\Modules\Collections\EntryService($db, $submissions);
    $app->route('GET', '/entry/assignments', static fn(Request $req, array $p, array $user) =>
        Response::data($entry->assignments((int) $user['id'])), ['permission' => 'collection.submit']);
    $app->route('GET', '/entry/{cs}', static fn(Request $req, array $p, array $user) =>
        Response::data($entry->open((int) $user['id'], (int) $p['cs'])), ['permission' => 'collection.submit']);
    $app->route('PUT', '/entry/{cs}/draft', static fn(Request $req, array $p, array $user) =>
        Response::data($entry->saveDraft((int) $user['id'], (int) $p['cs'], $objectBody($req))), ['permission' => 'collection.submit']);
    $app->route('DELETE', '/entry/{cs}/draft', static function (Request $req, array $p, array $user) use ($entry): Response {
        $entry->discardDraft((int) $user['id'], (int) $p['cs']);
        return Response::noContent();
    }, ['permission' => 'collection.submit']);

    $app->route('GET', '/rejection-reasons', static fn() => Response::data(
        $db->fetchAll('SELECT id, code, label FROM rejection_reasons WHERE active = 1 ORDER BY sort')));

    $app->route('GET', '/collections', static function (Request $req, array $p, array $user) use ($users, $reviews): Response {
        $status = $req->queryString('status', 20);
        if ($status !== null && !in_array($status, ['SOUMISE', 'EN_CONTROLE', 'VALIDEE', 'INVALIDEE', 'REOUVERTE'], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Filtre « status » invalide.');
        }
        $page = $req->pagination();
        [$rows, $total] = $reviews->list($users->actor($user), [
            'campaign_id' => $req->queryInt('campaign_id'), 'academic_year_id' => $req->queryInt('academic_year_id'), 'status' => $status,
            'review_level' => $req->queryInt('review_level'), 'region_id' => $req->queryInt('region_id'), 'department_id' => $req->queryInt('department_id'),
            'commune_id' => $req->queryInt('commune_id'), 'agent_uuid' => $req->queryString('agent_uuid', 36), 'type_code' => $req->queryString('type_code', 2),
            'q' => $req->queryString('q', 100), 'awaiting_me' => ($req->query['awaiting_me'] ?? '') === '1',
        ], $page);
        return Response::data($rows, 200, ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total]);
    }, ['permission' => 'collection.view']);

    $app->route('GET', '/collections/{uuid}', static fn(Request $req, array $p, array $user) =>
        Response::data($reviews->get($users->actor($user), $p['uuid'])), ['permission' => 'collection.view']);
    $app->route('GET', '/collections/{uuid}/submissions', static fn(Request $req, array $p, array $user) =>
        Response::data($reviews->submissions($users->actor($user), $p['uuid'])), ['permission' => 'collection.view']);
    $app->route('GET', '/collections/{uuid}/previous', static fn(Request $req, array $p, array $user) =>
        Response::data($reviews->previous($users->actor($user), $p['uuid'])), ['permission' => 'collection.view']);
    $app->route('GET', '/collections/{uuid}/attachments/{id}', static function (Request $req, array $p, array $user) use ($users, $reviews, $intParam, $app): Response {
        [$path, $name, $mime] = $reviews->attachment($users->actor($user), $p['uuid'], $intParam($p['id'], 'Pièce jointe'), $app->config['paths']['storage']);
        return Response::file($path, $name, $mime);
    }, ['permission' => 'collection.view']);

    foreach (['claim', 'release'] as $action) {
        $app->route('POST', "/collections/{uuid}/$action", static fn(Request $req, array $p, array $user) =>
            Response::data($reviews->$action($users->actor($user), $p['uuid'], $req->ip)), ['permission' => 'collection.review']);
    }
    foreach (['validate', 'invalidate'] as $action) {
        $app->route('POST', "/collections/{uuid}/$action", static fn(Request $req, array $p, array $user) =>
            Response::data($reviews->$action($users->actor($user), $p['uuid'], is_array($req->body) ? $req->body : [], $req->ip)), ['permission' => 'collection.review']);
    }
    // Validation groupée : chaque collecte suit les mêmes contrôles que la validation unitaire ; les refus sont détaillés.
    $app->route('POST', '/collections/validate-batch', static fn(Request $req, array $p, array $user) =>
        Response::data($reviews->validateMany($users->actor($user), is_array($req->body) ? ($req->body['uuids'] ?? null) : null,
            is_array($req->body) ? $req->body : [], $req->ip)), ['permission' => 'collection.review']);
    $app->route('POST', '/collections/{uuid}/reopen', static fn(Request $req, array $p, array $user) =>
        Response::data($reviews->reopen($users->actor($user), $p['uuid'], is_array($req->body) ? $req->body : [], $req->ip)), ['permission' => 'collection.reopen']);

    // ---------------------------------------------------------------- Statistiques et exports (lot L6)
    $stats = new StatsService($db);
    $exports = new ExportService($db, $app->config);
    $campaignParam = static function (Request $req): int {
        return $req->queryInt('campaign_id') ?? throw new HttpException(422, 'VALIDATION_FAILED', 'Paramètre « campaign_id » obligatoire.');
    };

    $app->route('GET', '/stats/progress', static fn(Request $req, array $p, array $user) =>
        Response::data($stats->progress($users->actor($user), $campaignParam($req), $req->queryString('level', 20) ?? 'region')), ['permission' => 'stats.view']);
    $app->route('GET', '/stats/agents', static fn(Request $req, array $p, array $user) =>
        Response::data($stats->agents($users->actor($user), $campaignParam($req))), ['permission' => 'stats.view']);
    $app->route('GET', '/stats/timeline', static fn(Request $req, array $p, array $user) =>
        Response::data($stats->timeline($users->actor($user), $campaignParam($req), $req->queryString('granularity', 10) ?? 'day')), ['permission' => 'stats.view']);
    $app->route('GET', '/stats/indicators', static fn(Request $req, array $p, array $user) =>
        Response::data($stats->indicators($users->actor($user), $campaignParam($req), $req->queryString('basis', 20), $req->queryString('group_by', 20))),
        ['permission' => 'stats.view']);
    $overview = new \App\Modules\Stats\OverviewService($db);
    $app->route('GET', '/stats/overview', static fn(Request $req, array $p, array $user) =>
        Response::data($overview->overview($users->actor($user), $campaignParam($req))), ['permission' => 'stats.view']);
    $map = new \App\Modules\Stats\MapService($db);
    $app->route('GET', '/stats/map', static fn(Request $req, array $p, array $user) =>
        Response::data($map->points($users->actor($user), $campaignParam($req))), ['permission' => 'stats.view']);
    $app->route('GET', '/stats/compare', static function (Request $req, array $p, array $user) use ($stats, $users): Response {
        $ids = explode(',', (string) $req->queryString('campaign_ids', 100));
        if (array_filter($ids, static fn($v) => !ctype_digit(trim($v))) !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'campaign_ids : identifiants séparés par des virgules (ex. 3,7).');
        }
        return Response::data($stats->compare($users->actor($user), $ids, $req->queryString('basis', 20)));
    }, ['permission' => 'stats.view']);

    $app->route('POST', '/exports', static fn(Request $req, array $p, array $user) =>
        Response::data($exports->request((int) $user['id'], $objectBody($req), $req->ip), 202), ['permission' => 'export.create']);
    $app->route('GET', '/exports', static fn(Request $req, array $p, array $user) =>
        Response::data($exports->myJobs((int) $user['id'])), ['permission' => 'export.create']);
    $app->route('GET', '/jobs/{id}', static fn(Request $req, array $p, array $user) =>
        Response::data($exports->job((int) $user['id'], $intParam($p['id'], 'Export'))), ['permission' => 'export.create']);
    $app->route('GET', '/files/{uuid}', static function (Request $req, array $p, array $user) use ($exports): Response {
        [$path, $name, $mime] = $exports->file((int) $user['id'], $p['uuid'], $req->ip);
        return Response::file($path, $name, $mime);
    }, ['permission' => 'export.create']);

    // ---------------------------------------------------------------- Paramètres
    $app->route('GET', '/settings', static fn() => Response::data(
        $db->fetchAll('SELECT setting_key AS `key`, value, value_type AS type, description, updated_at FROM system_settings ORDER BY setting_key')
    ), ['permission' => 'settings.manage']);

    $app->route('PATCH', '/settings', static function (Request $req, array $p, array $user) use ($db, $objectBody): Response {
        $body = $objectBody($req);
        $known = array_column($db->fetchAll('SELECT setting_key, value, value_type FROM system_settings'), null, 'setting_key');
        $errors = [];
        $updates = [];
        foreach ($body as $key => $value) {
            $s = $known[$key] ?? null;
            $ok = match ($s['value_type'] ?? null) {
                'int' => is_int($value) && $value >= 0,
                'float' => (is_int($value) || is_float($value)) && $value >= 0,
                'bool' => is_bool($value),
                'string' => is_string($value) && mb_strlen($value) <= 500,
                'json' => is_array($value),
                default => false,
            };
            if ($s === null || !$ok) {
                $errors[] = ['field' => (string) $key, 'message' => $s === null ? 'Paramètre inconnu.' : "Valeur de type {$s['value_type']} attendue."];
                continue;
            }
            if ($key === 'stats_basis' && !in_array($value, ['validated', 'submitted'], true)) {
                $errors[] = ['field' => 'stats_basis', 'message' => 'validated ou submitted.'];
                continue;
            }
            $updates[$key] = [$s['value'], match ($s['value_type']) {
                'bool' => $value ? '1' : '0', 'json' => json_encode($value, JSON_UNESCAPED_UNICODE), default => (string) $value,
            }];
        }
        if ($errors !== [] || $updates === []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Paramètres invalides.', ['errors' => $errors ?: [['field' => '', 'message' => 'Aucun paramètre fourni.']]]);
        }
        $db->transaction(function (\App\Core\Database $db) use ($updates, $user, $req): void {
            foreach ($updates as $key => [$before, $after]) {
                $db->execute('UPDATE system_settings SET value = ?, updated_by = ? WHERE setting_key = ?', [$after, $user['id'], $key]);
                (new \App\Core\Audit($db))->log($user['id'], 'SETTING_UPDATE', 'setting', $key, ['value' => $before], ['value' => $after], null, $req->ip);
            }
        });
        return Response::data($db->fetchAll('SELECT setting_key AS `key`, value, value_type AS type, description, updated_at FROM system_settings ORDER BY setting_key'));
    }, ['permission' => 'settings.manage']);

    // ---------------------------------------------------------------- Journaux (audit.view)
    $logs = [
        '/audit-logs' => ["SELECT a.id, a.created_at, u.matricule, a.action, a.entity_type, a.entity_id, a.reason, a.ip, a.before_json, a.after_json
                             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id", 'a', ['action' => 'a.action', 'entity_type' => 'a.entity_type', 'entity_id' => 'a.entity_id']],
        '/login-logs' => ["SELECT l.id, l.created_at, l.identifier, u.matricule, l.ip, l.channel, l.success, l.reason, l.user_agent
                             FROM login_logs l LEFT JOIN users u ON u.id = l.user_id", 'l', ['identifier' => 'l.identifier', 'channel' => 'l.channel']],
        '/sync-logs' => ["SELECT s.id, s.created_at, u.matricule, s.direction, s.endpoint, s.collection_uuid, s.http_status, s.outcome, s.detail, s.duration_ms
                            FROM sync_logs s JOIN users u ON u.id = s.user_id", 's', ['outcome' => 's.outcome', 'collection_uuid' => 's.collection_uuid']],
    ];
    foreach ($logs as $path => [$select, $alias, $filters]) {
        $app->route('GET', $path, static function (Request $req) use ($db, $select, $alias, $filters): Response {
            $where = ['1 = 1'];
            $params = [];
            foreach ($filters as $k => $col) {
                if (($v = $req->queryString($k, 100)) !== null) {
                    $where[] = "$col = ?";
                    $params[] = $v;
                }
            }
            if (($m = $req->queryString('matricule', 40)) !== null) {
                $where[] = 'u.matricule = ?';
                $params[] = $m;
            }
            foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
                if (($d = $req->queryString($k, 10)) !== null) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
                        throw new HttpException(422, 'VALIDATION_FAILED', "Paramètre « $k » : date AAAA-MM-JJ attendue.");
                    }
                    $where[] = "$alias.created_at $op ?";
                    $params[] = $k === 'to' ? "$d 23:59:59" : "$d 00:00:00";
                }
            }
            $page = $req->pagination();
            $w = implode(' AND ', $where);
            $total = (int) $db->fetchValue("SELECT COUNT(*) FROM ($select WHERE $w) x", $params);
            $rows = $db->fetchAll("$select WHERE $w ORDER BY $alias.id DESC LIMIT {$page['per_page']} OFFSET {$page['offset']}", $params);
            return Response::data($rows, 200, ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total]);
        }, ['permission' => 'audit.view']);
    }
};
