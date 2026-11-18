<?php

declare(strict_types=1);

namespace App\Modules\Referentials;

use App\Core\Database;
use App\Core\ImportException;
use App\Core\Text;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import de la nomenclature des secteurs et métiers (fichier listes_metiers.xlsx du client).
 *
 * Traitements (docs/phase1/04_risques_planning.md §2.2) :
 *  - doublons (même libellé normalisé dans le même secteur) : importés comme alias inactifs du premier ;
 *  - métiers sans secteur : rattachés selon $proposedSectors (rattachement PROVISOIRE, à valider par la DSD) ;
 *  - métier « autre » (code $otherCode) : marqué is_other, inactif (le questionnaire propose « AUTRE (préciser) »).
 * Idempotent : mise à jour par code source, aucune suppression.
 */
final class TradeImporter
{
    public const SECTOR_CODES = [1 => 'PRIMAIRE', 2 => 'SECONDAIRE', 3 => 'TERTIAIRE'];

    /** Rattachement proposé des métiers sans secteur (code métier => secteur). */
    public const PROPOSED_SECTORS = [56 => 3, 57 => 2, 59 => 2, 60 => 2, 61 => 2, 62 => 3, 63 => 3, 64 => 3, 65 => 3, 66 => 2, 67 => 2, 68 => 2];

    public function __construct(private readonly Database $db, private readonly int $otherCode = 58)
    {
    }

    /** @return array{sectors:int, active:int, aliases:int, provisional:list<string>, other:int} */
    public function importFile(string $path, array $proposedSectors = self::PROPOSED_SECTORS): array
    {
        $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        $sectorSheet = $book->getSheetByName('secteur');
        $tradeSheet = $book->getSheetByName('metier');
        if ($sectorSheet === null || $tradeSheet === null) {
            throw new ImportException('Feuilles « secteur » et « metier » attendues.', []);
        }
        $sectors = array_slice($sectorSheet->toArray(null, false, false, false), 1);
        $trades = array_slice($tradeSheet->toArray(null, false, false, false), 1);
        return $this->importRows($sectors, $trades, $proposedSectors);
    }

    public function importRows(array $sectorRows, array $tradeRows, array $proposedSectors = self::PROPOSED_SECTORS): array
    {
        $errors = [];
        $sectors = [];
        foreach ($sectorRows as $r) {
            if (($r[0] ?? null) === null) { continue; }
            $id = (int) $r[0];
            if (!isset(self::SECTOR_CODES[$id])) {
                $errors[] = "Secteur inconnu : « {$r[0]} ».";
                continue;
            }
            $sectors[$id] = ['label' => Text::clean((string) $r[1]), 'sort' => (int) ($r[2] ?? $id)];
        }
        if (count($sectors) !== 3) {
            $errors[] = 'Trois secteurs attendus (primaire, secondaire, tertiaire).';
        }

        $trades = [];
        foreach ($tradeRows as $i => $r) {
            if (($r[0] ?? null) === null || $r[0] === '') { continue; }
            $code = (int) $r[0];
            $label = Text::clean((string) ($r[1] ?? ''));
            $rawSector = $r[3] ?? null;
            $sector = ($rawSector === null || $rawSector === '' || strtoupper(trim((string) $rawSector)) === 'NULL') ? null : (int) $rawSector;
            if ($label === '') {
                $errors[] = 'Ligne ' . ($i + 2) . ' : libellé manquant.';
                continue;
            }
            if (isset($trades[$code])) {
                $errors[] = "Code métier $code en double.";
                continue;
            }
            if ($sector !== null && !isset(self::SECTOR_CODES[$sector])) {
                $errors[] = "Métier $code : secteur « $rawSector » inconnu.";
                continue;
            }
            $trades[$code] = ['label' => $label, 'sort' => (int) ($r[2] ?? $code), 'sector' => $sector];
        }
        if ($errors !== []) {
            throw new ImportException('Nomenclature des métiers rejetée : ' . count($errors) . ' anomalie(s).', $errors);
        }

        // Rattachements proposés et métier « autre »
        $provisional = [];
        foreach ($trades as $code => &$t) {
            if ($code === $this->otherCode) {
                $t['sector'] = null;
                continue;
            }
            if ($t['sector'] === null && isset($proposedSectors[$code])) {
                $t['sector'] = $proposedSectors[$code];
                $provisional[] = "$code {$t['label']} → " . self::SECTOR_CODES[$t['sector']];
            }
        }
        unset($t);

        return $this->db->transaction(function (Database $db) use ($sectors, $trades, $provisional): array {
            foreach ($sectors as $id => $s) {
                $db->execute('INSERT INTO sectors (id, code, label, sort) VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE label = VALUES(label), sort = VALUES(sort)',
                    [$id, self::SECTOR_CODES[$id], $s['label'], $s['sort']]);
            }

            ksort($trades);
            $canonical = []; // "secteur|libellé normalisé" => id
            $active = $aliases = $other = 0;
            foreach ($trades as $code => $t) {
                $isOther = $code === $this->otherCode;
                $key = ($t['sector'] ?? 0) . '|' . Text::normalize($t['label']);
                $aliasOf = (!$isOther && isset($canonical[$key])) ? $canonical[$key] : null;
                $isActive = !$isOther && $aliasOf === null && $t['sector'] !== null;

                $existing = $db->fetchValue('SELECT id FROM trades WHERE source_code = ?', [$code]);
                $params = [$t['sector'], $t['label'], $isOther ? 1 : 0, $aliasOf, $t['sort'], $isActive ? 1 : 0];
                if ($existing === null) {
                    $id = $db->insert('INSERT INTO trades (sector_id, label, is_other, alias_of_id, sort, active, source_code) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [...$params, $code]);
                } else {
                    $id = (int) $existing;
                    $db->execute('UPDATE trades SET sector_id = ?, label = ?, is_other = ?, alias_of_id = ?, sort = ?, active = ? WHERE id = ?',
                        [...$params, $id]);
                }
                if ($isOther) { $other++; } elseif ($aliasOf !== null) { $aliases++; } else { $canonical[$key] = $id; }
                if ($isActive) { $active++; }
            }
            return ['sectors' => count($sectors), 'active' => $active, 'aliases' => $aliases, 'provisional' => $provisional, 'other' => $other];
        });
    }
}
