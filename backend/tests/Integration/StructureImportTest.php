<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Response;
use App\Http\UploadedFile;
use App\Modules\Structures\StructureImporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Import Excel des centres connus : modèle à listes déroulantes, contrôle sans écriture (règles de la saisie
 * manuelle, doublons dans le fichier et avec le registre, périmètre), création tout-ou-rien, recontrôle.
 */
final class StructureImportTest extends ApiTestCase
{
    private static string $adminToken;
    private static int $code = 1010000000;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $t = new self('init');
        self::$adminToken = $t->tokenFor($t->createUser('ADMIN'));
    }

    public function testTemplateHasDropDownListsInCascade(): void
    {
        $r = $this->call('GET', '/imports/structures/template', token: self::$adminToken);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('modele-import-centres.xlsx', $r->headers['Content-Disposition']);
        $book = IOFactory::load($r->filePath);
        $this->assertSame(['Centres', 'Listes', 'Notice', 'Territoire'], $book->getSheetNames());
        $this->assertSame('hidden', $book->getSheetByName('Listes')->getSheetState());
        $sheet = $book->getSheet(0);
        $this->assertSame(StructureImporter::TEMPLATE_COLUMNS, $sheet->rangeToArray('A1:O1')[0]);
        foreach (['C2' => 'Type', 'E2' => 'Statut', 'F2' => 'Zone', 'G2' => 'Région', 'H2' => 'Département', 'I2' => 'Commune', 'O2' => 'Doublon vérifié'] as $cell => $what) {
            $this->assertSame('list', $sheet->getCell($cell)->getDataValidation()->getType(), "Liste déroulante attendue : $what");
        }
        $this->assertStringContainsString('INDIRECT', $sheet->getCell('I7')->getDataValidation()->getFormula1(), 'Commune en cascade du département');
        $this->assertSame('c - Atelier', $book->getSheetByName('Listes')->getCell('A4')->getValue());
        $book->disconnectWorksheets();

        $agent = $this->tokenFor($this->createUser('AGENT'));
        $this->assertSame(403, $this->call('GET', '/imports/structures/template', token: $agent)->status);
    }

    public function testValidFileIsCheckedWithoutWritingThenImportedAtomically(): void
    {
        $p = 'Imp' . bin2hex(random_bytes(3));
        $code = $this->code();
        $file = $this->xlsx([
            [$code, "Atelier $p couture", 'c - Atelier', '', 'Privé', 'Urbaine', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', 'Talladjé', 13.51, 2.11, 44927, '15/03/2023', ''],
            ['', "Coopérative $p tissage", 'h', '', 'public', 'rurale', '1', '101', '10102', '', '', '', '', '', ''],
            ['', "Centre $p divers", 'k - Autre structure', 'Ferme-école', '', '', 'Région deux', 'Deux', 'COMMUNE TEST 4', '', '13,6', '2,2', '', '', ''],
        ]);
        $before = (int) self::$db->fetchValue('SELECT COUNT(*) FROM structures');

        $preview = $this->upload($file);
        $this->assertSame(201, $preview->status, json_encode($preview->body, JSON_UNESCAPED_UNICODE));
        $d = $preview->body['data'];
        $this->assertSame(0, $d['error_rows'], json_encode($d['rows'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($d['can_commit']);
        $this->assertEquals(['ACTIVE' => 1, 'PROPOSEE' => 2], $d['by_status'], 'Avec code : actif ; sans code : proposé');
        $this->assertSame($before, (int) self::$db->fetchValue('SELECT COUNT(*) FROM structures'), 'Le contrôle ne crée rien.');

        $commit = $this->call('POST', "/imports/{$d['id']}/commit", token: self::$adminToken);
        $this->assertSame(200, $commit->status, json_encode($commit->body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $commit->body['data']['created']);
        $s = self::$db->fetchOne('SELECT s.*, t.code AS type_code FROM structures s JOIN structure_types t ON t.id = s.structure_type_id WHERE official_code = ?', [$code]);
        $this->assertSame(['ACTIVE', 'c', 'PRIVE', 'URBAINE', '2023-01-01', '2023-03-15'],
            [$s['status'], $s['type_code'], $s['legal_status'], $s['zone'], $s['authorization_date'], $s['opening_date']], 'Date Excel et JJ/MM/AAAA');
        $other = self::$db->fetchOne('SELECT status, other_type_label, latitude FROM structures WHERE name = ?', ["Centre $p divers"]);
        $this->assertSame(['PROPOSEE', 'Ferme-école'], [$other['status'], $other['other_type_label']]);
        $this->assertEqualsWithDelta(13.6, (float) $other['latitude'], 0.0001, 'Virgule décimale acceptée');
        $this->assertSame('PROPOSITION', self::$db->fetchValue('SELECT h.action FROM structure_history h JOIN structures s ON s.id = h.structure_id WHERE s.name = ?', ["Centre $p divers"]));

        $this->assertSame(409, $this->call('POST', "/imports/{$d['id']}/commit", token: self::$adminToken)->status, 'Un import ne se valide qu\'une fois');
    }

    public function testErrorsDuplicatesAndScopeBlockTheWholeFile(): void
    {
        $p = 'Dbl' . bin2hex(random_bytes(3));
        $code = $this->code();
        $this->assertSame(201, $this->call('POST', '/structures', ['official_code' => $code, 'name' => "Garage $p mécanique", 'type_code' => 'g',
            'commune_id' => self::communeId('10101')], self::$adminToken)->status);

        $file = $this->xlsx([
            [$code, 'Autre nom', 'c', '', '', '', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', '', '', '', '', '', ''],          // code déjà pris
            ['', "Garage $p mécanique", 'g', '', '', '', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', '', '', '', '', '', ''],   // doublon du registre
            ['', "Garage $p mécanique", 'g', '', '', '', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', '', '', '', '', '', 'Oui'], // vérifié… mais doublon de la ligne 3
            ['', "Atelier $p", 'zz', '', 'Mixte', '', 'REGION TEST', 'DEPARTEMENT B', 'COMMUNE TEST', '', '', '', '', '', ''],         // type, statut, commune
            ['', "Foyer $p", 'e', '', '', '', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', '', '40', '2', '32/13/2020', '', ''], // GPS, date
        ]);
        $d = $this->upload($file)->body['data'];
        $this->assertFalse($d['can_commit']);
        $this->assertSame(5, $d['error_rows'], json_encode($d['rows'], JSON_UNESCAPED_UNICODE));
        $errors = array_column($d['rows'], 'errors', 'line');
        $this->assertStringContainsString('déjà attribué', implode(' ', $errors[2]));
        $this->assertStringContainsString('Doublon probable', implode(' ', $errors[3]));
        $this->assertStringContainsString('ligne 3', implode(' ', $errors[4]), 'Doublon interne au fichier');
        $this->assertCount(3, $errors[5], 'Type inconnu, statut invalide, commune hors du département');
        $this->assertStringContainsString('territoire nigérien', implode(' ', $errors[6]));
        $this->assertStringContainsString('illisible', implode(' ', $errors[6]));
        $this->assertSame(422, $this->call('POST', "/imports/{$d['id']}/commit", token: self::$adminToken)->status);

        // Doublon vérifié (« Oui ») : accepté.
        $ok = $this->upload($this->xlsx([['', "Garage $p mécanique", 'g', '', '', '', 'REGION TEST', 'DEPARTEMENT TEST', 'COMMUNE TEST', '', '', '', '', '', 'Oui']]))->body['data'];
        $this->assertTrue($ok['can_commit'], json_encode($ok['rows'], JSON_UNESCAPED_UNICODE));

        // Superviseur départemental : seulement les communes de son département ; il ne voit pas l'import d'un autre.
        $sup = $this->tokenFor($this->createUser('SUP_DEP'));
        $out = $this->call('POST', '/imports/structures', token: $sup, files: ['file' => new UploadedFile('c.xlsx',
            $this->xlsx([['', "Hors zone $p", 'c', '', '', '', 'REGION DEUX', 'DEPARTEMENT D', 'COMMUNE TEST 5', '', '', '', '', '', '']]), 1000)])->body['data'];
        $this->assertStringContainsString('hors de votre périmètre', implode(' ', $out['rows'][0]['errors']));
        $this->assertSame(404, $this->call('GET', "/imports/{$ok['id']}", token: $sup)->status);
        $this->assertSame(404, $this->call('GET', "/imports/{$out['id']}", token: $this->tokenFor($this->createUser('AGENT')))->status);
    }

    public function testMissingColumnIsFatal(): void
    {
        $d = $this->upload($this->xlsx([['X', 'c']], ['Nom du centre', 'Type']))->body['data'];
        $this->assertFalse($d['can_commit']);
        $this->assertStringContainsString('Région', implode(' ', $d['fatal_errors']));
    }

    private function code(): string
    {
        return (string) (++self::$code + random_int(0, 999) * 10000);
    }

    private function xlsx(array $rows, ?array $header = null): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Centres');
        $sheet->fromArray($header ?? StructureImporter::TEMPLATE_COLUMNS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $path;
    }

    private function upload(string $path): Response
    {
        return $this->call('POST', '/imports/structures', token: self::$adminToken,
            files: ['file' => new UploadedFile('centres.xlsx', $path, (int) filesize($path))]);
    }
}
