<?php

declare(strict_types=1);

namespace App\Modules\Stats;

use App\Core\Database;

/**
 * Tableaux croisés « prêts pour l'annuaire statistique » (modèle proposé, à valider par la DSD), calculés sur
 * le même ensemble de collectes que l'export (campagne, base, périmètre).
 *
 * Chaque tableau porte les trois niveaux territoriaux en colonnes (Région, Département, Commune) : une ligne par
 * commune, puis « Ensemble du département » après chaque département, « Ensemble de la région » après chaque
 * région, et la ligne « Total » finale. Toutes les communes ayant au moins une collecte figurent dans tous les
 * tableaux (zéros compris), dans le même ordre, pour permettre la comparaison d'un tableau à l'autre.
 */
final class AnnuaireTables
{
    public const GEO_HEADER = ['Région', 'Département', 'Commune'];
    public const DEPT_TOTAL = 'Ensemble du département';
    public const REGION_TOTAL = 'Ensemble de la région';
    private const SECTORS = [1 => 'Primaire', 2 => 'Secondaire', 3 => 'Tertiaire'];

    /** @var list<array{int, string, string, string}> [commune_id, région, département, commune], triés par noms */
    private array $geo = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** Ligne de sous-total ou de total (mise en gras à l'export). */
    public static function isTotalRow(array $row): bool
    {
        return ($row[0] ?? null) === 'Total' || in_array($row[2] ?? null, [self::DEPT_TOTAL, self::REGION_TOTAL], true)
            || ($row[1] ?? null) === self::REGION_TOTAL;
    }

    /** @return list<array{sheet:string, title:string, header:list<string>, rows:list<list<mixed>>}> */
    public function build(string $where, array $params, array $def): array
    {
        $q = fn(string $sql, array $extra = []) => $this->db->fetchAll($sql, [...$params, ...$extra]);
        $this->geo = array_map(static fn($r) => [(int) $r['cid'], $r['region'], $r['department'], $r['commune']],
            $q("SELECT DISTINCT c.commune_id AS cid, r.name AS region, d.name AS department, cm.name AS commune
                  FROM collections c JOIN regions r ON r.id = c.region_id JOIN departments d ON d.id = c.department_id
                  JOIN communes cm ON cm.id = c.commune_id WHERE $where ORDER BY r.name, d.name, cm.name"));
        $promoM = '(ct.p1_m + ct.p2_m + ct.p3_m)';
        $promoF = '(ct.p1_f + ct.p2_f + ct.p3_f)';
        $tables = [];

        // T01 — Centres par type de structure
        $types = array_column($this->db->fetchAll('SELECT id, label FROM structure_types WHERE active = 1 ORDER BY sort'), 'label', 'id');
        $v = $this->pivotVectors($q("SELECT c.commune_id AS cid, s.structure_type_id AS k, COUNT(*) AS n FROM collections c
                                        JOIN structures s ON s.id = c.structure_id WHERE $where GROUP BY c.commune_id, s.structure_type_id"), array_keys($types), true);
        $tables[] = $this->geoTable('T01_centres_type', 'Centres recensés par type de structure', [...array_values($types), 'Total'], $v);

        // T02 — Centres par statut et zone
        $v = [];
        foreach ($q("SELECT c.commune_id AS cid, SUM(s.legal_status = 'PUBLIC') AS pub, SUM(s.legal_status = 'PRIVE') AS priv,
                            SUM(s.zone = 'URBAINE') AS urb, SUM(s.zone = 'RURALE') AS rur, COUNT(*) AS total
                       FROM collections c JOIN structures s ON s.id = c.structure_id WHERE $where GROUP BY c.commune_id") as $r) {
            $v[(int) $r['cid']] = [(int) $r['pub'], (int) $r['priv'], (int) $r['urb'], (int) $r['rur'], (int) $r['total']];
        }
        $tables[] = $this->geoTable('T02_centres_statut_zone', 'Centres recensés par statut et par zone', ['Public', 'Privé', 'Urbaine', 'Rurale', 'Total'], $v);

        // T03 — Apprentis par sexe
        $v = [];
        foreach ($q("SELECT c.commune_id AS cid, COUNT(DISTINCT c.id) AS centres, SUM($promoM) AS m, SUM($promoF) AS f
                       FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id WHERE $where GROUP BY c.commune_id") as $r) {
            $v[(int) $r['cid']] = [(int) $r['centres'], (int) $r['m'], (int) $r['f'], (int) $r['m'] + (int) $r['f']];
        }
        $tables[] = $this->geoTable('T03_apprentis_sexe', 'Apprentis par sexe', ['Centres', 'Hommes', 'Femmes', 'Total'], $v,
            static fn(array $x) => [$x[3] > 0 ? round(100 * $x[2] / $x[3], 1) : null], ['% femmes']);

        // T04 / T05 — Apprentis par secteur, par durée de formation (H, F, total)
        $tables[] = $this->sexTable('T04_apprentis_secteur', 'Apprentis par secteur et par sexe', self::SECTORS,
            $q("SELECT c.commune_id AS cid, ct.sector_id AS k, SUM($promoM) AS m, SUM($promoF) AS f FROM collection_trades ct
                  JOIN collections c ON c.id = ct.collection_id WHERE $where GROUP BY c.commune_id, ct.sector_id"));
        $tables[] = $this->sexTable('T05_apprentis_duree', 'Apprentis par durée de formation et par sexe', StatsService::DURATION_LABELS,
            $q("SELECT c.commune_id AS cid, ct.duration_class AS k, SUM($promoM) AS m, SUM($promoF) AS f FROM collection_trades ct
                  JOIN collections c ON c.id = ct.collection_id WHERE $where GROUP BY c.commune_id, ct.duration_class"));

        // T06 — Apprentis par métier (une ligne par commune, secteur et métier)
        $tradeExpr = "COALESCE(tr.label, CONCAT('Autre : ', ct.trade_other_label))";
        $order = array_flip(array_column($this->geo, 0));
        $geoById = array_column($this->geo, null, 0);
        $trades = $q("SELECT c.commune_id AS cid, se.id AS sid, se.label AS sector, $tradeExpr AS trade, SUM($promoM) AS m, SUM($promoF) AS f, COUNT(DISTINCT c.id) AS centres
                        FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id JOIN sectors se ON se.id = ct.sector_id LEFT JOIN trades tr ON tr.id = ct.trade_id
                       WHERE $where GROUP BY c.commune_id, se.id, se.label, $tradeExpr");
        usort($trades, static fn($a, $b) => [$order[(int) $a['cid']] ?? PHP_INT_MAX, (int) $a['sid'], -((int) $a['m'] + (int) $a['f']), $a['trade']]
            <=> [$order[(int) $b['cid']] ?? PHP_INT_MAX, (int) $b['sid'], -((int) $b['m'] + (int) $b['f']), $b['trade']]);
        $rows = [];
        foreach ($trades as $r) {
            [, $reg, $dep, $com] = $geoById[(int) $r['cid']] ?? [0, '?', '?', '?'];
            $rows[] = [$reg, $dep, $com, $r['sector'], $r['trade'], (int) $r['centres'], (int) $r['m'], (int) $r['f'], (int) $r['m'] + (int) $r['f']];
        }
        if ($rows !== []) {
            $total = ['Total', '', '', '', ''];
            for ($c = 5; $c < 9; $c++) {
                $total[] = array_sum(array_column($rows, $c));
            }
            $rows[] = $total;
        }
        $tables[] = ['sheet' => 'T06_apprentis_metier', 'title' => 'Apprentis par métier et par sexe',
            'header' => [...self::GEO_HEADER, 'Secteur', 'Métier', 'Centres', 'Hommes', 'Femmes', 'Total'], 'rows' => $rows];

        // T07 — Publics vulnérables (lignes « dont »)
        $tables[] = $this->sexTable('T07_vulnerables', 'Apprentis vulnérables (dont) par catégorie et par sexe', StatsService::CATEGORY_LABELS,
            $q("SELECT c.commune_id AS cid, css.category AS k, SUM(css.p1_m + css.p2_m + css.p3_m) AS m, SUM(css.p1_f + css.p2_f + css.p3_f) AS f
                  FROM collection_sector_specials css JOIN collections c ON c.id = css.collection_id WHERE $where GROUP BY c.commune_id, css.category"));

        // T08 — Maîtres artisans et formateurs (le ratio est recalculé à chaque niveau, jamais additionné)
        $v = [];
        foreach ($q("SELECT c.commune_id AS cid, SUM(t.sex = 'M') AS m, SUM(t.sex = 'F') AS f, SUM(t.grade = 'MAITRE_ARTISAN') AS ma, SUM(t.grade = 'FORMATEUR') AS fo,
                            SUM(t.certified = 1) AS cert, SUM(t.pedagogical_followup = 1) AS suivi
                       FROM collection_trainers t JOIN collections c ON c.id = t.collection_id WHERE $where GROUP BY c.commune_id") as $r) {
            $v[(int) $r['cid']] = [(int) $r['m'], (int) $r['f'], (int) $r['m'] + (int) $r['f'], (int) $r['ma'], (int) $r['fo'], (int) $r['cert'], (int) $r['suivi'], 0];
        }
        foreach ($q("SELECT c.commune_id AS cid, SUM($promoM + $promoF) AS n FROM collection_trades ct JOIN collections c ON c.id = ct.collection_id
                      WHERE $where GROUP BY c.commune_id") as $r) {
            $v[(int) $r['cid']] ??= array_fill(0, 8, 0);
            $v[(int) $r['cid']][7] = (int) $r['n'];
        }
        $tables[] = $this->geoTable('T08_formateurs', 'Maîtres artisans et formateurs',
            ['Hommes', 'Femmes', 'Total', 'Maîtres artisans', 'Formateurs', 'Certifiés', 'Suivi pédagogique', 'Apprentis'], $v,
            static fn(array $x) => [$x[2] > 0 ? round($x[7] / $x[2], 1) : null], ['Apprentis par formateur']);

        // T09 — Jeunes accompagnés (question 2.2)
        $tables[] = $this->sexTable('T09_jeunes_accompagnes', 'Jeunes accompagnés par mesure et par sexe', StatsService::MEASURE_LABELS,
            $q("SELECT c.commune_id AS cid, ca.measure AS k, SUM(ca.male) AS m, SUM(ca.female) AS f FROM collection_accompanied ca
                  JOIN collections c ON c.id = ca.collection_id WHERE $where GROUP BY c.commune_id, ca.measure"));

        // T10 — Équipements et services ; T11 — Sources de financement
        $yesno = [];
        $funding = null;
        foreach ($def['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if ($item['type'] === 'yesno' && str_starts_with($item['code'], 'Q2_')) {
                    $yesno[$item['code']] = trim(($item['number'] ?? '') . ' ' . $item['label']);
                }
                if ($item['code'] === 'Q1_10') {
                    $funding = $item;
                }
            }
        }
        if ($yesno !== []) {
            $codes = array_keys($yesno);
            $v = [];
            foreach ($q("SELECT c.commune_id AS cid, COUNT(*) AS n FROM collections c WHERE $where GROUP BY c.commune_id") as $r) {
                $v[(int) $r['cid']] = [(int) $r['n'], ...array_fill(0, count($codes), 0)];
            }
            $pos = array_flip($codes);
            foreach ($q("SELECT c.commune_id AS cid, ca.question_code AS k, SUM(ca.value_number = 1) AS yes FROM collection_answers ca
                          JOIN collections c ON c.id = ca.collection_id
                         WHERE $where AND ca.question_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ') GROUP BY c.commune_id, ca.question_code', $codes) as $r) {
                $v[(int) $r['cid']][1 + $pos[$r['k']]] = (int) $r['yes'];
            }
            $tables[] = $this->geoTable('T10_equipements', 'Équipements et services : nombre de centres répondant « Oui » (sur le nombre de centres recensés)',
                ['Centres recensés', ...array_values($yesno)], $v);
        }
        if ($funding !== null) {
            $labels = array_column($funding['options'] ?? [], 'label', 'code');
            $v = $this->pivotVectors($q("SELECT c.commune_id AS cid, ca.option_code AS k, COUNT(*) AS n FROM collection_answers ca JOIN collections c ON c.id = ca.collection_id
                                            WHERE $where AND ca.question_code = 'Q1_10' GROUP BY c.commune_id, ca.option_code"), array_keys($labels), false);
            $tables[] = $this->geoTable('T11_financement', 'Centres par source de financement (réponses multiples : un centre peut figurer dans plusieurs colonnes)',
                array_values($labels), $v);
        }
        return $tables;
    }

    /** Vecteurs par commune à partir de lignes {cid, k, n} : une colonne par clé de $keys (+ total si demandé). */
    private function pivotVectors(array $data, array $keys, bool $withTotal): array
    {
        $pos = array_flip(array_map('strval', $keys));
        $v = [];
        foreach ($data as $r) {
            $cid = (int) $r['cid'];
            $v[$cid] ??= array_fill(0, count($keys) + ($withTotal ? 1 : 0), 0);
            if (isset($pos[(string) $r['k']])) {
                $v[$cid][$pos[(string) $r['k']]] += (int) $r['n'];
            }
            if ($withTotal) {
                $v[$cid][count($keys)] += (int) $r['n'];
            }
        }
        return $v;
    }

    /** Tableau « modalité — Hommes / Femmes / Total » puis totaux, à partir de lignes {cid, k, m, f}. */
    private function sexTable(string $sheet, string $title, array $labels, array $data): array
    {
        $keys = array_map('strval', array_keys($labels));
        $pos = array_flip($keys);
        $n = count($keys);
        $v = [];
        foreach ($data as $r) {
            $cid = (int) $r['cid'];
            $v[$cid] ??= array_fill(0, 3 * ($n + 1), 0);
            $m = (int) $r['m'];
            $f = (int) $r['f'];
            if (isset($pos[(string) $r['k']])) {
                $i = 3 * $pos[(string) $r['k']];
                $v[$cid][$i] += $m;
                $v[$cid][$i + 1] += $f;
                $v[$cid][$i + 2] += $m + $f;
            }
            $v[$cid][3 * $n] += $m;
            $v[$cid][3 * $n + 1] += $f;
            $v[$cid][3 * $n + 2] += $m + $f;
        }
        $header = [];
        foreach ($labels as $label) {
            array_push($header, "$label — Hommes", "$label — Femmes", "$label — Total");
        }
        array_push($header, 'Total — Hommes', 'Total — Femmes', 'Total');
        return $this->geoTable($sheet, $title, $header, $v);
    }

    /**
     * Mise en forme territoriale : lignes communes, sous-totaux département et région, total général.
     * $vectors : commune_id => valeurs additives ; $derived : colonnes calculées (taux, ratios) à partir des
     * valeurs agrégées de la ligne, donc correctes à chaque niveau.
     */
    private function geoTable(string $sheet, string $title, array $valueHeaders, array $vectors, ?callable $derived = null, array $derivedHeaders = []): array
    {
        $zero = array_fill(0, count($valueHeaders), 0);
        $add = static fn(array $a, array $b) => array_map(static fn($x, $y) => $x + $y, $a, $b);
        $line = static fn(array $labels, array $vals) => [...$labels, ...$vals, ...($derived ? $derived($vals) : [])];
        $rows = [];
        $total = $regSum = $depSum = $zero;
        $reg = $dep = null;
        foreach ($this->geo as [$cid, $r, $d, $c]) {
            if ($dep !== null && ($d !== $dep || $r !== $reg)) {
                $rows[] = $line([$reg, $dep, self::DEPT_TOTAL], $depSum);
                $depSum = $zero;
            }
            if ($reg !== null && $r !== $reg) {
                $rows[] = $line([$reg, self::REGION_TOTAL, ''], $regSum);
                $regSum = $zero;
            }
            $vals = array_values($vectors[$cid] ?? $zero);
            $rows[] = $line([$r, $d, $c], $vals);
            $depSum = $add($depSum, $vals);
            $regSum = $add($regSum, $vals);
            $total = $add($total, $vals);
            [$reg, $dep] = [$r, $d];
        }
        if ($this->geo !== []) {
            $rows[] = $line([$reg, $dep, self::DEPT_TOTAL], $depSum);
            $rows[] = $line([$reg, self::REGION_TOTAL, ''], $regSum);
            $rows[] = $line(['Total', '', ''], $total);
        }
        return ['sheet' => $sheet, 'title' => $title, 'header' => [...self::GEO_HEADER, ...$valueHeaders, ...$derivedHeaders], 'rows' => $rows];
    }
}
