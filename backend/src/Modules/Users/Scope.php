<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;

/**
 * Périmètre territorial d'un utilisateur (lignes de user_scopes), résolu en ensembles
 * de régions, départements et communes *entièrement couverts*.
 *
 * - Une ligne REGION couvre la région, tous ses départements et toutes leurs communes.
 * - Une ligne DEPARTMENT couvre le département et toutes ses communes.
 * - Une ligne COMMUNE couvre la seule commune.
 * - NATIONAL couvre tout.
 *
 * Toute requête filtrée par périmètre passe par cette classe (docs/api/README.md §1,
 * « Contrôle d'accès ») : un identifiant transmis par le client n'est jamais suffisant.
 */
final class Scope
{
    public const LEVELS = ['NATIONAL', 'REGION', 'DEPARTMENT', 'COMMUNE'];

    /**
     * @param list<array{level:string, region_id:?int, department_id:?int, commune_id:?int}> $rows
     * @param array<int,true> $regions @param array<int,true> $departments @param array<int,true> $communes
     */
    private function __construct(
        public readonly array $rows,
        public readonly bool $national,
        private readonly array $regions,
        private readonly array $departments,
        private readonly array $communes,
    ) {
    }

    public static function forUser(Database $db, int $userId): self
    {
        return self::fromRows($db, $db->fetchAll(
            'SELECT level, region_id, department_id, commune_id FROM user_scopes WHERE user_id = ? ORDER BY id', [$userId]));
    }

    /** @param list<array> $rows lignes au format user_scopes (identifiants déjà vérifiés) */
    public static function fromRows(Database $db, array $rows): self
    {
        $rows = array_map(static fn(array $r) => [
            'level' => (string) $r['level'],
            'region_id' => isset($r['region_id']) ? (int) $r['region_id'] : null,
            'department_id' => isset($r['department_id']) ? (int) $r['department_id'] : null,
            'commune_id' => isset($r['commune_id']) ? (int) $r['commune_id'] : null,
        ], $rows);

        $national = false;
        $regions = $departments = $communes = [];
        foreach ($rows as $r) {
            match ($r['level']) {
                'NATIONAL' => $national = true,
                'REGION' => $regions[$r['region_id']] = true,
                'DEPARTMENT' => $departments[$r['department_id']] = true,
                'COMMUNE' => $communes[$r['commune_id']] = true,
                default => throw new \InvalidArgumentException("Niveau de périmètre inconnu : {$r['level']}"),
            };
        }
        if (!$national) {
            if ($regions !== []) {
                foreach (self::ids($db, 'SELECT id FROM departments WHERE region_id IN', array_keys($regions)) as $id) {
                    $departments[$id] = true;
                }
            }
            if ($departments !== []) {
                foreach (self::ids($db, 'SELECT id FROM communes WHERE department_id IN', array_keys($departments)) as $id) {
                    $communes[$id] = true;
                }
            }
        }
        return new self($rows, $national, $regions, $departments, $communes);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function coversRegion(int $id): bool
    {
        return $this->national || isset($this->regions[$id]);
    }

    public function coversDepartment(int $id): bool
    {
        return $this->national || isset($this->departments[$id]);
    }

    public function coversCommune(int $id): bool
    {
        return $this->national || isset($this->communes[$id]);
    }

    /** Vrai si chaque ligne du périmètre $other est entièrement couverte par ce périmètre. Un périmètre vide n'est contenu que dans le périmètre national. */
    public function contains(self $other): bool
    {
        if ($this->national) {
            return true;
        }
        if ($other->national || $other->isEmpty()) {
            return false;
        }
        foreach ($other->rows as $r) {
            $ok = match ($r['level']) {
                'REGION' => $this->coversRegion($r['region_id']),
                'DEPARTMENT' => $this->coversDepartment($r['department_id']),
                'COMMUNE' => $this->coversCommune($r['commune_id']),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * Clause SQL restreignant une colonne « commune » au périmètre.
     * @return array{0:string, 1:list<int>}
     */
    public function communeClause(string $column): array
    {
        if ($this->national) {
            return ['1 = 1', []];
        }
        if ($this->communes === []) {
            return ['1 = 0', []];
        }
        $ids = array_keys($this->communes);
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * Clause SQL « l'utilisateur $userColumn a au moins un périmètre, et tous sont couverts ».
     * Les comptes de périmètre national ou sans périmètre ne sont visibles que d'un périmètre national.
     * @return array{0:string, 1:list<int>}
     */
    public function userVisibilityClause(string $userColumn): array
    {
        if ($this->national) {
            return ['1 = 1', []];
        }
        $covered = [];
        $params = [];
        foreach (['REGION' => ['region_id', $this->regions], 'DEPARTMENT' => ['department_id', $this->departments],
                     'COMMUNE' => ['commune_id', $this->communes]] as $level => [$col, $set]) {
            if ($set !== []) {
                $ids = array_keys($set);
                $covered[] = "(xs.level = '$level' AND xs.$col IN (" . implode(',', array_fill(0, count($ids), '?')) . '))';
                array_push($params, ...$ids);
            }
        }
        if ($covered === []) {
            return ['1 = 0', []];
        }
        $sql = "EXISTS (SELECT 1 FROM user_scopes ys WHERE ys.user_id = $userColumn)"
            . " AND NOT EXISTS (SELECT 1 FROM user_scopes xs WHERE xs.user_id = $userColumn AND NOT (" . implode(' OR ', $covered) . '))';
        return [$sql, $params];
    }

    /** @return list<int> */
    private static function ids(Database $db, string $sqlPrefix, array $ids): array
    {
        return array_map('intval', array_column(
            $db->fetchAll($sqlPrefix . ' (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)), 'id'));
    }
}
