<?php

declare(strict_types=1);

namespace App\Modules\Stats;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Text;
use App\Core\XlsxStreamWriter;
use App\Http\HttpException;
use App\Modules\Users\Scope;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Exports (docs/api/README.md §6) : tâches de fond (table jobs), produisant un fichier téléchargeable par
 * son seul demandeur pendant une durée limitée. Le périmètre territorial du demandeur est appliqué au moment
 * du traitement (recalculé depuis la base : un compte désactivé entre-temps n'obtient rien).
 *
 * Jeux de données :
 * - collections : microdonnées (une ligne par collecte + feuilles formateurs, métiers, « dont », jeunes
 *   accompagnés, dictionnaire). Les données personnelles des répondants (noms et téléphones de la section V)
 *   et les noms des formateurs sont exclus : les exports servent à la statistique, pas à l'annuaire.
 * - indicators : tableaux d'indicateurs (Excel ou rapport PDF) ;
 * - progress : couverture par région, département ou commune ;
 * - agents : performance des agents.
 */
final class ExportService
{
    public const DATASETS = [
        'collections' => ['xlsx', 'csv'],
        'indicators' => ['xlsx', 'pdf'],
        'progress' => ['xlsx', 'csv', 'pdf'],
        'agents' => ['xlsx', 'csv'],
    ];
    private const DATASET_LABELS = ['collections' => 'Microdonnées des collectes', 'indicators' => 'Indicateurs', 'progress' => 'Avancement', 'agents' => 'Performance des agents'];
    private const MIME = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf'];
    /** Types exportés : « phone » (téléphones des répondants) volontairement absent. */
    private const SIMPLE_TYPES = ['text', 'yesno', 'integer', 'date', 'single', 'multi', 'geo'];
    private const PROMOS = ['p1_m', 'p1_f', 'p2_m', 'p2_f', 'p3_m', 'p3_f'];

    private readonly StatsService $stats;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db, private readonly array $config)
    {
        $this->stats = new StatsService($db);
        $this->audit = new Audit($db);
    }

    // ===================================================================== Demande, suivi, téléchargement

    public function request(int $userId, array $in, string $ip): array
    {
        $dataset = is_string($in['dataset'] ?? null) ? $in['dataset'] : '';
        $format = is_string($in['format'] ?? null) ? $in['format'] : '';
        if (!isset(self::DATASETS[$dataset])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Jeu de données : ' . implode(', ', array_keys(self::DATASETS)) . '.');
        }
        if (!in_array($format, self::DATASETS[$dataset], true)) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Formats possibles pour « $dataset » : " . implode(', ', self::DATASETS[$dataset]) . '.');
        }
        $f = is_array($in['filters'] ?? null) ? $in['filters'] : [];
        $campaignId = isset($f['campaign_id']) && (is_int($f['campaign_id']) || ctype_digit((string) $f['campaign_id'])) ? (int) $f['campaign_id'] : 0;
        $this->stats->campaign($campaignId); // 404 si inconnue
        $filters = ['campaign_id' => $campaignId];
        if (isset($f['basis'])) {
            if (!is_string($f['basis']) || !isset(StatsService::BASIS[$f['basis']])) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Base : validated ou submitted.');
            }
            $filters['basis'] = $f['basis'];
        }
        if (isset($f['level'])) {
            if (!in_array($f['level'], ['region', 'department', 'commune'], true)) {
                throw new HttpException(422, 'VALIDATION_FAILED', 'Niveau : region, department ou commune.');
            }
            $filters['level'] = $f['level'];
        }
        $payload = ['user_id' => $userId, 'dataset' => $dataset, 'format' => $format, 'filters' => $filters];
        $id = $this->db->insert("INSERT INTO jobs (type, payload, created_by) VALUES ('EXPORT', ?, ?)",
            [json_encode($payload, JSON_THROW_ON_ERROR), $userId]);
        $this->audit->log($userId, 'EXPORT_REQUEST', 'job', (string) $id, null, $payload, null, $ip);
        if ($this->config['exports']['inline']) {
            $this->process($id);
        }
        return $this->job($userId, $id);
    }

    public function job(int $userId, int $id): array
    {
        $j = $this->db->fetchOne("SELECT j.*, f.uuid AS file_uuid, f.original_name, f.size_bytes, f.expires_at FROM jobs j LEFT JOIN files f ON f.id = j.result_file_id
                                   WHERE j.id = ? AND j.created_by = ? AND j.type = 'EXPORT'", [$id, $userId]);
        if ($j === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Export introuvable.');
        }
        return $this->presentJob($j);
    }

    /** @return list<array> derniers exports de l'utilisateur */
    public function myJobs(int $userId): array
    {
        return array_map([$this, 'presentJob'], $this->db->fetchAll(
            "SELECT j.*, f.uuid AS file_uuid, f.original_name, f.size_bytes, f.expires_at FROM jobs j LEFT JOIN files f ON f.id = j.result_file_id
              WHERE j.created_by = ? AND j.type = 'EXPORT' ORDER BY j.id DESC LIMIT 30", [$userId]));
    }

    /** @return array{0:string, 1:string, 2:string} chemin, nom, type MIME */
    public function file(int $userId, string $uuid, string $ip): array
    {
        $f = $this->db->fetchOne("SELECT * FROM files WHERE uuid = ? AND owner_id = ? AND purpose = 'EXPORT'", [$uuid, $userId]);
        $path = $f === null ? null : $this->config['paths']['storage'] . '/' . $f['stored_path'];
        if ($f === null || !is_file($path)) {
            throw new HttpException(404, 'NOT_FOUND', 'Fichier introuvable.');
        }
        if ($f['expires_at'] !== null && strtotime($f['expires_at']) <= time()) {
            @unlink($path);
            throw new HttpException(410, 'GONE', "Ce fichier a expiré : relancer l'export.");
        }
        $this->audit->log($userId, 'EXPORT_DOWNLOAD', 'file', $uuid, null, null, null, $ip);
        return [$path, $f['original_name'], $f['mime_type']];
    }

    // ===================================================================== Traitement (worker ou en ligne)

    /** Traite les tâches en attente (au plus $max). @return int nombre de tâches traitées */
    public function runPending(int $max = 20): int
    {
        $n = 0;
        foreach ($this->db->fetchAll("SELECT id FROM jobs WHERE type = 'EXPORT' AND status = 'EN_ATTENTE' AND available_at <= NOW() ORDER BY id LIMIT " . max(1, $max)) as $j) {
            if ($this->process((int) $j['id'])) {
                $n++;
            }
        }
        return $n;
    }

    /** Traite une tâche. Prise exclusive (mise à jour conditionnelle) : deux workers ne la traitent jamais deux fois. */
    public function process(int $jobId): bool
    {
        if ($this->db->execute("UPDATE jobs SET status = 'EN_COURS', started_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status = 'EN_ATTENTE'", [$jobId]) !== 1) {
            return false;
        }
        $job = $this->db->fetchOne('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        $p = json_decode((string) $job['payload'], true);
        $relative = null;
        try {
            $actor = $this->actor((int) $p['user_id']);
            $campaign = $this->stats->campaign((int) $p['filters']['campaign_id']);
            $name = sprintf('export-%s-%s-%s.%s', $p['dataset'], $campaign['code'], date('Ymd-His'), $p['format']);
            $relative = 'exports/' . Text::uuid4() . '.' . $p['format'];
            $abs = $this->config['paths']['storage'] . '/' . $relative;
            if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0770, true) && !is_dir(dirname($abs))) {
                throw new \RuntimeException('Dossier des exports inaccessible.');
            }
            @set_time_limit(0);
            $rows = match ($p['dataset']) {
                'collections' => $this->exportCollections($actor, $campaign, $p['filters']['basis'] ?? $this->stats->defaultBasis(), $p['format'], $abs),
                'indicators' => $this->exportIndicators($actor, $campaign, $p['filters']['basis'] ?? null, $p['format'], $abs),
                'progress' => $this->exportProgress($actor, $campaign, $p['filters']['level'] ?? 'region', $p['format'], $abs),
                'agents' => $this->exportAgents($actor, $campaign, $p['format'], $abs),
            };
            $this->db->transaction(function (Database $db) use ($jobId, $p, $name, $relative, $abs, $rows): void {
                $fileId = $db->insert("INSERT INTO files (uuid, owner_id, purpose, original_name, stored_path, mime_type, size_bytes, sha256, expires_at)
                                        VALUES (?, ?, 'EXPORT', ?, ?, ?, ?, ?, NOW() + INTERVAL ? DAY)",
                    [Text::uuid4(), $p['user_id'], $name, $relative, self::MIME[$p['format']], filesize($abs), hash_file('sha256', $abs), (int) $this->config['exports']['ttl_days']]);
                $db->execute("UPDATE jobs SET status = 'TERMINE', progress = 100, finished_at = NOW(), result_file_id = ?, result_json = ? WHERE id = ?",
                    [$fileId, json_encode(['rows' => $rows]), $jobId]);
            });
        } catch (\Throwable $e) {
            if ($relative !== null) {
                @unlink($this->config['paths']['storage'] . '/' . $relative);
            }
            $message = $e instanceof HttpException ? $e->getMessage() : 'Erreur pendant la production du fichier : ' . $e->getMessage();
            $this->db->execute("UPDATE jobs SET status = 'ECHEC', finished_at = NOW(), error = ? WHERE id = ?", [mb_substr($message, 0, 2000), $jobId]);
        }
        return true;
    }

    /** Supprime les fichiers expirés (exports, archives d'identifiants). @return int nombre de fichiers supprimés */
    public function purgeExpired(): int
    {
        $n = 0;
        foreach ($this->db->fetchAll("SELECT stored_path FROM files WHERE expires_at IS NOT NULL AND expires_at <= NOW() AND purpose IN ('EXPORT', 'REPORT')") as $f) {
            $path = $this->config['paths']['storage'] . '/' . $f['stored_path'];
            if (is_file($path) && @unlink($path)) {
                $n++;
            }
        }
        return $n;
    }

    // ===================================================================== Jeux de données

    /** Variables simples exportées, dans l'ordre du questionnaire (sans noms ni téléphones des répondants). */
    private function exportableItems(array $def): array
    {
        $items = [];
        foreach ($def['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (in_array($item['type'], self::SIMPLE_TYPES, true) && !str_contains($item['code'], '.') && preg_match('/_NOM$/', $item['code']) !== 1) {
                    $items[$item['code']] = $item;
                }
            }
        }
        return $items;
    }

    private function exportCollections(array $actor, array $campaign, string $basis, string $format, string $path): int
    {
        $def = json_decode((string) $this->db->fetchValue('SELECT definition_json FROM questionnaire_versions WHERE id = ?', [$campaign['questionnaire_version_id']]), true);
        // Variables du questionnaire en clair ; identité et localisation (déjà en colonnes fixes) non répétées.
        $items = array_filter($this->exportableItems($def), static fn($i) => !in_array($i['code'], ['Q1_1', 'Q1_2'], true) && $i['type'] !== 'geo');
        $computed = $def['computed'] ?? [];
        $fixed = ['Identifiant de la collecte', 'Statut de la collecte', 'Région', 'Département', 'Commune', 'Village / quartier', 'Code du centre', 'Nom du centre',
            'Type de structure', 'Statut juridique (registre)', 'Zone (registre)', 'Agent (matricule)', 'Date de première soumission', 'Date de validation',
            'Latitude GPS', 'Longitude GPS', 'Précision GPS (m)', "Nombre d'avertissements",
            'Canal de saisie', 'N° de la fiche papier', "Date de l'entretien (fiche papier)", 'Enquêteur (fiche papier)'];
        $header = $fixed;
        foreach ($items as $item) {
            $header[] = self::clearName($item);
        }
        foreach ($computed as $c) {
            $header[] = self::computedName($c['code'], $c['label'] ?? null);
        }
        $header = self::uniqueHeaders($header);

        $sink = $this->sink($format, $path);
        if ($format === 'xlsx') {
            $sink->addSheet('donnees_generale_centre', $header, 'donnees_generale_centre', 22);
        } else {
            $sink->addSheet('donnees_generale_centre', $header);
        }
        [$where, $params] = $this->stats->collectionFilter($actor, (int) $campaign['id'], $basis);
        $ids = array_map('intval', array_column($this->db->fetchAll("SELECT c.id FROM collections c WHERE $where ORDER BY c.region_id, c.department_id, c.commune_id, c.id", $params), 'id'));
        $statusLabels = ['SOUMISE' => 'Soumise', 'EN_CONTROLE' => 'En contrôle', 'VALIDEE' => 'Validée', 'INVALIDEE' => 'Invalidée', 'REOUVERTE' => 'Rouverte'];

        $total = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            $answers = [];
            foreach ($this->db->fetchAll("SELECT collection_id, question_code, option_code, value_text, value_number, value_date FROM collection_answers WHERE collection_id IN ($in)") as $a) {
                $answers[(int) $a['collection_id']][$a['question_code']][] = $a;
            }
            foreach ($this->db->fetchAll(
                "SELECT c.*, r.name AS region, d.name AS department, co.name AS commune, s.official_code, s.name AS structure_name, t.label AS type_label,
                        s.legal_status, s.zone, u.matricule
                   FROM collections c JOIN regions r ON r.id = c.region_id JOIN departments d ON d.id = c.department_id JOIN communes co ON co.id = c.commune_id
                   JOIN structures s ON s.id = c.structure_id JOIN structure_types t ON t.id = s.structure_type_id JOIN users u ON u.id = c.agent_id
                  WHERE c.id IN ($in) ORDER BY c.region_id, c.department_id, c.commune_id, c.id") as $c) {
                $a = $answers[(int) $c['id']] ?? [];
                $row = [$c['uuid'], $statusLabels[$c['status']] ?? $c['status'], $c['region'], $c['department'], $c['commune'], $c['locality_label'],
                    $c['official_code'], $c['structure_name'], $c['type_label'], ['PUBLIC' => 'Public', 'PRIVE' => 'Privé'][$c['legal_status']] ?? null,
                    ['URBAINE' => 'Urbaine', 'RURALE' => 'Rurale'][$c['zone']] ?? null, $c['matricule'], $c['first_submitted_at'], $c['validated_at'],
                    self::num($c['latitude']), self::num($c['longitude']), self::num($c['gps_accuracy_m']), (int) $c['warnings_count'],
                    ['MOBILE' => 'Tablette', 'WEB' => 'Saisie web directe', 'PAPIER' => 'Fiche papier saisie'][$c['channel']] ?? $c['channel'],
                    $c['paper_form_ref'], $c['paper_interview_date'], $c['paper_enumerator']];
                foreach ($items as $code => $item) {
                    $row[] = self::clearValue($item, $a[$code] ?? []);
                }
                foreach ($computed as $comp) {
                    $row[] = self::num($a[$comp['code']][0]['value_number'] ?? null);
                }
                $sink->addRow($row);
                $total++;
            }
        }

        if ($format === 'xlsx') {
            // Tableaux croisés prêts pour l'annuaire.
            foreach ((new AnnuaireTables($this->db))->build($where, $params, $def) as $t) {
                $sink->addSheet($t['sheet'], $t['header'], null, 16);
                foreach ($t['rows'] as $r) {
                    $sink->addRow($r, AnnuaireTables::isTotalRow($r));
                }
            }
            $this->detailSheets($sink, $ids, $def, $items);
            $this->readmeSheet($sink, $campaign, $basis, $total);
        }
        $sink->close();
        return $total;
    }

    /** Feuilles annexes des microdonnées (Excel uniquement), en clair, et dictionnaire des variables. */
    private function detailSheets(object $sink, array $ids, array $def, array $items): void
    {
        $ref = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->fetchAll('SELECT c.id, c.uuid, s.official_code, s.name FROM collections c JOIN structures s ON s.id = c.structure_id WHERE c.id IN (' . implode(',', $chunk) . ')') as $r) {
                $ref[(int) $r['id']] = [$r['uuid'], $r['official_code'], $r['name']];
            }
        }
        $yes = static fn($v) => (int) $v === 1 ? 'Oui' : 'Non';
        $sex = static fn($v) => $v === 'M' ? 'Homme' : 'Femme';
        $promoHeader = ['Promotion 1 — Hommes', 'Promotion 1 — Femmes', 'Promotion 2 — Hommes', 'Promotion 2 — Femmes', 'Promotion 3 — Hommes', 'Promotion 3 — Femmes'];
        $keys = ['Identifiant de la collecte', 'Code du centre', 'Nom du centre'];
        $sheets = [
            ['formateurs', [...$keys, 'N° de ligne', 'Sexe', 'Grade', 'Formation initiale', 'Nombre de formations continues', 'Certifié', 'Intitulé du certificat',
                'Niveau du certificat', 'Organisme certificateur', 'Année du certificat', 'Nationalité', 'Suivi pédagogique'],
                'SELECT collection_id, line_no, sex, grade, initial_training, continuing_trainings_count, certified, cert_name, cert_level, cert_body, cert_year, nationality, pedagogical_followup
                   FROM collection_trainers WHERE collection_id IN (%s) ORDER BY collection_id, line_no',
                static fn($r) => [$r['line_no'], $sex($r['sex']), $r['grade'] === 'MAITRE_ARTISAN' ? 'Maître artisan' : 'Formateur', $yes($r['initial_training']),
                    (int) $r['continuing_trainings_count'], $yes($r['certified']), $r['cert_name'], $r['cert_level'], $r['cert_body'], $r['cert_year'],
                    ['NIGERIEN' => 'Nigérien', 'UEMOA' => 'UEMOA', 'AUTRE' => 'Autre'][$r['nationality']] ?? $r['nationality'], $yes($r['pedagogical_followup'])]],
            ['metiers_apprentis', [...$keys, 'Secteur', 'N° de ligne', 'Métier', 'Durée de la formation', ...$promoHeader, 'Total apprentis'],
                "SELECT ct.collection_id, se.label AS sector, ct.line_no, COALESCE(tr.label, CONCAT('Autre : ', ct.trade_other_label)) AS trade, ct.duration_class,
                        ct.p1_m, ct.p1_f, ct.p2_m, ct.p2_f, ct.p3_m, ct.p3_f
                   FROM collection_trades ct JOIN sectors se ON se.id = ct.sector_id LEFT JOIN trades tr ON tr.id = ct.trade_id
                  WHERE ct.collection_id IN (%s) ORDER BY ct.collection_id, ct.sector_id, ct.line_no",
                static fn($r) => [$r['sector'], $r['line_no'], $r['trade'], StatsService::DURATION_LABELS[$r['duration_class']] ?? $r['duration_class'],
                    (int) $r['p1_m'], (int) $r['p1_f'], (int) $r['p2_m'], (int) $r['p2_f'], (int) $r['p3_m'], (int) $r['p3_f'],
                    (int) $r['p1_m'] + (int) $r['p1_f'] + (int) $r['p2_m'] + (int) $r['p2_f'] + (int) $r['p3_m'] + (int) $r['p3_f']]],
            ['apprentis_vulnerables', [...$keys, 'Secteur', 'Catégorie', ...$promoHeader, 'Total'],
                'SELECT css.collection_id, se.label AS sector, css.category, css.p1_m, css.p1_f, css.p2_m, css.p2_f, css.p3_m, css.p3_f
                   FROM collection_sector_specials css JOIN sectors se ON se.id = css.sector_id WHERE css.collection_id IN (%s) ORDER BY css.collection_id, css.sector_id, css.category',
                static fn($r) => [$r['sector'], StatsService::CATEGORY_LABELS[$r['category']] ?? $r['category'], (int) $r['p1_m'], (int) $r['p1_f'], (int) $r['p2_m'],
                    (int) $r['p2_f'], (int) $r['p3_m'], (int) $r['p3_f'], (int) $r['p1_m'] + (int) $r['p1_f'] + (int) $r['p2_m'] + (int) $r['p2_f'] + (int) $r['p3_m'] + (int) $r['p3_f']]],
            ['jeunes_accompagnes', [...$keys, 'Durée de la formation', 'Mesure', 'Hommes', 'Femmes', 'Total'],
                'SELECT collection_id, duration_class, measure, male, female FROM collection_accompanied WHERE collection_id IN (%s) ORDER BY collection_id, duration_class, measure',
                static fn($r) => [StatsService::DURATION_LABELS[$r['duration_class']] ?? $r['duration_class'], StatsService::MEASURE_LABELS[$r['measure']] ?? $r['measure'],
                    (int) $r['male'], (int) $r['female'], (int) $r['male'] + (int) $r['female']]],
        ];
        foreach ($sheets as [$title, $header, $sql, $map]) {
            $sink->addSheet($title, $header, $title, 18);
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->db->fetchAll(sprintf($sql, implode(',', $chunk))) as $r) {
                    $sink->addRow([...($ref[(int) $r['collection_id']] ?? [$r['collection_id'], null, null]), ...$map($r)]);
                }
            }
        }
        $sink->addSheet('dictionnaire_variables', ['Colonne (feuille donnees_generale_centre)', 'Code de la variable', 'Question', 'Type', 'Modalités'], null, 40);
        foreach ($items as $item) {
            $sink->addRow([self::clearName($item), $item['code'], $item['number'] ?? '', $item['type'],
                isset($item['options']) ? implode(' ; ', array_map(static fn($o) => $o['code'] . ' = ' . $o['label'], $item['options'])) : '']);
        }
        foreach ($def['computed'] ?? [] as $c) {
            $sink->addRow([self::computedName($c['code'], $c['label'] ?? null), $c['code'], '', 'calculée', '']);
        }
    }

    private function readmeSheet(object $sink, array $campaign, string $basis, int $rows): void
    {
        $sink->addSheet('lisez_moi', ['Information', 'Valeur'], null, 60);
        foreach ([
            ['Campagne', $campaign['code'] . ' — ' . $campaign['name']],
            ['Base', $basis === 'validated' ? 'Collectes validées uniquement' : "Collectes soumises et validées (une partie n'est pas encore contrôlée)"],
            ['Centres', $rows], ['Produit le', date('d/m/Y à H:i')],
            ['donnees_generale_centre', 'Une ligne par centre, variables en clair. Tableau Excel : Insertion › Tableau croisé dynamique pour vos propres croisements.'],
            ['T01 à T11', "Tableaux croisés prêts pour l'annuaire (modèle proposé, à valider par la DSD). Chaque tableau donne Région, Département et Commune : une ligne par commune, "
                . "« Ensemble du département » et « Ensemble de la région » (en gras) après chaque niveau, puis « Total ». Pour ne garder qu'un niveau, filtrer la colonne Commune "
                . "(ex. sur « Ensemble du département ») ou Département (« Ensemble de la région »)."],
            ['formateurs, metiers_apprentis, apprentis_vulnerables, jeunes_accompagnes', 'Détail par centre, reliable à donnees_generale_centre par « Identifiant de la collecte » ou « Code du centre ».'],
            ['dictionnaire_variables', 'Correspondance entre colonnes en clair, codes des variables et numéros des questions.'],
            ['Données personnelles', 'Exclues : noms et téléphones des répondants (section V), noms des formateurs.'],
        ] as $r) {
            $sink->addRow($r);
        }
    }

    /** Nom de colonne en clair : « 1.10 Source(s) de financement ». */
    private static function clearName(array $item): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $item['label'])));
        $name = trim(($item['number'] ?? '') . ' ' . $label);
        return mb_substr($name !== '' ? $name : $item['code'], 0, 200);
    }

    /** Nom en clair des variables calculées (Q2_1.LE3.s1.M → « 2.1 Apprentis ≤ 3 mois — Primaire — Hommes »). */
    private static function computedName(string $code, ?string $label): string
    {
        if ($label !== null && $label !== '') {
            return $label;
        }
        $sectors = ['s1' => 'Primaire', 's2' => 'Secondaire', 's3' => 'Tertiaire'];
        $sexes = ['M' => 'Hommes', 'F' => 'Femmes', 'T' => 'Total'];
        if (preg_match('/^Q2_1\.(\w+)\.(s\d)\.([MFT])$/', $code, $m)) {
            return '2.1 Apprentis ' . (StatsService::DURATION_LABELS[$m[1]] ?? $m[1]) . " — {$sectors[$m[2]]} — {$sexes[$m[3]]}";
        }
        // Version 2025.2 et suivantes : 2.1 est saisi ; la même ventilation recalculée depuis la Section IV sert au contrôle.
        if (preg_match('/^IV_DUR\.(\w+)\.(s\d)\.([MFT])$/', $code, $m)) {
            return 'IV Apprentis ' . (StatsService::DURATION_LABELS[$m[1]] ?? $m[1]) . " — {$sectors[$m[2]]} — {$sexes[$m[3]]} (calculé depuis la Section IV)";
        }
        if (preg_match('/^IV\.(s\d)\.total\.p(\d)_([mf])$/', $code, $m)) {
            return "IV Total {$sectors[$m[1]]} — promotion {$m[2]} — " . ($m[3] === 'm' ? 'Hommes' : 'Femmes');
        }
        if (preg_match('/^Q2_19\.([MFT])$/', $code, $m)) {
            return "2.19 Maîtres artisans / formateurs — {$sexes[$m[1]]}";
        }
        return match ($code) {
            'APPRENTICES_TOTAL' => 'Total apprentis (section IV)',
            'Q2_20_T' => '2.20 Jeunes accompagnés par le FAFPA — Total',
            'Q2_2_INSCRITS_TOTAL' => '2.2 Jeunes accompagnés inscrits — Total',
            default => $code,
        };
    }

    /** En-têtes uniques (exigence des tableaux Excel) : un doublon reçoit un suffixe. */
    private static function uniqueHeaders(array $header): array
    {
        $seen = [];
        foreach ($header as $i => $h) {
            $k = mb_strtolower($h);
            if (isset($seen[$k])) {
                $n = 2;
                while (isset($seen[mb_strtolower("$h ($n)")])) {
                    $n++;
                }
                $header[$i] = "$h ($n)";
                $k = mb_strtolower($header[$i]);
            }
            $seen[$k] = true;
        }
        return $header;
    }

    /** Valeur en clair : Oui/Non, libellé des modalités, plusieurs modalités séparées par « ; ». */
    private static function clearValue(array $item, array $rows): mixed
    {
        if ($rows === []) {
            return null;
        }
        $labels = array_column($item['options'] ?? [], 'label', 'code');
        return match ($item['type']) {
            'multi' => implode(' ; ', array_map(static fn($r) => $labels[$r['option_code']] ?? $r['option_code'], $rows)),
            'single' => $labels[$rows[0]['option_code']] ?? $rows[0]['option_code'],
            'yesno' => (int) $rows[0]['value_number'] === 1 ? 'Oui' : 'Non',
            'integer' => self::num($rows[0]['value_number']),
            'date' => $rows[0]['value_date'] ?? $rows[0]['value_text'],
            default => $rows[0]['value_text'],
        };
    }

    private function exportIndicators(array $actor, array $campaign, ?string $basis, string $format, string $path): int
    {
        $i = $this->stats->indicators($actor, (int) $campaign['id'], $basis, 'region');
        if ($format === 'pdf') {
            $this->pdf($path, $this->indicatorsHtml($i));
            return $i['structures']['total'];
        }
        $sink = $this->sink('xlsx', $path);
        $sink->addSheet('Synthèse', ['Indicateur', 'Valeur']);
        foreach ([
            ['Campagne', $campaign['code'] . ' — ' . $campaign['name']], ['Base', $i['basis'] === 'validated' ? 'Collectes validées' : 'Collectes soumises et validées'],
            ['Structures', $i['structures']['total']], ['Apprentis — total', $i['apprentices']['total']], ['Apprentis — hommes', $i['apprentices']['male']],
            ['Apprentis — femmes', $i['apprentices']['female']], ['Part des femmes (%)', $i['apprentices']['female_share']],
            ['Maîtres artisans / formateurs', $i['trainers']['total']], ['Apprentis par formateur', $i['trainers']['apprentices_per_trainer']],
            ['Formateurs certifiés', $i['trainers']['certified']], ['Produit le', date('d/m/Y H:i')],
        ] as $r) {
            $sink->addRow($r);
        }
        $sexSheet = static function (string $title, array $rows) use ($sink): void {
            $sink->addSheet($title, ['Catégorie', 'Hommes', 'Femmes', 'Total']);
            foreach ($rows as $r) {
                $sink->addRow([$r['label'], $r['male'], $r['female'], $r['total']]);
            }
        };
        $sexSheet('Apprentis par durée', $i['apprentices']['by_duration']);
        $sexSheet('Apprentis par secteur', $i['apprentices']['by_sector']);
        $sexSheet('Vulnérables', $i['vulnerable']);
        $sexSheet('Jeunes accompagnés', $i['accompanied']);
        $sink->addSheet('Métiers les plus suivis', ['Métier', 'Apprentis', 'Structures']);
        foreach ($i['apprentices']['top_trades'] as $r) {
            $sink->addRow([$r['label'], $r['total'], $r['structures']]);
        }
        $sink->addSheet('Formateurs', ['Indicateur', 'Valeur']);
        foreach (['total' => 'Total', 'male' => 'Hommes', 'female' => 'Femmes', 'maitres_artisans' => 'Maîtres artisans', 'formateurs' => 'Formateurs',
                     'certified' => 'Certifiés', 'initial_training' => 'Formation initiale', 'pedagogical_followup' => 'Suivi pédagogique'] as $k => $label) {
            $sink->addRow([$label, $i['trainers'][$k]]);
        }
        foreach ($i['trainers']['nationality'] as $k => $v) {
            $sink->addRow(['Nationalité ' . $k, $v]);
        }
        $sink->addSheet('Équipements (Oui-Non)', ['Question', 'Libellé', 'Réponses', 'Oui', '% Oui']);
        foreach ($i['yes_no'] as $r) {
            $sink->addRow([$r['number'] ?: $r['code'], $r['label'], $r['answered'], $r['yes'], $r['rate']]);
        }
        $sink->addSheet('Choix multiples', ['Question', 'Libellé', 'Modalité', 'Structures', '% des structures']);
        foreach ($i['multiple_choice'] as $q) {
            foreach ($q['options'] as $o) {
                $sink->addRow([$q['number'] ?: $q['code'], $q['label'], $o['label'], $o['structures'], $o['rate']]);
            }
        }
        $sink->addSheet('Par région', ['Région', 'Structures', 'Apprentis hommes', 'Apprentis femmes', 'Apprentis', 'Formateurs']);
        foreach ($i['by_territory'] as $r) {
            $sink->addRow([$r['name'], $r['structures'], $r['apprentices_male'], $r['apprentices_female'], $r['apprentices'], $r['trainers']]);
        }
        $sink->close();
        return $i['structures']['total'];
    }

    private function exportProgress(array $actor, array $campaign, string $level, string $format, string $path): int
    {
        $p = $this->stats->progress($actor, (int) $campaign['id'], $level);
        $header = ['Unité', 'Ciblées', 'Affectées', 'Collectées', 'Soumises', 'En contrôle', 'Validées', 'Invalidées', 'Rouvertes', 'Non collectées', 'Couverture (%)', 'Validation (%)'];
        $line = static fn(array $r) => [$r['name'], $r['targeted'], $r['assigned'], $r['collected'], $r['soumise'], $r['en_controle'], $r['validee'], $r['invalidee'],
            $r['reouverte'], $r['not_collected'], $r['coverage_rate'], $r['validation_rate']];
        if ($format === 'pdf') {
            $levelLabel = ['region' => 'région', 'department' => 'département', 'commune' => 'commune'][$level];
            $this->pdf($path, $this->reportHtml("Avancement par $levelLabel — " . $campaign['name'], $campaign['code'],
                $this->table($header, [...array_map($line, $p['rows']), $line($p['total'])], true)));
            return count($p['rows']);
        }
        $sink = $this->sink($format, $path);
        $sink->addSheet('Avancement', $header);
        foreach ($p['rows'] as $r) {
            $sink->addRow($line($r));
        }
        $sink->addRow($line($p['total']));
        $sink->close();
        return count($p['rows']);
    }

    private function exportAgents(array $actor, array $campaign, string $format, string $path): int
    {
        $rows = $this->stats->agents($actor, (int) $campaign['id']);
        $sink = $this->sink($format, $path);
        $sink->addSheet('Agents', ['Matricule', 'Nom', 'Affectées', 'Collectées', 'En attente de contrôle', 'Validées', 'Invalidées', 'Restantes', 'Réalisation (%)', 'Invalidation (%)', 'Dernière soumission']);
        foreach ($rows as $r) {
            $sink->addRow([$r['matricule'], $r['name'], $r['assigned'], $r['collected'], $r['pending'], $r['validated'], $r['invalidated'], $r['remaining'],
                $r['completion_rate'], $r['invalidation_rate'], $r['last_submitted_at']]);
        }
        $sink->close();
        return count($rows);
    }

    // ===================================================================== PDF

    private function indicatorsHtml(array $i): string
    {
        $sexTable = fn(array $rows) => $this->table(['', 'Hommes', 'Femmes', 'Total'], array_map(static fn($r) => [$r['label'], $r['male'], $r['female'], $r['total']], $rows));
        $t = $i['trainers'];
        $body = '<h2>Chiffres clés</h2>' . $this->table(['Indicateur', 'Valeur'], [
                ['Structures recensées', $i['structures']['total']], ['Apprentis', $i['apprentices']['total']],
                ['dont femmes', $i['apprentices']['female'] . ' (' . ($i['apprentices']['female_share'] ?? '—') . ' %)'],
                ['Maîtres artisans / formateurs', $t['total']], ['Apprentis par formateur', $t['apprentices_per_trainer'] ?? '—'], ['Formateurs certifiés', $t['certified']],
            ])
            . '<h2>Structures par type</h2>' . $this->table(['Type', 'Nombre'], array_map(static fn($r) => [$r['label'], $r['count']], $i['structures']['by_type']))
            . '<h2>Apprentis par durée de formation</h2>' . $sexTable($i['apprentices']['by_duration'])
            . '<h2>Apprentis par secteur</h2>' . $sexTable($i['apprentices']['by_sector'])
            . '<h2>Métiers les plus suivis</h2>' . $this->table(['Métier', 'Apprentis', 'Structures'], array_map(static fn($r) => [$r['label'], $r['total'], $r['structures']], $i['apprentices']['top_trades']))
            . '<h2>Publics vulnérables (dont)</h2>' . $sexTable($i['vulnerable'])
            . '<h2>Jeunes accompagnés</h2>' . $sexTable($i['accompanied'])
            . '<h2>Équipements et services</h2>' . $this->table(['Question', 'Réponses', 'Oui', '% Oui'],
                array_map(static fn($r) => [trim($r['number'] . ' ' . $r['label']), $r['answered'], $r['yes'], $r['rate'] ?? '—'], $i['yes_no']))
            . '<h2>Par région</h2>' . $this->table(['Région', 'Structures', 'Apprentis', 'Formateurs'],
                array_map(static fn($r) => [$r['name'], $r['structures'], $r['apprentices'], $r['trainers']], $i['by_territory'] ?? []));
        return $this->reportHtml('Indicateurs — ' . $i['campaign']['name'], $i['campaign']['code'], $body,
            $i['basis'] === 'validated' ? 'Base : collectes validées.' : 'Base : collectes soumises et validées (données en partie non contrôlées).');
    }

    private function reportHtml(string $title, string $campaignCode, string $body, string $note = ''): string
    {
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #222; }
            h1 { font-size: 15pt; color: #0d6b30; border-bottom: 3px solid #d35400; padding-bottom: 4px; }
            h2 { font-size: 11pt; color: #0d6b30; margin-top: 14px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
            th, td { border: 1px solid #bbb; padding: 3px 5px; } th { background: #eef4ef; text-align: left; }
            td.n { text-align: right; } tr.total td { font-weight: bold; background: #f7f7f7; }
            .meta { color: #666; font-size: 8pt; }
          </style></head><body>'
            . "<div class=\"meta\">République du Niger — Ministère de l'Enseignement et de la Formation Techniques et Professionnels — Recensement EFTP non formel</div>"
            . '<h1>' . $e($title) . '</h1><div class="meta">Campagne ' . $e($campaignCode) . ' — produit le ' . date('d/m/Y à H:i') . ($note !== '' ? ' — ' . $e($note) : '') . '</div>'
            . $body . '</body></html>';
    }

    private function table(array $header, array $rows, bool $lastIsTotal = false): string
    {
        $e = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $html = '<table><thead><tr>' . implode('', array_map(static fn($h) => '<th>' . $e($h) . '</th>', $header)) . '</tr></thead><tbody>';
        $last = count($rows) - 1;
        foreach (array_values($rows) as $idx => $r) {
            $html .= '<tr' . ($lastIsTotal && $idx === $last ? ' class="total"' : '') . '>'
                . implode('', array_map(static fn($v) => '<td' . (is_int($v) || is_float($v) ? ' class="n"' : '') . '>' . $e($v) . '</td>', $r)) . '</tr>';
        }
        return $html . ($rows === [] ? '<tr><td colspan="' . count($header) . '">Aucune donnée.</td></tr>' : '') . '</tbody></table>';
    }

    private function pdf(string $path, string $html): void
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false); // aucune ressource externe chargée
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        if (file_put_contents($path, $dompdf->output()) === false) {
            throw new \RuntimeException('Écriture du PDF impossible.');
        }
    }

    // ===================================================================== Interne

    /** Écrivain tabulaire : xlsx en flux, ou CSV (première feuille seulement, « ; », UTF-8 avec BOM). */
    private function sink(string $format, string $path): object
    {
        if ($format === 'xlsx') {
            return new XlsxStreamWriter($path);
        }
        return new class ($path) {
            /** @var resource|null */
            private $h = null;
            private bool $active = false;

            public function __construct(private readonly string $path)
            {
            }

            public function addSheet(string $name, array $header): void
            {
                if ($this->h === null) {
                    $this->h = fopen($this->path, 'wb');
                    fwrite($this->h, "\xEF\xBB\xBF");
                    $this->active = true;
                    $this->addRow($header);
                } else {
                    $this->active = false; // CSV : une seule table
                }
            }

            public function addRow(array $values): void
            {
                if ($this->active) {
                    // Neutralisation des formules (injection CSV) : un texte commençant par = + - @ est préfixé d'une apostrophe.
                    fputcsv($this->h, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, $values), ';', '"', '');
                }
            }

            public function close(): void
            {
                if ($this->h !== null) {
                    fclose($this->h);
                }
            }
        };
    }

    /** Contexte d'acteur recalculé depuis la base (rôles et périmètre actuels ; compte actif exigé). */
    private function actor(int $userId): array
    {
        $u = $this->db->fetchOne('SELECT id, status FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if ($u === null || $u['status'] !== 'ACTIF') {
            throw new HttpException(403, 'FORBIDDEN', 'Compte désactivé : export annulé.');
        }
        $roles = array_column($this->db->fetchAll('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$userId]), 'code');
        return ['id' => $userId, 'roles' => $roles, 'is_admin' => in_array('ADMIN', $roles, true), 'scope' => Scope::forUser($this->db, $userId)];
    }

    private function presentJob(array $j): array
    {
        $p = json_decode((string) $j['payload'], true);
        $result = json_decode((string) $j['result_json'], true);
        $expired = $j['expires_at'] !== null && strtotime($j['expires_at']) <= time();
        return [
            'id' => (int) $j['id'], 'status' => $j['status'], 'progress' => (int) $j['progress'],
            'dataset' => $p['dataset'], 'dataset_label' => self::DATASET_LABELS[$p['dataset']] ?? $p['dataset'], 'format' => $p['format'], 'filters' => $p['filters'],
            'rows' => $result['rows'] ?? null, 'error' => $j['error'], 'created_at' => $j['created_at'], 'finished_at' => $j['finished_at'],
            'file' => $j['file_uuid'] === null || $expired ? null : ['uuid' => $j['file_uuid'], 'name' => $j['original_name'], 'size' => (int) $j['size_bytes'],
                'expires_at' => $j['expires_at'], 'url' => '/api/v1/files/' . $j['file_uuid']],
        ];
    }

    private static function answerValue(string $type, array $rows): mixed
    {
        if ($rows === []) {
            return null;
        }
        return match ($type) {
            'multi' => implode('|', array_column($rows, 'option_code')),
            'single' => $rows[0]['option_code'],
            'yesno', 'integer', 'geo' => self::num($rows[0]['value_number']),
            'date' => $rows[0]['value_date'] ?? $rows[0]['value_text'],
            default => $rows[0]['value_text'],
        };
    }

    private static function num(mixed $v): int|float|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) $v;
        return floor($f) === $f && abs($f) < 1e15 ? (int) $f : $f;
    }
}
