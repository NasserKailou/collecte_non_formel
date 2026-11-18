<?php

declare(strict_types=1);

namespace App\Questionnaire;

use App\Rules\JsonLogic;

/**
 * Moteur de validation d'une collecte selon une définition de questionnaire versionnée.
 *
 * Spécification : docs/architecture/questionnaire-et-regles.md §3.
 * Ordre : visibilité + nettoyage + contrôles génériques → variables calculées → règles.
 * Une règle n'est pas évaluée si l'une de ses cibles porte déjà une erreur générique
 * (valeur manquante, mauvais type…) afin de ne pas signaler deux fois la même anomalie.
 */
final class QuestionnaireEngine
{
    private const SKIPPED_TYPES = ['computed', 'computed_grid', 'auto', 'geo', 'note'];
    private const NIGER_BBOX = ['lat_min' => 11.0, 'lat_max' => 24.0, 'lng_min' => 0.0, 'lng_max' => 16.5];

    private array $data = [];
    /** @var list<array{code:string,severity:string,target:string,message:string}> */
    private array $issues = [];
    /** @var list<string> */
    private array $cleared = [];
    /** @var array<string,true> cibles portant une erreur générique */
    private array $errorTargets = [];

    /**
     * Question attendue mais non renseignée : simple avertissement (décision du 14/11/2026), jamais bloquant.
     * L'agent est invité à la remplir ; le contrôleur voit l'avertissement lors de la validation.
     */
    public const MSG_REQUIRED = 'Non renseigné : cette question devrait normalement être remplie.';
    public const MSG_REQUIRED_GPS = "Position GPS non relevée : la relever devant le centre, ou indiquer pourquoi c'est impossible.";
    /**
     * Contrôles génériques qui restent bloquants : valeur impossible à enregistrer correctement (type, modalité inconnue,
     * hors bornes, date mal formée) ou ligne en double (fausserait les totaux). Tous les autres sont des avertissements.
     */
    public const BLOCKING_CODES = ['TYPE', 'OPTION', 'RANGE', 'DATE', 'DUPLICATE'];
    /** @var list<array{item:array,code:string}> */
    private array $rosters = [];

    public function __construct(private readonly array $definition)
    {
        if (($definition['schema'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Définition de questionnaire : schéma non pris en charge.');
        }
    }

    public static function fromJsonFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Définition introuvable : $path");
        }
        return new self(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function version(): string
    {
        return (string) $this->definition['version'];
    }

    /**
     * @param array $data données de la collecte, avec `_ctx` (contexte d'évaluation)
     * @return array{valid:bool, errors:list<array>, warnings:list<array>, cleared:list<string>, computed:array, data:array}
     */
    public function validate(array $data): array
    {
        $this->data = $data;
        $this->issues = [];
        $this->cleared = [];
        $this->errorTargets = [];
        $this->rosters = [];

        foreach ($this->definition['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $this->processItem($item);
            }
        }

        $computed = [];
        foreach ($this->definition['computed'] ?? [] as $c) {
            $value = JsonLogic::apply($c['expr'], $this->data);
            $segments = explode('.', $c['code']);
            self::setPath($this->data, $segments, $value);
            self::setPath($computed, $segments, $value);
        }

        foreach ($this->definition['rules'] ?? [] as $rule) {
            $this->evaluateRule($rule, $this->data, static fn(string $t) => $t);
        }
        foreach ($this->rosters as ['item' => $item, 'code' => $code]) {
            $rows = JsonLogic::var($code, $this->data) ?? [];
            foreach ($item['row_rules'] ?? [] as $rule) {
                foreach ($rows as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ctx = $row + ['_line' => $i + 1, '_root' => $this->data];
                    $this->evaluateRule($rule, $ctx, static fn(string $t) => "{$code}[$i].$t");
                }
            }
        }

        $errors = array_values(array_filter($this->issues, static fn($i) => $i['severity'] === 'error'));
        $warnings = array_values(array_filter($this->issues, static fn($i) => $i['severity'] === 'warning'));
        $clean = $this->data;
        unset($clean['_ctx']);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'cleared' => $this->cleared,
            'computed' => $computed,
            'data' => $clean,
        ];
    }

    // -----------------------------------------------------------------------
    // Éléments
    // -----------------------------------------------------------------------

    private function processItem(array $item): void
    {
        $type = $item['type'];
        if (in_array($type, self::SKIPPED_TYPES, true)) {
            return;
        }
        $code = $item['code'];
        $segments = explode('.', $code);

        if (!$this->isVisible($item, $this->data)) {
            if (!self::isEmpty(self::getPath($this->data, $segments))) {
                $this->cleared[] = $code;
            }
            self::unsetPath($this->data, $segments);
            return;
        }

        match ($type) {
            'roster' => $this->processRoster($item, $segments, $code),
            'grid' => $this->processGrid($item, $segments, $code),
            default => $this->checkValue($item, self::getPath($this->data, $segments), $code),
        };
    }

    private function processRoster(array $item, array $segments, string $code): void
    {
        $rows = self::getPath($this->data, $segments) ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->addIssue('TYPE', 'error', $code, 'Tableau invalide.');
            return;
        }
        $n = count($rows);
        if (isset($item['min_rows']) && $n < $item['min_rows']) {
            $this->addIssue('ROWS', 'error', $code, "Au moins {$item['min_rows']} ligne(s) attendue(s).");
        }
        if (isset($item['max_rows']) && $n > $item['max_rows']) {
            $this->addIssue('ROWS', 'error', $code, "Au plus {$item['max_rows']} ligne(s) autorisée(s).");
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                $this->addIssue('TYPE', 'error', "{$code}[$i]", 'Ligne invalide.');
                continue;
            }
            foreach ($item['columns'] as $col) {
                $target = "{$code}[$i].{$col['code']}";
                $ctx = $row + ['_line' => $i + 1, '_root' => $this->data];
                if (!$this->isVisible($col, $ctx)) {
                    if (array_key_exists($col['code'], $row) && !self::isEmpty($row[$col['code']])) {
                        $this->cleared[] = $target;
                    }
                    unset($row[$col['code']]);
                    continue;
                }
                $this->checkValue($col, $row[$col['code']] ?? null, $target);
            }
            $rows[$i] = $row;
        }
        self::setPath($this->data, $segments, $rows);

        if (!empty($item['unique'])) {
            $seen = [];
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parts = array_map(static fn($c) => is_scalar($row[$c] ?? null) ? (string) $row[$c] : '', $item['unique']);
                if (implode('', $parts) === '') {
                    continue;
                }
                $key = implode("\x1F", $parts);
                if (isset($seen[$key])) {
                    $this->addIssue('DUPLICATE', 'error', "{$code}[$i].{$item['unique'][0]}",
                        'Ligne ' . ($i + 1) . ' : doublon de la ligne ' . ($seen[$key] + 1) . '.');
                } else {
                    $seen[$key] = $i;
                }
            }
        }
        $this->rosters[] = ['item' => $item, 'code' => $code];
    }

    private function processGrid(array $item, array $segments, string $code): void
    {
        $grid = self::getPath($this->data, $segments);
        if ($grid !== null && !is_array($grid)) {
            $this->addIssue('TYPE', 'error', $code, 'Grille invalide.');
            return;
        }
        $cell = $item['cell'] + ['required' => (bool) ($item['required'] ?? false)];
        foreach ($item['rows'] as $r) {
            foreach ($item['columns'] as $c) {
                $cellSegments = array_merge($segments, [$r['code']], explode('.', $c['code']));
                $this->checkValue($cell, self::getPath($this->data, $cellSegments), "$code.{$r['code']}.{$c['code']}");
            }
        }
    }

    private function isVisible(array $item, array $ctx): bool
    {
        return !isset($item['visible_if']) || JsonLogic::truthy(JsonLogic::apply($item['visible_if'], $ctx));
    }

    // -----------------------------------------------------------------------
    // Contrôles génériques
    // -----------------------------------------------------------------------

    private function checkValue(array $item, mixed $value, string $target): void
    {
        $type = $item['type'];
        if ($type === 'gps') {
            $this->checkGps($item, $value, $target);
            return;
        }
        if (self::isEmpty($value)) {
            if (!empty($item['required'])) {
                $this->addIssue('REQUIRED', 'warning', $target, self::MSG_REQUIRED);
            }
            return;
        }

        switch ($type) {
            case 'text':
                if (!is_string($value)) {
                    $this->addIssue('TYPE', 'error', $target, 'Texte attendu.');
                    return;
                }
                $len = mb_strlen(trim($value));
                if ((isset($item['min_length']) && $len < $item['min_length']) || (isset($item['max_length']) && $len > $item['max_length'])) {
                    $this->addIssue('LENGTH', 'error', $target,
                        sprintf('Longueur attendue : %d à %d caractères.', $item['min_length'] ?? 0, $item['max_length'] ?? 9999));
                    return;
                }
                if (isset($item['pattern']) && preg_match('/' . str_replace('/', '\/', $item['pattern']) . '/u', $value) !== 1) {
                    $this->addIssue('PATTERN', 'error', $target, 'Format invalide.');
                }
                return;
            case 'phone':
                if (!is_string($value) || preg_match('/^[0-9]{8}$/', $value) !== 1) {
                    $this->addIssue('PATTERN', 'error', $target, 'Numéro de téléphone invalide : 8 chiffres attendus.');
                }
                return;
            case 'integer':
                if (!is_int($value)) {
                    $this->addIssue('TYPE', 'error', $target, 'Nombre entier attendu.');
                    return;
                }
                if ((isset($item['min']) && $value < $item['min']) || (isset($item['max']) && $value > $item['max'])) {
                    $this->addIssue('RANGE', 'error', $target,
                        sprintf('Valeur attendue entre %s et %s.', $item['min'] ?? '−∞', $item['max'] ?? '+∞'));
                }
                return;
            case 'date':
                if (!is_string($value) || !self::isValidDate($value, !empty($item['partial']))) {
                    $this->addIssue('DATE', 'error', $target, !empty($item['partial'])
                        ? 'Date invalide (formats acceptés : AAAA-MM-JJ, AAAA-MM ou AAAA).'
                        : 'Date invalide (format attendu : AAAA-MM-JJ).');
                }
                return;
            case 'yesno':
                if (!is_bool($value)) {
                    $this->addIssue('TYPE', 'error', $target, 'Réponse Oui/Non attendue.');
                }
                return;
            case 'single':
                if (!is_string($value) && !is_int($value)) {
                    $this->addIssue('TYPE', 'error', $target, 'Choix invalide.');
                    return;
                }
                $allowed = $this->allowedCodes($item);
                if ($allowed !== null && !in_array((string) $value, $allowed, true)) {
                    $this->addIssue('OPTION', 'error', $target, 'Modalité inconnue.');
                }
                return;
            case 'multi':
                if (!is_array($value) || !array_is_list($value)) {
                    $this->addIssue('TYPE', 'error', $target, 'Liste de choix attendue.');
                    return;
                }
                $asStrings = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $value);
                if (count(array_unique($asStrings)) !== count($asStrings)) {
                    $this->addIssue('DUPLICATE', 'error', $target, 'Une modalité est cochée plusieurs fois.');
                    return;
                }
                $allowed = $this->allowedCodes($item);
                foreach ($value as $v) {
                    if (!is_string($v) || ($allowed !== null && !in_array($v, $allowed, true))) {
                        $this->addIssue('OPTION', 'error', $target, 'Modalité inconnue.');
                        return;
                    }
                }
                return;
            default:
                throw new \DomainException("Type d'élément inconnu : « $type »");
        }
    }

    private function checkGps(array $item, mixed $value, string $target): void
    {
        if (self::isEmpty($value)) {
            if (!empty($item['required'])) {
                $this->addIssue('REQUIRED', 'warning', $target, self::MSG_REQUIRED_GPS);
            }
            return;
        }
        if (!is_array($value)) {
            $this->addIssue('TYPE', 'error', $target, 'Coordonnées GPS invalides.');
            return;
        }
        $status = $value['status'] ?? null;
        if ($status === 'OK' || $status === 'MANUEL') {
            $lat = $value['lat'] ?? null;
            $lng = $value['lng'] ?? null;
            if (!(is_int($lat) || is_float($lat)) || !(is_int($lng) || is_float($lng))) {
                $this->addIssue('GPS', 'error', $target, 'Latitude et longitude numériques attendues.');
            } elseif ($lat < self::NIGER_BBOX['lat_min'] || $lat > self::NIGER_BBOX['lat_max']
                || $lng < self::NIGER_BBOX['lng_min'] || $lng > self::NIGER_BBOX['lng_max']) {
                $this->addIssue('GPS', 'error', $target, 'Position hors du territoire du Niger.');
            }
            return;
        }
        if ($status === 'INDISPONIBLE' || $status === 'REFUSE') {
            $note = $value['note'] ?? null;
            if (!is_string($note) || mb_strlen(trim($note)) < 3) {
                $this->addIssue('GPS', 'error', $target, "Indiquer pourquoi la position GPS n'a pas pu être relevée.");
            }
            return;
        }
        $this->addIssue('GPS', 'error', $target, 'Statut GPS inconnu.');
    }

    /** @return list<string>|null null si la liste de référence n'est pas fournie */
    private function allowedCodes(array $item): ?array
    {
        if (isset($item['options'])) {
            return array_map(static fn($o) => (string) $o['code'], $item['options']);
        }
        if (isset($item['options_source'])) {
            $list = $this->data['_ctx']['options'][$item['options_source']] ?? null;
            return is_array($list) ? array_map('strval', $list) : null;
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Règles
    // -----------------------------------------------------------------------

    /** @param callable(string):string $resolveTarget */
    private function evaluateRule(array $rule, array $ctx, callable $resolveTarget): void
    {
        $targets = array_map($resolveTarget, $rule['targets'] ?? []);
        foreach ($targets as $t) {
            if (isset($this->errorTargets[$t])) {
                return;
            }
        }
        if (isset($rule['when']) && !JsonLogic::truthy(JsonLogic::apply($rule['when'], $ctx))) {
            return;
        }
        if (!JsonLogic::truthy(JsonLogic::apply($rule['assert'], $ctx))) {
            $this->addIssue($rule['code'], $rule['severity'], $targets[0] ?? '', self::interpolate($rule['message'], $ctx), false);
        }
    }

    private static function interpolate(string $message, array $ctx): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static function (array $m) use ($ctx): string {
            $v = JsonLogic::var($m[1], $ctx);
            return match (true) {
                $v === null => '—',
                is_bool($v) => $v ? 'Oui' : 'Non',
                is_float($v) => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ','),
                is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE) ?: '',
                default => (string) $v,
            };
        }, $message);
    }

    private function addIssue(string $code, string $severity, string $target, string $message, bool $generic = true): void
    {
        $original = $severity;
        // Saisie sans blocage (décision du 15/11/2026) : seuls les contrôles qui protègent l'intégrité des données
        // restent bloquants ; format de téléphone, longueur, nombre de lignes, GPS… deviennent des avertissements.
        if ($generic && $severity === 'error' && !in_array($code, self::BLOCKING_CODES, true)) {
            $severity = 'warning';
        }
        $this->issues[] = ['code' => $code, 'severity' => $severity, 'target' => $target, 'message' => $message];
        // Une réponse manquante (avertissement REQUIRED) suspend aussi les règles qui portent sur elle :
        // sinon une règle évaluée sur une case vide produirait une fausse anomalie.
        if ($generic && ($original === 'error' || $code === 'REQUIRED')) {
            $this->errorTargets[$target] = true;
        }
    }

    // -----------------------------------------------------------------------
    // Utilitaires
    // -----------------------------------------------------------------------

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === [] || (is_string($v) && trim($v) === '');
    }

    private static function isValidDate(string $v, bool $partial): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }
        if (!$partial) {
            return false;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m) === 1) {
            return (int) $m[2] >= 1 && (int) $m[2] <= 12;
        }
        return preg_match('/^\d{4}$/', $v) === 1;
    }

    private static function getPath(array $data, array $segments): mixed
    {
        $cur = $data;
        foreach ($segments as $s) {
            if (!is_array($cur) || !array_key_exists($s, $cur)) {
                return null;
            }
            $cur = $cur[$s];
        }
        return $cur;
    }

    private static function setPath(array &$data, array $segments, mixed $value): void
    {
        $ref = &$data;
        foreach ($segments as $s) {
            if (!isset($ref[$s]) || !is_array($ref[$s])) {
                $ref[$s] = [];
            }
            $ref = &$ref[$s];
        }
        $ref = $value;
    }

    private static function unsetPath(array &$data, array $segments): void
    {
        $last = array_pop($segments);
        $ref = &$data;
        foreach ($segments as $s) {
            if (!isset($ref[$s]) || !is_array($ref[$s])) {
                return;
            }
            $ref = &$ref[$s];
        }
        unset($ref[$last]);
    }
}
