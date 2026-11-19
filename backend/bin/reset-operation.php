<?php

declare(strict_types=1);

/**
 * Nettoyage et réinitialisation de la base AVANT LE LANCEMENT DE L'OPÉRATION (après la formation et les tests).
 *
 * Toujours en deux temps :
 *   php bin/reset-operation.php [options]                                   APERÇU : compte ce qui sera supprimé, ne modifie rien
 *   php bin/reset-operation.php [options] --executer --confirmer=NOM_BASE   exécution réelle
 *
 * Supprimé dans tous les cas (données de formation / test) :
 *   collectes et tout leur contenu (réponses, formateurs, métiers, révisions, contrôles, historiques, photos),
 *   brouillons web/papier, années statistiques, campagnes, structures inscrites aux campagnes, affectations, zones
 *   des agents, journaux de synchronisation, de connexion et d'audit, jetons et appareils (toutes les tablettes et
 *   sessions web devront se reconnecter), tâches d'export, imports, fichiers produits (exports, photos, rapports),
 *   localités proposées sur le terrain, centres créés sur le terrain pendant la formation (structures proposées).
 *
 * Conservé : référentiels (territoire RENACOM, secteurs, métiers, types de structure, motifs de refus), rôles et
 *   permissions, questionnaires publiés, paramètres, versions publiées de l'application mobile, registre officiel
 *   des structures (sauf --toutes-structures), comptes utilisateurs (sauf --utilisateurs).
 *
 * Options :
 *   --toutes-structures      supprime AUSSI tout le registre des structures (à réimporter ensuite), ex. structures « DÉMO »
 *   --utilisateurs           supprime AUSSI tous les comptes sauf les administrateurs nationaux (ou sauf --garder=…)
 *   --garder=ADM001,ADM002   avec --utilisateurs : ne garder QUE ces matricules (administrateurs)
 *
 * Les compteurs d'identifiants (AUTO_INCREMENT) ne sont PAS remis à zéro, volontairement : une tablette qui aurait
 * gardé des collectes de formation ne pourra jamais les rattacher à une campagne ou une structure réelle.
 * FAIRE UNE SAUVEGARDE AVANT (phpMyAdmin › Exporter). Opération irréversible.
 */

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
['config' => $config, 'db' => $db] = require __DIR__ . '/../bootstrap.php';
/** @var Database $db */

$opt = static fn(string $name): bool => in_array("--$name", $argv, true);
$val = static function (string $name) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};
$execute = $opt('executer');
$allStructures = $opt('toutes-structures');
$purgeUsers = $opt('utilisateurs');
$keep = array_values(array_filter(array_map('trim', explode(',', (string) $val('garder')))));
$dbName = $db->databaseName();
$pdo = $db->pdo();

// ------------------------------------------------------------- Inventaire et garde-fous
// Chaque table doit être classée : une table inconnue (migration future) arrête le script.
$purged = ['collection_answers', 'collection_accompanied', 'collection_trades', 'collection_trainers', 'collection_sector_specials',
    'collection_submissions', 'collection_reviews', 'collection_status_history', 'collection_attachments', 'collection_drafts',
    'collections', 'assignments', 'agent_areas', 'campaign_structures', 'campaign_status_history', 'campaigns', 'academic_years',
    'sync_logs', 'login_logs', 'audit_logs', 'api_tokens', 'devices', 'jobs', 'imports'];
$partial = ['files', 'localities', 'structures', 'structure_history', 'users', 'user_roles', 'user_scopes'];
$kept = ['regions', 'departments', 'communes', 'sectors', 'trades', 'structure_types', 'rejection_reasons', 'roles', 'permissions',
    'role_permissions', 'system_settings', 'questionnaires', 'questionnaire_versions', 'questionnaire_sections', 'questionnaire_questions',
    'questionnaire_options', 'questionnaire_rules', 'variable_mappings', 'app_releases', 'schema_migrations'];
$tables = array_column($db->fetchAll("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"), 't');
$unknown = array_diff($tables, $purged, $partial, $kept);
if ($unknown !== []) {
    fwrite(STDERR, 'Table(s) non prévue(s) par ce script : ' . implode(', ', $unknown) . " — mettre à jour bin/reset-operation.php.\n");
    exit(1);
}

// Comptes conservés
$admins = $db->fetchAll("SELECT u.id, u.matricule, CONCAT(u.first_name, ' ', u.last_name) AS name FROM users u
    JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.code = 'ADMIN' WHERE u.deleted_at IS NULL ORDER BY u.id");
$keptUsers = [];
$keepIds = null;
if ($purgeUsers) {
    $keptUsers = $keep === [] ? $admins : array_values(array_filter($admins, static fn(array $a): bool => in_array($a['matricule'], $keep, true)));
    $missing = array_diff($keep, array_column($keptUsers, 'matricule'));
    if ($missing !== []) {
        fwrite(STDERR, 'Matricule(s) introuvable(s) ou non administrateur(s) : ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    if ($keptUsers === []) {
        fwrite(STDERR, "Aucun administrateur ne serait conservé : opération refusée (créer d'abord un administrateur : bin/create-admin.php).\n");
        exit(1);
    }
    $keepIds = array_map('intval', array_column($keptUsers, 'id'));
}

// Structures supprimées : toutes, ou seulement celles créées sur le terrain (proposées par un agent).
$structWhere = $allStructures ? '1 = 1' : "(proposed_by IS NOT NULL OR status IN ('PROPOSEE', 'REJETEE'))";
$userWhere = $keepIds === null ? '1 = 0' : 'id NOT IN (' . implode(',', $keepIds) . ')';

$count = static fn(string $sql): int => (int) $db->fetchValue($sql);
$plan = [];
foreach ($purged as $t) {
    $plan[$t] = $count("SELECT COUNT(*) FROM `$t`");
}
$plan['files (exports, photos, imports, rapports)'] = $count('SELECT COUNT(*) FROM files');
$plan['localities (proposées sur le terrain)'] = $count("SELECT COUNT(*) FROM localities WHERE source = 'TERRAIN'");
$plan['structures' . ($allStructures ? ' (TOUT le registre)' : ' (créées sur le terrain)')] = $count("SELECT COUNT(*) FROM structures WHERE $structWhere");
$plan['structure_history'] = $count("SELECT COUNT(*) FROM structure_history WHERE structure_id IN (SELECT id FROM structures WHERE $structWhere)");
$plan['users' . ($purgeUsers ? ' (sauf comptes conservés)' : ' (aucun : comptes conservés)')] = $count("SELECT COUNT(*) FROM users WHERE $userWhere");

echo "Base : $dbName (environnement " . ($config['app']['env'] ?? '?') . ")\n";
echo $execute ? "EXÉCUTION\n" : "APERÇU — rien ne sera modifié\n";
echo str_repeat('-', 72) . "\n";
foreach ($plan as $label => $n) {
    printf("  %-58s %8d\n", $label, $n);
}
echo str_repeat('-', 72) . "\n";
echo '  Structures conservées : ' . $count("SELECT COUNT(*) FROM structures WHERE NOT ($structWhere)") . "\n";
echo '  Comptes conservés     : ' . ($purgeUsers
    ? implode(', ', array_map(static fn(array $a): string => "{$a['matricule']} ({$a['name']})", $keptUsers))
    : $count('SELECT COUNT(*) FROM users') . ' (tous)') . "\n";
$demoAccounts = $db->fetchAll("SELECT matricule FROM users WHERE matricule LIKE '%DEMO%'" . ($keepIds === null ? '' : ' AND id IN (' . implode(',', $keepIds) . ')'));
if ($demoAccounts !== []) {
    echo '  ATTENTION : comptes de démonstration conservés : ' . implode(', ', array_column($demoAccounts, 'matricule'))
        . "\n    → relancer avec --utilisateurs --garder=<matricule du vrai administrateur>, ou les désactiver dans le back-office.\n";
}

if (!$execute) {
    echo "\nPour exécuter : php bin/reset-operation.php" . ($allStructures ? ' --toutes-structures' : '') . ($purgeUsers ? ' --utilisateurs' : '')
        . ($keep !== [] ? ' --garder=' . implode(',', $keep) : '') . " --executer --confirmer=$dbName\n"
        . "(après une SAUVEGARDE de la base : phpMyAdmin › Exporter)\n";
    exit(0);
}
if ($val('confirmer') !== $dbName) {
    fwrite(STDERR, "\nConfirmation absente ou incorrecte : ajouter --confirmer=$dbName\n");
    exit(1);
}

// ------------------------------------------------------------------------- Exécution
$storage = rtrim($config['paths']['storage'], '/\\');
$filesToDelete = array_column($db->fetchAll('SELECT stored_path FROM files'), 'stored_path');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    $pdo->beginTransaction();
    foreach ($purged as $t) {
        $pdo->exec("DELETE FROM `$t`"); // DELETE (et non TRUNCATE) : les identifiants ne repartent pas de 1
    }
    $pdo->exec('DELETE FROM files');
    $pdo->exec("DELETE FROM localities WHERE source = 'TERRAIN'");
    $pdo->exec('UPDATE structures SET locality_id = NULL WHERE locality_id IS NOT NULL AND locality_id NOT IN (SELECT id FROM localities)');
    $pdo->exec("DELETE FROM structure_history WHERE structure_id IN (SELECT id FROM structures WHERE $structWhere)");
    $pdo->exec("UPDATE structures SET merged_into_id = NULL WHERE merged_into_id IN (SELECT id FROM (SELECT id FROM structures WHERE $structWhere) x)");
    $pdo->exec("DELETE FROM structures WHERE $structWhere");

    if ($keepIds !== null) {
        $in = implode(',', $keepIds);
        // Références vers les comptes supprimés : remises à NULL (ou au premier administrateur conservé si obligatoires).
        foreach (['structures' => ['created_by', 'approved_by', 'proposed_by'], 'structure_history' => ['user_id'],
            'questionnaire_versions' => ['published_by'], 'system_settings' => ['updated_by'], 'localities' => ['created_by'],
            'users' => ['created_by', 'supervisor_id']] as $table => $cols) {
            foreach ($cols as $c) {
                $pdo->exec("UPDATE `$table` SET `$c` = NULL WHERE `$c` IS NOT NULL AND `$c` NOT IN ($in)");
            }
        }
        $pdo->exec("UPDATE app_releases SET published_by = {$keepIds[0]} WHERE published_by NOT IN ($in)");
        $pdo->exec("DELETE FROM user_scopes WHERE user_id NOT IN ($in)");
        $pdo->exec("DELETE FROM user_roles WHERE user_id NOT IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id NOT IN ($in)");
    }
    // Comptes conservés : compteurs d'échecs remis à zéro (les jetons ont tous été supprimés : reconnexion obligatoire).
    $pdo->exec('UPDATE users SET failed_attempts = 0, locked_until = NULL');
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, after_json, reason) VALUES (NULL, 'DATABASE_RESET', 'database', ?, ?, ?)")
        ->execute([$dbName, json_encode(['toutes_structures' => $allStructures, 'utilisateurs' => $purgeUsers, 'garder' => $keep]),
            "Réinitialisation avant lancement de l'opération (bin/reset-operation.php)"]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, 'Échec, aucune modification enregistrée : ' . $e->getMessage() . "\n");
    exit(1);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Fichiers produits pendant la formation (exports, photos des fiches, imports, rapports). Les APK publiés
// (storage/releases), la clé app.key et les journaux techniques ne sont pas touchés.
$removed = 0;
foreach ($filesToDelete as $rel) {
    $abs = $storage . '/' . ltrim((string) $rel, '/\\');
    if (!str_contains((string) $rel, '..') && is_file($abs) && @unlink($abs)) {
        $removed++;
    }
}
foreach (['exports', 'imports'] as $dir) {
    foreach (glob("$storage/$dir/*") ?: [] as $f) {
        if (is_file($f) && basename($f) !== '.gitkeep' && @unlink($f)) {
            $removed++;
        }
    }
}

echo "\nRéinitialisation terminée. $removed fichier(s) supprimé(s) du stockage.\n";
echo "Suite : créer l'année statistique et la campagne réelles, importer agents et structures, publier les affectations.\n";
echo "Tablettes ayant servi à la formation avec de vrais comptes : effacer les données de l'application\n";
echo "(Paramètres Android › Applications › Collecte EFTP › Stockage › Effacer les données) avant de les remettre aux agents.\n";
