<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Questionnaire\QuestionnaireEngine;
use App\Rules\JsonLogic;
use App\Rules\JsonLogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rejoue les vecteurs de test partagés avec le moteur Dart (shared/rules-fixtures).
 * Un échec ici signifie que le serveur et le mobile risquent de diverger.
 */
final class SharedFixturesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../shared';

    // ------------------------------------------------------------------ JsonLogic

    public static function jsonLogicCases(): iterable
    {
        foreach (self::readJson(self::ROOT . '/rules-fixtures/jsonlogic.json') as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('jsonLogicCases')]
    public function testJsonLogic(array $case): void
    {
        if (!empty($case['error'])) {
            $this->expectException(JsonLogicException::class);
            JsonLogic::apply($case['logic'], $case['data']);
            return;
        }
        $this->assertSame($case['expected'], JsonLogic::apply($case['logic'], $case['data']));
    }

    // ------------------------------------------------------------- Questionnaire

    public static function questionnaireCases(): iterable
    {
        // Un fichier de vecteurs par version du questionnaire (eftp-nf-2025.1, eftp-nf-2025.2…).
        foreach (glob(self::ROOT . '/rules-fixtures/eftp-nf-*.json') as $file) {
            $fixture = self::readJson($file);
            $definition = self::ROOT . '/' . substr($fixture['definition'], strlen('shared/'));
            foreach ($fixture['cases'] as $case) {
                yield basename($file, '.json') . ' — ' . $case['name'] => [$fixture['base'], $case, $definition];
            }
        }
    }

    #[DataProvider('questionnaireCases')]
    public function testQuestionnaire(array $base, array $case, string $definition): void
    {
        $engine = QuestionnaireEngine::fromJsonFile($definition);
        $data = $base;
        foreach ($case['patch'] as $op) {
            self::applyPatch($data, $op);
        }
        $result = $engine->validate($data);
        $expect = $case['expect'];

        $describe = static fn(array $issues) => array_map(static fn($i) => "{$i['code']} @ {$i['target']} — {$i['message']}", $issues);
        $context = "\nErreurs : " . json_encode($describe($result['errors']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\nAvertissements : " . json_encode($describe($result['warnings']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $this->assertSame(self::pairs($expect['errors']), self::issuePairs($result['errors']), 'Erreurs inattendues.' . $context);
        $this->assertSame(self::pairs($expect['warnings']), self::issuePairs($result['warnings']), 'Avertissements inattendus.' . $context);
        $this->assertSame($expect['valid'], $result['valid'], 'Statut de validité inattendu.' . $context);

        $cleared = $result['cleared'];
        $expectedCleared = $expect['cleared'];
        sort($cleared);
        sort($expectedCleared);
        $this->assertSame($expectedCleared, $cleared, 'Valeurs effacées inattendues.');

        foreach ($expect['computed'] ?? [] as $path => $value) {
            $this->assertSame($value, JsonLogic::var($path, $result['computed']), "Variable calculée « $path » inattendue.");
        }
    }

    public function testEveryRuleCodeIsUnique(): void
    {
        foreach (glob(self::ROOT . '/questionnaire/eftp-nf-*.json') as $file) {
        $def = self::readJson($file);
        $codes = array_column($def['rules'], 'code');
        foreach ($def['sections'] as $s) {
            foreach ($s['items'] as $item) {
                foreach ($item['row_rules'] ?? [] as $r) {
                    $codes[] = $r['code'];
                }
            }
        }
        $this->assertSame(count($codes), count(array_unique($codes)), 'Codes de contrôle en double : ' . basename($file));
        }
    }

    public function testEveryItemCodeIsUnique(): void
    {
        foreach (glob(self::ROOT . '/questionnaire/eftp-nf-*.json') as $file) {
        $def = self::readJson($file);
        $codes = [];
        foreach ($def['sections'] as $s) {
            foreach ($s['items'] as $item) {
                $codes[] = $item['code'];
            }
        }
        $this->assertSame(count($codes), count(array_unique($codes)), "Codes d'éléments en double : " . basename($file));
        }
    }

    // ---------------------------------------------------------------- Utilitaires

    private static function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function pairs(array $expected): array
    {
        $p = array_map(static fn(array $e) => "{$e[0]} @ {$e[1]}", $expected);
        sort($p);
        return $p;
    }

    /** @return list<string> */
    private static function issuePairs(array $issues): array
    {
        $p = array_map(static fn(array $i) => "{$i['code']} @ {$i['target']}", $issues);
        sort($p);
        return $p;
    }

    private static function applyPatch(array &$data, array $op): void
    {
        $segments = explode('.', $op['path']);
        $last = array_pop($segments);
        $ref = &$data;
        foreach ($segments as $s) {
            if (!isset($ref[$s]) || !is_array($ref[$s])) {
                $ref[$s] = [];
            }
            $ref = &$ref[$s];
        }
        if ($op['op'] === 'set') {
            $ref[$last] = $op['value'];
        } elseif ($op['op'] === 'unset') {
            unset($ref[$last]);
        } else {
            throw new \InvalidArgumentException("Opération de patch inconnue : {$op['op']}");
        }
    }
}
