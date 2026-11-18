<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Response;
use App\Http\UploadedFile;
use App\Modules\Users\UserImporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Import Excel des agents (lot L2) : modèle, prévisualisation sans écriture, rapport d'anomalies,
 * import tout-ou-rien, recontrôle à la validation, archive chiffrée des identifiants, volume de 500 agents.
 */
final class UserImportTest extends ApiTestCase
{
    private static string $adminToken;
    private static int $phone = 70000000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    // ------------------------------------------------------------------ Modèle

    public function testTemplateContainsInstructionsAndTheTerritory(): void
    {
        $r = $this->call('GET', '/imports/users/template', token: self::$adminToken);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('attachment; filename="modele-import-agents.xlsx"', $r->headers['Content-Disposition']);
        $book = IOFactory::load($r->filePath);
        $this->assertSame(['Agents', 'Listes', 'Notice', 'Territoire'], $book->getSheetNames());
        $this->assertSame('hidden', $book->getSheetByName('Listes')->getSheetState(), 'Les correspondances libellé → code sont en arrière-plan.');
        $agents = $book->getSheet(0);
        foreach (['D2' => 'Sexe', 'G2' => 'Rôle', 'H2' => 'Région', 'I2' => 'Département', 'J2' => 'Commune', 'K2' => 'Superviseur'] as $cell => $what) {
            $this->assertSame('list', $agents->getCell($cell)->getDataValidation()->getType(), "Liste déroulante attendue : $what");
        }
        $this->assertStringContainsString('INDIRECT', $agents->getCell('J9')->getDataValidation()->getFormula1(), 'Commune en cascade du département.');
        $this->assertSame(UserImporter::TEMPLATE_COLUMNS, $book->getSheet(0)->rangeToArray('A1:K1')[0]);
        $this->assertSame(7, $book->getSheetByName('Territoire')->getHighestDataRow(), 'En-tête + 6 communes du territoire de test');
        $book->disconnectWorksheets();

        $agent = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(403, $this->call('GET', '/imports/users/template', token: $agent)->status);
    }

    // ------------------------------------------------------------------ Import valide

    public function testValidFileIsPreviewedWithoutWritingThenImportedAtomically(): void
    {
        $p = $this->prefix();
        $P = strtoupper($p);
        $file = $this->xlsx([
            [$p . 'R1', 'Abdou', 'Ali', 'M', '', '', 'SUP_REG', 'Région test', '', '', ''],
            [$p . 'D1', 'Hama', 'Fati', 'F', $this->phone(), '', 'superviseur départemental', 'REGION TEST', 'departement test', '', $p . 'R1'],
            [$p . 'A1', 'Moussa', 'Ibrahim', 'M', (float) $this->phone(), $P . '@example.ne', 'AGENT', '1', '101', 'Commune test; COMMUNE TEST 2', $p . 'D1'],
            [$p . 'A2', 'Saley', 'Mariama', 'F', '+227 ' . $this->phone(), '', 'agent recenseur', 'REGION DEUX', 'Deux', '', ''],
            [$p . 'V1', 'Garba', 'Zara', 'F', '', '', 'VIEWER', 'national', '', '', ''],
        ]);
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM users');

        $preview = $this->upload($file);
        $this->assertSame(201, $preview->status, json_encode($preview->body));
        $d = $preview->body['data'];
        $this->assertSame('PREVISUALISE', $d['status']);
        $this->assertSame(5, $d['total_rows']);
        $this->assertSame(0, $d['error_rows'], json_encode($d['rows']));
        $this->assertTrue($d['can_commit']);
        $this->assertEqualsCanonicalizing(['SUP_REG' => 1, 'SUP_DEP' => 1, 'AGENT' => 2, 'VIEWER' => 1], $d['by_role']);
        $this->assertSame($before, (int) self::$db->fetchValue('SELECT COUNT(*) FROM users'), 'La prévisualisation ne crée aucun compte.');
        // « Deux » est reconnu pour le département « VILLE DE DEUX ».
        $rows = array_column($d['rows'], null, 'matricule');
        $this->assertSame('Département VILLE DE DEUX', $rows[$P . 'A2']['scope']);

        $commit = $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken);
        $this->assertSame(200, $commit->status, json_encode($commit->body));
        $this->assertSame(5, $commit->body['data']['created']);
        $this->assertSame('IMPORTE', $commit->body['data']['import']['status']);
        $this->assertSame($before + 5, (int) self::$db->fetchValue('SELECT COUNT(*) FROM users'));

        // Liens hiérarchiques résolus à l'intérieur du fichier ; périmètres et rôles corrects.
        $a1 = self::$db->fetchOne('SELECT u.*, s.matricule AS sup FROM users u LEFT JOIN users s ON s.id = u.supervisor_id WHERE u.matricule = ?', [$P . 'A1']);
        $this->assertSame($P . 'D1', $a1['sup']);
        $this->assertSame(1, (int) $a1['must_change_password']);
        $this->assertSame(2, (int) self::$db->fetchValue("SELECT COUNT(*) FROM user_scopes WHERE user_id = ? AND level = 'COMMUNE'", [$a1['id']]));
        $this->assertSame($P . 'R1', self::$db->fetchValue(
            'SELECT s.matricule FROM users u JOIN users s ON s.id = u.supervisor_id WHERE u.matricule = ?', [$P . 'D1']));

        // Archive des identifiants : chiffrée, et les mots de passe qu'elle contient fonctionnent.
        $credentials = $this->readCredentials($d['id'], $commit->body['data']['archive_password']);
        $this->assertCount(5, $credentials);
        $login = $this->login($P . 'A1', $credentials[$P . 'A1']);
        $this->assertSame(200, $login->status);
        $this->assertTrue($login->body['data']['must_change_password']);

        // Un import validé ne peut pas l'être une seconde fois.
        $this->assertSame(409, $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken)->status);
        $this->assertSame(1, (int) self::$db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'IMPORT_USERS' AND entity_id = ?", [$d['id']]));
    }

    /** Le modèle rempli avec les libellés de ses propres listes déroulantes s'importe sans retouche ; les codes sont enregistrés. */
    public function testTemplateFilledWithDropDownLabelsImportsWithCodes(): void
    {
        $r = $this->call('GET', '/imports/users/template', token: self::$adminToken);
        $book = IOFactory::load($r->filePath);
        $lists = $book->getSheetByName('Listes');
        $named = static fn(string $name) => array_column($lists->rangeToArray(
            str_replace('$', '', (string) preg_replace('/^.*!/', '', $book->getNamedRange($name)->getRange()))), 0);
        // Région de test → ses départements → communes du département de test, tels que proposés par les listes.
        $regionRows = $lists->rangeToArray('C2:D20');
        $regionLabel = null;
        $deptRange = null;
        foreach ($regionRows as [$label, $range]) {
            if ($label !== null && str_starts_with((string) $label, 'REGION TEST')) {
                [$regionLabel, $deptRange] = [$label, $range];
            }
        }
        $this->assertNotNull($regionLabel, 'La région de test figure dans la liste.');
        $this->assertSame('NATIONAL (lecteurs uniquement)', $regionRows[0][0], 'NATIONAL proposé à un administrateur.');
        $deptLabel = array_values(array_filter($named($deptRange), static fn($d) => str_contains((string) $d, 'DEPARTEMENT TEST')))[0];
        $communes = [];
        foreach ($book->getNamedRanges() as $nr) {
            if (str_starts_with($nr->getName(), 'L_DEP_')) {
                $values = $named($nr->getName());
                if (in_array('COMMUNE TEST', $values, true)) {
                    $communes = $values;
                }
            }
        }
        $this->assertContains('COMMUNE TEST', $communes, 'Communes du département proposées en cascade.');

        $p = $this->prefix();
        $P = strtoupper($p);
        $book->disconnectWorksheets(); // les listes sont lues : libérer le classeur
        $path = $this->xlsx([
            [$p . 'S1', 'Issa', 'Adamou', UserImporter::SEX_LABELS['M'], '', '', UserImporter::ROLE_LABELS['SUP_DEP'], $regionLabel, $deptLabel, '', ''],
            [$p . 'G1', 'Ali', 'Mariama', UserImporter::SEX_LABELS['F'], '', '', UserImporter::ROLE_LABELS['AGENT'], $regionLabel, $deptLabel, 'COMMUNE TEST', $p . 'S1'],
            [$p . 'L1', 'Sani', 'Hawa', UserImporter::SEX_LABELS['F'], '', '', UserImporter::ROLE_LABELS['VIEWER'], UserImporter::NATIONAL_LABEL, '', '', ''],
        ]);

        $d = $this->upload($path)->body['data'];
        $this->assertSame(0, $d['error_rows'], json_encode($d['rows'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(200, $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken)->status);
        $g1 = self::$db->fetchOne(
            "SELECT u.sex, r.code AS role, s.matricule AS sup FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
               LEFT JOIN users s ON s.id = u.supervisor_id WHERE u.matricule = ?", [$P . 'G1']);
        $this->assertSame(['sex' => 'F', 'role' => 'AGENT', 'sup' => $P . 'S1'], $g1, 'Codes enregistrés, pas les libellés.');
        $this->assertSame('COMMUNE', self::$db->fetchValue('SELECT xs.level FROM user_scopes xs JOIN users u ON u.id = xs.user_id WHERE u.matricule = ?', [$P . 'G1']));
        $this->assertSame('NATIONAL', self::$db->fetchValue('SELECT xs.level FROM user_scopes xs JOIN users u ON u.id = xs.user_id WHERE u.matricule = ?', [$P . 'L1']));
    }

    public function testLabelsAreDecodedToCodes(): void
    {
        $in = UserImporter::decodeLabels(['sex' => 'F - Féminin', 'role' => 'Superviseur régional (SUP_REG)', 'region' => 'NATIONAL (lecteurs uniquement)',
            'supervisor' => 'MAT-07 — Abdou Ali (SUP_REG)']);
        $this->assertSame(['F', 'SUP_REG', 'NATIONAL', 'MAT-07'], [$in['sex'], $in['role'], $in['region'], $in['supervisor']]);
        $old = UserImporter::decodeLabels(['sex' => 'm', 'role' => 'AGENT', 'region' => 'Dosso', 'supervisor' => 'MAT-01']);
        $this->assertSame(['M', 'AGENT', 'Dosso', 'MAT-01'], [$old['sex'], $old['role'], $old['region'], $old['supervisor']], 'Anciennes saisies acceptées.');
    }

    // ------------------------------------------------------------------ Anomalies

    public function testEveryAnomalyIsReportedAndNothingIsImported(): void
    {
        $p = $this->prefix();
        $existing = $this->createUser('AGENT');
        $outsideSup = $this->createUser('SUP_DEP', scopes: [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('202')]]);
        $file = $this->xlsx([
            [$p . 'OK', 'Bon', 'Agent', 'M', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],             // 2 : valide
            [$p . 'OK', 'Double', 'Matricule', 'F', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],       // 3 : doublon fichier
            [$existing, 'Deja', 'Pris', 'M', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],             // 4 : existe en base
            [$p . 'TEL', 'Mauvais', 'Tel', 'M', '12345', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],      // 5 : téléphone
            [$p . 'COM', 'Commune', 'Inconnue', 'F', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', 'TAHOUA', ''], // 6 : commune
            [$p . 'ADM', 'Admin', 'Interdit', 'M', '', '', 'ADMIN', 'REGION TEST', '', '', ''],                       // 7 : rôle
            [$p . 'SUP', 'Sup', 'Hors', 'M', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', $outsideSup],     // 8 : superviseur hors périmètre
            [$p . 'SRG', 'Sup', 'Reg', 'M', '', '', 'SUP_REG', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],             // 9 : SUP_REG avec département
            [$p . 'SX', 'Sexe', '', 'X', '', '', 'AGENT', 'REGION FANTOME', '', '', ''],                              // 10 : prénom, sexe, région
        ]);
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM users');
        $d = $this->upload($file)->body['data'];
        $this->assertSame(9, $d['total_rows']);
        $this->assertSame(8, $d['error_rows'], json_encode($d['rows']));
        $this->assertFalse($d['can_commit']);

        $byLine = array_column($d['rows'], 'errors', 'line');
        $this->assertSame([], $byLine[2]);
        $expect = [3 => 'en double dans le fichier (ligne 2)', 4 => 'déjà attribué', 5 => 'Téléphone invalide', 6 => 'Commune « TAHOUA » inconnue',
            7 => 'administrateurs ne peuvent pas', 8 => 'ne couvre pas', 9 => 'laisser Département et Commune vides', 10 => 'Région « REGION FANTOME » inconnue'];
        foreach ($expect as $line => $needle) {
            $this->assertStringContainsString($needle, implode(' | ', $byLine[$line]), "Ligne $line");
        }
        $this->assertGreaterThanOrEqual(3, count($byLine[10]), "Toutes les anomalies d'une ligne sont signalées.");

        // Rapport ligne par ligne téléchargeable.
        $report = $this->call('GET', '/imports/' . $d['id'] . '/report', token: self::$adminToken);
        $this->assertSame(200, $report->status);
        $sheet = IOFactory::load($report->filePath)->getSheet(0)->toArray();
        $this->assertSame('Résultat', $sheet[0][6]);
        $this->assertCount(8, array_filter($sheet, static fn($r) => $r[6] === 'ERREUR'));

        // Validation refusée : tout-ou-rien, aucun compte créé (pas même la ligne valide).
        $commit = $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken);
        $this->assertSame(422, $commit->status);
        $this->assertSame('IMPORT_REJECTED', $commit->body['error']['code']);
        $this->assertNotEmpty($commit->body['error']['details']['errors']);
        $this->assertSame($before, (int) self::$db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testMissingRequiredColumnsBlockTheFile(): void
    {
        $d = $this->upload($this->xlsx([['X1', 'Nom', 'Prénom']], ['Matricule', 'Nom', 'Prénom']))->body['data'];
        $this->assertFalse($d['can_commit']);
        $this->assertCount(3, $d['fatal_errors']); // Sexe, Rôle, Région
        $this->assertStringContainsString('Sexe', $d['fatal_errors'][0]);
    }

    public function testFileIsRecheckedAtCommitTime(): void
    {
        $p = $this->prefix();
        $d = $this->upload($this->xlsx([
            [$p . 'X1', 'Premier', 'Agent', 'M', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],
            [$p . 'X2', 'Second', 'Agent', 'F', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', ''],
        ]))->body['data'];
        $this->assertTrue($d['can_commit']);

        // Entre la prévisualisation et la validation, quelqu'un crée un compte avec le même matricule.
        $created = $this->call('POST', '/users', ['matricule' => $p . 'X2', 'last_name' => 'Concurrent', 'first_name' => 'Compte', 'sex' => 'M',
            'role' => 'AGENT', 'scopes' => [['level' => 'DEPARTMENT', 'department_id' => self::departmentId('101')]]], self::$adminToken);
        $this->assertSame(201, $created->status);

        $commit = $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken);
        $this->assertSame(422, $commit->status);
        $this->assertStringContainsString('Ligne 3', $commit->body['error']['details']['errors'][0]);
        $this->assertNull(self::$db->fetchValue('SELECT id FROM users WHERE matricule = ?', [strtoupper($p) . 'X1']), "Aucune ligne n'est importée.");
        $this->assertSame(1, $this->call('GET', '/imports/' . $d['id'], token: self::$adminToken)->body['data']['error_rows']);
    }

    public function testNonExcelFilesAreRefused(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, "matricule;nom\nA;B\n");
        $r = $this->call('POST', '/imports/users', token: self::$adminToken, files: ['file' => new UploadedFile('agents.xlsx', $tmp, (int) filesize($tmp))]);
        $this->assertSame(422, $r->status);
        $this->assertSame('UNSUPPORTED_FILE', $r->body['error']['code']);
        $r = $this->call('POST', '/imports/users', token: self::$adminToken, files: ['file' => new UploadedFile('agents.csv', $tmp, (int) filesize($tmp))]);
        $this->assertSame('UNSUPPORTED_FILE', $r->body['error']['code']);
        $this->assertSame(422, $this->call('POST', '/imports/users', token: self::$adminToken)->status);
        @unlink($tmp);
    }

    public function testCancelledImportCannotBeCommitted(): void
    {
        $p = $this->prefix();
        $d = $this->upload($this->xlsx([[$p . 'C1', 'Annule', 'Agent', 'M', '', '', 'AGENT', 'REGION TEST', 'DEPARTEMENT TEST', '', '']]))->body['data'];
        $this->assertSame('ANNULE', $this->call('POST', '/imports/' . $d['id'] . '/cancel', token: self::$adminToken)->body['data']['status']);
        $this->assertSame(409, $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken)->status);
        $this->assertSame(404, $this->call('GET', '/imports/00000000-0000-4000-8000-000000000000', token: self::$adminToken)->status);
    }

    // ------------------------------------------------------------------ Volume

    public function testFiveHundredAgentsAreImportedInOneTransaction(): void
    {
        $p = $this->prefix();
        $rows = [
            [$p . 'REG1', 'Superviseur', 'Région un', 'M', $this->phone(), '', 'SUP_REG', 'REGION TEST', '', '', ''],
            [$p . 'REG2', 'Superviseur', 'Région deux', 'F', $this->phone(), '', 'SUP_REG', 'REGION DEUX', '', '', ''],
        ];
        $depts = [['101', 'REGION TEST', 'REG1', ['COMMUNE TEST', 'COMMUNE TEST 2']], ['102', 'REGION TEST', 'REG1', ['COMMUNE TEST 3']],
            ['201', 'REGION DEUX', 'REG2', ['COMMUNE TEST 4']], ['202', 'REGION DEUX', 'REG2', ['COMMUNE TEST 5', 'COMMUNE TEST 6']]];
        foreach ($depts as [$code, $region, $sup]) {
            $rows[] = [$p . 'DEP' . $code, 'Superviseur', 'Département ' . $code, 'M', $this->phone(), '', 'SUP_DEP', $region, $code, '', $p . $sup];
        }
        for ($i = 1; count($rows) < 500; $i++) {
            [$code, $region, , $communes] = $depts[$i % 4];
            $rows[] = [sprintf('%sAG%04d', $p, $i), 'Agent' . $i, 'Fictif', $i % 2 ? 'M' : 'F', $this->phone(), "agent$i.$p@example.ne",
                'AGENT', $region, $code, $communes[$i % count($communes)], $p . 'DEP' . $code];
        }
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM users');

        $t0 = microtime(true);
        $d = $this->upload($this->xlsx($rows))->body['data'];
        $previewSeconds = microtime(true) - $t0;
        $this->assertSame(500, $d['total_rows']);
        $this->assertSame(0, $d['error_rows'], json_encode(array_slice($d['rows'], 0, 3)));

        $t1 = microtime(true);
        $commit = $this->call('POST', '/imports/' . $d['id'] . '/commit', token: self::$adminToken);
        $commitSeconds = microtime(true) - $t1;
        $this->assertSame(200, $commit->status, json_encode($commit->body));
        $this->assertSame(500, $commit->body['data']['created']);
        $this->assertSame($before + 500, (int) self::$db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame(494, (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM users u JOIN users s ON s.id = u.supervisor_id WHERE u.matricule LIKE ? AND s.matricule LIKE ?',
            [strtoupper($p) . 'AG%', strtoupper($p) . 'DEP%']));
        $this->assertCount(500, $this->readCredentials($d['id'], $commit->body['data']['archive_password']));

        fwrite(STDERR, sprintf("\n[Volume] 500 agents : prévisualisation %.1f s, validation %.1f s\n", $previewSeconds, $commitSeconds));
        $this->assertLessThan(60, $previewSeconds + $commitSeconds, 'Import de 500 agents trop lent pour une requête HTTP.');
    }

    // ------------------------------------------------------------------ Utilitaires

    private function prefix(): string
    {
        return 'i' . bin2hex(random_bytes(2)) . '-';
    }

    private function phone(): string
    {
        return (string) (++self::$phone);
    }

    /** Classeur .xlsx temporaire avec la feuille « Agents ». */
    private function xlsx(array $rows, ?array $header = null): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Agents');
        $sheet->fromArray($header ?? UserImporter::TEMPLATE_COLUMNS, null, 'A1');
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path): Response
    {
        return $this->call('POST', '/imports/users', token: self::$adminToken,
            files: ['file' => new UploadedFile('agents.xlsx', $path, (int) filesize($path))]);
    }

    /** @return array<string,string> matricule → mot de passe provisoire, lus dans l'archive chiffrée */
    private function readCredentials(string $importId, string $archivePassword): array
    {
        $r = $this->call('GET', '/imports/' . $importId . '/report', token: self::$adminToken);
        $this->assertSame(200, $r->status);
        $this->assertSame('application/zip', $r->headers['Content-Type']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($r->filePath));
        $this->assertFalse(@$zip->getFromName('identifiants-agents.xlsx'), 'Illisible sans le mot de passe.');
        $zip->setPassword($archivePassword);
        $content = $zip->getFromName('identifiants-agents.xlsx');
        $zip->close();
        $this->assertIsString($content);
        $tmp = tempnam(sys_get_temp_dir(), 'cred') . '.xlsx';
        file_put_contents($tmp, $content);
        $out = [];
        foreach (IOFactory::load($tmp)->getSheet(0)->toArray() as $i => $row) {
            if ($i > 0 && ($row[7] ?? null) !== null && $row[7] !== '') {
                $out[(string) $row[0]] = (string) $row[7];
            }
        }
        @unlink($tmp);
        return $out;
    }
}
