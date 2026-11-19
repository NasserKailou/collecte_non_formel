<?php

declare(strict_types=1);

/**
 * Exporte le « pack de démonstration » embarqué dans l'application mobile (mode Démo / formation des agents) :
 * référentiels (territoire RENACOM, secteurs et métiers, types de structure), dernière version publiée du
 * questionnaire, et quelques centres FICTIFS à collecter. Le mode Démo fonctionne entièrement hors ligne,
 * dans une base locale séparée : rien n'est jamais envoyé au serveur.
 *
 *   php bin/export-demo-pack.php [--out=../mobile/assets/demo/demo_pack.json]
 *
 * À relancer quand le questionnaire ou les référentiels changent, puis recompiler l'APK.
 * Les formats sont ceux des réponses de l'API (/referentials/*, /questionnaires/{version}).
 */

use App\Core\Database;

['db' => $db] = require __DIR__ . '/../bootstrap.php';
/** @var Database $db */

$out = __DIR__ . '/../../mobile/assets/demo/demo_pack.json';
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    }
}

$q = $db->fetchOne("SELECT version_code, definition_json FROM questionnaire_versions WHERE status = 'PUBLIEE' ORDER BY published_at DESC, id DESC LIMIT 1");
if ($q === null) {
    fwrite(STDERR, "Aucun questionnaire publié : lancer d'abord php bin/seed.php.\n");
    exit(1);
}

$territory = [
    'regions' => $db->fetchAll('SELECT id, code_renacom AS code, name FROM regions WHERE valid_to IS NULL ORDER BY name'),
    'departments' => $db->fetchAll('SELECT id, region_id, code_renacom AS code, name FROM departments WHERE valid_to IS NULL ORDER BY name'),
    'communes' => $db->fetchAll('SELECT id, department_id, code_renacom AS code, name, centroid_lat, centroid_lng FROM communes WHERE valid_to IS NULL ORDER BY name'),
];
$trades = [
    'sectors' => $db->fetchAll('SELECT id, code, label FROM sectors ORDER BY sort'),
    'trades' => $db->fetchAll('SELECT id, sector_id, label FROM trades WHERE active = 1 ORDER BY sector_id, label'),
];
$types = $db->fetchAll('SELECT code, label, is_other FROM structure_types WHERE active = 1 ORDER BY sort');
if ($territory['communes'] === [] || $trades['trades'] === [] || $types === []) {
    fwrite(STDERR, "Référentiels vides : lancer d'abord php bin/seed.php.\n");
    exit(1);
}

// Centres fictifs (noms explicitement « DÉMO »), placés dans des communes réelles pour un pré-remplissage réaliste.
$geo = 'SELECT c.id AS commune_id, c.department_id, d.region_id FROM communes c JOIN departments d ON d.id = c.department_id';
$specs = [
    ['NIAMEY ARRONDISSEMENT 1', 'DÉMO — Atelier de couture Espoir', 'c', 'a', 'b', 'Quartier Plateau'],
    ['NIAMEY ARRONDISSEMENT 3', 'DÉMO — Garage mécanique Al Barka', 'g', 'a', 'b', 'Quartier Lazaret'],
    ['NIAMEY ARRONDISSEMENT 4', 'DÉMO — Centre de formation aux métiers', 'b', 'a', 'a', 'Quartier Gamkallé'],
    ['KOLLO', 'DÉMO — Groupement des teinturières', 'f', 'b', 'b', 'Village de Kollo'],
    ['SAY', 'DÉMO — Menuiserie du fleuve', 'c', 'b', 'b', 'Quartier Château'],
];
$structures = [];
foreach ($specs as $i => [$commune, $name, $type, $zone, $status, $locality]) {
    $c = $db->fetchOne("$geo WHERE c.name = ? LIMIT 1", [$commune]) ?? $db->fetchOne("$geo ORDER BY c.id LIMIT 1");
    $n = $i + 1;
    $structures[] = [
        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $n),
        'official_code' => sprintf('99990000%02d', $n),
        'name' => $name,
        'status' => 'ACTIVE',
        'region_id' => (int) $c['region_id'],
        'department_id' => (int) $c['department_id'],
        'commune_id' => (int) $c['commune_id'],
        'type_code' => $type,
        'zone' => $zone,
        'legal_status' => $status,
        'locality_label' => $locality,
        'latitude' => null,
        'longitude' => null,
    ];
}

$pack = [
    'pack' => 'eftp-nf-demo',
    'generated_at' => date('c'),
    'questionnaire_version' => $q['version_code'],
    'questionnaire' => json_decode($q['definition_json'], true, flags: JSON_THROW_ON_ERROR),
    'territory' => $territory,
    'trades' => $trades,
    'structure_types' => $types,
    'structures' => $structures,
];

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
printf("Pack démo écrit : %s (%.0f Ko) — questionnaire %s, %d communes, %d métiers, %d centres fictifs\n",
    realpath($out) ?: $out, filesize($out) / 1024, $q['version_code'], count($territory['communes']), count($trades['trades']), count($structures));
