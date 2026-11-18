<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Écriture d'un classeur .xlsx en flux (mémoire constante, quel que soit le nombre de lignes).
 * PhpSpreadsheet charge tout le classeur en mémoire (≈ 1 Ko par cellule) : inutilisable pour les exports
 * de microdonnées nationaux. Ici, chaque feuille est écrite ligne par ligne dans un fichier temporaire,
 * puis assemblée dans l'archive OOXML. Textes en chaînes en ligne (jamais interprétés comme formules),
 * en-têtes en gras, première ligne figée, filtre automatique.
 */
final class XlsxStreamWriter
{
    /** @var list<array{name:string, path:string, handle:resource, rows:int, cols:int}> */
    private array $sheets = [];
    private int $current = -1;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param string|null $table nom de « tableau Excel » (lettres, chiffres, _) : la feuille devient un tableau structuré
     *                           filtrable, source directe d'un tableau croisé dynamique (Insertion › TCD). En-têtes uniques exigés.
     * @param int|null $width largeur uniforme des colonnes (caractères), facultative
     */
    public function addSheet(string $name, array $header, ?string $table = null, ?int $width = null): void
    {
        $name = mb_substr(trim((string) preg_replace('#[\\\\/?*\[\]:]#', ' ', $name)), 0, 31);
        if ($table !== null) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,200}$/', $table) !== 1) {
                throw new \InvalidArgumentException("Nom de tableau Excel invalide : $table");
            }
            $seen = [];
            foreach ($header as $hd) {
                $k = mb_strtolower((string) $hd);
                if ($k === '' || isset($seen[$k])) {
                    throw new \InvalidArgumentException("En-tête vide ou en double dans le tableau $table : « $hd »");
                }
                $seen[$k] = true;
            }
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $h = fopen($tmp, 'wb');
        fwrite($h, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($width !== null && count($header) > 0 ? '<cols><col min="1" max="' . count($header) . '" width="' . max(6, min(80, $width)) . '" customWidth="1"/></cols>' : '')
            . '<sheetData>');
        $this->sheets[] = ['name' => $name, 'path' => $tmp, 'handle' => $h, 'rows' => 0, 'cols' => count($header),
            'table' => $table, 'header' => array_map('strval', array_values($header))];
        $this->current = count($this->sheets) - 1;
        $this->addRow($header, true);
    }

    public function addRow(array $values, bool $bold = false): void
    {
        $s = &$this->sheets[$this->current];
        $r = ++$s['rows'];
        $xml = "<row r=\"$r\">";
        $style = $bold ? ' s="1"' : '';
        foreach (array_values($values) as $c => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $ref = self::column($c) . $r;
            if (is_bool($v)) {
                $xml .= "<c r=\"$ref\" t=\"b\"$style><v>" . ($v ? 1 : 0) . '</v></c>';
            } elseif ((is_int($v) || is_float($v)) && is_finite((float) $v)) {
                $xml .= "<c r=\"$ref\"$style><v>$v</v></c>";
            } else {
                $text = htmlspecialchars(self::clean((string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "<c r=\"$ref\" t=\"inlineStr\"$style><is><t xml:space=\"preserve\">$text</t></is></c>";
            }
        }
        fwrite($s['handle'], $xml . '</row>');
        $s['cols'] = max($s['cols'], count($values));
    }

    public function close(): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Impossible de créer le classeur {$this->path}");
        }
        $sheetsXml = $rels = $types = '';
        $tableNo = 0;
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $lastCol = self::column(max(0, $s['cols'] - 1));
            $ref = "A1:$lastCol{$s['rows']}";
            $asTable = $s['table'] !== null && $s['rows'] > 1;
            if ($asTable) {
                // Tableau structuré : son propre filtre (pas de filtre de feuille, incompatible).
                $tableNo++;
                fwrite($s['handle'], '</sheetData><tableParts count="1"><tablePart r:id="rId1"/></tableParts></worksheet>');
                $cols = '';
                foreach (array_slice($s['header'], 0, $s['cols']) as $c => $name) {
                    $cols .= '<tableColumn id="' . ($c + 1) . '" name="' . htmlspecialchars(self::clean($name), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';
                }
                $zip->addFromString("xl/tables/table$tableNo.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . "<table xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" id=\"$tableNo\" name=\"{$s['table']}\" displayName=\"{$s['table']}\" ref=\"$ref\" headerRowCount=\"1\">"
                    . "<autoFilter ref=\"$ref\"/><tableColumns count=\"{$s['cols']}\">$cols</tableColumns>"
                    . '<tableStyleInfo name="TableStyleMedium7" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>');
                $zip->addFromString("xl/worksheets/_rels/sheet$n.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . "<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/table\" Target=\"../tables/table$tableNo.xml\"/></Relationships>");
                $types .= "<Override PartName=\"/xl/tables/table$tableNo.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml\"/>";
            } else {
                fwrite($s['handle'], '</sheetData>' . ($s['rows'] > 1 ? "<autoFilter ref=\"$ref\"/>" : '') . '</worksheet>');
            }
            fclose($s['handle']);
            $zip->addFile($s['path'], "xl/worksheets/sheet$n.xml");
            $sheetsXml .= '<sheet name="' . htmlspecialchars($s['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
            $rels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
            $types .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $styleRel = count($this->sheets) + 1;
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>$sheetsXml</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . "<Relationship Id=\"rId$styleRel\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>");
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        if (!$zip->close()) {
            throw new \RuntimeException("Impossible d'écrire le classeur {$this->path}");
        }
        foreach ($this->sheets as $s) {
            @unlink($s['path']);
        }
    }

    /** Lettre de colonne Excel (0 → A, 26 → AA). */
    public static function column(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** Supprime les caractères de contrôle interdits en XML. */
    private static function clean(string $v): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    }
}
