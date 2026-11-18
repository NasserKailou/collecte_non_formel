<?php

declare(strict_types=1);

namespace App\Modules\Stats;

use App\Core\Database;
use App\Http\HttpException;

/**
 * Tableau de bord de pilotage d'une campagne (périmètre de l'utilisateur) : couverture, rythme réel / rythme requis,
 * date d'achèvement projetée, stock à contrôler et son ancienneté, délai de contrôle, qualité des données (refus au
 * premier contrôle et motifs, GPS, avertissements), canaux de saisie, activité des agents, couverture par région.
 *
 * Dates à l'heure du Niger (UTC+1) ; taux arrondis à 0,1 point.
 */
final class OverviewService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function overview(array $actor, int $campaignId): array
    {
        $c = $this->db->fetchOne('SELECT id, code, name, status, opens_on, closes_on, validation_levels FROM campaigns WHERE id = ?', [$campaignId])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
        [$sScope, $sParams] = $actor['scope']->communeClause('s.commune_id');
        [$cScope, $cParams] = $actor['scope']->communeClause('c.commune_id');
        $w = "c.campaign_id = ? AND $cScope";
        $p = [$campaignId, ...$cParams];
        $rate = static fn(int|float $num, int|float $den) => $den > 0 ? round(100 * $num / $den, 1) : null;

        // Cible et affectation.
        $t = $this->db->fetchOne(
            "SELECT COUNT(*) AS targeted, COUNT(a.id) AS assigned, COUNT(DISTINCT a.agent_id) AS agents
               FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id
               LEFT JOIN assignments a ON a.campaign_structure_id = cs.id AND a.ended_at IS NULL
              WHERE cs.campaign_id = ? AND $sScope", [$campaignId, ...$sParams]);
        $targeted = (int) $t['targeted'];

        // Collectes par état.
        $byStatus = array_fill_keys(['SOUMISE', 'EN_CONTROLE', 'VALIDEE', 'INVALIDEE', 'REOUVERTE'], 0);
        foreach ($this->db->fetchAll("SELECT c.status, COUNT(*) AS n FROM collections c WHERE $w GROUP BY c.status", $p) as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }
        $collected = array_sum($byStatus);
        $pending = $byStatus['SOUMISE'] + $byStatus['EN_CONTROLE'];

        // Rythme : 30 derniers jours, 7 derniers jours ; projection de la fin de collecte au rythme récent.
        $tz = new \DateTimeZone('Africa/Niamey');
        $today = new \DateTimeImmutable('today', $tz);
        $daily = [];
        foreach ($this->db->fetchAll("SELECT DATE(c.first_submitted_at) AS d, COUNT(*) AS n FROM collections c
                                       WHERE $w AND c.first_submitted_at >= ? GROUP BY DATE(c.first_submitted_at)",
            [...$p, $today->modify('-29 days')->format('Y-m-d')]) as $r) {
            $daily[$r['d']] = (int) $r['n'];
        }
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->modify("-$i days")->format('Y-m-d');
            $series[] = ['date' => $d, 'submitted' => $daily[$d] ?? 0];
        }
        $last7 = array_sum(array_column(array_slice($series, -7), 'submitted'));
        $remaining = max(0, $targeted - $collected);
        $closes = new \DateTimeImmutable((string) $c['closes_on'], $tz);
        $daysLeft = max(0, (int) $today->diff($closes)->format('%r%a'));
        $perDay = round($last7 / 7, 1);
        $projected = $remaining === 0 ? $today->format('Y-m-d')
            : ($perDay > 0 ? $today->modify('+' . (int) ceil($remaining / $perDay) . ' days')->format('Y-m-d') : null);

        // Contrôle : stock et ancienneté, délai de validation, décisions et motifs de refus.
        $age = $this->db->fetchOne("SELECT MIN(c.last_submitted_at) AS oldest, AVG(TIMESTAMPDIFF(HOUR, c.last_submitted_at, NOW())) AS avg_h,
                                           SUM(c.last_submitted_at < NOW() - INTERVAL 3 DAY) AS over3
                                      FROM collections c WHERE $w AND c.status IN ('SOUMISE', 'EN_CONTROLE')", $p);
        $delays = array_map('floatval', array_column($this->db->fetchAll(
            "SELECT TIMESTAMPDIFF(MINUTE, c.first_submitted_at, c.validated_at) / 60 AS h FROM collections c
              WHERE $w AND c.status = 'VALIDEE' AND c.validated_at IS NOT NULL ORDER BY h", $p), 'h'));
        $decisions = ['VALIDEE' => 0, 'INVALIDEE' => 0, 'REOUVERTURE' => 0];
        foreach ($this->db->fetchAll("SELECT r.decision, COUNT(*) AS n FROM collection_reviews r JOIN collections c ON c.id = r.collection_id
                                       WHERE $w GROUP BY r.decision", $p) as $r) {
            $decisions[$r['decision']] = (int) $r['n'];
        }
        $reasons = array_map(static fn($r) => ['label' => $r['label'], 'count' => (int) $r['n']], $this->db->fetchAll(
            "SELECT COALESCE(rr.label, 'Motif non précisé') AS label, COUNT(*) AS n FROM collection_reviews r JOIN collections c ON c.id = r.collection_id
               LEFT JOIN rejection_reasons rr ON rr.id = r.reason_id WHERE $w AND r.decision = 'INVALIDEE' GROUP BY label ORDER BY n DESC", $p));
        // Qualité au premier contrôle : part des collectes dont la première décision est un refus.
        $firstPass = $this->db->fetchOne(
            "SELECT COUNT(*) AS decided, SUM(r.decision = 'INVALIDEE') AS refused FROM collection_reviews r JOIN collections c ON c.id = r.collection_id
              WHERE $w AND r.id = (SELECT MIN(r2.id) FROM collection_reviews r2 WHERE r2.collection_id = r.collection_id AND r2.decision IN ('VALIDEE', 'INVALIDEE'))", $p);

        // Qualité des données.
        $accWarn = (float) ($this->db->fetchValue("SELECT value FROM system_settings WHERE setting_key = 'gps_accuracy_warn_m'") ?? 50);
        $q = $this->db->fetchOne(
            "SELECT AVG(c.warnings_count) AS avg_warn, SUM(c.warnings_count > 0) AS with_warn, SUM(c.paper_form_signed = 1) AS signed,
                    AVG(CASE WHEN c.gps_status = 'OK' THEN c.gps_accuracy_m END) AS avg_acc,
                    SUM(c.gps_status = 'OK' AND c.gps_accuracy_m <= ?) AS precise
               FROM collections c WHERE $w", [$accWarn, ...$p]);
        $gps = array_fill_keys(['OK', 'MANUEL', 'INDISPONIBLE', 'REFUSE'], 0);
        foreach ($this->db->fetchAll("SELECT c.gps_status, COUNT(*) AS n FROM collections c WHERE $w GROUP BY c.gps_status", $p) as $r) {
            $gps[$r['gps_status']] = (int) $r['n'];
        }
        $channels = array_fill_keys(['MOBILE', 'WEB', 'PAPIER'], 0);
        foreach ($this->db->fetchAll("SELECT c.channel, COUNT(*) AS n FROM collections c WHERE $w GROUP BY c.channel", $p) as $r) {
            $channels[$r['channel']] = (int) $r['n'];
        }
        $activeAgents = (int) $this->db->fetchValue("SELECT COUNT(DISTINCT c.agent_id) FROM collections c WHERE $w AND c.last_submitted_at >= NOW() - INTERVAL 7 DAY", $p);

        // Couverture par région.
        $regions = array_map(static fn($r) => [
            'name' => $r['name'], 'targeted' => (int) $r['targeted'], 'collected' => (int) $r['collected'], 'validated' => (int) $r['validated'],
            'coverage_rate' => $rate((int) $r['collected'], (int) $r['targeted']), 'validation_rate' => $rate((int) $r['validated'], (int) $r['targeted']),
        ], $this->db->fetchAll(
            "SELECT rg.name, COUNT(cs.id) AS targeted, COUNT(col.id) AS collected, COALESCE(SUM(col.status = 'VALIDEE'), 0) AS validated
               FROM campaign_structures cs JOIN structures s ON s.id = cs.structure_id JOIN communes co ON co.id = s.commune_id
               JOIN departments d ON d.id = co.department_id JOIN regions rg ON rg.id = d.region_id
               LEFT JOIN collections col ON col.campaign_structure_id = cs.id
              WHERE cs.campaign_id = ? AND $sScope GROUP BY rg.id, rg.name ORDER BY rg.name", [$campaignId, ...$sParams]));

        $median = static function (array $v): ?float {
            $n = count($v);
            return $n === 0 ? null : round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
        };

        return [
            'campaign' => ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'status' => $c['status'], 'opens_on' => $c['opens_on'],
                'closes_on' => $c['closes_on'], 'validation_levels' => (int) $c['validation_levels']],
            'today' => $today->format('Y-m-d'),
            'targeted' => $targeted, 'assigned' => (int) $t['assigned'], 'unassigned' => $targeted - (int) $t['assigned'],
            'collected' => $collected, 'by_status' => $byStatus, 'pending' => $pending, 'to_fix' => $byStatus['INVALIDEE'] + $byStatus['REOUVERTE'],
            'coverage_rate' => $rate($collected, $targeted), 'validated_rate' => $rate($byStatus['VALIDEE'], $targeted),
            'validated_share' => $rate($byStatus['VALIDEE'], $collected),
            'pace' => [
                'today' => end($series)['submitted'], 'last7' => $last7, 'per_day_7' => $perDay, 'remaining' => $remaining, 'days_left' => $daysLeft,
                'required_per_day' => $daysLeft > 0 ? round($remaining / $daysLeft, 2) : (float) $remaining,
                'projected_end' => $projected, 'on_track' => $projected !== null && $projected <= $c['closes_on'],
                'daily' => $series,
            ],
            'review' => [
                'pending' => $pending, 'oldest_pending_at' => $age['oldest'], 'pending_avg_hours' => $age['avg_h'] === null ? null : round((float) $age['avg_h'], 1),
                'pending_over_3_days' => (int) ($age['over3'] ?? 0),
                'validation_delay_median_h' => $median($delays), 'validation_delay_avg_h' => $delays === [] ? null : round(array_sum($delays) / count($delays), 1),
                'decisions' => $decisions,
                'first_pass_rejection_rate' => $rate((int) ($firstPass['refused'] ?? 0), (int) ($firstPass['decided'] ?? 0)),
                'rejection_reasons' => $reasons,
            ],
            'quality' => [
                'avg_warnings' => $q['avg_warn'] === null ? null : round((float) $q['avg_warn'], 2), 'with_warnings_rate' => $rate((int) $q['with_warn'], $collected),
                'paper_signed_rate' => $rate((int) $q['signed'], $collected),
                'gps' => $gps, 'gps_located_rate' => $rate($gps['OK'] + $gps['MANUEL'], $collected),
                'gps_avg_accuracy_m' => $q['avg_acc'] === null ? null : round((float) $q['avg_acc'], 1), 'gps_precise_rate' => $rate((int) $q['precise'], $gps['OK']),
                'gps_accuracy_threshold_m' => $accWarn,
            ],
            'channels' => $channels,
            'agents' => ['assigned' => (int) $t['agents'], 'active_7_days' => $activeAgents],
            'regions' => $regions,
        ];
    }
}
