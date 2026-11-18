<?php

declare(strict_types=1);

/**
 * Jeu de DÉMONSTRATION (données fictives, clairement identifiées « DEMO » / « DÉMO ») pour essayer la plateforme :
 * un compte par profil, 12 structures, une campagne ouverte à 2 niveaux de validation, des affectations et
 * des collectes à tous les stades (soumises, en contrôle, validées niveau 1, validées, invalidée).
 *
 * Usage : php bin/seed-demo.php [--reset-passwords]
 * - Refusé si EFTP_ENV=prod.
 * - Relançable : rien n'est dupliqué ; les mots de passe ne changent qu'avec --reset-passwords.
 * - Identifiants écrits dans storage/demo-identifiants.txt (hors racine web) et affichés à l'écran.
 * - Territoire réel (RENACOM) : région de Tillabéri, départements et communes pris dans la base.
 */

use App\Core\Database;
use App\Core\Text;
use App\Modules\Auth\AuthService;
use App\Modules\Collections\ReviewService;
use App\Modules\Collections\SubmissionService;
use App\Modules\Users\Scope;

['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';
/** @var Database $db */

if ($config['app']['env'] === 'prod') {
    fwrite(STDERR, "Refusé : jeu de démonstration interdit en production.\n");
    exit(1);
}
$resetPasswords = in_array('--reset-passwords', $argv, true);

// ------------------------------------------------------------------ Territoire
$region = $db->fetchOne("SELECT id, name FROM regions WHERE name = 'TILLABERI'") ?? $db->fetchOne('SELECT id, name FROM regions ORDER BY name LIMIT 1');
$depts = $db->fetchAll('SELECT d.id, d.name FROM departments d WHERE d.region_id = ? AND (SELECT COUNT(*) FROM communes c WHERE c.department_id = d.id) >= 2 ORDER BY d.name LIMIT 2', [$region['id']]);
if (count($depts) < 2) {
    fwrite(STDERR, "Territoire insuffisant : lancer d'abord php bin/seed.php.\n");
    exit(1);
}
$communes = [];
foreach ($depts as $d) {
    $communes[$d['id']] = $db->fetchAll('SELECT id, name FROM communes WHERE department_id = ? ORDER BY name LIMIT 2', [$d['id']]);
}

// ------------------------------------------------------------------ Comptes
$accounts = [
    ['DEMO-SUPREG', 'SUPERVISEUR', 'Régional', 'M', 'SUP_REG', [['level' => 'REGION', 'region_id' => $region['id']]]],
    ['DEMO-SUPDEP1', 'SUPERVISEUR', "Départemental {$depts[0]['name']}", 'F', 'SUP_DEP', [['level' => 'DEPARTMENT', 'department_id' => $depts[0]['id']]]],
    ['DEMO-SUPDEP2', 'SUPERVISEUR', "Départemental {$depts[1]['name']}", 'M', 'SUP_DEP', [['level' => 'DEPARTMENT', 'department_id' => $depts[1]['id']]]],
    ['DEMO-AGENT1', 'AGENT', 'Un', 'F', 'AGENT', [['level' => 'COMMUNE', 'commune_id' => $communes[$depts[0]['id']][0]['id']]]],
    ['DEMO-AGENT2', 'AGENT', 'Deux', 'M', 'AGENT', [['level' => 'COMMUNE', 'commune_id' => $communes[$depts[0]['id']][1]['id']]]],
    ['DEMO-AGENT3', 'AGENT', 'Trois', 'F', 'AGENT', [['level' => 'DEPARTMENT', 'department_id' => $depts[1]['id']]]],
    ['DEMO-LECTEUR', 'LECTEUR', 'National', 'M', 'VIEWER', [['level' => 'NATIONAL']]],
];
$supervisorOf = ['DEMO-SUPDEP1' => 'DEMO-SUPREG', 'DEMO-SUPDEP2' => 'DEMO-SUPREG', 'DEMO-AGENT1' => 'DEMO-SUPDEP1', 'DEMO-AGENT2' => 'DEMO-SUPDEP1', 'DEMO-AGENT3' => 'DEMO-SUPDEP2'];
$ids = [];
$passwords = [];
foreach ($accounts as [$matricule, $last, $first, $sex, $role, $scopes]) {
    $existing = $db->fetchValue('SELECT id FROM users WHERE matricule = ?', [$matricule]);
    if ($existing !== null) {
        $ids[$matricule] = (int) $existing;
        if ($resetPasswords) {
            $passwords[$matricule] = AuthService::generateTemporaryPassword();
            $db->execute("UPDATE users SET password_hash = ?, must_change_password = 0, status = 'ACTIF', failed_attempts = 0, locked_until = NULL WHERE id = ?",
                [AuthService::hashPassword($passwords[$matricule]), $existing]);
        }
        continue;
    }
    $passwords[$matricule] = AuthService::generateTemporaryPassword();
    $id = $db->insert("INSERT INTO users (uuid, matricule, last_name, first_name, sex, password_hash, must_change_password, status, password_changed_at)
                       VALUES (?, ?, ?, ?, ?, ?, 0, 'ACTIF', NOW())", [Text::uuid4(), $matricule, $last, $first, $sex, AuthService::hashPassword($passwords[$matricule])]);
    $db->execute('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?', [$id, $role]);
    foreach ($scopes as $s) {
        $regionId = $s['region_id'] ?? null;
        $deptId = $s['department_id'] ?? null;
        if (isset($s['commune_id'])) {
            $deptId = (int) $db->fetchValue('SELECT department_id FROM communes WHERE id = ?', [$s['commune_id']]);
        }
        if ($deptId !== null) {
            $regionId = (int) $db->fetchValue('SELECT region_id FROM departments WHERE id = ?', [$deptId]);
        }
        $db->execute('INSERT INTO user_scopes (user_id, level, region_id, department_id, commune_id) VALUES (?, ?, ?, ?, ?)',
            [$id, $s['level'], $s['level'] === 'NATIONAL' ? null : $regionId, in_array($s['level'], ['NATIONAL', 'REGION'], true) ? null : $deptId, $s['commune_id'] ?? null]);
    }
    $ids[$matricule] = $id;
}
foreach ($supervisorOf as $m => $sup) {
    $db->execute('UPDATE users SET supervisor_id = ? WHERE id = ?', [$ids[$sup], $ids[$m]]);
}
$adminId = (int) ($db->fetchValue("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'ADMIN' ORDER BY u.id LIMIT 1") ?? 0);
if ($adminId === 0) {
    fwrite(STDERR, "Aucun administrateur : lancer d'abord php bin/create-admin.php.\n");
    exit(1);
}

// ------------------------------------------------------------------ Campagne de démonstration
$campaignId = $db->fetchValue("SELECT id FROM campaigns WHERE code = 'DEMO-CAMPAGNE'");
if ($campaignId === null) {
    $versionId = (int) $db->fetchValue("SELECT id FROM questionnaire_versions WHERE version_code = '2025.1' AND status = 'PUBLIEE'");
    $yearId = $db->fetchValue("SELECT id FROM academic_years WHERE code = '2090-2091'")
        ?? $db->insert("INSERT INTO academic_years (code, label, start_date, end_date, status, created_by) VALUES ('2090-2091', 'DÉMONSTRATION (fictive)', '2090-10-01', '2091-09-30', 'ACTIVE', ?)", [$adminId]);
    $campaignId = $db->insert("INSERT INTO campaigns (academic_year_id, code, name, questionnaire_version_id, status, opens_on, closes_on, validation_levels, instructions, created_by)
                               VALUES (?, 'DEMO-CAMPAGNE', 'Campagne de démonstration (données fictives)', ?, 'OUVERTE', CURDATE(), CURDATE() + INTERVAL 90 DAY, 2, ?, ?)",
        [$yearId, $versionId, 'Campagne fictive pour essayer la plateforme. Ne pas utiliser pour des statistiques.', $adminId]);
    $db->execute("INSERT INTO campaign_status_history (campaign_id, from_status, to_status, user_id, reason) VALUES (?, NULL, 'OUVERTE', ?, 'Jeu de démonstration')", [$campaignId, $adminId]);

    // 12 structures fictives : 3 par commune, affectées à l'agent de la commune.
    $typeIds = array_column($db->fetchAll("SELECT id, code FROM structure_types WHERE code IN ('b','c','f','g')"), 'id', 'code');
    $names = ['Atelier de couture', 'Garage mécanique', 'Menuiserie', 'Centre de formation', 'Groupement de teinturières', 'Atelier de soudure'];
    $types = ['c', 'g', 'c', 'b', 'f', 'c'];
    $n = 0;
    foreach ($depts as $di => $d) {
        foreach ($communes[$d['id']] as $ci => $c) {
            $agent = $di === 0 ? ($ci === 0 ? 'DEMO-AGENT1' : 'DEMO-AGENT2') : 'DEMO-AGENT3';
            for ($k = 0; $k < 3; $k++) {
                $i = $n % count($names);
                $n++;
                $label = "DÉMO — {$names[$i]} {$c['name']} $n";
                $sid = $db->insert("INSERT INTO structures (uuid, official_code, name, name_normalized, structure_type_id, legal_status, zone, commune_id, locality_label, status, created_by, approved_by, approved_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, NOW())",
                    [Text::uuid4(), sprintf('99%08d', $n), $label, mb_substr(Text::normalize($label), 0, 150), $typeIds[$types[$i]],
                        $n % 4 === 0 ? 'PUBLIC' : 'PRIVE', $n % 3 === 0 ? 'RURALE' : 'URBAINE', $c['id'], 'Quartier fictif', $adminId, $adminId]);
                $csId = $db->insert('INSERT INTO campaign_structures (campaign_id, structure_id, added_by) VALUES (?, ?, ?)', [$campaignId, $sid, $adminId]);
                $db->execute('INSERT INTO assignments (campaign_structure_id, agent_id, assigned_by) VALUES (?, ?, ?)', [$csId, $ids[$agent], $adminId]);
            }
        }
    }

    // Collectes : données de référence des vecteurs de test, effectifs variés ; métier tertiaire pris dans la nomenclature.
    $fixture = json_decode((string) file_get_contents($config['paths']['shared'] . '/rules-fixtures/eftp-nf-2025.1.json'), true);
    $base = $fixture['base'];
    unset($base['_ctx']);
    $base['Q5_DATE'] = date('Y-m-d');
    $base['GPS']['captured_at'] = date('c');
    $base['sectors']['s3']['trades'][0]['trade'] = (int) $db->fetchValue('SELECT id FROM trades WHERE sector_id = 3 AND active = 1 AND alias_of_id IS NULL AND is_other = 0 ORDER BY id LIMIT 1');

    $submissions = new SubmissionService($db, $config['paths']['storage']);
    $reviews = new ReviewService($db);
    $actor = static fn(int $id, array $roles) => ['id' => $id, 'roles' => $roles, 'is_admin' => false, 'scope' => Scope::forUser($db, $id)];
    $rows = $db->fetchAll('SELECT cs.id AS cs, a.agent_id FROM campaign_structures cs JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
                            WHERE cs.campaign_id = ? ORDER BY cs.id', [$campaignId]);
    // Scénario sur 9 collectes : 0-2 validées ; 3-4 validées au niveau 1 ; 5 en contrôle ; 6 invalidée ; 7-8 soumises. 3 structures non collectées.
    foreach (array_slice($rows, 0, 9) as $i => $r) {
        $data = $base;
        $factor = 1 + ($i % 3);
        foreach ($data['sectors']['s3']['trades'] as $t => $line) {
            foreach (['p1_m', 'p1_f', 'p2_m', 'p2_f', 'p3_m', 'p3_f'] as $p) {
                $data['sectors']['s3']['trades'][$t][$p] = ($line[$p] ?? 0) * $factor;
            }
        }
        $uuid = Text::uuid4();
        $submissions->submit(['id' => (int) $r['agent_id'], 'device_id' => null], $uuid,
            ['campaign_structure_id' => (int) $r['cs'], 'questionnaire_version' => '2025.1', 'base_revision' => 0, 'data' => $data], '1.0.0', 0);
        $dept = (int) $db->fetchValue('SELECT department_id FROM collections WHERE uuid = ?', [$uuid]);
        $supDep = $dept === (int) $depts[0]['id'] ? 'DEMO-SUPDEP1' : 'DEMO-SUPDEP2';
        if ($i <= 4) {
            $reviews->validate($actor($ids[$supDep], ['SUP_DEP']), $uuid, ['comment' => 'Contrôle départemental : conforme.'], '127.0.0.1');
        }
        if ($i <= 2) {
            $reviews->validate($actor($ids['DEMO-SUPREG'], ['SUP_REG']), $uuid, [], '127.0.0.1');
        }
        if ($i === 5) {
            $reviews->claim($actor($ids[$supDep], ['SUP_DEP']), $uuid, '127.0.0.1');
        }
        if ($i === 6) {
            $reviews->invalidate($actor($ids[$supDep], ['SUP_DEP']), $uuid,
                ['reason_code' => 'EFFECTIFS', 'comment' => 'Effectifs à vérifier avec le maître artisan.'], '127.0.0.1');
        }
    }
    echo "Campagne DEMO-CAMPAGNE créée : 12 structures, 9 collectes (3 validées, 2 validées au niveau 1, 1 en contrôle, 1 invalidée, 2 soumises), 3 non collectées.\n";
} else {
    echo "Campagne DEMO-CAMPAGNE déjà présente : rien n'est recréé.\n";
}

// ------------------------------------------------------------------ Identifiants
$file = $config['paths']['storage'] . '/demo-identifiants.txt';
$previous = [];
if (is_file($file)) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^(DEMO-[A-Z0-9]+)\s+(\S+)/', $l, $m) && !str_starts_with($m[2], '(')) {
            $previous[$m[1]] = $m[2];
        }
    }
}
$labels = ['DEMO-SUPREG' => "Superviseur régional ({$region['name']})", 'DEMO-SUPDEP1' => "Superviseur départemental ({$depts[0]['name']})",
    'DEMO-SUPDEP2' => "Superviseur départemental ({$depts[1]['name']})", 'DEMO-AGENT1' => 'Agent (application mobile)', 'DEMO-AGENT2' => 'Agent (application mobile)',
    'DEMO-AGENT3' => 'Agent (application mobile)', 'DEMO-LECTEUR' => 'Lecteur national'];
$out = 'Comptes de DÉMONSTRATION — base de développement uniquement (' . date('d/m/Y H:i') . ")\n"
    . "Back-office : http://localhost:8085/non_formel/  (les agents utilisent l'application mobile)\n\n"
    . sprintf("%-14s %-20s %s\n", 'Identifiant', 'Mot de passe', 'Profil');
foreach ($labels as $m => $label) {
    $pw = $passwords[$m] ?? $previous[$m] ?? '(inconnu : relancer avec --reset-passwords)';
    $out .= sprintf("%-14s %-20s %s\n", $m, $pw, $label);
}
$out .= "\nAdministrateur national : ADM-DEMO (mot de passe défini lors de sa création).\n";
if (!is_dir(dirname($file))) {
    mkdir(dirname($file), 0770, true);
}
file_put_contents($file, $out);
echo "\n" . $out . "\nÉcrit dans $file\n";
