<?php

declare(strict_types=1);

namespace App\Modules\Structures;

use App\Core\Audit;
use App\Core\Database;
use App\Core\ImportException;
use App\Core\Text;
use App\Http\HttpException;
use App\Http\UploadedFile;
use App\Modules\Imports\ExcelImportSupport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Import en masse des centres déjà connus (registre des structures), sur le modèle de l'import des agents :
 *
 * 1. writeTemplate() : modèle Excel propre à l'utilisateur, avec listes déroulantes (type de structure, statut,
 *    zone, Région → Département → Commune en cascade, limitées à son périmètre, « Doublon vérifié »).
 * 2. preview() : chaque ligne est contrôlée avec les règles de la saisie manuelle (StructureService), plus les
 *    doublons dans le fichier et avec le registre ; rien n'est créé ; rapport ligne par ligne.
 * 3. commit() : recontrôle complet, puis création de TOUS les centres en une transaction, ou d'aucun.
 *    Avec code officiel : centre ACTIVE (ciblable et affectable aussitôt) ; sans code : PROPOSEE (à codifier).
 */
final class StructureImporter
{
    use ExcelImportSupport;

    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    /** En-têtes reconnus (forme normalisée) → clé interne. */
    private const HEADERS = [
        'CODE OFFICIEL' => 'official_code', 'CODE' => 'official_code', 'CODE OFFICIEL 10 CHIFFRES' => 'official_code',
        'NOM DU CENTRE' => 'name', 'NOM' => 'name', 'NOM DE LA STRUCTURE' => 'name',
        'TYPE DE STRUCTURE' => 'type', 'TYPE' => 'type',
        'PRECISION SI AUTRE' => 'other_type_label', 'PRECISION' => 'other_type_label', 'AUTRE TYPE' => 'other_type_label',
        'STATUT' => 'legal_status', 'STATUT PUBLIC PRIVE' => 'legal_status', 'PUBLIC PRIVE' => 'legal_status',
        'ZONE' => 'zone', 'MILIEU' => 'zone',
        'REGION' => 'region', 'DEPARTEMENT' => 'department', 'COMMUNE' => 'commune',
        'VILLAGE QUARTIER' => 'locality_label', 'VILLAGE' => 'locality_label', 'QUARTIER' => 'locality_label', 'LOCALITE' => 'locality_label',
        'LATITUDE' => 'latitude', 'LONGITUDE' => 'longitude',
        'DATE D AUTORISATION' => 'authorization_date', 'DATE AUTORISATION' => 'authorization_date',
        'DATE D OUVERTURE' => 'opening_date', 'DATE OUVERTURE' => 'opening_date',
        'DOUBLON VERIFIE' => 'confirm_duplicate', 'DOUBLON CONFIRME' => 'confirm_duplicate',
    ];
    private const REQUIRED = ['name' => 'Nom du centre', 'type' => 'Type de structure', 'region' => 'Région', 'department' => 'Département', 'commune' => 'Commune'];
    public const TEMPLATE_COLUMNS = ['Code officiel (10 chiffres)', 'Nom du centre *', 'Type de structure *', 'Précision (si Autre)', 'Statut',
        'Zone', 'Région *', 'Département *', 'Commune *', 'Village / quartier', 'Latitude', 'Longitude', "Date d'autorisation", "Date d'ouverture",
        'Doublon vérifié'];
    public const LEGAL_LABELS = ['PUBLIC' => 'Public', 'PRIVE' => 'Privé'];
    public const ZONE_LABELS = ['URBAINE' => 'Urbaine', 'RURALE' => 'Rurale'];
    private const YES_NO = ['Non', 'Oui'];

    private readonly Audit $audit;

    public function __construct(private readonly Database $db, private readonly string $storageDir, private readonly StructureService $structures)
    {
        $this->audit = new Audit($db);
    }

    // ===================================================================== Modèle

    /** Écrit le modèle Excel à remplir (listes limitées au périmètre de l'acteur s'il n'est pas administrateur). */
    public function writeTemplate(string $path, ?array $actor = null): string
    {
        $scope = ($actor === null || $actor['is_admin']) ? null : $actor['scope'];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Centres');
        $sheet->fromArray(self::TEMPLATE_COLUMNS, null, 'A1');
        $sheet->getStyle('A1:O1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('128A3E');
        $sheet->getStyle('A1:O1')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(32);
        foreach (['A' => 16, 'B' => 36, 'C' => 30, 'D' => 20, 'E' => 10, 'F' => 11, 'G' => 18, 'H' => 22, 'I' => 24, 'J' => 22,
                     'K' => 11, 'L' => 11, 'M' => 14, 'N' => 14, 'O' => 12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $last = self::MAX_ROWS + 1;
        $sheet->getStyle("A2:A$last")->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle("M2:N$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("K2:L$last")->getNumberFormat()->setFormatCode('0.000000');
        $sheet->freezePane('C2');

        // ---- Feuille « Listes » (masquée).
        $lists = $book->createSheet()->setTitle('Listes');
        $put = static function (int $col, array $values, string $title) use ($lists): string {
            $letter = Coordinate::stringFromColumnIndex($col);
            $lists->setCellValue($letter . '1', $title);
            foreach (array_values($values) as $i => $v) {
                $lists->setCellValueExplicit($letter . ($i + 2), (string) $v, DataType::TYPE_STRING);
            }
            return '$' . $letter . '$2:$' . $letter . '$' . (max(1, count($values)) + 1);
        };
        $types = $this->typeLabels();
        $typeRange = 'Listes!' . $put(1, $types, 'Type de structure');
        $legalRange = 'Listes!' . $put(2, self::LEGAL_LABELS, 'Statut');
        $zoneRange = 'Listes!' . $put(3, self::ZONE_LABELS, 'Zone');
        $yesNoRange = 'Listes!' . $put(4, self::YES_NO, 'Doublon vérifié');

        $tree = $this->territoryTree($scope);
        $col = 7; // colonnes E et F : table des régions ; ensuite une liste par région et par département
        $regionTable = [];
        $deptTable = [];
        $regionLabels = self::listLabels($tree);
        foreach ($tree as $rid => $reg) {
            $deptLabels = self::listLabels($reg['deps']);
            $book->addNamedRange(new NamedRange("L_REG_$rid", $lists, $put($col++, $deptLabels, 'Départements ' . $reg['name'])));
            $regionTable[$regionLabels[$rid]] = "L_REG_$rid";
            foreach ($reg['deps'] as $did => $dep) {
                $book->addNamedRange(new NamedRange("L_DEP_$did", $lists, $put($col++, self::listLabels($dep['communes']), 'Communes ' . $dep['name'])));
                $deptTable[$regionLabels[$rid] . '|' . $deptLabels[$did]] = "L_DEP_$did";
            }
        }
        $put(5, array_keys($regionTable), 'Région');
        $put(6, array_values($regionTable), 'Plage des départements');
        $n = max(1, count($regionTable)) + 1;
        $regionRange = 'Listes!$E$2:$E$' . $n;
        $regionLookup = 'Listes!$E$2:$F$' . $n;
        $keyCol = $col++;
        $put($keyCol, array_keys($deptTable), 'Région|Département');
        $put($col++, array_values($deptTable), 'Plage des communes');
        $deptLookup = 'Listes!$' . Coordinate::stringFromColumnIndex($keyCol) . '$2:$' . Coordinate::stringFromColumnIndex($keyCol + 1)
            . '$' . (max(1, count($deptTable)) + 1);
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // ---- Listes déroulantes de la feuille « Centres ».
        $this->listValidation($sheet, "C2:C$last", $typeRange, 'Type de structure', 'Choisir le type dans la liste (question 1.9 du questionnaire).');
        $this->listValidation($sheet, "E2:E$last", $legalRange, 'Statut', 'Public ou Privé (question 1.8).');
        $this->listValidation($sheet, "F2:F$last", $zoneRange, 'Zone', 'Urbaine ou Rurale (question 1.7).');
        $this->listValidation($sheet, "G2:G$last", $regionRange, 'Région', 'Choisir la région dans la liste.');
        $this->listValidation($sheet, "H2:H$last", "INDIRECT(VLOOKUP(\$G2,$regionLookup,2,FALSE))", 'Département',
            "Choisir d'abord la région : seuls ses départements sont proposés.");
        $this->listValidation($sheet, "I2:I$last", "INDIRECT(VLOOKUP(\$G2&\"|\"&\$H2,$deptLookup,2,FALSE))", 'Commune',
            "Choisir d'abord le département : seules ses communes sont proposées.");
        $this->listValidation($sheet, "O2:O$last", $yesNoRange, 'Doublon vérifié',
            "Oui : créer le centre même si un centre semblable existe déjà (après vérification). Laisser vide sinon.");

        $notice = $book->createSheet()->setTitle('Notice');
        $lines = [
            ["Import des centres (registre des structures) — mode d'emploi"],
            [''],
            ["1. Remplir la feuille « Centres », une ligne par centre, sans modifier la ligne d'en-tête. Les colonnes marquées * sont obligatoires."],
            ['2. Type de structure, Statut, Zone, Région, Département, Commune et Doublon vérifié se choisissent dans des listes déroulantes.'],
            ["   Remplir dans l'ordre Région → Département → Commune : chaque liste ne propose que les choix du niveau au-dessus."],
            ['3. Code officiel : 10 chiffres, unique. Avec un code, le centre est créé « Actif » : il peut être ciblé par une campagne et affecté à un agent.'],
            ['   Sans code, il est créé « Proposé » : il faudra le codifier puis l\'approuver depuis sa fiche (page Structures) avant de l\'affecter.'],
            ['4. Type « Autre structure » : remplir la colonne « Précision ».'],
            ['5. Latitude et longitude (facultatives, ensemble) en degrés décimaux, par exemple 13.5137 et 2.1098 (territoire nigérien).'],
            ['6. Dates (facultatives) : JJ/MM/AAAA, non futures.'],
            ["7. Doublons : un centre de même nom (ou de nom très proche) dans la même commune, ou situé à moins de 100 m d'un centre existant, est signalé."],
            ['   Après vérification, choisir « Oui » dans « Doublon vérifié » pour le créer quand même. Deux lignes identiques du fichier sont refusées.'],
            ["8. L'import se fait en deux temps : contrôle (aucune écriture, rapport des anomalies ligne par ligne), puis validation. Il est tout-ou-rien."],
            ["9. Ensuite : page Campagnes › cibler les centres, puis page Affectations › affecter chaque centre connu à un agent."],
            ['10. La feuille « Territoire » donne les noms et codes RENACOM ; un code peut aussi être saisi à la place du nom.'],
        ];
        $notice->fromArray($lines, null, 'A1');
        $notice->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $notice->getColumnDimension('A')->setWidth(150);

        $terr = $book->createSheet()->setTitle('Territoire');
        $terr->fromArray(['Code région', 'Région', 'Code département', 'Département', 'Code commune', 'Commune'], null, 'A1');
        $terr->getStyle('A1:F1')->getFont()->setBold(true);
        $line = 2;
        foreach ($tree as $reg) {
            foreach ($reg['deps'] as $dep) {
                foreach ($dep['communes'] as $c) {
                    $terr->fromArray([$reg['code'], $reg['name'], $dep['code'], $dep['name'], $c['code'], $c['name']], null, 'A' . $line++, true);
                }
            }
        }
        foreach (range('A', 'F') as $c) {
            $terr->getColumnDimension($c)->setAutoSize(true);
        }
        $book->setActiveSheetIndex(0);
        $this->ensureDir(dirname($path));
        (new Xlsx($book))->save($path);
        return $path;
    }

    /** « c - Atelier » pour chaque type actif (code → libellé de liste). */
    private function typeLabels(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT code, label FROM structure_types WHERE active = 1 ORDER BY sort') as $t) {
            $out[$t['code']] = "{$t['code']} - {$t['label']}";
        }
        return $out;
    }

    // ===================================================================== Prévisualisation, validation, rapport

    public function preview(array $actor, UploadedFile $upload): array
    {
        $upload->assertValid(['xlsx'], self::MAX_BYTES);
        if (file_get_contents($upload->path, false, null, 0, 4) !== "PK\x03\x04") {
            throw new HttpException(422, 'UNSUPPORTED_FILE', "Le fichier n'est pas un classeur Excel .xlsx valide.");
        }
        $uuid = Text::uuid4();
        $relative = 'imports/' . $uuid . '.xlsx';
        $upload->moveTo($this->storageDir . '/' . $relative);

        $analysis = $this->analyze($actor, $this->storageDir . '/' . $relative);
        $reportRel = 'imports/' . $uuid . '-controle.xlsx';
        $this->writeCheckReport($analysis, $this->storageDir . '/' . $reportRel);

        return $this->db->transaction(function (Database $db) use ($actor, $upload, $uuid, $relative, $analysis, $reportRel): array {
            $sourceId = $this->insertFile($actor['id'], 'IMPORT_STRUCTURES', $upload->clientName, $relative, null);
            $reportId = $this->insertFile($actor['id'], 'REPORT', 'controle-import-centres.xlsx', $reportRel, null);
            $db->execute(
                "INSERT INTO imports (uuid, type, status, source_file_id, report_file_id, total_rows, valid_rows, error_rows, summary_json, created_by)
                 VALUES (?, 'STRUCTURES', 'PREVISUALISE', ?, ?, ?, ?, ?, ?, ?)",
                [$uuid, $sourceId, $reportId, $analysis['total'], $analysis['valid'], $analysis['invalid'],
                    json_encode($this->summary($analysis), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actor['id']]);
            return $this->get($actor, $uuid);
        });
    }

    /** État d'un import, avec le détail des lignes (erreurs d'abord, 1000 au plus ; tout figure dans le rapport). */
    public function get(array $actor, string $uuid, bool $withRows = true): array
    {
        $imp = $this->findOwned($actor, $uuid);
        $summary = json_decode((string) $imp['summary_json'], true) ?? [];
        $out = [
            'id' => $imp['uuid'],
            'type' => $imp['type'],
            'status' => $imp['status'],
            'file_name' => $imp['original_name'],
            'total_rows' => (int) $imp['total_rows'],
            'valid_rows' => (int) $imp['valid_rows'],
            'error_rows' => (int) $imp['error_rows'],
            'can_commit' => $imp['status'] === 'PREVISUALISE' && (int) $imp['error_rows'] === 0
                && (int) $imp['total_rows'] > 0 && ($summary['fatal'] ?? []) === [],
            'fatal_errors' => $summary['fatal'] ?? [],
            'by_status' => $summary['by_status'] ?? [],
            'created_at' => $imp['created_at'],
            'committed_at' => $imp['committed_at'],
            'report_url' => '/api/v1/imports/' . $imp['uuid'] . '/report',
        ];
        if ($withRows) {
            $rows = $summary['rows'] ?? [];
            usort($rows, static fn($a, $b) => [$a['errors'] === [] ? 1 : 0, $a['line']] <=> [$b['errors'] === [] ? 1 : 0, $b['line']]);
            $out['rows'] = array_slice($rows, 0, 1000);
            $out['rows_truncated'] = count($rows) > 1000;
        }
        return $out;
    }

    /**
     * Recontrôle puis crée tous les centres en une transaction.
     * @return array{import:array, created:int, active:int, proposed:int}
     * @throws ImportException si une anomalie apparaît (aucun centre créé)
     */
    public function commit(array $actor, string $uuid, string $ip): array
    {
        $imp = $this->findOwned($actor, $uuid);
        if ($imp['status'] !== 'PREVISUALISE') {
            throw new HttpException(409, 'CONFLICT', 'Cet import a déjà été validé ou annulé.');
        }
        $abs = $this->storageDir . '/' . $imp['stored_path'];
        if (!is_file($abs) || hash_file('sha256', $abs) !== $imp['sha256']) {
            throw new HttpException(409, 'CONFLICT', "Le fichier source de cet import est introuvable ou altéré. Recommencer l'import.");
        }
        $analysis = $this->analyze($actor, $abs);
        if ($analysis['invalid'] > 0 || $analysis['fatal'] !== [] || $analysis['total'] === 0) {
            $this->db->execute('UPDATE imports SET total_rows = ?, valid_rows = ?, error_rows = ?, summary_json = ? WHERE id = ?',
                [$analysis['total'], $analysis['valid'], $analysis['invalid'],
                    json_encode($this->summary($analysis), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $imp['id']]);
            $errors = $this->flatErrors($analysis) ?: ['Le fichier ne contient aucune ligne à importer.'];
            throw new ImportException("Import refusé : aucun centre n'a été créé.", array_slice($errors, 0, 200));
        }

        @set_time_limit(600);
        try {
            [$active, $proposed] = $this->db->transaction(function (Database $db) use ($actor, $imp, $analysis, $ip): array {
                $active = $proposed = 0;
                foreach ($analysis['rows'] as $r) {
                    $note = $r['duplicates'] !== [] ? 'créé malgré ' . count($r['duplicates']) . ' doublon(s) potentiel(s) vérifié(s)' : null;
                    $this->structures->insertImported($actor, $r['data'], $imp['uuid'], $note, $ip);
                    $r['data']['official_code'] !== null ? $active++ : $proposed++;
                }
                $db->execute("UPDATE imports SET status = 'IMPORTE', committed_by = ?, committed_at = NOW() WHERE id = ?", [$actor['id'], $imp['id']]);
                $this->audit->log($actor['id'], 'IMPORT_STRUCTURES', 'import', $imp['uuid'], null,
                    ['created' => $active + $proposed, 'active' => $active, 'proposed' => $proposed], null, $ip);
                return [$active, $proposed];
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'CONFLICT', "Un code officiel de ce fichier vient d'être attribué par ailleurs. Relancer le contrôle.");
            }
            throw $e;
        }
        return ['import' => $this->get($actor, $uuid, false), 'created' => $active + $proposed, 'active' => $active, 'proposed' => $proposed];
    }

    public function cancel(array $actor, string $uuid, string $ip): array
    {
        $imp = $this->findOwned($actor, $uuid);
        if ($imp['status'] !== 'PREVISUALISE') {
            throw new HttpException(409, 'CONFLICT', 'Seul un import non validé peut être annulé.');
        }
        $this->db->execute("UPDATE imports SET status = 'ANNULE' WHERE id = ?", [$imp['id']]);
        $this->audit->log($actor['id'], 'IMPORT_CANCEL', 'import', $imp['uuid'], null, null, null, $ip);
        return $this->get($actor, $uuid, false);
    }

    /** @return array{0:string, 1:string, 2:string} chemin, nom, type MIME du rapport de contrôle */
    public function report(array $actor, string $uuid, string $ip = ''): array
    {
        $imp = $this->findOwned($actor, $uuid);
        $f = $imp['report_file_id'] === null ? null
            : $this->db->fetchOne('SELECT original_name, stored_path, mime_type FROM files WHERE id = ?', [$imp['report_file_id']]);
        if ($f === null || !is_file($this->storageDir . '/' . $f['stored_path'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Rapport introuvable.');
        }
        return [$this->storageDir . '/' . $f['stored_path'], $f['original_name'], $f['mime_type']];
    }

    // ===================================================================== Analyse du fichier

    /** @return array{total:int, valid:int, invalid:int, fatal:list<string>, rows:list<array>} */
    public function analyze(array $actor, string $path): array
    {
        $result = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'fatal' => [], 'rows' => []];
        try {
            $book = IOFactory::createReader('Xlsx')->setReadDataOnly(true)->load($path);
        } catch (\Throwable) {
            $result['fatal'][] = "Le classeur est illisible ou n'est pas au format .xlsx.";
            return $result;
        }
        $sheet = $book->getSheetByName('Centres') ?? $book->getSheet(0);
        $raw = $sheet->toArray(null, false, false, false);

        $headerIndex = null;
        foreach ($raw as $i => $r) {
            if (array_filter($r, static fn($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            $result['fatal'][] = 'La feuille « Centres » est vide.';
            return $result;
        }
        $map = [];
        foreach ($raw[$headerIndex] as $col => $h) {
            $key = self::HEADERS[Text::normalize((string) $h)] ?? null;
            if ($key !== null && !isset($map[$key])) {
                $map[$key] = $col;
            }
        }
        foreach (self::REQUIRED as $key => $label) {
            if (!isset($map[$key])) {
                $result['fatal'][] = "Colonne obligatoire « $label » absente de la ligne d'en-tête.";
            }
        }
        if ($result['fatal'] !== []) {
            return $result;
        }

        $records = [];
        foreach ($raw as $i => $r) {
            if ($i <= $headerIndex) {
                continue;
            }
            $rec = [];
            foreach ($map as $key => $col) {
                $rec[$key] = $r[$col] ?? null;
            }
            if (implode('', array_map(static fn($v) => trim((string) $v), $rec)) === '') {
                continue;
            }
            $records[] = ['line' => $i + 1, 'in' => $rec];
        }
        if (count($records) > self::MAX_ROWS) {
            $result['fatal'][] = sprintf('Trop de lignes : %d (maximum %d par import).', count($records), self::MAX_ROWS);
            return $result;
        }

        $territory = $this->territoryIndex();
        $types = $this->typeIndex();
        $seenCode = [];
        $seenName = [];
        foreach ($records as $rec) {
            [$in, $decodeErrors, $communeLabel] = $this->decodeRow($rec['in'], $types, $territory);
            $duplicates = [];
            // Règles de la saisie manuelle, toujours appliquées (toutes les anomalies de la ligne en une fois) ; un
            // champ déjà signalé au décodage (type, statut, commune, date…) n'est pas signalé une seconde fois.
            [$data, $ruleErrors] = $this->structures->checkImportRow($in);
            $msgs = array_values($decodeErrors);
            foreach ($ruleErrors as $e) {
                if (!isset($decodeErrors[$e['field']])) {
                    $msgs[] = $e['message'];
                }
            }
            $data = $msgs === [] ? $data : null;
            if ($data !== null) {
                if (!$actor['scope']->coversCommune($data['commune_id'])) {
                    $msgs[] = "Commune $communeLabel hors de votre périmètre.";
                }
                if ($data['official_code'] !== null) {
                    if (isset($seenCode[$data['official_code']])) {
                        $msgs[] = "Code {$data['official_code']} en double dans le fichier (ligne {$seenCode[$data['official_code']]}).";
                    } else {
                        $seenCode[$data['official_code']] = $rec['line'];
                    }
                }
                $key = $data['commune_id'] . '|' . $data['name_normalized'];
                if (isset($seenName[$key])) {
                    $msgs[] = "Même nom dans la même commune qu'à la ligne {$seenName[$key]}.";
                } else {
                    $seenName[$key] = $rec['line'];
                }
                $duplicates = array_map(static fn($c) => trim(($c['official_code'] ?? '') . ' ' . $c['name']) . " ({$c['commune']} : " . implode(', ', $c['reasons']) . ')',
                    $this->structures->duplicateCandidates($data, null));
                if ($duplicates !== [] && !$in['confirm_duplicate']) {
                    $msgs[] = 'Doublon probable avec : ' . implode(' ; ', $duplicates) . '. Vérifier, puis choisir « Oui » dans « Doublon vérifié » pour le créer quand même.';
                }
            }
            $result['total']++;
            $msgs === [] ? $result['valid']++ : $result['invalid']++;
            $result['rows'][] = ['line' => $rec['line'], 'data' => $msgs === [] ? $data : null, 'errors' => $msgs, 'duplicates' => $duplicates,
                'code' => $data['official_code'] ?? (is_scalar($in['official_code']) ? (string) $in['official_code'] : ''),
                'name' => $data['name'] ?? (string) $in['name'], 'type' => $data['type_code'] ?? (string) $in['type_code'], 'commune' => $communeLabel,
                'status' => $data === null ? null : ($data['official_code'] !== null ? 'ACTIVE' : 'PROPOSEE')];
        }
        return $result;
    }

    /**
     * Libellés du modèle → valeurs attendues par StructureService (codes de type, PUBLIC/PRIVE, URBAINE/RURALE,
     * commune_id, dates ISO, coordonnées). @return array{0:array, 1:array<string,string>, 2:string} entrée, erreurs par champ, libellé de la commune
     */
    private function decodeRow(array $raw, array $types, array $t): array
    {
        $txt = static fn($v): string => $v === null ? '' : Text::clean(is_float($v) && floor($v) === $v ? sprintf('%.0f', $v) : (string) $v);
        $errors = [];

        $typeIn = $txt($raw['type'] ?? null);
        $typeCode = null;
        if ($typeIn !== '') {
            if (preg_match('/^([a-zA-Z])\s*[-–.)]/u', $typeIn, $m) || preg_match('/^([a-zA-Z])$/', $typeIn, $m)) {
                $typeCode = isset($types['codes'][strtolower($m[1])]) ? strtolower($m[1]) : null;
            }
            $typeCode ??= $types['labels'][Text::normalize($typeIn)] ?? null;
            if ($typeCode === null) {
                $errors['type_code'] = "Type de structure « $typeIn » inconnu (choisir dans la liste).";
            }
        }
        $legal = ['' => '', 'PUBLIC' => 'PUBLIC', 'PRIVE' => 'PRIVE'][Text::normalize($txt($raw['legal_status'] ?? null))] ?? null;
        if ($legal === null) {
            $errors['legal_status'] = 'Statut : Public ou Privé.';
        }
        $zone = ['' => '', 'URBAINE' => 'URBAINE', 'URBAIN' => 'URBAINE', 'RURALE' => 'RURALE', 'RURAL' => 'RURALE'][Text::normalize($txt($raw['zone'] ?? null))] ?? null;
        if ($zone === null) {
            $errors['zone'] = 'Zone : Urbaine ou Rurale.';
        }
        $confirm = in_array(Text::normalize($txt($raw['confirm_duplicate'] ?? null)), ['OUI', 'O', 'YES', '1', 'X'], true);

        // Territoire : Région → Département → Commune (noms, codes RENACOM ou libellés « NOM (code) »).
        $communeId = null;
        $communeLabel = $txt($raw['commune'] ?? null);
        $regionIn = $txt($raw['region'] ?? null);
        $deptIn = $txt($raw['department'] ?? null);
        $regionId = $this->lookup($t['regions'], $regionIn);
        if ($regionId === null || $regionId === -1) {
            $errors['commune_id'] = $regionIn === '' ? 'Région obligatoire.' : "Région « $regionIn » " . ($regionId === -1 ? 'ambiguë : utiliser le code RENACOM.' : 'inconnue (voir la feuille « Territoire »).');
        } else {
            $deptId = $this->lookup($t['departments'][$regionId] ?? [], $deptIn);
            if ($deptId === null || $deptId === -1) {
                $errors['commune_id'] = $deptIn === '' ? 'Département obligatoire.' : "Département « $deptIn » " . ($deptId === -1 ? 'ambigu : utiliser le code RENACOM.' : "inconnu dans la région {$t['region_names'][$regionId]}.");
            } else {
                $communeId = $this->lookup($t['communes'][$deptId] ?? [], $communeLabel);
                if ($communeId === null || $communeId === -1) {
                    $errors['commune_id'] = $communeLabel === '' ? 'Commune obligatoire.'
                        : "Commune « $communeLabel » " . ($communeId === -1 ? 'ambiguë : utiliser le code RENACOM.' : "inconnue dans le département {$t['department_names'][$deptId]}.");
                    $communeId = null;
                } else {
                    $communeLabel = "{$t['commune_names'][$communeId]} ({$t['department_names'][$deptId]})";
                }
            }
        }

        $dates = [];
        foreach (['authorization_date' => "Date d'autorisation", 'opening_date' => "Date d'ouverture"] as $k => $label) {
            $v = $raw[$k] ?? null;
            $dates[$k] = self::isoDate($v);
            if ($dates[$k] === false) {
                $errors[$k] = "$label « " . $txt($v) . ' » illisible (JJ/MM/AAAA).';
                $dates[$k] = '';
            }
        }
        $coord = static fn($v) => $v === null || $v === '' ? '' : (is_numeric($v) ? $v : str_replace(',', '.', trim((string) $v)));

        return [[
            'official_code' => $txt($raw['official_code'] ?? null),
            'name' => $txt($raw['name'] ?? null),
            'type_code' => $typeCode ?? '',
            'other_type_label' => $txt($raw['other_type_label'] ?? null),
            'legal_status' => $legal ?? '',
            'zone' => $zone ?? '',
            'commune_id' => $communeId ?? 0,
            'locality_label' => $txt($raw['locality_label'] ?? null),
            'latitude' => $coord($raw['latitude'] ?? null),
            'longitude' => $coord($raw['longitude'] ?? null),
            'authorization_date' => $dates['authorization_date'],
            'opening_date' => $dates['opening_date'],
            'confirm_duplicate' => $confirm,
        ], $errors, $communeLabel];
    }

    /** Date Excel (numéro de série), JJ/MM/AAAA ou AAAA-MM-JJ → AAAA-MM-JJ ; '' si vide ; false si illisible. */
    private static function isoDate(mixed $v): string|false
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return $v > 0 && $v < 2958466 ? ExcelDate::excelToDateTimeObject($v)->format('Y-m-d') : false;
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : false;
        }
        return false;
    }

    /** Types actifs : codes, et libellés normalisés (« ATELIER », « C ATELIER ») → code. */
    private function typeIndex(): array
    {
        $out = ['codes' => [], 'labels' => []];
        foreach ($this->db->fetchAll('SELECT code, label FROM structure_types WHERE active = 1') as $t) {
            $out['codes'][$t['code']] = true;
            $out['labels'][Text::normalize($t['label'])] = $t['code'];
            $out['labels'][Text::normalize("{$t['code']} {$t['label']}")] = $t['code'];
        }
        return $out;
    }

    // ===================================================================== Rapports

    private function summary(array $a): array
    {
        $byStatus = [];
        foreach ($a['rows'] as $r) {
            if ($r['errors'] === [] && $r['status'] !== null) {
                $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            }
        }
        return [
            'fatal' => $a['fatal'],
            'by_status' => $byStatus,
            'rows' => array_map(static fn($r) => [
                'line' => $r['line'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'], 'commune' => $r['commune'],
                'status' => $r['status'], 'duplicates' => $r['duplicates'], 'errors' => $r['errors'],
            ], $a['rows']),
        ];
    }

    /** @return list<string> */
    private function flatErrors(array $a): array
    {
        $out = $a['fatal'];
        foreach ($a['rows'] as $r) {
            foreach ($r['errors'] as $e) {
                $out[] = "Ligne {$r['line']} : $e";
            }
        }
        return $out;
    }

    private function writeCheckReport(array $a, string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Contrôle');
        $sheet->fromArray(['Ligne', 'Code officiel', 'Nom du centre', 'Type', 'Commune', 'Création', 'Résultat', 'Anomalies', 'Doublons signalés'], null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $line = 2;
        foreach ($a['fatal'] as $f) {
            $sheet->fromArray(['', '', '', '', '', '', 'BLOQUANT', $f], null, 'A' . $line++);
        }
        foreach ($a['rows'] as $r) {
            $sheet->setCellValueExplicit("B$line", (string) $r['code'], DataType::TYPE_STRING);
            $sheet->fromArray([$r['line']], null, "A$line");
            $sheet->fromArray([$r['name'], $r['type'], $r['commune'], ['ACTIVE' => 'Actif', 'PROPOSEE' => 'Proposé (sans code)'][$r['status']] ?? '',
                $r['errors'] === [] ? 'OK' : 'ERREUR', implode(' | ', $r['errors']), implode(' | ', $r['duplicates'])], null, "C$line", true);
            $sheet->getStyle("G$line")->getFont()->getColor()->setRGB($r['errors'] === [] ? '1E7B34' : 'C62828');
            $line++;
        }
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('H')->setWidth(90);
        $sheet->getColumnDimension('I')->setWidth(60);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:I' . max(1, $line - 1));
        $this->ensureDir(dirname($path));
        (new Xlsx($book))->save($path);
    }

    /** Import de centres appartenant à l'acteur (ou n'importe lequel pour l'administrateur national) ; sinon 404. */
    private function findOwned(array $actor, string $uuid): array
    {
        $imp = $this->db->fetchOne(
            "SELECT i.*, f.original_name, f.stored_path, f.sha256 FROM imports i JOIN files f ON f.id = i.source_file_id
              WHERE i.uuid = ? AND i.type = 'STRUCTURES'", [$uuid]);
        if ($imp === null || (!$actor['is_admin'] && (int) $imp['created_by'] !== $actor['id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Import introuvable.');
        }
        return $imp;
    }
}
