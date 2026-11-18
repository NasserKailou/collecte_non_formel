<?php
/**
 * Génère la définition JSON du questionnaire EFTP non formel 2025-2026 (version 2025.1).
 *
 * Source officielle : Questionnaire_EFTP_Non_Formel_do30 12 2025.pdf (DSD/MEFTP)
 * Analyse et adaptations : docs/phase1/01_analyse_questionnaire.md
 * Format : docs/architecture/questionnaire-et-regles.md
 *
 * Usage : php scripts/questionnaire/build_eftp_nf_2025.php
 */

declare(strict_types=1);

const OUT = __DIR__ . '/../../shared/questionnaire/eftp-nf-2025.1.json';

// ---------------------------------------------------------------------------
// Aides à l'écriture des expressions JsonLogic
// ---------------------------------------------------------------------------
function v(string $path): array { return ['var' => $path]; }
function eq($a, $b): array { return ['==' => [$a, $b]]; }
function le($a, $b): array { return ['<=' => [$a, $b]]; }
function gt($a, $b): array { return ['>' => [$a, $b]]; }
function all_(array ...$c): array { return ['and' => $c]; }
function not_($a): array { return ['!' => [$a]]; }
function filled(string $path): array { return ['!!' => [v($path)]]; }
function plus(...$a): array { return ['+' => $a]; }
function inArr($needle, string $path): array { return ['in' => [$needle, v($path)]]; }
/** Somme d'une expression de champs sur un tableau (éventuellement filtré). */
function sumOver($array, array $fieldsExpr): array {
    return ['reduce' => [$array, plus(v('accumulator'), $fieldsExpr), 0]];
}
function countWhere(string $arrayPath, array $cond): array {
    return ['reduce' => [['filter' => [v($arrayPath), $cond]], plus(v('accumulator'), 1), 0]];
}
function opts(array $pairs): array {
    $o = [];
    foreach ($pairs as $code => $label) { $o[] = ['code' => (string)$code, 'label' => $label]; }
    return $o;
}

// ---------------------------------------------------------------------------
// Nomenclatures
// ---------------------------------------------------------------------------
$DURATIONS = ['LE3' => '≤ 3 mois', 'M4_9' => '4 à 9 mois', 'GT9' => '> 9 mois'];
$SECTORS = [
    's1' => ['id' => 1, 'number' => '4.1', 'label' => 'Secteur Primaire/Agricole'],
    's2' => ['id' => 2, 'number' => '4.2', 'label' => 'Secteur Secondaire/Industriel'],
    's3' => ['id' => 3, 'number' => '4.3', 'label' => 'Secteur Tertiaire/Service'],
];
$CELLS = [
    'p1_m' => 'Promotion I — Hommes', 'p1_f' => 'Promotion I — Femmes',
    'p2_m' => 'Promotion II — Hommes', 'p2_f' => 'Promotion II — Femmes',
    'p3_m' => 'Promotion III — Hommes', 'p3_f' => 'Promotion III — Femmes',
];
$SPECIALS = ['HANDICAPES' => 'Dont handicapés', 'REFUGIES' => 'Dont réfugiés', 'RETOURNES' => 'Dont retournés', 'DEPLACES' => 'Dont déplacés'];
$MEASURES = ['INSCRITS' => 'Jeunes inscrits', 'PLACES' => 'Dont placés', 'OUTILLES' => 'Dont outillés (espèces, nature ou kits)'];
$SEXES = ['M' => 'Masculin', 'F' => 'Féminin'];

$YES = fn(string $code) => eq(v($code), true);

// ---------------------------------------------------------------------------
// Section I
// ---------------------------------------------------------------------------
$s1 = [
    ['code' => 'Q1_1', 'number' => '1.1', 'label' => 'Nom de la structure', 'type' => 'text', 'required' => true,
     'min_length' => 3, 'max_length' => 150, 'prefill' => 'structure.name', 'locked' => true],
    ['code' => 'Q1_2', 'number' => '1.2', 'label' => 'Code', 'type' => 'text', 'required' => false,
     'pattern' => '^[0-9]{10}$', 'prefill' => 'structure.official_code', 'locked' => true,
     'help' => 'Code officiel à 10 chiffres, attribué par le référentiel des structures.'],
    ['code' => 'Q1_3', 'number' => '1.3', 'label' => "La structure dispose-t-elle d'un document administratif ?", 'type' => 'yesno', 'required' => true],
    ['code' => 'Q1_3_1', 'number' => '1.3.1', 'label' => 'Précisez le ministère de tutelle', 'type' => 'text', 'required' => true,
     'max_length' => 200, 'visible_if' => $YES('Q1_3')],
    ['code' => 'Q1_3_2', 'number' => '1.3.2', 'label' => 'Précisez le type du document administratif', 'type' => 'text', 'required' => true,
     'max_length' => 200, 'visible_if' => $YES('Q1_3')],
    ['code' => 'Q1_3_3', 'number' => '1.3.3', 'label' => 'Références du document administratif', 'type' => 'text', 'required' => true,
     'max_length' => 200, 'visible_if' => $YES('Q1_3')],
    ['code' => 'Q1_4', 'number' => '1.4', 'label' => "Date d'autorisation de création / début d'exercice", 'type' => 'date', 'required' => true,
     'partial' => true, 'help' => "Si le jour ou le mois est inconnu, saisir l'année seule."],
    ['code' => 'Q1_5', 'number' => '1.5', 'label' => "Date d'ouverture", 'type' => 'date', 'required' => true, 'partial' => true,
     'help' => "Si le jour ou le mois est inconnu, saisir l'année seule."],
    ['code' => 'Q1_6_1', 'number' => '1.6.1', 'label' => 'Région', 'type' => 'geo', 'level' => 'region', 'prefill' => 'structure.region_id', 'locked' => true, 'group' => '1.6 Localisation administrative'],
    ['code' => 'Q1_6_2', 'number' => '1.6.2', 'label' => 'Département', 'type' => 'geo', 'level' => 'department', 'prefill' => 'structure.department_id', 'locked' => true, 'group' => '1.6 Localisation administrative'],
    ['code' => 'Q1_6_3', 'number' => '1.6.3', 'label' => 'Commune', 'type' => 'geo', 'level' => 'commune', 'prefill' => 'structure.commune_id', 'locked' => true, 'group' => '1.6 Localisation administrative'],
    ['code' => 'Q1_6_4', 'number' => '1.6.4', 'label' => 'Quartier/village', 'type' => 'text', 'required' => true, 'min_length' => 2, 'max_length' => 150,
     'prefill' => 'structure.locality_label', 'group' => '1.6 Localisation administrative',
     'help' => "Saisi par l'agent (il n'existe pas de référentiel officiel des villages)."],
    ['code' => 'Q1_7', 'number' => '1.7', 'label' => 'Zone', 'type' => 'single', 'required' => true, 'prefill' => 'structure.zone',
     'options' => opts(['a' => 'Urbaine', 'b' => 'Rurale'])],
    ['code' => 'Q1_8', 'number' => '1.8', 'label' => 'Statut', 'type' => 'single', 'required' => true, 'prefill' => 'structure.legal_status',
     'options' => opts(['a' => 'Public', 'b' => 'Privé'])],
    ['code' => 'Q1_9', 'number' => '1.9', 'label' => 'Type de structure de la formation professionnelle non formelle', 'type' => 'single',
     'required' => true, 'prefill' => 'structure.type_code',
     'options' => opts(['a' => 'SFMA (SIFA)', 'b' => 'CFA', 'c' => 'Atelier', 'd' => 'SAA', 'e' => 'Foyer', 'f' => 'Groupement',
         'g' => 'Garage', 'h' => 'Coopérative/Association', 'i' => 'Cabinet de formation', 'j' => "Plateforme d'orientation",
         'k' => 'Autre structure'])],
    ['code' => 'Q1_9_autre', 'number' => '1.9', 'label' => 'Autre structure : préciser', 'type' => 'text', 'required' => true,
     'max_length' => 150, 'visible_if' => eq(v('Q1_9'), 'k')],
    ['code' => 'Q1_10', 'number' => '1.10', 'label' => 'Source(s) de financement de la structure', 'type' => 'multi', 'required' => true,
     'help' => 'Plusieurs réponses possibles.',
     'options' => opts(['a' => 'État', 'b' => 'PTF', 'c' => 'FAFPA', 'd' => 'EPA', 'e' => 'ONG', 'f' => 'Associations',
         'g' => 'Fonds propres', 'h' => 'Autres'])],
    ['code' => 'Q1_10_autre', 'number' => '1.10', 'label' => 'Autres sources : préciser', 'type' => 'text', 'required' => true,
     'max_length' => 150, 'visible_if' => inArr('h', 'Q1_10')],
    ['code' => 'Q1_11', 'number' => '1.11', 'label' => 'Régime de la structure', 'type' => 'single', 'required' => true,
     'options' => opts(['a' => 'Internat', 'b' => 'Externat'])],
    ['code' => 'Q1_12', 'number' => '1.12', 'label' => 'Cette structure délivre-t-elle des attestations en fin de formation ?', 'type' => 'yesno', 'required' => true],
    ['code' => 'Q1_13', 'number' => '1.13', 'label' => "Type d'accompagnement", 'type' => 'multi', 'required' => true,
     'help' => "Plusieurs réponses possibles. Réservée aux plateformes d'orientation.",
     'visible_if' => eq(v('Q1_9'), 'j'),
     'options' => opts(['a' => 'En espèces', 'b' => 'En matériel et outillage technique', 'c' => 'Renforcement des capacités techniques',
         'd' => 'Placement', 'e' => 'Autres'])],
    ['code' => 'Q1_13_autre', 'number' => '1.13', 'label' => 'Autre accompagnement : préciser', 'type' => 'text', 'required' => true,
     'max_length' => 150, 'visible_if' => inArr('e', 'Q1_13')],
    ['code' => 'Q1_14', 'number' => '1.14', 'label' => 'La formation est-elle payante ?', 'type' => 'yesno', 'required' => true],
    ['code' => 'Q1_14_1', 'number' => '1.14.1', 'label' => 'Mode(s) de paiement', 'type' => 'multi', 'required' => true,
     'help' => 'Plusieurs réponses possibles.', 'visible_if' => $YES('Q1_14'),
     'options' => opts(['a' => 'Paiement en espèces', 'b' => 'Paiement en chèque', 'c' => 'Paiement en ligne', 'd' => 'Paiement en nature'])],
    ['code' => 'GPS', 'number' => '1.15 / 1.16', 'label' => 'Coordonnées GPS de la structure (longitude, latitude)', 'type' => 'gps', 'required' => true,
     'help' => 'Capturer la position devant la structure. Si le GPS ne fonctionne pas, indiquer pourquoi.'],
];

// ---------------------------------------------------------------------------
// Section II
// ---------------------------------------------------------------------------
$q21Cols = [];
foreach ($SECTORS as $sk => $s) {
    foreach (['M' => 'M', 'F' => 'F', 'T' => 'Total'] as $sx => $sxl) {
        $q21Cols[] = ['code' => "$sk.$sx", 'label' => $s['label'] . " — $sxl"];
    }
}
$q22Cols = [];
foreach ($MEASURES as $mk => $ml) {
    foreach ($SEXES as $sx => $sxl) { $q22Cols[] = ['code' => "$mk.$sx", 'label' => "$ml — $sxl"]; }
}
$durRows = [];
foreach ($DURATIONS as $dk => $dl) { $durRows[] = ['code' => $dk, 'label' => $dl]; }

$yn = fn(string $code, string $number, string $label) =>
    ['code' => $code, 'number' => $number, 'label' => $label, 'type' => 'yesno', 'required' => true];
$latrine = fn(string $l, string $lbl) => ['code' => "Q2_11_2_$l", 'number' => "2.11.2 $l", 'label' => "Dont fonctionnelles pour $lbl",
    'type' => 'integer', 'required' => true, 'min' => 0, 'max' => 99, 'visible_if' => $YES('Q2_11'), 'group' => '2.11.2 Dont fonctionnelles pour'];

$s2 = [
    ['code' => 'Q2_1', 'number' => '2.1', 'label' => 'Effectif des apprentis par durée selon le secteur et le sexe', 'type' => 'computed_grid',
     'source' => 'Q2_1', 'rows' => $durRows, 'columns' => $q21Cols,
     'help' => 'Calculé automatiquement à partir de la Section IV (apprentis par métier). Non saisissable.'],
    ['code' => 'accompanied', 'number' => '2.2', 'label' => 'Nombre de jeunes accompagnés par durée selon le sexe', 'type' => 'grid', 'required' => true,
     'help' => "Jeunes appuyés en dehors de la formation reçue, dans le cadre de l'orientation et de l'insertion professionnelle.",
     'rows' => $durRows, 'columns' => $q22Cols, 'cell' => ['type' => 'integer', 'min' => 0, 'max' => 9999], 'totals' => true],
    $yn('Q2_3', '2.3', 'La structure est-elle alimentée en électricité ?'),
    ['code' => 'Q2_3_1', 'number' => '2.3.1', 'label' => 'Principale source', 'type' => 'single', 'required' => true, 'visible_if' => $YES('Q2_3'),
     'options' => opts(['a' => 'Groupe électrogène', 'b' => 'Panneau solaire', 'c' => 'Réseau électrique'])],
    $yn('Q2_4', '2.4', "La structure dispose-t-elle d'un point d'eau potable ?"),
    ['code' => 'Q2_4_1', 'number' => '2.4.1', 'label' => 'Principale source', 'type' => 'single', 'required' => true, 'visible_if' => $YES('Q2_4'),
     'options' => opts(['a' => 'Robinet', 'b' => 'AEP', 'c' => 'Forage', 'd' => 'Puits cimenté'])],
    $yn('Q2_5', '2.5', "La structure dispose-t-elle d'une clôture ?"),
    $yn('Q2_6', '2.6', "La structure dispose-t-elle d'une infirmerie fonctionnelle ?"),
    $yn('Q2_7', '2.7', "La structure dispose-t-elle d'une boîte à pharmacie ?"),
    $yn('Q2_8', '2.8', "La structure dispose-t-elle d'un dépotoir ?"),
    ['code' => 'Q2_8_1', 'number' => '2.8.1', 'label' => 'Nombre de dépotoirs', 'type' => 'integer', 'required' => true, 'min' => 1, 'max' => 999,
     'visible_if' => $YES('Q2_8')],
    $yn('Q2_9', '2.9', 'La structure dispose-t-elle de rampes adaptées aux personnes handicapées ?'),
    $yn('Q2_10', '2.10', "La structure dispose-t-elle d'une cour de récréation ?"),
    $yn('Q2_11', '2.11', 'La structure dispose-t-elle de latrines fonctionnelles ?'),
    ['code' => 'Q2_11_1', 'number' => '2.11.1', 'label' => 'Nombre total de latrines', 'type' => 'integer', 'required' => true, 'min' => 1, 'max' => 99,
     'visible_if' => $YES('Q2_11')],
    $latrine('a', 'les apprenants (masculin + féminin)'),
    $latrine('b', 'les filles uniquement'),
    $latrine('c', 'le personnel'),
    $latrine('d', 'les handicapés'),
    $yn('Q2_12', '2.12', "La structure dispose-t-elle d'une bibliothèque ?"),
    $yn('Q2_13', '2.13', "La structure dispose-t-elle d'une connexion Internet ?"),
    ['code' => 'Q2_13_1', 'number' => '2.13.1', 'label' => 'Type(s) de connexion', 'type' => 'multi', 'required' => true, 'visible_if' => $YES('Q2_13'),
     'options' => opts(['a' => 'ADSL', 'b' => 'Liaison satellite', 'c' => 'Connexion mobile'])],
    ['code' => 'Q2_13_2', 'number' => '2.13.2', 'label' => "Qui a accès à l'Internet ?", 'type' => 'multi', 'required' => true, 'visible_if' => $YES('Q2_13'),
     'help' => 'Numérotée « 2.14.2 » par erreur sur le formulaire papier.',
     'options' => opts(['a' => 'Apprentis', 'b' => 'Formateurs', 'c' => 'Personnel administratif', 'd' => 'Externe'])],
    $yn('Q2_14', '2.14', "La structure dispose-t-elle d'un terrain de sport ?"),
    ['code' => 'Q2_14_1', 'number' => '2.14.1', 'label' => 'Type(s) de sport', 'type' => 'multi', 'required' => true, 'visible_if' => $YES('Q2_14'),
     'options' => opts(['a' => 'Football', 'b' => 'Handball', 'c' => 'Basketball', 'd' => 'Volleyball', 'e' => 'Dojo', 'f' => 'Ring', 'g' => 'Autres'])],
    ['code' => 'Q2_14_1_autre', 'number' => '2.14.1', 'label' => 'Autres sports : préciser', 'type' => 'text', 'required' => true, 'max_length' => 150,
     'visible_if' => inArr('g', 'Q2_14_1')],
    ['code' => 'Q2_14_2', 'number' => '2.14.2', 'label' => 'Cette activité sportive dispose-t-elle de plateau technique ?', 'type' => 'yesno',
     'required' => true, 'visible_if' => $YES('Q2_14')],
    $yn('Q2_15', '2.15', "La structure dispose-t-elle d'un aménagement paysager ?"),
    $yn('Q2_16', '2.16', "La structure dispose-t-elle d'un parking auto/moto ?"),
    $yn('Q2_17', '2.17', "La structure dispose-t-elle d'un dispositif de lavage des mains ?"),
    $yn('Q2_18', '2.18', "La structure dispose-t-elle d'un dispositif de collecte des ordures ?"),
    ['code' => 'Q2_19', 'number' => '2.19', 'label' => 'Nombre de maîtres artisans/formateurs (Homme, Femme, Total)', 'type' => 'computed',
     'source' => 'Q2_19', 'help' => 'Calculé automatiquement à partir du tableau de la Section III. Non saisissable.'],
    ['code' => 'Q2_20_H', 'number' => '2.20', 'label' => 'Jeunes apprentis accompagnés par le FAFPA — Homme', 'type' => 'integer', 'required' => true,
     'min' => 0, 'max' => 999, 'group' => '2.20 Jeunes apprentis accompagnés par le FAFPA'],
    ['code' => 'Q2_20_F', 'number' => '2.20', 'label' => 'Jeunes apprentis accompagnés par le FAFPA — Femme', 'type' => 'integer', 'required' => true,
     'min' => 0, 'max' => 999, 'group' => '2.20 Jeunes apprentis accompagnés par le FAFPA'],
];

// ---------------------------------------------------------------------------
// Section III — tableau des maîtres artisans / formateurs
// ---------------------------------------------------------------------------
$certVisible = eq(v('certified'), true);
$s3 = [[
    'code' => 'trainers', 'number' => '3', 'label' => 'Maîtres artisans / formateurs', 'type' => 'roster',
    'min_rows' => 0, 'max_rows' => 99, 'row_label' => 'Formateur {_line}',
    'columns' => [
        ['code' => 'full_name', 'label' => 'Nom et prénom', 'type' => 'text', 'required' => true, 'min_length' => 2, 'max_length' => 150],
        ['code' => 'sex', 'label' => 'Sexe', 'type' => 'single', 'required' => true, 'options' => opts(['M' => 'Homme', 'F' => 'Femme'])],
        ['code' => 'grade', 'label' => 'Grade', 'type' => 'single', 'required' => true,
         'options' => opts(['MAITRE_ARTISAN' => 'Maître artisan', 'FORMATEUR' => 'Formateur'])],
        ['code' => 'initial_training', 'label' => 'Formation initiale', 'type' => 'yesno', 'required' => true],
        ['code' => 'continuing_trainings_count', 'label' => 'Nombre de formations continues suivies', 'type' => 'integer', 'required' => true, 'min' => 0, 'max' => 99],
        ['code' => 'certified', 'label' => 'La qualification est-elle certifiée ?', 'type' => 'yesno', 'required' => true],
        ['code' => 'cert_name', 'label' => 'Nom du dernier certificat obtenu', 'type' => 'text', 'required' => true, 'max_length' => 150, 'visible_if' => $certVisible],
        ['code' => 'cert_level', 'label' => 'Niveau du dernier certificat', 'type' => 'text', 'required' => true, 'max_length' => 100, 'visible_if' => $certVisible],
        ['code' => 'cert_body', 'label' => 'Nom de la structure certifiante', 'type' => 'text', 'required' => true, 'max_length' => 150, 'visible_if' => $certVisible],
        ['code' => 'cert_year', 'label' => 'Année de certification', 'type' => 'integer', 'required' => true, 'min' => 1950, 'max' => 2100, 'visible_if' => $certVisible],
        ['code' => 'nationality', 'label' => 'Nationalité', 'type' => 'single', 'required' => true,
         'options' => opts(['NIGERIEN' => 'Nigérien', 'UEMOA' => 'UEMOA', 'AUTRE' => 'Autre'])],
        ['code' => 'pedagogical_followup', 'label' => 'A-t-il reçu un suivi technico-pédagogique ?', 'type' => 'yesno', 'required' => true],
    ],
    'row_rules' => [
        ['code' => 'R_TRAINER_CERT_YEAR_FUTURE', 'severity' => 'error', 'targets' => ['cert_year'],
         'when' => all_($certVisible, filled('cert_year')), 'assert' => le(v('cert_year'), v('_root._ctx.current_year')),
         'message' => "Formateur {_line} : l'année de certification ({cert_year}) ne peut pas être dans le futur."],
    ],
]];

// ---------------------------------------------------------------------------
// Section IV — apprentis par secteur
// ---------------------------------------------------------------------------
$durOpts = opts($DURATIONS);
$cellRows = [];
foreach ($SPECIALS as $ck => $cl) { $cellRows[] = ['code' => $ck, 'label' => $cl]; }
$cellCols = [];
foreach ($CELLS as $ck => $cl) { $cellCols[] = ['code' => $ck, 'label' => $cl]; }

$s4 = [];
foreach ($SECTORS as $sk => $s) {
    $tradeCols = [
        ['code' => 'trade', 'label' => 'Métier', 'type' => 'single', 'required' => true, 'options_source' => "trades.{$s['id']}",
         'help' => 'Choisir dans la liste ; « AUTRE » si le métier ne figure pas dans la liste.'],
        ['code' => 'trade_other', 'label' => 'Autre métier : préciser', 'type' => 'text', 'required' => true, 'max_length' => 150,
         'visible_if' => eq(v('trade'), 'AUTRE')],
        ['code' => 'duration', 'label' => "Durée d'apprentissage", 'type' => 'single', 'required' => true, 'options' => $durOpts],
    ];
    foreach ($CELLS as $ck => $cl) {
        $tradeCols[] = ['code' => $ck, 'label' => $cl, 'type' => 'integer', 'required' => true, 'min' => 0, 'max' => 999];
    }
    $allCells = plus(...array_map(fn($c) => v($c), array_keys($CELLS)));
    $s4[] = [
        'code' => "sectors.$sk.trades", 'number' => $s['number'], 'label' => $s['label'] . ' — métiers', 'type' => 'roster',
        'group' => $s['number'] . ' ' . $s['label'], 'sector' => $sk, 'sector_id' => $s['id'],
        'min_rows' => 0, 'max_rows' => 50, 'row_label' => 'Métier {_line}',
        'unique' => ['trade', 'trade_other', 'duration'],
        'columns' => $tradeCols,
        'row_rules' => [
            ['code' => "R_TRADE_ALL_ZERO_$sk", 'severity' => 'warning', 'targets' => ['p1_m'],
             'assert' => gt($allCells, 0),
             'message' => "{$s['label']}, métier {_line} : tous les effectifs sont à 0. Vérifier ou supprimer la ligne."],
            ['code' => "R_TRADE_SHORT_MULTI_PROMO_$sk", 'severity' => 'warning', 'targets' => ['p2_m'],
             'when' => eq(v('duration'), 'LE3'),
             'assert' => eq(plus(v('p2_m'), v('p2_f'), v('p3_m'), v('p3_f')), 0),
             'message' => "{$s['label']}, métier {_line} : formation de 3 mois au plus avec des apprentis en promotion II ou III. Vérifier."],
        ],
    ];
    $s4[] = [
        'code' => "sectors.$sk.specials", 'number' => $s['number'], 'label' => $s['label'] . ' — dont apprentis vulnérables',
        'type' => 'grid', 'required' => true, 'group' => $s['number'] . ' ' . $s['label'],
        'rows' => $cellRows, 'columns' => $cellCols, 'cell' => ['type' => 'integer', 'min' => 0, 'max' => 999],
        'help' => 'Réfugiés : parents/tuteurs venant de pays touchés par des catastrophes ou des conflits, vivant dans des camps. '
            . "Déplacés : parents/tuteurs nigériens ayant quitté leur localité. Retournés : parents/tuteurs nigériens revenus après l'accalmie.",
    ];
}

// ---------------------------------------------------------------------------
// Section V
// ---------------------------------------------------------------------------
$s5 = [
    ['code' => 'Q5_OBS', 'number' => 'V', 'label' => 'Observations éventuelles', 'type' => 'text', 'required' => false, 'max_length' => 2000, 'multiline' => true],
    ['code' => 'Q5_DATE', 'number' => 'V', 'label' => 'Date de remplissage', 'type' => 'date', 'required' => true, 'prefill' => 'ctx.today'],
    ['code' => 'Q5_REP_NOM', 'number' => 'V', 'label' => 'Nom et prénom du répondant', 'type' => 'text', 'required' => true, 'min_length' => 2, 'max_length' => 150],
    ['code' => 'Q5_REP_TEL', 'number' => 'V', 'label' => 'Numéro de téléphone du répondant', 'type' => 'phone', 'required' => true],
    ['code' => 'Q5_CHEF_NOM', 'number' => 'V', 'label' => 'Nom et prénom du chef de la structure', 'type' => 'text', 'required' => true, 'min_length' => 2, 'max_length' => 150],
    ['code' => 'Q5_CHEF_TEL', 'number' => 'V', 'label' => 'Numéro de téléphone du chef', 'type' => 'phone', 'required' => false],
    ['code' => 'Q5_PAPER_SIGNED', 'number' => 'V', 'label' => 'La fiche papier a été signée et cachetée par le répondant et le chef de la structure', 'type' => 'yesno', 'required' => true],
    ['code' => 'Q5_ENQ', 'number' => 'V', 'label' => "Nom et prénom de l'enquêteur", 'type' => 'auto', 'source' => 'agent.full_name'],
    ['code' => 'Q5_SUP', 'number' => 'V', 'label' => 'Nom et prénom du superviseur', 'type' => 'auto', 'source' => 'supervisor.full_name'],
];

// ---------------------------------------------------------------------------
// Variables calculées
// ---------------------------------------------------------------------------
$computed = [];
// Q2.1 : apprentis par durée × secteur × sexe (depuis la Section IV)
foreach ($DURATIONS as $dk => $dl) {
    foreach ($SECTORS as $sk => $s) {
        $rows = ['filter' => [v("sectors.$sk.trades"), eq(v('duration'), $dk)]];
        $computed[] = ['code' => "Q2_1.$dk.$sk.M", 'expr' => sumOver($rows, plus(v('current.p1_m'), v('current.p2_m'), v('current.p3_m')))];
        $computed[] = ['code' => "Q2_1.$dk.$sk.F", 'expr' => sumOver($rows, plus(v('current.p1_f'), v('current.p2_f'), v('current.p3_f')))];
        $computed[] = ['code' => "Q2_1.$dk.$sk.T", 'expr' => plus(v("Q2_1.$dk.$sk.M"), v("Q2_1.$dk.$sk.F"))];
    }
}
// Totaux de la Section IV par secteur et par cellule
foreach ($SECTORS as $sk => $s) {
    foreach ($CELLS as $ck => $cl) {
        $computed[] = ['code' => "IV.$sk.total.$ck", 'expr' => sumOver(v("sectors.$sk.trades"), v("current.$ck"))];
    }
}
// Total général des apprentis
$grand = [];
foreach ($DURATIONS as $dk => $dl) { foreach ($SECTORS as $sk => $s) { $grand[] = v("Q2_1.$dk.$sk.T"); } }
$computed[] = ['code' => 'APPRENTICES_TOTAL', 'expr' => plus(...$grand)];
// Q2.19 : formateurs par sexe (depuis la Section III)
$computed[] = ['code' => 'Q2_19.M', 'expr' => countWhere('trainers', eq(v('sex'), 'M'))];
$computed[] = ['code' => 'Q2_19.F', 'expr' => countWhere('trainers', eq(v('sex'), 'F'))];
$computed[] = ['code' => 'Q2_19.T', 'expr' => plus(v('Q2_19.M'), v('Q2_19.F'))];
// Q2.20 total ; total des jeunes inscrits en 2.2
$computed[] = ['code' => 'Q2_20_T', 'expr' => plus(v('Q2_20_H'), v('Q2_20_F'))];
$insc = [];
foreach ($DURATIONS as $dk => $dl) { foreach ($SEXES as $sx => $sxl) { $insc[] = v("accompanied.$dk.INSCRITS.$sx"); } }
$computed[] = ['code' => 'Q2_2_INSCRITS_TOTAL', 'expr' => plus(...$insc)];

// ---------------------------------------------------------------------------
// Contrôles de cohérence
// ---------------------------------------------------------------------------
$rules = [];
$rule = function (string $code, string $severity, array $targets, array $assert, string $message, ?array $when = null) use (&$rules) {
    $r = ['code' => $code, 'severity' => $severity, 'targets' => $targets];
    if ($when !== null) { $r['when'] = $when; }
    $r['assert'] = $assert;
    $r['message'] = $message;
    $rules[] = $r;
};

// Dates
foreach (['Q1_4' => "La date d'autorisation", 'Q1_5' => "La date d'ouverture", 'Q5_DATE' => 'La date de remplissage'] as $q => $lbl) {
    $rule("R_DATE_FUTURE_$q", 'error', [$q], le(v($q), v('_ctx.today')), "$lbl ne peut pas être dans le futur.", filled($q));
}
$rule('R_DATE_OPEN_BEFORE_AUTH', 'warning', ['Q1_5', 'Q1_4'], ['>=' => [v('Q1_5'), v('Q1_4')]],
    "La date d'ouverture est antérieure à la date d'autorisation. Vérifier (cas possible de régularisation).",
    all_(filled('Q1_4'), filled('Q1_5')));
$rule('R_DATE_FILL_BEFORE_CAMPAIGN', 'warning', ['Q5_DATE'], ['>=' => [v('Q5_DATE'), v('_ctx.campaign_opens_on')]],
    "La date de remplissage est antérieure à l'ouverture de la campagne. Vérifier la date de la tablette.",
    all_(filled('Q5_DATE'), filled('_ctx.campaign_opens_on')));

// Latrines (2.11.2) : chaque catégorie ≤ total ; somme > total = avertissement
foreach (['a' => 'apprenants', 'b' => 'filles', 'c' => 'personnel', 'd' => 'handicapés'] as $l => $lbl) {
    $rule("R_LATRINES_{$l}_LE_TOTAL", 'error', ["Q2_11_2_$l", 'Q2_11_1'], le(v("Q2_11_2_$l"), v('Q2_11_1')),
        "Latrines fonctionnelles pour les $lbl ({Q2_11_2_$l}) supérieures au nombre total de latrines ({Q2_11_1}).", $YES('Q2_11'));
}
$rule('R_LATRINES_SUM', 'warning', ['Q2_11_2_a', 'Q2_11_1'],
    le(plus(v('Q2_11_2_a'), v('Q2_11_2_b'), v('Q2_11_2_c'), v('Q2_11_2_d')), v('Q2_11_1')),
    'La somme des latrines par catégorie dépasse le total ({Q2_11_1}). Acceptable seulement si des latrines sont comptées dans plusieurs catégories.',
    $YES('Q2_11'));

// 2.2 : placés ≤ inscrits, outillés ≤ inscrits ; placés + outillés > inscrits = avertissement
foreach ($DURATIONS as $dk => $dl) {
    foreach ($SEXES as $sx => $sxl) {
        $base = "accompanied.$dk";
        foreach (['PLACES' => 'placés', 'OUTILLES' => 'outillés'] as $m => $ml) {
            $rule("R_ACC_{$m}_LE_INSCRITS_{$dk}_$sx", 'error', ["$base.$m.$sx", "$base.INSCRITS.$sx"],
                le(v("$base.$m.$sx"), v("$base.INSCRITS.$sx")),
                "2.2, durée $dl, $sxl : jeunes $ml ({{$base}.$m.$sx}) supérieurs aux jeunes inscrits ({{$base}.INSCRITS.$sx}).");
        }
        $rule("R_ACC_SUM_{$dk}_$sx", 'warning', ["$base.PLACES.$sx", "$base.INSCRITS.$sx"],
            le(plus(v("$base.PLACES.$sx"), v("$base.OUTILLES.$sx")), v("$base.INSCRITS.$sx")),
            "2.2, durée $dl, $sxl : placés + outillés dépassent les inscrits. Acceptable seulement si un même jeune est à la fois placé et outillé.");
    }
}

// Section IV : « dont » ≤ total, par secteur × catégorie × cellule
foreach ($SECTORS as $sk => $s) {
    foreach ($CELLS as $ck => $cl) {
        foreach ($SPECIALS as $cat => $catl) {
            $rule("R_SPECIAL_{$sk}_{$cat}_$ck", 'error', ["sectors.$sk.specials.$cat.$ck"],
                le(v("sectors.$sk.specials.$cat.$ck"), v("IV.$sk.total.$ck")),
                "{$s['label']}, $cl : « " . mb_strtolower($catl) . " » ({sectors.$sk.specials.$cat.$ck}) supérieur au total des apprentis ({IV.$sk.total.$ck}).");
        }
        $rule("R_SPECIAL_{$sk}_STATUS_SUM_$ck", 'warning', ["sectors.$sk.specials.REFUGIES.$ck"],
            le(plus(v("sectors.$sk.specials.REFUGIES.$ck"), v("sectors.$sk.specials.RETOURNES.$ck"), v("sectors.$sk.specials.DEPLACES.$ck")),
                v("IV.$sk.total.$ck")),
            "{$s['label']}, $cl : réfugiés + retournés + déplacés dépassent le total des apprentis ({IV.$sk.total.$ck}). Ces catégories sont normalement exclusives.");
    }
}

// Cohérence globale
$rule('R_NO_APPRENTICE', 'error', ['sectors.s1.trades'], gt(v('APPRENTICES_TOTAL'), 0),
    "Aucun apprenti n'est déclaré dans les trois secteurs. Saisir les métiers et effectifs, ou expliquer la situation dans les observations.",
    not_(filled('Q5_OBS')));
$rule('R_APPRENTICES_WITHOUT_TRAINER', 'warning', ['trainers'], gt(v('Q2_19.T'), 0),
    "Des apprentis sont déclarés mais aucun maître artisan/formateur n'est saisi en Section III.", gt(v('APPRENTICES_TOTAL'), 0));
$rule('R_RATIO_APPRENTICES_TRAINER', 'warning', ['trainers'],
    le(v('APPRENTICES_TOTAL'), ['*' => [v('Q2_19.T'), v('_ctx.max_apprentices_per_trainer')]]),
    'Plus de {_ctx.max_apprentices_per_trainer} apprentis par formateur ({APPRENTICES_TOTAL} apprentis, {Q2_19.T} formateurs). Vérifier les effectifs.',
    all_(gt(v('Q2_19.T'), 0), filled('_ctx.max_apprentices_per_trainer')));
$rule('R_FAFPA_GT_ACCOMPANIED', 'warning', ['Q2_20_H'], le(v('Q2_20_T'), v('Q2_2_INSCRITS_TOTAL')),
    'Les jeunes accompagnés par le FAFPA ({Q2_20_T}) dépassent le total des jeunes inscrits en 2.2 ({Q2_2_INSCRITS_TOTAL}). Vérifier.');

// GPS
$rule('R_GPS_ACCURACY', 'warning', ['GPS'], le(v('GPS.accuracy_m'), 50),
    'Précision GPS faible ({GPS.accuracy_m} m). Reprendre la mesure à découvert si possible.',
    all_(eq(v('GPS.status'), 'OK'), filled('GPS.accuracy_m')));
$rule('R_GPS_FAR_FROM_COMMUNE', 'warning', ['GPS'], le(v('_ctx.gps_distance_km'), v('_ctx.gps_max_km')),
    'La position GPS est à {_ctx.gps_distance_km} km du centre de la commune déclarée. Vérifier la commune ou reprendre la mesure.',
    all_(filled('_ctx.gps_distance_km'), filled('_ctx.gps_max_km')));

// ---------------------------------------------------------------------------
// Assemblage
// ---------------------------------------------------------------------------
$definition = [
    'schema' => 1,
    'questionnaire' => 'EFTP_NF',
    'version' => '2025.1',
    'title' => 'Recensement EFTP non formel — Questionnaire 2025-2026',
    'source' => 'Questionnaire_EFTP_Non_Formel_do30 12 2025 (DSD/MEFTP)',
    'enums' => [
        'duration' => $durOpts,
        'sector' => array_map(fn($k, $s) => ['code' => $k, 'id' => $s['id'], 'label' => $s['label']], array_keys($SECTORS), $SECTORS),
    ],
    'sections' => [
        ['code' => 'S1', 'number' => 'I', 'title' => 'Localisation et identification de la structure', 'items' => $s1],
        ['code' => 'S2', 'number' => 'II', 'title' => "Environnement d'apprentissage", 'items' => $s2],
        ['code' => 'S3', 'number' => 'III', 'title' => 'Informations relatives aux maîtres artisans/formateurs', 'items' => $s3],
        ['code' => 'S4', 'number' => 'IV', 'title' => 'Apprentis dans les différents secteurs', 'items' => $s4],
        ['code' => 'S5', 'number' => 'V', 'title' => 'Observations et identification', 'items' => $s5],
    ],
    'computed' => $computed,
    'rules' => $rules,
];

$json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (!is_dir(dirname(OUT))) { mkdir(dirname(OUT), 0775, true); }
file_put_contents(OUT, $json);

$nItems = array_sum(array_map(fn($s) => count($s['items']), $definition['sections']));
fprintf(STDOUT, "OK %s — %d éléments, %d variables calculées, %d contrôles, sha256=%s\n",
    realpath(OUT), $nItems, count($computed), count($rules), hash('sha256', $json));
