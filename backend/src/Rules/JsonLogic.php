<?php

declare(strict_types=1);

namespace App\Rules;

/**
 * Évaluateur d'un sous-ensemble strict de JsonLogic.
 *
 * Sémantique : docs/architecture/questionnaire-et-regles.md §4.
 * Toute divergence avec l'implémentation Dart est un défaut : les deux sont
 * vérifiées par shared/rules-fixtures/jsonlogic.json.
 */
final class JsonLogic
{
    private const ARRAY_OPS = ['map', 'filter', 'reduce', 'all', 'some', 'none'];

    /**
     * @param mixed $logic expression (tableau PHP issu de json_decode(..., true)) ou valeur littérale
     * @param mixed $data  données d'évaluation
     */
    public static function apply(mixed $logic, mixed $data = null): mixed
    {
        if (is_array($logic) && array_is_list($logic)) {
            return array_map(static fn($l) => self::apply($l, $data), $logic);
        }
        if (!self::isOperation($logic)) {
            return $logic;
        }

        $op = (string) array_key_first($logic);
        $rawArgs = $logic[$op];
        $args = (is_array($rawArgs) && array_is_list($rawArgs)) ? $rawArgs : [$rawArgs];

        // Opérateurs à évaluation paresseuse ou à portée de données propre
        switch ($op) {
            case 'var':
                $default = array_key_exists(1, $args) ? self::apply($args[1], $data) : null;
                return self::var(self::apply($args[0] ?? '', $data), $data, $default);
            case 'if':
                return self::opIf($args, $data);
            case 'and':
                $last = true;
                foreach ($args as $a) {
                    $last = self::apply($a, $data);
                    if (!self::truthy($last)) {
                        return $last;
                    }
                }
                return $last;
            case 'or':
                $last = false;
                foreach ($args as $a) {
                    $last = self::apply($a, $data);
                    if (self::truthy($last)) {
                        return $last;
                    }
                }
                return $last;
        }
        if (in_array($op, self::ARRAY_OPS, true)) {
            return self::arrayOp($op, $args, $data);
        }

        $values = array_map(static fn($a) => self::apply($a, $data), $args);

        return match ($op) {
            '==' => self::equals($values[0] ?? null, $values[1] ?? null),
            '!=' => !self::equals($values[0] ?? null, $values[1] ?? null),
            '<' => self::compareChain($values, static fn(int $c) => $c < 0),
            '<=' => self::compareChain($values, static fn(int $c) => $c <= 0),
            '>' => self::compare($values[0] ?? null, $values[1] ?? null, static fn(int $c) => $c > 0),
            '>=' => self::compare($values[0] ?? null, $values[1] ?? null, static fn(int $c) => $c >= 0),
            '!' => !self::truthy($values[0] ?? null),
            '!!' => self::truthy($values[0] ?? null),
            'in' => self::in($values[0] ?? null, $values[1] ?? null),
            '+' => self::add($values),
            '-' => self::subtract($values),
            '*' => self::multiply($values),
            '/' => self::divide($values[0] ?? null, $values[1] ?? null),
            'max' => self::extremum($values, true),
            'min' => self::extremum($values, false),
            default => throw new JsonLogicException("Opérateur non autorisé : « $op »"),
        };
    }

    /** Vérité JsonLogic : false, null, 0, "" et [] sont faux. */
    public static function truthy(mixed $v): bool
    {
        if (is_array($v)) {
            return count($v) > 0;
        }
        return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0);
    }

    /** Lecture d'un chemin pointé (« a.b.0.c ») dans des données imbriquées. */
    public static function var(mixed $path, mixed $data, mixed $default = null): mixed
    {
        if ($path === null || $path === '') {
            return $data;
        }
        $current = $data;
        foreach (explode('.', (string) $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current ?? $default;
    }

    private static function isOperation(mixed $logic): bool
    {
        return is_array($logic) && count($logic) === 1 && is_string(array_key_first($logic));
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return $a == $b;
        }
        return $a === $b;
    }

    /** @param callable(int):bool $test */
    private static function compare(mixed $a, mixed $b, callable $test): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return $test($a <=> $b);
        }
        if (is_string($a) && is_string($b)) {
            return $test(strcmp($a, $b) <=> 0);
        }
        return false;
    }

    /** Forme « entre » de JsonLogic : {"<": [a, b, c]} ⇔ a < b < c. */
    private static function compareChain(array $values, callable $test): bool
    {
        $n = count($values);
        if ($n < 2) {
            return false;
        }
        for ($i = 0; $i < $n - 1; $i++) {
            if (!self::compare($values[$i], $values[$i + 1], $test)) {
                return false;
            }
        }
        return true;
    }

    private static function opIf(array $args, mixed $data): mixed
    {
        $n = count($args);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            if (self::truthy(self::apply($args[$i], $data))) {
                return self::apply($args[$i + 1], $data);
            }
        }
        return $n % 2 === 1 ? self::apply($args[$n - 1], $data) : null;
    }

    private static function in(mixed $needle, mixed $haystack): bool
    {
        if (is_array($haystack)) {
            foreach ($haystack as $h) {
                if (self::equals($needle, $h)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($haystack) && is_string($needle)) {
            return str_contains($haystack, $needle);
        }
        return false;
    }

    /** null vaut 0 ; toute autre valeur non numérique rend le résultat null. */
    private static function add(array $values): int|float|null
    {
        $sum = 0;
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            if (!self::isNumber($v)) {
                return null;
            }
            $sum += $v;
        }
        return $sum;
    }

    private static function subtract(array $values): int|float|null
    {
        foreach ($values as $v) {
            if ($v !== null && !self::isNumber($v)) {
                return null;
            }
        }
        if (count($values) === 1) {
            return -($values[0] ?? 0);
        }
        return ($values[0] ?? 0) - ($values[1] ?? 0);
    }

    private static function multiply(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }
        $p = 1;
        foreach ($values as $v) {
            if (!self::isNumber($v)) {
                return null;
            }
            $p *= $v;
        }
        return $p;
    }

    private static function divide(mixed $a, mixed $b): int|float|null
    {
        if (!self::isNumber($a) || !self::isNumber($b) || $b == 0) {
            return null;
        }
        $r = $a / $b;
        return (is_float($r) && floor($r) === $r && abs($r) < PHP_INT_MAX) ? (int) $r : $r;
    }

    private static function extremum(array $values, bool $max): int|float|null
    {
        if ($values === []) {
            return null;
        }
        foreach ($values as $v) {
            if (!self::isNumber($v)) {
                return null;
            }
        }
        return $max ? max($values) : min($values);
    }

    private static function arrayOp(string $op, array $args, mixed $data): mixed
    {
        $items = self::apply($args[0] ?? null, $data);
        $items = is_array($items) ? array_values($items) : [];
        $logic = $args[1] ?? null;

        switch ($op) {
            case 'map':
                return array_map(static fn($it) => self::apply($logic, $it), $items);
            case 'filter':
                return array_values(array_filter($items, static fn($it) => self::truthy(self::apply($logic, $it))));
            case 'reduce':
                $acc = self::apply($args[2] ?? null, $data);
                foreach ($items as $it) {
                    $acc = self::apply($logic, ['current' => $it, 'accumulator' => $acc]);
                }
                return $acc;
            case 'all':
                if ($items === []) {
                    return false;
                }
                foreach ($items as $it) {
                    if (!self::truthy(self::apply($logic, $it))) {
                        return false;
                    }
                }
                return true;
            case 'some':
                foreach ($items as $it) {
                    if (self::truthy(self::apply($logic, $it))) {
                        return true;
                    }
                }
                return false;
            default: // none
                foreach ($items as $it) {
                    if (self::truthy(self::apply($logic, $it))) {
                        return false;
                    }
                }
                return true;
        }
    }
}
