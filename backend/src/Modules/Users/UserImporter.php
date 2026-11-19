<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Audit;
use App\Core\Database;
use App\Core\ImportException;
use App\Core\Text;
use App\Http\HttpException;
use App\Http\UploadedFile;
use App\Modules\Auth\AuthService;
use App\Modules\Imports\ExcelImportSupport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Import en masse des agents et superviseurs (docs/phase1/02 §2 et UC-03), en deux temps :
 *
 * 1. preview() : le fichier est enregistré hors racine web, chaque ligne est contrôlée (colonnes, formats,
 *    doublons dans le fichier et en base, cohérence rôle/territoire, superviseur, droits de l'importateur).
 *    Rien n'est écrit dans les comptes. Un rapport ligne par ligne est produit.
 * 2. commit() : le fichier est recontrôlé (la base a pu changer), puis TOUS les comptes sont créés dans
 *    une seule transaction, ou aucun. Les mots de passe provisoires sont remis dans une archive ZIP
 *    chiffrée AES-256 dont le mot de passe n'est affiché qu'une fois et n'est jamais stocké.
 */
final class UserImporter
{
    use ExcelImportSupport;

    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const CREDENTIALS_TTL_DAYS = 7;

    /** En-têtes reconnus (forme normalisée) → clé interne. */
    private const HEADERS = [
        'MATRICULE' => 'matricule', 'NOM' => 'last_name', 'PRENOM' => 'first_name', 'PRENOMS' => 'first_name', 'SEXE' => 'sex',
        'TELEPHONE' => 'phone', 'EMAIL' => 'email', 'E MAIL' => 'email', 'COURRIEL' => 'email', 'ROLE' => 'role',
        'REGION' => 'region', 'DEPARTEMENT' => 'department', 'DEPARTEMENTS' => 'department', 'COMMUNE' => 'commune', 'COMMUNES' => 'commune',
        'MATRICULE SUPERVISEUR' => 'supervisor', 'SUPERVISEUR' => 'supervisor',
    ];
    private const REQUIRED = ['matricule' => 'Matricule', 'last_name' => 'Nom', 'first_name' => 'Prénom', 'sex' => 'Sexe', 'role' => 'Rôle', 'region' => 'Région'];
    public const TEMPLATE_COLUMNS = ['Matricule *', 'Nom *', 'Prénom *', 'Sexe *', 'Téléphone', 'E-mail', 'Rôle *', 'Région *', 'Département', 'Commune', 'Superviseur'];
    private const SUP_SEPARATOR = ' — ';

    /** Libellés de rôle acceptés dans le fichier. L'administrateur national n'est pas importable. */
    private const ROLE_ALIASES = [
        'AGENT' => 'AGENT', 'AGENT RECENSEUR' => 'AGENT', 'ENQUETEUR' => 'AGENT',
        'SUP DEP' => 'SUP_DEP', 'SUPERVISEUR DEPARTEMENTAL' => 'SUP_DEP',
        'SUP REG' => 'SUP_REG', 'SUPERVISEUR REGIONAL' => 'SUP_REG',
        'VIEWER' => 'VIEWER', 'LECTEUR' => 'VIEWER', 'ANALYSTE' => 'VIEWER',
    ];

    private readonly UserService $users;
    private readonly Audit $audit;
    /** @var array<string, Scope> */
    private array $scopeCache = [];

    public function __construct(private readonly Database $db, private readonly string $storageDir)
    {
        $this->users = new UserService($db);
        $this->audit = new Audit($db);
    }

    // ===================================================================== Modèle

    /** Libellés affichés dans les listes déroulantes du modèle → code enregistré en base. */
    public const SEX_LABELS = ['M' => 'M - Masculin', 'F' => 'F - Féminin'];
    public const ROLE_LABELS = [
        'AGENT' => 'Agent recenseur (AGENT)',
        'SUP_DEP' => 'Superviseur départemental (SUP_DEP)',
        'SUP_REG' => 'Superviseur régional (SUP_REG)',
        'VIEWER' => 'Lecteur / analyste (VIEWER)',
    ];
    public const NATIONAL_LABEL = 'NATIONAL (lecteurs uniquement)';

    /**
     * Écrit le modèle Excel à remplir et renvoie son chemin.
     *
     * Toutes les colonnes codées se remplissent par liste déroulante : Sexe, Rôle, Région, puis Département
     * (seulement ceux de la région choisie) et Commune (seulement celles du département choisi), et le superviseur
     * parmi les comptes existants. Les libellés affichés sont traduits en codes à l'import (feuille « Listes », masquée).
     * Avec un acteur non administrateur, les listes sont limitées à son périmètre.
     */
    public function writeTemplate(string $path, ?array $actor = null): string
    {
        $scope = ($actor === null || $actor['is_admin']) ? null : $actor['scope'];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Agents');
        $sheet->fromArray(self::TEMPLATE_COLUMNS, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E07B00');
        $sheet->getRowDimension(1)->setRowHeight(22);
        foreach (['A' => 14, 'B' => 18, 'C' => 18, 'D' => 15, 'E' => 14, 'F' => 26, 'G' => 34, 'H' => 22, 'I' => 24, 'J' => 24, 'K' => 44] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $last = self::MAX_ROWS + 1;
        $sheet->getStyle("A2:A$last")->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle("E2:E$last")->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');

        // ---- Feuille « Listes » (masquée) : libellés des listes déroulantes et correspondances libellé → plage.
        $lists = $book->createSheet()->setTitle('Listes');
        $put = static function (int $col, array $values, string $title) use ($lists): string {
            $letter = Coordinate::stringFromColumnIndex($col);
            $lists->setCellValue($letter . '1', $title);
            foreach (array_values($values) as $i => $v) {
                $lists->setCellValueExplicit($letter . ($i + 2), (string) $v, DataType::TYPE_STRING);
            }
            return '$' . $letter . '$2:$' . $letter . '$' . (max(1, count($values)) + 1);
        };
        $sexRange = 'Listes!' . $put(1, self::SEX_LABELS, 'Sexe');
        $roleRange = 'Listes!' . $put(2, self::ROLE_LABELS, 'Rôle');

        // Territoire du périmètre : régions → départements → communes ; libellé = nom (code ajouté en cas d'homonymie).
        $tree = $this->territoryTree($scope);
        $labels = self::listLabels(...);

        $col = 5; // colonnes A à D : sexe, rôle, table des régions ; ensuite une liste par région et par département
        $book->addNamedRange(new NamedRange('L_VIDE', $lists, $put($col++, [], 'Vide')));
        $regionTable = $scope === null ? [self::NATIONAL_LABEL => 'L_VIDE'] : []; // libellé région → plage de ses départements
        $deptTable = [];                                                          // « région|département » → plage des communes
        $regionLabels = $labels($tree);
        foreach ($tree as $rid => $reg) {
            $deptLabels = $labels($reg['deps']);
            $book->addNamedRange(new NamedRange("L_REG_$rid", $lists, $put($col++, $deptLabels, 'Départements ' . $reg['name'])));
            $regionTable[$regionLabels[$rid]] = "L_REG_$rid";
            foreach ($reg['deps'] as $did => $dep) {
                $book->addNamedRange(new NamedRange("L_DEP_$did", $lists, $put($col++, $labels($dep['communes']), 'Communes ' . $dep['name'])));
                $deptTable[$regionLabels[$rid] . '|' . $deptLabels[$did]] = "L_DEP_$did";
            }
        }
        $put(3, array_keys($regionTable), 'Région');
        $put(4, array_values($regionTable), 'Plage des départements');
        $n = max(1, count($regionTable)) + 1;
        $regionRange = 'Listes!$C$2:$C$' . $n;
        $regionLookup = 'Listes!$C$2:$D$' . $n;
        $keyCol = $col++;
        $put($keyCol, array_keys($deptTable), 'Région|Département');
        $put($col++, array_values($deptTable), 'Plage des communes');
        $deptLookup = 'Listes!$' . Coordinate::stringFromColumnIndex($keyCol) . '$2:$' . Coordinate::stringFromColumnIndex($keyCol + 1)
            . '$' . (max(1, count($deptTable)) + 1);

        // Superviseurs existants (actifs, dans le périmètre) : « MATRICULE — Nom Prénom (rôle) ».
        $supLabels = [];
        foreach ($this->db->fetchAll(
            "SELECT u.id, u.matricule, u.last_name, u.first_name, GROUP_CONCAT(DISTINCT r.code) AS roles FROM users u
               JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.deleted_at IS NULL AND u.status = 'ACTIF' AND r.code IN ('SUP_DEP', 'SUP_REG')
              GROUP BY u.id, u.matricule, u.last_name, u.first_name ORDER BY u.last_name, u.first_name") as $u) {
            if ($scope !== null && !$scope->contains(Scope::forUser($this->db, (int) $u['id']))) {
                continue;
            }
            $supLabels[] = $u['matricule'] . self::SUP_SEPARATOR . "{$u['last_name']} {$u['first_name']} (" . str_replace(',', ', ', (string) $u['roles']) . ')';
        }
        $supRange = 'Listes!' . $put($col++, $supLabels, 'Superviseurs existants');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // ---- Listes déroulantes de la feuille « Agents ».
        $this->listValidation($sheet, "D2:D$last", $sexRange, 'Sexe', 'Choisir dans la liste : M - Masculin ou F - Féminin.');
        $this->listValidation($sheet, "G2:G$last", $roleRange, 'Rôle', 'Choisir le rôle du compte dans la liste.');
        $this->listValidation($sheet, "H2:H$last", $regionRange, 'Région', 'Choisir la région dans la liste.');
        $this->listValidation($sheet, "I2:I$last", "INDIRECT(VLOOKUP(\$H2,$regionLookup,2,FALSE))", 'Département',
            "Choisir d'abord la région : seuls ses départements sont proposés. Superviseur départemental : plusieurs départements séparés par « ; ».", false);
        $this->listValidation($sheet, "J2:J$last", "INDIRECT(VLOOKUP(\$H2&\"|\"&\$I2,$deptLookup,2,FALSE))", 'Commune',
            "Facultatif (agents). Choisir d'abord le département : seules ses communes sont proposées. Plusieurs communes : séparer par « ; ».", false);
        $this->listValidation($sheet, "K2:K$last", $supRange, 'Superviseur',
            "Facultatif. Choisir un superviseur existant, ou écrire le matricule d'un superviseur ajouté dans ce fichier.", false);

        $notice = $book->createSheet()->setTitle('Notice');
        $lines = [
            ["Import des agents et superviseurs — mode d'emploi"],
            [''],
            ["1. Remplir la feuille « Agents », une ligne par compte, sans modifier la ligne d'en-tête. Les colonnes marquées * sont obligatoires."],
            ['2. Sexe, Rôle, Région, Département, Commune et Superviseur se choisissent dans des listes déroulantes (cliquer sur la cellule, puis sur la flèche).'],
            ["   Remplir dans l'ordre Région → Département → Commune : chaque liste ne propose que les choix du niveau au-dessus. Les codes sont ajoutés automatiquement à l'import."],
            ['3. Ce que chaque rôle demande :'],
            ['   - Superviseur régional : Région seule.'],
            ['   - Superviseur départemental : Région + Département (plusieurs départements possibles, séparés par « ; »).'],
            ['   - Agent recenseur : Région + Département ; Commune facultative (plusieurs communes du même département séparées par « ; »).'],
            ["   - Lecteur / analyste : Région (ou NATIONAL) et Département facultatif. Les administrateurs ne s'importent pas."],
            ["4. Téléphone : 8 chiffres, avec ou sans +227. Il sert aussi d'identifiant de connexion. Matricule, téléphone et e-mail doivent être uniques."],
            ["5. Superviseur : facultatif. Choisir un superviseur existant dans la liste, ou écrire le matricule d'un superviseur ajouté dans ce même fichier."],
            ["6. L'import se fait en deux temps : prévisualisation (aucune écriture, rapport des anomalies ligne par ligne), puis validation. Il est tout-ou-rien."],
            ["7. Après validation, les mots de passe provisoires sont remis dans une archive ZIP protégée (AES-256, à ouvrir avec 7-Zip). Son mot de passe n'est affiché qu'une seule fois."],
            ['   Chaque utilisateur doit changer son mot de passe à la première connexion.'],
            ['8. La feuille « Territoire » donne les noms et codes RENACOM ; un code peut aussi être saisi à la place du nom.'],
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

    // ===================================================================== Prévisualisation

    /** Enregistre le fichier, le contrôle et renvoie la prévisualisation (aucun compte n'est créé). */
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
            $sourceId = $this->insertFile($actor['id'], 'IMPORT_USERS', $upload->clientName, $relative, null);
            $reportId = $this->insertFile($actor['id'], 'REPORT', 'controle-import-agents.xlsx', $reportRel, null);
            $db->execute(
                "INSERT INTO imports (uuid, type, status, source_file_id, report_file_id, total_rows, valid_rows, error_rows, summary_json, created_by)
                 VALUES (?, 'USERS', 'PREVISUALISE', ?, ?, ?, ?, ?, ?, ?)",
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
            'by_role' => $summary['by_role'] ?? [],
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

    // ===================================================================== Validation

    /**
     * Recontrôle puis crée tous les comptes en une transaction.
     * @return array{import:array, created:int, archive_password:string}
     * @throws ImportException si une anomalie apparaît (aucun compte créé)
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
            throw new ImportException("Import refusé : aucun compte n'a été créé.", array_slice($errors, 0, 200));
        }

        @set_time_limit(600);
        $archivePassword = AuthService::generateTemporaryPassword() . '-' . substr(AuthService::generateTemporaryPassword(), 0, 4);
        $zipRel = 'imports/' . $imp['uuid'] . '-identifiants.zip';
        $zipAbs = $this->storageDir . '/' . $zipRel;
        $rows = $analysis['rows'];
        // Superviseurs d'abord (rang décroissant) pour que les liens hiérarchiques internes au fichier se résolvent.
        usort($rows, static fn($a, $b) => [UserService::ROLE_RANK[$b['role']], $a['line']] <=> [UserService::ROLE_RANK[$a['role']], $b['line']]);

        try {
            $created = $this->db->transaction(function (Database $db) use ($actor, $imp, $rows, $archivePassword, $zipAbs, $zipRel, $ip): int {
                $ids = [];
                $credentials = [];
                foreach ($rows as $r) {
                    $sup = $r['supervisor'];
                    $supervisorId = match ($sup['source'] ?? null) {
                        'db' => $sup['id'],
                        'file' => $ids[$sup['matricule']] ?? throw new \LogicException('Superviseur interne au fichier non encore créé.'),
                        default => null,
                    };
                    $password = AuthService::generateTemporaryPassword();
                    $ids[$r['data']['matricule']] = $this->users->insertUser($r['data'], $r['role'], $r['scope_rows'], $supervisorId, $password, $actor['id']);
                    $credentials[] = [$r['data']['matricule'], $r['data']['last_name'], $r['data']['first_name'], $r['role'], $r['scope_label'],
                        $sup['matricule'] ?? '', $r['data']['phone'] ?? '', $password];
                }
                usort($credentials, static fn($a, $b) => [$a[4], $a[1], $a[2]] <=> [$b[4], $b[1], $b[2]]);
                $this->writeCredentialsArchive($credentials, $zipAbs, $archivePassword);
                $reportId = $this->insertFile($actor['id'], 'REPORT', 'identifiants-agents.zip', $zipRel, self::CREDENTIALS_TTL_DAYS);
                $db->execute("UPDATE imports SET status = 'IMPORTE', committed_by = ?, committed_at = NOW(), report_file_id = ? WHERE id = ?",
                    [$actor['id'], $reportId, $imp['id']]);
                $this->audit->log($actor['id'], 'IMPORT_USERS', 'import', $imp['uuid'], null,
                    ['created' => count($ids), 'matricules' => array_keys($ids)], null, $ip);
                return count($ids);
            });
        } catch (\Throwable $e) {
            @unlink($zipAbs); // transaction annulée : l'archive ne doit pas survivre
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Un compte de ce fichier vient d\'être créé par ailleurs (doublon). Relancer la prévisualisation.');
            }
            throw $e;
        }
        return ['import' => $this->get($actor, $uuid, false), 'created' => $created, 'archive_password' => $archivePassword];
    }

    /** Annule un import non validé (le fichier source est conservé pour l'audit). */
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

    /**
     * Rapport téléchargeable : contrôle ligne par ligne avant validation ; archive protégée des identifiants après.
     * @return array{0:string, 1:string, 2:string} chemin, nom, type MIME
     */
    public function report(array $actor, string $uuid, string $ip): array
    {
        $imp = $this->findOwned($actor, $uuid);
        $f = $imp['report_file_id'] === null ? null
            : $this->db->fetchOne('SELECT original_name, stored_path, mime_type, expires_at FROM files WHERE id = ?', [$imp['report_file_id']]);
        if ($f === null || !is_file($this->storageDir . '/' . $f['stored_path'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Rapport introuvable.');
        }
        if ($f['expires_at'] !== null && strtotime($f['expires_at']) <= time()) {
            @unlink($this->storageDir . '/' . $f['stored_path']); // mots de passe provisoires : rien ne reste sur le disque après expiration
            throw new HttpException(410, 'GONE', 'Ce rapport a expiré. Réinitialiser les mots de passe des comptes concernés si nécessaire.');
        }
        if ($imp['status'] === 'IMPORTE') {
            $this->audit->log($actor['id'], 'IMPORT_CREDENTIALS_DOWNLOAD', 'import', $imp['uuid'], null, null, null, $ip);
        }
        return [$this->storageDir . '/' . $f['stored_path'], $f['original_name'], $f['mime_type']];
    }

    // ===================================================================== Analyse du fichier

    /** @return array{total:int, valid:int, invalid:int, fatal:list<string>, rows:list<array>} */
    public function analyze(array $actor, string $path): array
    {
        $result = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'fatal' => [], 'rows' => []];
        try {
            // Lecteur Xlsx explicite : aucun autre format (CSV, HTML, XML…) n'est interprété.
            $book = IOFactory::createReader('Xlsx')->setReadDataOnly(true)->load($path);
        } catch (\Throwable) {
            $result['fatal'][] = "Le classeur est illisible ou n'est pas au format .xlsx.";
            return $result;
        }
        $sheet = $book->getSheetByName('Agents') ?? $book->getSheet(0);
        $raw = $sheet->toArray(null, false, false, false);

        $headerIndex = null;
        foreach ($raw as $i => $r) {
            if (array_filter($r, static fn($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            $result['fatal'][] = 'La feuille « Agents » est vide.';
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
                $v = $r[$col] ?? null;
                if (is_float($v) && floor($v) === $v) {
                    $v = sprintf('%.0f', $v); // matricules et téléphones saisis comme nombres
                }
                $rec[$key] = $v === null ? '' : Text::clean((string) $v);
            }
            if (implode('', $rec) === '') {
                continue;
            }
            $records[] = ['line' => $i + 1, 'in' => self::decodeLabels($rec)];
        }
        if (count($records) > self::MAX_ROWS) {
            $result['fatal'][] = sprintf('Trop de lignes : %d (maximum %d par import).', count($records), self::MAX_ROWS);
            return $result;
        }

        $territory = $this->territoryIndex();
        $existing = $this->existingKeys();
        $seen = ['matricule' => [], 'phone' => [], 'email' => []];
        $rows = [];

        // Passe 1 : contrôles propres à chaque ligne.
        foreach ($records as $rec) {
            $in = $rec['in'];
            $errs = [];
            $data = $this->users->validateIdentity($in, $errs, null, false);
            $msgs = array_column($errs, 'message');

            foreach (['matricule' => 'Matricule', 'phone' => 'Téléphone', 'email' => 'E-mail'] as $k => $label) {
                $v = $data[$k];
                if ($v === null || $v === '') {
                    continue;
                }
                $key = mb_strtolower($v);
                if (isset($existing[$k][$key])) {
                    $msgs[] = "$label « $v » déjà attribué à un compte existant.";
                }
                if (isset($seen[$k][$key])) {
                    $msgs[] = "$label « $v » en double dans le fichier (ligne {$seen[$k][$key]}).";
                } else {
                    $seen[$k][$key] = $rec['line'];
                }
            }

            $roleKey = Text::normalize($in['role'] ?? '');
            $role = self::ROLE_ALIASES[$roleKey] ?? null;
            if ($role === null) {
                $msgs[] = $roleKey === 'ADMIN'
                    ? 'Les administrateurs ne peuvent pas être importés.'
                    : 'Rôle inconnu « ' . ($in['role'] ?? '') . ' » (AGENT, SUP_DEP, SUP_REG ou VIEWER).';
            }
            $scopeRows = [];
            $scopeLabel = '';
            if ($role !== null) {
                [$scopeRows, $scopeLabel, $terrErrors] = $this->resolveTerritory($role, $in, $territory);
                array_push($msgs, ...$terrErrors);
                if ($terrErrors === []) {
                    $e2 = [];
                    $scopeRows = $this->users->normalizeScopes($scopeRows, $role, $e2);
                    array_push($msgs, ...array_column($e2, 'message'));
                    if ($e2 === []) {
                        try {
                            $this->users->assertCanGrant($actor, $role, $this->scope($scopeRows));
                        } catch (HttpException $e) {
                            $msgs[] = $e->getMessage();
                        }
                    }
                }
            }
            $rows[] = ['line' => $rec['line'], 'data' => $data, 'role' => $role, 'scope_rows' => $scopeRows, 'scope_label' => $scopeLabel,
                'supervisor_ref' => mb_strtoupper($in['supervisor'] ?? ''), 'supervisor' => null, 'errors' => $msgs];
        }

        // Passe 2 : superviseur, désigné dans le fichier ou parmi les comptes existants.
        $byMatricule = [];
        foreach ($rows as $idx => $r) {
            $byMatricule[$r['data']['matricule']] ??= $idx;
        }
        foreach ($rows as &$r) {
            $ref = $r['supervisor_ref'];
            if ($ref === '' || $r['role'] === null || $r['scope_rows'] === []) {
                continue;
            }
            if ($ref === $r['data']['matricule']) {
                $r['errors'][] = 'Un compte ne peut pas être son propre superviseur.';
                continue;
            }
            if (isset($byMatricule[$ref])) {
                $sup = $rows[$byMatricule[$ref]];
                if ($sup['errors'] !== [] || $sup['role'] === null) {
                    $r['errors'][] = "Le superviseur « $ref » (ligne {$sup['line']}) comporte lui-même des erreurs.";
                    continue;
                }
                $problem = $this->users->supervisorProblem(['id' => -$sup['line'], 'status' => 'ACTIF', 'roles' => [$sup['role']]],
                    $r['role'], $this->scope($r['scope_rows']), null, $this->scope($sup['scope_rows']));
                $r['supervisor'] = ['source' => 'file', 'matricule' => $ref];
            } else {
                $cand = $this->users->supervisorCandidate('u.matricule = ?', $ref);
                $problem = $cand === null ? 'introuvable (ni dans le fichier, ni parmi les comptes existants).'
                    : $this->users->supervisorProblem($cand, $r['role'], $this->scope($r['scope_rows']));
                if ($cand !== null) {
                    $r['supervisor'] = ['source' => 'db', 'id' => $cand['id'], 'matricule' => $ref];
                }
            }
            if ($problem !== null) {
                $r['errors'][] = "Superviseur « $ref » : $problem";
            }
        }
        unset($r);

        foreach ($rows as $r) {
            $result['total']++;
            $r['errors'] === [] ? $result['valid']++ : $result['invalid']++;
        }
        $result['rows'] = $rows;
        return $result;
    }

    /**
     * Traduit Région / Département / Commune (noms ou codes) en lignes de périmètre selon le rôle.
     * @return array{0:list<array>, 1:string, 2:list<string>} lignes, libellé, erreurs
     */
    private function resolveTerritory(string $role, array $in, array $t): array
    {
        $regionIn = $in['region'] ?? '';
        $deptsIn = $this->splitList($in['department'] ?? '');
        $communesIn = $this->splitList($in['commune'] ?? '');
        $errors = [];

        if (Text::normalize($regionIn) === 'NATIONAL') {
            if ($role !== 'VIEWER') {
                return [[], '', ["Le périmètre « NATIONAL » n'est admis que pour le rôle VIEWER."]];
            }
            if ($deptsIn !== [] || $communesIn !== []) {
                return [[], '', ['Périmètre NATIONAL : laisser Département et Commune vides.']];
            }
            return [[['level' => 'NATIONAL']], 'National', []];
        }
        $regionId = $this->lookup($t['regions'], $regionIn);
        if ($regionId === null) {
            return [[], '', [$regionIn === '' ? 'Région obligatoire.' : "Région « $regionIn » inconnue (voir la feuille « Territoire »)."]];
        }
        if ($regionId === -1) {
            return [[], '', ["Région « $regionIn » ambiguë : utiliser le code RENACOM."]];
        }
        $regionName = $t['region_names'][$regionId];

        $deptIds = [];
        foreach ($deptsIn as $d) {
            $id = $this->lookup($t['departments'][$regionId] ?? [], $d);
            if ($id === null) {
                $errors[] = "Département « $d » inconnu dans la région $regionName.";
            } elseif ($id === -1) {
                $errors[] = "Département « $d » ambigu : utiliser le code RENACOM.";
            } else {
                $deptIds[$id] = true;
            }
        }
        $communeIds = [];
        if ($communesIn !== []) {
            if (count($deptsIn) !== 1) {
                $errors[] = 'Pour indiquer des communes, renseigner un et un seul département.';
            } elseif ($deptIds !== []) {
                $deptId = array_key_first($deptIds);
                foreach ($communesIn as $c) {
                    $id = $this->lookup($t['communes'][$deptId] ?? [], $c);
                    if ($id === null) {
                        $errors[] = "Commune « $c » inconnue dans le département {$t['department_names'][$deptId]}.";
                    } elseif ($id === -1) {
                        $errors[] = "Commune « $c » ambiguë : utiliser le code RENACOM.";
                    } else {
                        $communeIds[$id] = true;
                    }
                }
            }
        }
        if ($errors !== []) {
            return [[], '', $errors];
        }

        $deptNames = implode(', ', array_map(static fn($id) => $t['department_names'][$id], array_keys($deptIds)));
        $deptRows = array_map(static fn($id) => ['level' => 'DEPARTMENT', 'department_id' => $id], array_keys($deptIds));
        switch ($role) {
            case 'SUP_REG':
                if ($deptsIn !== [] || $communesIn !== []) {
                    return [[], '', ['Superviseur régional : laisser Département et Commune vides.']];
                }
                return [[['level' => 'REGION', 'region_id' => $regionId]], "Région $regionName", []];
            case 'SUP_DEP':
                if ($deptIds === []) {
                    return [[], '', ['Superviseur départemental : le département est obligatoire.']];
                }
                if ($communesIn !== []) {
                    return [[], '', ['Superviseur départemental : laisser la commune vide.']];
                }
                return [$deptRows, "Département $deptNames", []];
            case 'AGENT':
                if ($deptIds === []) {
                    return [[], '', ['Agent : le département est obligatoire (la commune est facultative).']];
                }
                if ($communeIds !== []) {
                    $names = implode(', ', array_map(static fn($id) => $t['commune_names'][$id], array_keys($communeIds)));
                    return [array_map(static fn($id) => ['level' => 'COMMUNE', 'commune_id' => $id], array_keys($communeIds)), "Commune $names ($deptNames)", []];
                }
                return [$deptRows, "Département $deptNames", []];
            default: // VIEWER
                if ($communesIn !== []) {
                    return [[], '', ['Lecteur : périmètre régional ou départemental (laisser la commune vide).']];
                }
                return $deptIds === []
                    ? [[['level' => 'REGION', 'region_id' => $regionId]], "Région $regionName", []]
                    : [$deptRows, "Département $deptNames", []];
        }
    }

    /**
     * Libellés des listes déroulantes du modèle → codes (les anciennes saisies, codes ou noms, restent acceptées) :
     * « M - Masculin » → M ; « Agent recenseur (AGENT) » → AGENT ; « MAT01 — Nom Prénom (SUP_DEP) » → MAT01 ;
     * « NATIONAL (lecteurs uniquement) » → NATIONAL.
     */
    public static function decodeLabels(array $in): array
    {
        $sex = Text::normalize($in['sex'] ?? '');
        if (preg_match('/^([MF])\b/', $sex, $m)) {
            $in['sex'] = $m[1];
        } elseif (in_array($sex, ['MASCULIN', 'HOMME'], true)) {
            $in['sex'] = 'M';
        } elseif (in_array($sex, ['FEMININ', 'FEMME'], true)) {
            $in['sex'] = 'F';
        }
        if (preg_match('/\(([A-Za-z_]+)\)\s*$/', $in['role'] ?? '', $m) && isset(self::ROLE_LABELS[strtoupper($m[1])])) {
            $in['role'] = strtoupper($m[1]);
        }
        if (str_starts_with(Text::normalize($in['region'] ?? ''), 'NATIONAL')) {
            $in['region'] = 'NATIONAL';
        }
        $sup = $in['supervisor'] ?? '';
        if (str_contains($sup, trim(self::SUP_SEPARATOR))) {
            $in['supervisor'] = trim(explode(trim(self::SUP_SEPARATOR), $sup, 2)[0]);
        }
        return $in;
    }

    /** @return list<string> */
    private function splitList(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $s)), static fn($v) => $v !== ''));
    }

    /** Matricules, téléphones et e-mails déjà pris (comptes supprimés compris : index uniques). */
    private function existingKeys(): array
    {
        $out = ['matricule' => [], 'phone' => [], 'email' => []];
        foreach ($this->db->fetchAll('SELECT matricule, phone, email FROM users') as $u) {
            foreach (array_keys($out) as $k) {
                if ($u[$k] !== null && $u[$k] !== '') {
                    $out[$k][mb_strtolower($u[$k])] = true;
                }
            }
        }
        return $out;
    }

    private function scope(array $rows): Scope
    {
        $key = json_encode($rows);
        return $this->scopeCache[$key] ??= Scope::fromRows($this->db, $rows);
    }

    // ===================================================================== Rapports et fichiers

    private function summary(array $a): array
    {
        $byRole = [];
        foreach ($a['rows'] as $r) {
            if ($r['role'] !== null) {
                $byRole[$r['role']] = ($byRole[$r['role']] ?? 0) + 1;
            }
        }
        return [
            'fatal' => $a['fatal'],
            'by_role' => $byRole,
            'rows' => array_map(static fn($r) => [
                'line' => $r['line'],
                'matricule' => $r['data']['matricule'],
                'name' => trim($r['data']['first_name'] . ' ' . $r['data']['last_name']),
                'role' => $r['role'],
                'scope' => $r['scope_label'],
                'supervisor' => $r['supervisor_ref'] !== '' ? $r['supervisor_ref'] : null,
                'errors' => $r['errors'],
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
        $sheet->fromArray(['Ligne', 'Matricule', 'Nom et prénom', 'Rôle', 'Périmètre', 'Superviseur', 'Résultat', 'Anomalies'], null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $line = 2;
        foreach ($a['fatal'] as $f) {
            $sheet->fromArray(['', '', '', '', '', '', 'BLOQUANT', $f], null, 'A' . $line++);
        }
        foreach ($a['rows'] as $r) {
            $sheet->fromArray([$r['line'], $r['data']['matricule'], trim($r['data']['last_name'] . ' ' . $r['data']['first_name']),
                $r['role'] ?? '', $r['scope_label'], $r['supervisor_ref'], $r['errors'] === [] ? 'OK' : 'ERREUR', implode(' | ', $r['errors'])],
                null, 'A' . $line, true);
            $sheet->getStyle("G$line")->getFont()->getColor()->setRGB($r['errors'] === [] ? '1E7B34' : 'C62828');
            $line++;
        }
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('H')->setWidth(100);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:H' . max(1, $line - 1));
        $this->ensureDir(dirname($path));
        (new Xlsx($book))->save($path);
    }

    private function writeCredentialsArchive(array $credentials, string $zipPath, string $password): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Identifiants');
        $sheet->fromArray(['Matricule (identifiant)', 'Nom', 'Prénom', 'Rôle', 'Périmètre', 'Superviseur', 'Téléphone (identifiant)', 'Mot de passe provisoire'], null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->fromArray($credentials, null, 'A2', true);
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $last = count($credentials) + 3;
        $sheet->setCellValue("A$last", 'Document confidentiel. Remettre à chaque agent son seul mot de passe. Changement obligatoire à la première connexion. Détruire ce fichier après distribution.');

        $this->ensureDir(dirname($zipPath));
        $tmp = $zipPath . '.tmp.xlsx';
        (new Xlsx($book))->save($tmp);
        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true
                || !$zip->addFile($tmp, 'identifiants-agents.xlsx')
                || !$zip->setEncryptionName('identifiants-agents.xlsx', \ZipArchive::EM_AES_256, $password)
                || !$zip->close()) {
                throw new \RuntimeException("Impossible de produire l'archive chiffrée des identifiants.");
            }
        } finally {
            @unlink($tmp);
        }
    }

    /** Import appartenant à l'acteur (ou n'importe lequel pour l'administrateur national) ; sinon 404. */
    private function findOwned(array $actor, string $uuid): array
    {
        $imp = $this->db->fetchOne(
            "SELECT i.*, f.original_name, f.stored_path, f.sha256 FROM imports i JOIN files f ON f.id = i.source_file_id
              WHERE i.uuid = ? AND i.type = 'USERS'", [$uuid]);
        if ($imp === null || (!$actor['is_admin'] && (int) $imp['created_by'] !== $actor['id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Import introuvable.');
        }
        return $imp;
    }
}
