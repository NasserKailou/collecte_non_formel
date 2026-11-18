<?php

declare(strict_types=1);

namespace App\Modules\Territory;

use App\Core\Database;
use App\Core\ImportException;
use App\Core\Text;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import du référentiel administratif officiel (format RENACOM fourni par le client) :
 * une feuille, colonnes A=niveau (region|departement|communes), B=code, C=libellé,
 * E=code région parente (départements), F=code département parent (communes).
 *
 * L'import est tout-ou-rien : le fichier est entièrement contrôlé avant toute écriture,
 * puis appliqué dans une transaction (création ou mise à jour par code, sans suppression).
 */
final class TerritoryImporter
{
    /** Effectifs attendus (contrôle de cohérence, configurable). */
    public function __construct(
        private readonly Database $db,
        private readonly array $expected = ['regions' => 8, 'departments' => 67, 'communes' => 266],
    ) {
    }

    /**
     * @return array{regions:int, departments:int, communes:int, created:int, updated:int}
     * @throws ImportException si le fichier est incohérent (aucune écriture n'a lieu)
     */
    public function importFile(string $path): array
    {
        $sheet = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path)->getSheet(0);
        $rows = [];
        foreach ($sheet->toArray(null, false, false, false) as $r) {
            if (array_filter($r, static fn($v) => $v !== null && $v !== '') !== []) {
                $rows[] = $r;
            }
        }
        return $this->importRows($rows);
    }

    /** @param list<array> $rows lignes brutes du fichier */
    public function importRows(array $rows): array
    {
        $regions = $departments = $communes = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            $line = $i + 1;
            $level = strtolower(trim((string) ($r[0] ?? '')));
            $code = trim((string) ($r[1] ?? ''));
            $name = Text::clean((string) ($r[2] ?? ''));
            if ($code === '' || $name === '') {
                $errors[] = "Ligne $line : code ou libellé manquant.";
                continue;
            }
            switch ($level) {
                case 'region':
                    $this->put($regions, $code, ['name' => $name], $line, $errors);
                    break;
                case 'departement':
                    $this->put($departments, $code, ['name' => $name, 'region' => trim((string) ($r[4] ?? ''))], $line, $errors);
                    break;
                case 'communes':
                case 'commune':
                    $this->put($communes, $code, ['name' => $name, 'department' => trim((string) ($r[5] ?? ''))], $line, $errors);
                    break;
                default:
                    $errors[] = "Ligne $line : niveau inconnu « $level ».";
            }
        }
        foreach ($departments as $code => $d) {
            if (!isset($regions[$d['region']])) {
                $errors[] = "Département $code ({$d['name']}) : région parente « {$d['region']} » introuvable.";
            }
        }
        foreach ($communes as $code => $c) {
            if (!isset($departments[$c['department']])) {
                $errors[] = "Commune $code ({$c['name']}) : département parent « {$c['department']} » introuvable.";
            }
        }
        foreach (['regions' => $regions, 'departments' => $departments, 'communes' => $communes] as $k => $set) {
            if (isset($this->expected[$k]) && count($set) !== $this->expected[$k]) {
                $errors[] = sprintf('Effectif inattendu pour %s : %d au lieu de %d.', $k, count($set), $this->expected[$k]);
            }
        }
        if ($errors !== []) {
            throw new ImportException('Référentiel territorial rejeté : ' . count($errors) . ' anomalie(s).', $errors);
        }

        return $this->db->transaction(function (Database $db) use ($regions, $departments, $communes): array {
            $created = $updated = 0;
            $count = static function (int $affected) use (&$created, &$updated): void {
                // ON DUPLICATE KEY UPDATE : 1 = insertion, 2 = mise à jour, 0 = inchangé
                if ($affected === 1) { $created++; } elseif ($affected === 2) { $updated++; }
            };
            foreach ($regions as $code => $r) {
                $count($db->execute('INSERT INTO regions (code_renacom, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [(string) $code, $r['name']]));
            }
            $regionIds = array_column($db->fetchAll('SELECT id, code_renacom FROM regions'), 'id', 'code_renacom');
            foreach ($departments as $code => $d) {
                $isCity = str_starts_with(Text::normalize($d['name']), 'VILLE DE ') ? 1 : 0;
                $count($db->execute(
                    'INSERT INTO departments (region_id, code_renacom, name, is_city) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE region_id = VALUES(region_id), name = VALUES(name), is_city = VALUES(is_city)',
                    [$regionIds[$d['region']], (string) $code, $d['name'], $isCity]
                ));
            }
            $deptIds = array_column($db->fetchAll('SELECT id, code_renacom FROM departments'), 'id', 'code_renacom');
            foreach ($communes as $code => $c) {
                $count($db->execute(
                    'INSERT INTO communes (department_id, code_renacom, name) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE department_id = VALUES(department_id), name = VALUES(name)',
                    [$deptIds[$c['department']], (string) $code, $c['name']]
                ));
            }
            return ['regions' => count($regions), 'departments' => count($departments), 'communes' => count($communes),
                'created' => $created, 'updated' => $updated];
        });
    }

    private function put(array &$set, string $code, array $value, int $line, array &$errors): void
    {
        if (isset($set[$code])) {
            $errors[] = "Ligne $line : code « $code » en double.";
            return;
        }
        $set[$code] = $value;
    }
}
