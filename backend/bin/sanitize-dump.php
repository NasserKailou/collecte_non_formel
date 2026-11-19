<?php

declare(strict_types=1);

/**
 * Prépare une sauvegarde SQL (export phpMyAdmin ou mysqldump) pour l'importer sur un autre serveur MySQL/MariaDB
 * où le compte n'a pas le privilège SUPER / SET_USER_ID (hébergement mutualisé, base gérée, VPS avec compte dédié).
 *
 *   php bin/sanitize-dump.php <entree.sql> <sortie.sql> [--drop]
 *
 * Corrige :
 *  - « #1227 Access denied; you need the SUPER, SET USER privilege(s) » : les clauses DEFINER=`root`@`localhost`
 *    des vues, déclencheurs, procédures et événements sont retirées ; le propriétaire devient le compte qui importe.
 *    SQL SECURITY DEFINER devient SQL SECURITY INVOKER (la vue s'exécute avec les droits du lecteur).
 *  - Conséquence de cette erreur : phpMyAdmin place APRÈS les vues les clés primaires, index, AUTO_INCREMENT et
 *    clés étrangères. Un import interrompu à la vue laisse des tables sans clé ni auto-incrément, d'où
 *    « SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value » à la connexion.
 *
 * --drop : ajoute en tête la suppression des vues et tables du fichier (vérification des clés étrangères suspendue),
 *          pour réimporter proprement par-dessus un import précédent incomplet. Les données présentes sur le
 *          serveur dans ces tables sont remplacées par celles du fichier.
 *
 * Ne lit ni n'écrit aucune base : le fichier produit s'importe ensuite (phpMyAdmin › Importer, ou
 * « mysql -u UTILISATEUR -p NOM_BASE < sortie.sql »).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$drop = in_array('--drop', $argv, true);
if (count($args) !== 2) {
    fwrite(STDERR, "Usage : php bin/sanitize-dump.php <entree.sql> <sortie.sql> [--drop]\n");
    exit(2);
}
[$in, $out] = $args;
if (!is_file($in) || !is_readable($in)) {
    fwrite(STDERR, "Fichier introuvable : $in\n");
    exit(2);
}
if (is_file($out) && realpath($in) === realpath($out)) {
    fwrite(STDERR, "Le fichier de sortie doit être différent du fichier d'entrée.\n");
    exit(2);
}

$sql = file_get_contents($in);
if ($sql === false) {
    fwrite(STDERR, "Lecture impossible : $in\n");
    exit(1);
}
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql); // BOM éventuel

$stats = ['definer' => 0, 'security' => 0];

// 1. DEFINER=`utilisateur`@`hôte` (phpMyAdmin) et /*!50013 DEFINER=... */ / /*!50017 DEFINER=... */ (mysqldump).
$sql = preg_replace_callback(
    '/\s*DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@]+)\s*@\s*(?:`[^`]*`|\'[^\']*\'|[^\s*]+)/i',
    static function () use (&$stats): string {
        $stats['definer']++;
        return '';
    },
    $sql,
);

// 2. SQL SECURITY DEFINER → INVOKER (les droits du compte qui lit la vue suffisent : même base).
$sql = preg_replace_callback(
    '/SQL\s+SECURITY\s+DEFINER/i',
    static function () use (&$stats): string {
        $stats['security']++;
        return 'SQL SECURITY INVOKER';
    },
    $sql,
);
// Commentaires conditionnels devenus vides : /*!50013  */
$sql = preg_replace('#/\*!\d{5}\s*\*/#', '', $sql);

// 3. Objets créés par le fichier (pour --drop et pour le compte rendu).
preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $t);
preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`([^`]+)`/i', $sql, $v);
$views = array_values(array_unique($v[1]));
// Dans un export phpMyAdmin, chaque vue est d'abord créée comme « table de substitution » puis remplacée.
$tables = array_values(array_diff(array_unique($t[1]), $views));

$header = '-- Fichier préparé par backend/bin/sanitize-dump.php le ' . date('Y-m-d H:i') . "\n"
    . '-- Source : ' . basename($in) . " — clauses DEFINER retirées : {$stats['definer']}, SQL SECURITY INVOKER : {$stats['security']}\n";
if ($drop) {
    $header .= "-- Option --drop : vues et tables du fichier supprimées avant recréation.\nSET FOREIGN_KEY_CHECKS = 0;\n";
    foreach ($views as $name) {
        $header .= "DROP VIEW IF EXISTS `$name`;\nDROP TABLE IF EXISTS `$name`;\n";
    }
    foreach ($tables as $name) {
        $header .= "DROP TABLE IF EXISTS `$name`;\n";
    }
}
// Clés étrangères suspendues pendant l'import (ordre des tables indifférent), rétablies à la fin.
$sql = $header . "SET FOREIGN_KEY_CHECKS = 0;\n\n" . $sql . "\nSET FOREIGN_KEY_CHECKS = 1;\n";

if (preg_match('/\bDEFINER\s*=/i', $sql)) {
    fwrite(STDERR, "Attention : une clause DEFINER subsiste (format non reconnu) ; vérifier le fichier produit.\n");
}
if (file_put_contents($out, $sql) === false) {
    fwrite(STDERR, "Écriture impossible : $out\n");
    exit(1);
}

printf(
    "OK : %s\n  %d table(s), %d vue(s) ; %d clause(s) DEFINER retirée(s) ; SQL SECURITY INVOKER : %d%s\n",
    $out,
    count($tables),
    count($views),
    $stats['definer'],
    $stats['security'],
    $drop ? ' ; suppression préalable des tables (--drop)' : '',
);
if (!preg_match('/ADD\s+PRIMARY\s+KEY|PRIMARY\s+KEY\s*\(/i', $sql) || !preg_match('/AUTO_INCREMENT/i', $sql)) {
    fwrite(STDERR, "Attention : aucune clé primaire / AUTO_INCREMENT dans le fichier — export incomplet ?\n");
    exit(3);
}
