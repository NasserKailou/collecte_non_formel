<?php

declare(strict_types=1);

namespace App\Modules\Stats;

use App\Core\Database;
use App\Http\HttpException;

/**
 * Statistiques et indicateurs (docs/api/README.md §6, UC-11, UC-14).
 *
 * - Toutes les requêtes appliquent le périmètre territorial de l'appelant.
 * - Base des indicateurs : « validated » (collectes VALIDEE) ou « submitted » (SOUMISE, EN_CONTROLE, VALIDEE) ;
 *   les collectes INVALIDEE ou REOUVERTE, en attente de correction, ne sont jamais comptées.
 * - Les indicateurs sont calculés à partir des tables ventilées (et non des charges utiles), avec les mêmes
 *   définitions que les variables calculées du questionnaire (Q2.1 = Section IV, Q2.19 = Section III).
 */
final class StatsService
{
    public const BASIS = ['validated' => ['VALIDEE'], 'submitted' => ['SOUMISE', 'EN_CONTROLE', 'VALIDEE']];
    private const ALL_STATUSES = ['SOUMISE', 'EN_CONTROLE', 'VALIDEE', 'INVALIDEE', 'REOUVERTE'];
    private const LEVELS = ['region' => ['r.id', 'r.name'], 'department' => ['d.id', 'd.name'], 'commune' => ['co.id', 'co.name']];
    public const DURATION_LABELS = ['LE3' => '≤ 3 mois', 'M4_9' => '4 à 9 mois', 'GT9' => '> 9 mois'];
    public const CATEGORY_LABELS = ['HANDICAPES' => 'Handicapés', 'REFUGIES' => 'Réfugiés', 'RETOURNES' => 'Retournés', 'DEPLACES' => 'Déplacés internes'];
    public const MEASURE_LABELS = ['INSCRITS' => 'Inscrits', 'PLACES' => 'Placés', 'OUTILLES' => 'Outillés'];

    public function __construct(private readonly Database $db)
    {
    }

    public function defaultBasis(): string
    {
        $v = (string) $this->db->fetchValue("SELECT value FROM system_settings WHERE setting_key = 'stats_basis'");
        return isset(self::BASIS[$v]) ? $v : 'validated';
    }

    // ===================================================================== Avancement

    /** Couverture par unité territoriale : ciblées, affectées, collectées par statut, non collectées. */
    public function progress(array $actor, int $campaignId, string $level): array
    {
        $this->campaign($campaignId);
        if (!isset(self::LEVELS[$level])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Niveau : region, department ou commune.');
        }
        [$idCol, $nameCol] = self::LEVELS[$level];
        [$scopeSql, $params] = $actor['scope']->communeClause('s.commune_id');
        $statusCols = implode(', ', array_map(static fn($st) => "SUM(col.status = '$st') AS " . strtolower($st), self::ALL_STATUSES));
        $rows = $this->db->fetchAll(
            "SELECT $idCol AS id, $nameCol AS name, COUNT(*) AS targeted, COUNT(a.id) AS assigned, COUNT(col.id) AS collected, $statusCols
               FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id
               JOIN communes co ON co.id = s.commune_id JOIN departments d ON d.id = co.department_id JOIN regions r ON r.id = d.region_id
               LEFT JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
               LEFT JOIN collections col ON col.campaign_structure_id = cs.id
              WHERE cs.campaign_id = ? AND $scopeSql
              GROUP BY $idCol, $nameCol ORDER BY $nameCol", [$campaignId, ...$params]);
        $out = array_map(fn(array $r) => $this->progressRow($r), $rows);
        $total = ['id' => null, 'name' => 'Total', 'targeted' => 0, 'assigned' => 0, 'collected' => 0];
        foreach (self::ALL_STATUSES as $st) {
            $total[strtolower($st)] = 0;
        }
        foreach ($out as $r) {
            foreach (array_keys($total) as $k) {
                if ($k !== 'id' && $k !== 'name') {
                    $total[$k] += $r[$k];
                }
            }
        }
        return ['level' => $level, 'rows' => $out, 'total' => $this->withRates($total)];
    }

    /** Performance des agents du périmètre : affectées, remises, validées, invalidées, dernière soumission. */
    public function agents(array $actor, int $campaignId): array
    {
        $this->campaign($campaignId);
        [$scopeSql, $params] = $actor['scope']->communeClause('s.commune_id');
        return array_map(static fn(array $r) => [
            'matricule' => $r['matricule'], 'name' => $r['name'], 'assigned' => (int) $r['assigned'], 'collected' => (int) $r['collected'],
            'validated' => (int) $r['validated'], 'invalidated' => (int) $r['invalidated'], 'pending' => (int) $r['pending'],
            'remaining' => (int) $r['assigned'] - (int) $r['collected'],
            'completion_rate' => (int) $r['assigned'] > 0 ? round(100 * (int) $r['collected'] / (int) $r['assigned'], 1) : null,
            'invalidation_rate' => (int) $r['collected'] > 0 ? round(100 * (int) $r['invalidated'] / (int) $r['collected'], 1) : null,
            'last_submitted_at' => $r['last_submitted_at'],
        ], $this->db->fetchAll(
            "SELECT u.matricule, CONCAT(u.first_name, ' ', u.last_name) AS name, COUNT(*) AS assigned, COUNT(col.id) AS collected,
                    SUM(col.status = 'VALIDEE') AS validated, SUM(col.status = 'INVALIDEE') AS invalidated,
                    SUM(col.status IN ('SOUMISE', 'EN_CONTROLE')) AS pending, MAX(col.last_submitted_at) AS last_submitted_at
               FROM assignments a JOIN campaign_structures cs ON cs.id = a.campaign_structure_id JOIN structures s ON s.id = cs.structure_id
               JOIN users u ON u.id = a.agent_id LEFT JOIN collections col ON col.campaign_structure_id = cs.id
              WHERE a.ended_at IS NULL AND cs.campaign_id = ? AND $scopeSql
              GROUP BY u.id, u.matricule, u.first_name, u.last_name ORDER BY name", [$campaignId, ...$params]));
    }

    /** Évolution des premières soumissions et des validations par jour ou par semaine (lundi). */
    public function timeline(array $actor, int $campaignId, string $granularity): array
    {
        $this->campaign($campaignId);
        if (!in_array($granularity, ['day', 'week'], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Granularité : day ou week.');
        }
        [$scopeSql, $params] = $actor['scope']->communeClause('c.commune_id');
        $series = [];
        foreach (['submitted' => 'c.first_submitted_at', 'validated' => 'c.validated_at'] as $key => $col) {
            $bucket = $granularity === 'day' ? "DATE($col)" : "DATE(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY))";
            foreach ($this->db->fetchAll("SELECT $bucket AS period, COUNT(*) AS n FROM collections c
                                           WHERE c.campaign_id = ? AND $col IS NOT NULL AND $scopeSql GROUP BY period ORDER BY period",
                [$campaignId, ...$params]) as $r) {
                $series[$r['period']][$key] = (int) $r['n'];
            }
        }
        ksort($series);
        $out = [];
        $cumS = $cumV = 0;
        foreach ($series as $period => $v) {
            $cumS += $v['submitted'] ?? 0;
            $cumV += $v['validated'] ?? 0;
            $out[] = ['period' => $period, 'submitted' => $v['submitted'] ?? 0, 'validated' => $v['validated'] ?? 0,
                'submitted_cumulative' => $cumS, 'validated_cumulative' => $cumV];
        }
        return ['granularity' => $granularity, 'series' => $out];
    }

    // ===================================================================== Indicateurs du questionnaire

    /**
     * Indicateurs agrégés d'une campagne dans le périmètre de l'appelant.
     * $groupBy : null, 'region' ou 'department' (tableau par unité territoriale en plus).
     */
    public function indicators(array $actor, int $campaignId, ?string $basis, ?string $groupBy = null): array
    {
        $campaign = $this->campaign($campaignId);
        $basis ??= $this->defaultBasis();
        if (!isset(self::BASIS[$basis])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Base : validated ou submitted.');
        }
        [$where, $params] = $this->collectionFilter($actor, $campaignId, $basis);
        $def = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?',
            [$campaign['questionnaire_version_id']]), true);

        $one = fn(string $sql) => $this->db->fetchOne($sql, $params) ?? [];
        $all = fn(string $sql) => $this->db->fetchAll($sql, $params);
        $int = static fn($v) => (int) ($v ?? 0);

        $structures = $int($this->db->fetchValue("SELECT COUNT(*) FROM collections c WHERE $where", $params));
        $byType = $all("SELECT t.code, t.label, COUNT(*) AS n FROM collections c JOIN structures s ON s.id = c.structure_id JOIN structure_types t ON t.id = s.structure_type_id
                         WHERE $where GROUP BY t.code, t.label, t.sort ORDER BY t.sort");

        // Zone (1.7) et statut juridique (1.8) tels que collectés, avec les libellés du questionnaire.
        $optionLabels = [];
        foreach ($def['sections'] ?? [] as $section) {
            foreach ($section['items'] as $item) {
                if (in_array($item['code'], ['Q1_7', 'Q1_8'], true)) {
                    $optionLabels[$item['code']] = array_column($item['options'] ?? [], 'label', 'code');
                }
            }
        }
        $answerDistribution = function (string $code) use ($where, $params, $optionLabels): array {
            $rows = $this->db->fetchAll("SELECT ca.option_code AS code, COUNT(*) AS n FROM collection_answers ca JOIN collections c ON c.id = ca.collection_id
                                          WHERE $where AND ca.question_code = ? GROUP BY ca.option_code ORDER BY ca.option_code", [...$params, $code]);
            return array_map(static fn($r) => ['code' => $r['code'], 'label' => $optionLabels[$code][$r['code']] ?? $r['code'], 'count' => (int) $r['n']], $rows);
        };

        $promoM = '(ct.p1_m + ct.p2_m + ct.p3_m)';
        $promoF = '(ct.p1_f + ct.p2_f + ct.p3_f)';
        $app = $one("SELECT SUM($promoM) AS m, SUM($promoF) AS f FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id WHERE $where");
        $appByDuration = $all("SELECT ct.duration_class AS k, SUM($promoM) AS m, SUM($promoF) AS f FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id
                                WHERE $where GROUP BY ct.duration_class ORDER BY FIELD(ct.duration_class, 'LE3', 'M4_9', 'GT9')");
        $appBySector = $all("SELECT se.id AS k, se.label, SUM($promoM) AS m, SUM($promoF) AS f FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id
                              JOIN sectors se ON se.id = ct.sector_id WHERE $where GROUP BY se.id, se.label ORDER BY se.id");
        // Regroupement sur une table dérivée : MySQL 8 (ONLY_FULL_GROUP_BY) refuse GROUP BY COALESCE(tr.label, …)
        // (« 'tr.label' isn't in GROUP BY »), contrairement à MariaDB.
        $topTrades = $all("SELECT x.label, SUM(x.n) AS total, COUNT(DISTINCT x.collection_id) AS structures
                             FROM (SELECT COALESCE(tr.label, CONCAT('Autre : ', ct.trade_other_label)) AS label, $promoM + $promoF AS n, ct.collection_id
                                     FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id LEFT JOIN trades tr ON tr.id = ct.trade_id
                                    WHERE $where) x
                            GROUP BY x.label ORDER BY total DESC LIMIT 15");
        $vulnerable = $all("SELECT css.category AS k, SUM(css.p1_m + css.p2_m + css.p3_m) AS m, SUM(css.p1_f + css.p2_f + css.p3_f) AS f
                              FROM collection_sector_specials css JOIN collections c ON c.id = css.collection_id WHERE $where
                             GROUP BY css.category ORDER BY FIELD(css.category, 'HANDICAPES', 'REFUGIES', 'RETOURNES', 'DEPLACES')");
        $trainers = $one("SELECT COUNT(*) AS total, SUM(t.sex = 'M') AS m, SUM(t.sex = 'F') AS f, SUM(t.grade = 'MAITRE_ARTISAN') AS maitres,
                                 SUM(t.grade = 'FORMATEUR') AS formateurs, SUM(t.certified = 1) AS certified, SUM(t.initial_training = 1) AS initial_training,
                                 SUM(t.pedagogical_followup = 1) AS followup, SUM(t.nationality = 'NIGERIEN') AS nigeriens,
                                 SUM(t.nationality = 'UEMOA') AS uemoa, SUM(t.nationality = 'AUTRE') AS autres
                            FROM collection_trainers t JOIN collections c ON c.id = t.collection_id WHERE $where");
        $accompanied = $all("SELECT ca.measure AS k, SUM(ca.male) AS m, SUM(ca.female) AS f FROM collection_accompanied ca JOIN collections c ON c.id = ca.collection_id
                              WHERE $where GROUP BY ca.measure ORDER BY FIELD(ca.measure, 'INSCRITS', 'PLACES', 'OUTILLES')");

        // Questions Oui/Non et à choix multiple : proportions et effectifs, avec les libellés du questionnaire.
        $yesno = [];
        $multi = [];
        foreach ($def['sections'] ?? [] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['type'] === 'yesno' && !str_contains($item['code'], '.') && !str_starts_with($item['code'], 'Q5')) {
                    $yesno[$item['code']] = ['number' => $item['number'] ?? '', 'label' => $item['label']];
                }
                if ($item['type'] === 'multi' && isset($item['options'])) {
                    $multi[$item['code']] = ['number' => $item['number'] ?? '', 'label' => $item['label'], 'options' => array_column($item['options'], 'label', 'code')];
                }
            }
        }
        $yesnoRows = [];
        if ($yesno !== []) {
            $codes = array_keys($yesno);
            $counts = $this->db->fetchAll("SELECT ca.question_code, COUNT(*) AS answered, SUM(ca.value_number = 1) AS yes
                                             FROM collection_answers ca JOIN collections c ON c.id = ca.collection_id
                                            WHERE $where AND ca.question_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ')
                                            GROUP BY ca.question_code', [...$params, ...$codes]);
            foreach ($counts as $r) {
                $q = $yesno[$r['question_code']];
                $yesnoRows[] = ['code' => $r['question_code'], 'number' => $q['number'], 'label' => $q['label'], 'answered' => (int) $r['answered'],
                    'yes' => (int) $r['yes'], 'rate' => (int) $r['answered'] > 0 ? round(100 * (int) $r['yes'] / (int) $r['answered'], 1) : null];
            }
            usort($yesnoRows, static fn($a, $b) => strnatcmp($a['code'], $b['code']));
        }
        $multiRows = [];
        foreach ($multi as $code => $q) {
            $rows = $this->db->fetchAll("SELECT ca.option_code, COUNT(*) AS n FROM collection_answers ca JOIN collections c ON c.id = ca.collection_id
                                          WHERE $where AND ca.question_code = ? GROUP BY ca.option_code ORDER BY n DESC", [...$params, $code]);
            if ($rows !== []) {
                $multiRows[] = ['code' => $code, 'number' => $q['number'], 'label' => $q['label'], 'options' => array_map(static fn($r) => [
                    'code' => $r['option_code'], 'label' => $q['options'][$r['option_code']] ?? $r['option_code'], 'structures' => (int) $r['n'],
                    'rate' => $structures > 0 ? round(100 * (int) $r['n'] / $structures, 1) : null], $rows)];
            }
        }

        $appM = $int($app['m'] ?? 0);
        $appF = $int($app['f'] ?? 0);
        $trainersTotal = $int($trainers['total'] ?? 0);
        $sexRow = static fn(array $r, ?string $label = null) => ['key' => $r['k'] ?? null, 'label' => $label ?? ($r['label'] ?? $r['k'] ?? null),
            'male' => (int) ($r['m'] ?? 0), 'female' => (int) ($r['f'] ?? 0), 'total' => (int) ($r['m'] ?? 0) + (int) ($r['f'] ?? 0)];

        $out = [
            'campaign' => ['id' => (int) $campaign['id'], 'code' => $campaign['code'], 'name' => $campaign['name'], 'questionnaire_version' => $campaign['version_code']],
            'basis' => $basis,
            'structures' => ['total' => $structures, 'by_type' => array_map(static fn($r) => ['code' => $r['code'], 'label' => $r['label'], 'count' => (int) $r['n']], $byType),
                'by_zone' => $answerDistribution('Q1_7'), 'by_legal_status' => $answerDistribution('Q1_8')],
            'apprentices' => [
                'male' => $appM, 'female' => $appF, 'total' => $appM + $appF,
                'female_share' => $appM + $appF > 0 ? round(100 * $appF / ($appM + $appF), 1) : null,
                'by_duration' => array_map(static fn($r) => $sexRow($r, self::DURATION_LABELS[$r['k']] ?? $r['k']), $appByDuration),
                'by_sector' => array_map(static fn($r) => $sexRow($r), $appBySector),
                'top_trades' => array_map(static fn($r) => ['label' => $r['label'], 'total' => (int) $r['total'], 'structures' => (int) $r['structures']], $topTrades),
            ],
            'vulnerable' => array_map(static fn($r) => $sexRow($r, self::CATEGORY_LABELS[$r['k']] ?? $r['k']), $vulnerable),
            'trainers' => [
                'total' => $trainersTotal, 'male' => $int($trainers['m'] ?? 0), 'female' => $int($trainers['f'] ?? 0),
                'maitres_artisans' => $int($trainers['maitres'] ?? 0), 'formateurs' => $int($trainers['formateurs'] ?? 0),
                'certified' => $int($trainers['certified'] ?? 0), 'initial_training' => $int($trainers['initial_training'] ?? 0),
                'pedagogical_followup' => $int($trainers['followup'] ?? 0),
                'nationality' => ['NIGERIEN' => $int($trainers['nigeriens'] ?? 0), 'UEMOA' => $int($trainers['uemoa'] ?? 0), 'AUTRE' => $int($trainers['autres'] ?? 0)],
                'apprentices_per_trainer' => $trainersTotal > 0 ? round(($appM + $appF) / $trainersTotal, 1) : null,
            ],
            'accompanied' => array_map(static fn($r) => $sexRow($r, self::MEASURE_LABELS[$r['k']] ?? $r['k']), $accompanied),
            'yes_no' => $yesnoRows,
            'multiple_choice' => $multiRows,
        ];
        if ($groupBy !== null) {
            $out['by_territory'] = $this->indicatorsByTerritory($where, $params, $groupBy);
        }
        return $out;
    }

    /** Comparaison pluriannuelle : indicateurs clés de plusieurs campagnes, avec signalement des différences de version. */
    public function compare(array $actor, array $campaignIds, ?string $basis): array
    {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        if (count($campaignIds) < 2 || count($campaignIds) > 6) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Comparer de 2 à 6 campagnes (campaign_ids=3,7).');
        }
        $items = array_map(fn(int $id) => $this->indicators($actor, $id, $basis), $campaignIds);
        $versions = array_values(array_unique(array_map(static fn($i) => $i['campaign']['questionnaire_version'], $items)));
        $notes = [];
        if (count($versions) > 1) {
            $notes[] = 'Versions de questionnaire différentes (' . implode(', ', $versions) . ') : seuls les indicateurs communs sont comparables ; '
                . 'les correspondances de variables (variable_mappings) doivent être validées par la DSD.';
        }
        $notes[] = "Les structures ciblées peuvent différer d'une campagne à l'autre : comparer aussi la couverture (avancement).";
        $key = static fn(array $i) => [
            'campaign' => $i['campaign'],
            'structures' => $i['structures']['total'],
            'apprentices' => $i['apprentices']['total'], 'apprentices_female' => $i['apprentices']['female'],
            'female_share' => $i['apprentices']['female_share'],
            'trainers' => $i['trainers']['total'], 'apprentices_per_trainer' => $i['trainers']['apprentices_per_trainer'],
            'accompanied_inscrits' => array_sum(array_column(array_filter($i['accompanied'], static fn($r) => $r['key'] === 'INSCRITS'), 'total')),
            'vulnerable' => array_sum(array_column($i['vulnerable'], 'total')),
        ];
        return ['basis' => $items[0]['basis'], 'comparable' => count($versions) === 1, 'notes' => $notes, 'campaigns' => array_map($key, $items)];
    }

    /**
     * Filtre SQL des collectes retenues (campagne, base, périmètre), à utiliser avec l'alias « c ».
     * @return array{0:string, 1:list<mixed>}
     */
    public function collectionFilter(array $actor, int $campaignId, string $basis): array
    {
        [$scopeSql, $scopeParams] = $actor['scope']->communeClause('c.commune_id');
        $statuses = self::BASIS[$basis];
        return ['c.campaign_id = ? AND c.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ") AND $scopeSql",
            [$campaignId, ...$statuses, ...$scopeParams]];
    }

    public function campaign(int $id): array
    {
        return $this->db->fetchOne('SELECT c.*, v.version_code FROM campaigns c JOIN questionnaire_versions v ON v.id = c.questionnaire_version_id WHERE c.id = ?', [$id])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
    }

    // ===================================================================== Interne

    private function indicatorsByTerritory(string $where, array $params, string $groupBy): array
    {
        $map = ['region' => ['c.region_id', 'r.name', 'JOIN regions r ON r.id = c.region_id'], 'department' => ['c.department_id', 'd.name', 'JOIN departments d ON d.id = c.department_id']];
        if (!isset($map[$groupBy])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Regroupement : region ou department.');
        }
        [$col, $name, $join] = $map[$groupBy];
        $rows = [];
        foreach ($this->db->fetchAll("SELECT $col AS id, $name AS name, COUNT(*) AS structures FROM collections c $join WHERE $where GROUP BY $col, $name ORDER BY $name", $params) as $r) {
            $rows[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => $r['name'], 'structures' => (int) $r['structures'],
                'apprentices_male' => 0, 'apprentices_female' => 0, 'apprentices' => 0, 'trainers' => 0];
        }
        foreach ($this->db->fetchAll("SELECT $col AS id, SUM(ct.p1_m + ct.p2_m + ct.p3_m) AS m, SUM(ct.p1_f + ct.p2_f + ct.p3_f) AS f
                                        FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id WHERE $where GROUP BY $col", $params) as $r) {
            if (isset($rows[(int) $r['id']])) {
                $rows[(int) $r['id']]['apprentices_male'] = (int) $r['m'];
                $rows[(int) $r['id']]['apprentices_female'] = (int) $r['f'];
                $rows[(int) $r['id']]['apprentices'] = (int) $r['m'] + (int) $r['f'];
            }
        }
        foreach ($this->db->fetchAll("SELECT $col AS id, COUNT(*) AS n FROM collection_trainers t JOIN collections c ON c.id = t.collection_id WHERE $where GROUP BY $col", $params) as $r) {
            if (isset($rows[(int) $r['id']])) {
                $rows[(int) $r['id']]['trainers'] = (int) $r['n'];
            }
        }
        return array_values($rows);
    }

    private function progressRow(array $r): array
    {
        $row = ['id' => (int) $r['id'], 'name' => $r['name'], 'targeted' => (int) $r['targeted'], 'assigned' => (int) $r['assigned'], 'collected' => (int) $r['collected']];
        foreach (self::ALL_STATUSES as $st) {
            $row[strtolower($st)] = (int) $r[strtolower($st)];
        }
        return $this->withRates($row);
    }

    private function withRates(array $row): array
    {
        $row['not_collected'] = $row['targeted'] - $row['collected'];
        $row['coverage_rate'] = $row['targeted'] > 0 ? round(100 * $row['collected'] / $row['targeted'], 1) : null;
        $row['validation_rate'] = $row['targeted'] > 0 ? round(100 * $row['validee'] / $row['targeted'], 1) : null;
        return $row;
    }
}
