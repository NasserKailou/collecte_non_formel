<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Core\Text;
use App\Modules\Users\Scope;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Outils communs aux imports Excel en deux temps (agents, centres) : territoire RENACOM (arbre pour les listes
 * déroulantes en cascade, index de recherche par nom ou code), listes déroulantes, fichiers stockés hors racine web.
 * La classe utilisatrice fournit $this->db (Database) et $this->storageDir (string).
 */
trait ExcelImportSupport
{
    /**
     * Territoire en arbre régions → départements → communes, limité au périmètre s'il est fourni.
     * @return array<int, array{name:string, code:string, deps:array<int, array{name:string, code:string, communes:array<int, array{name:string, code:string}>}>}>
     */
    private function territoryTree(?Scope $scope): array
    {
        $tree = [];
        foreach ($this->db->fetchAll(
            'SELECT r.id AS rid, r.name AS rn, r.code_renacom AS rc, d.id AS did, d.name AS dn, d.code_renacom AS dc,
                    c.id AS cid, c.name AS cn, c.code_renacom AS cc
               FROM communes c JOIN departments d ON d.id = c.department_id JOIN regions r ON r.id = d.region_id
              WHERE c.valid_to IS NULL ORDER BY r.name, d.name, c.name') as $r) {
            if ($scope !== null && !$scope->coversCommune((int) $r['cid'])) {
                continue;
            }
            $tree[$r['rid']] ??= ['name' => $r['rn'], 'code' => $r['rc'], 'deps' => []];
            $tree[$r['rid']]['deps'][$r['did']] ??= ['name' => $r['dn'], 'code' => $r['dc'], 'communes' => []];
            $tree[$r['rid']]['deps'][$r['did']]['communes'][$r['cid']] = ['name' => $r['cn'], 'code' => $r['cc']];
        }
        return $tree;
    }

    /** Libellés de liste : le nom, suivi du code RENACOM en cas d'homonymie (« NOM (code) »). */
    private static function listLabels(array $items): array
    {
        $count = array_count_values(array_map(static fn($i) => Text::normalize($i['name']), $items));
        return array_map(static fn($i) => $count[Text::normalize($i['name'])] > 1 ? "{$i['name']} ({$i['code']})" : $i['name'], $items);
    }

    /** Index de recherche par nom normalisé et par code RENACOM ; -1 = ambigu. */
    private function territoryIndex(): array
    {
        $t = ['regions' => [], 'departments' => [], 'communes' => [], 'region_names' => [], 'department_names' => [], 'commune_names' => []];
        $add = static function (array &$idx, string $key, int $id): void {
            if ($key !== '' && $key !== 'CODE:') {
                $idx[$key] = isset($idx[$key]) && $idx[$key] !== $id ? -1 : $id;
            }
        };
        foreach ($this->db->fetchAll('SELECT id, code_renacom, name FROM regions WHERE valid_to IS NULL') as $r) {
            $id = (int) $r['id'];
            $t['region_names'][$id] = $r['name'];
            $add($t['regions'], Text::normalize($r['name']), $id);
            $add($t['regions'], 'CODE:' . $r['code_renacom'], $id);
        }
        foreach ($this->db->fetchAll('SELECT id, region_id, code_renacom, name FROM departments WHERE valid_to IS NULL') as $d) {
            $id = (int) $d['id'];
            $rid = (int) $d['region_id'];
            $t['department_names'][$id] = $d['name'];
            $t['departments'][$rid] ??= [];
            $norm = Text::normalize($d['name']);
            $add($t['departments'][$rid], $norm, $id);
            if (str_starts_with($norm, 'VILLE DE ')) {
                $add($t['departments'][$rid], substr($norm, 9), $id); // « NIAMEY » pour « VILLE DE NIAMEY »
            }
            $add($t['departments'][$rid], 'CODE:' . $d['code_renacom'], $id);
        }
        foreach ($this->db->fetchAll('SELECT id, department_id, code_renacom, name FROM communes WHERE valid_to IS NULL') as $c) {
            $id = (int) $c['id'];
            $did = (int) $c['department_id'];
            $t['commune_names'][$id] = $c['name'];
            $t['communes'][$did] ??= [];
            $add($t['communes'][$did], Text::normalize($c['name']), $id);
            $add($t['communes'][$did], 'CODE:' . $c['code_renacom'], $id);
        }
        return $t;
    }

    /** Nom, code RENACOM, ou libellé de liste « Nom (code) » (homonymes) ; null = inconnu, -1 = ambigu. */
    private function lookup(array $index, string $value): ?int
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(.*\S)\s*\(([^()]+)\)$/u', $v, $m) && isset($index['CODE:' . trim($m[2])])) {
            return $index['CODE:' . trim($m[2])];
        }
        return $index[Text::normalize($v)] ?? $index['CODE:' . $v] ?? null;
    }

    /** Liste déroulante. $strict : valeur hors liste refusée ; sinon simple avertissement. */
    private function listValidation(Worksheet $sheet, string $range, string $formula, string $title, string $prompt, bool $strict = true): void
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST)->setFormula1($formula)->setAllowBlank(true)->setShowDropDown(true)
            ->setErrorStyle($strict ? DataValidation::STYLE_STOP : DataValidation::STYLE_WARNING)
            ->setShowErrorMessage(true)->setErrorTitle('Valeur hors liste')
            ->setError(mb_substr(($strict ? 'Choisir une valeur dans la liste. ' : 'Valeur hors liste : vérifier. ') . $prompt, 0, 255))
            ->setShowInputMessage(true)->setPromptTitle($title)->setPrompt(mb_substr($prompt, 0, 255));
        $sheet->setDataValidation($range, $v);
    }

    private function insertFile(int $ownerId, string $purpose, string $name, string $relative, ?int $ttlDays): int
    {
        $abs = $this->storageDir . '/' . $relative;
        $mime = str_ends_with($relative, '.zip') ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        return $this->db->insert(
            'INSERT INTO files (uuid, owner_id, purpose, original_name, stored_path, mime_type, size_bytes, sha256, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($ttlDays === null ? 'NULL' : 'NOW() + INTERVAL ' . (int) $ttlDays . ' DAY') . ')',
            [Text::uuid4(), $ownerId, $purpose, mb_substr($name, 0, 255), $relative, $mime, filesize($abs), hash_file('sha256', $abs)]);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le dossier $dir");
        }
    }
}
