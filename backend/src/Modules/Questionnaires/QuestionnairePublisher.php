<?php

declare(strict_types=1);

namespace App\Modules\Questionnaires;

use App\Core\Database;

/**
 * Publication d'une version de questionnaire : la définition JSON est stockée telle quelle
 * (immuable, empreinte SHA-256) et décomposée dans les tables questionnaire_* pour l'interrogation
 * (libellés des exports, statistiques par règle…).
 *
 * Republier un contenu identique ne fait rien ; tenter de modifier une version publiée est refusé.
 */
final class QuestionnairePublisher
{
    private const LOCAL_TYPES = ['computed', 'computed_grid', 'auto', 'geo', 'note'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{version_id:int, status:string} status = created | unchanged */
    public function publishFile(string $path, ?int $publishedBy = null): array
    {
        $json = (string) file_get_contents($path);
        $def = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $this->publish($def, $json, $publishedBy);
    }

    public function publish(array $def, string $json, ?int $publishedBy = null): array
    {
        if (($def['schema'] ?? null) !== 1 || empty($def['questionnaire']) || empty($def['version'])) {
            throw new \InvalidArgumentException('Définition invalide : schema, questionnaire et version sont obligatoires.');
        }
        $checksum = hash('sha256', $json);

        return $this->db->transaction(function (Database $db) use ($def, $json, $checksum, $publishedBy): array {
            $db->execute('INSERT INTO questionnaires (code, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = label',
                [$def['questionnaire'], $def['title'] ?? $def['questionnaire']]);
            $qid = (int) $db->fetchValue('SELECT id FROM questionnaires WHERE code = ?', [$def['questionnaire']]);

            $existing = $db->fetchOne('SELECT id, checksum, status FROM questionnaire_versions WHERE questionnaire_id = ? AND version_code = ? FOR UPDATE',
                [$qid, $def['version']]);
            if ($existing !== null) {
                if ($existing['checksum'] === $checksum) {
                    return ['version_id' => (int) $existing['id'], 'status' => 'unchanged'];
                }
                throw new \RuntimeException("La version {$def['version']} est déjà publiée avec un contenu différent : une version publiée est immuable, créer une nouvelle version.");
            }

            $vid = $db->insert(
                "INSERT INTO questionnaire_versions (questionnaire_id, version_code, label, definition_json, checksum, status, published_at, published_by)
                 VALUES (?, ?, ?, ?, ?, 'PUBLIEE', NOW(), ?)",
                [$qid, $def['version'], $def['title'] ?? $def['version'], $json, $checksum, $publishedBy]
            );

            $qSort = 0;
            foreach ($def['sections'] as $sSort => $section) {
                $sid = $db->insert('INSERT INTO questionnaire_sections (version_id, code, title, sort) VALUES (?, ?, ?, ?)',
                    [$vid, $section['code'], $section['title'], $sSort + 1]);
                foreach ($section['items'] as $item) {
                    $this->insertQuestion($db, $vid, $sid, $item, $item['code'], ++$qSort);
                    if (isset($item['visible_if'])) {
                        $this->insertRule($db, $vid, 'VIS_' . $item['code'], 'VISIBLE_IF', $item['code'], $item['visible_if'], 'error', 'Visibilité');
                    }
                    if ($item['type'] === 'roster') {
                        foreach ($item['columns'] as $col) {
                            $code = $item['code'] . '[].' . $col['code'];
                            $this->insertQuestion($db, $vid, $sid, $col, $code, ++$qSort);
                            if (isset($col['visible_if'])) {
                                $this->insertRule($db, $vid, 'VIS_' . $code, 'VISIBLE_IF', $code, $col['visible_if'], 'error', 'Visibilité');
                            }
                        }
                        foreach ($item['row_rules'] ?? [] as $r) {
                            $this->insertRule($db, $vid, $r['code'], 'CHECK', $item['code'] . '[].' . ($r['targets'][0] ?? ''),
                                ['when' => $r['when'] ?? null, 'assert' => $r['assert']], $r['severity'], $r['message']);
                        }
                    }
                }
            }
            foreach ($def['rules'] ?? [] as $r) {
                $this->insertRule($db, $vid, $r['code'], 'CHECK', $r['targets'][0] ?? null,
                    ['when' => $r['when'] ?? null, 'assert' => $r['assert']], $r['severity'], $r['message']);
            }
            return ['version_id' => $vid, 'status' => 'created'];
        });
    }

    private function insertQuestion(Database $db, int $vid, int $sid, array $item, string $code, int $sort): void
    {
        $config = array_diff_key($item, array_flip(['code', 'number', 'label', 'type', 'required', 'options', 'columns', 'row_rules', 'visible_if']));
        $qid = $db->insert(
            'INSERT INTO questionnaire_questions (version_id, section_id, code, number_label, label, type, required, sort, config_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$vid, $sid, $code, $item['number'] ?? null, mb_substr($item['label'], 0, 500), $item['type'],
                !empty($item['required']) && !in_array($item['type'], self::LOCAL_TYPES, true) ? 1 : 0, $sort,
                $config === [] ? null : json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
        );
        foreach ($item['options'] ?? [] as $i => $o) {
            $db->execute('INSERT INTO questionnaire_options (question_id, code, label, sort) VALUES (?, ?, ?, ?)',
                [$qid, $o['code'], $o['label'], $i + 1]);
        }
    }

    private function insertRule(Database $db, int $vid, string $code, string $kind, ?string $target, array $expr, string $severity, string $message): void
    {
        if (strlen($code) > 40 || ($target !== null && strlen($target) > 40)) {
            throw new \LengthException("Code de règle ou cible trop long (40 caractères maximum) : $code");
        }
        $db->execute(
            'INSERT INTO questionnaire_rules (version_id, code, kind, target_code, expression, severity, message) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$vid, $code, $kind, $target, json_encode($expr, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $severity === 'warning' ? 'AVERTISSEMENT' : 'ERREUR', mb_substr($message, 0, 500)]
        );
    }
}
