<?php

declare(strict_types=1);

namespace App\Modules\Structures;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;

/**
 * Registre permanent des structures (docs/phase1/02 §2.3, UC-08).
 *
 * - Identité stable (UUID) + code officiel unique à 10 chiffres (obligatoire pour une structure ACTIVE).
 * - Détection de doublons : code identique (bloquant) ; même commune et nom proche, ou point GPS
 *   à moins de 100 m (alerte : la création est suspendue tant que l'utilisateur ne confirme pas).
 * - Structures découvertes sur le terrain : proposées par l'agent (idempotent par UUID), puis
 *   approuvées, rejetées ou fusionnées par un superviseur de la commune.
 * - Historique complet (structure_history) ; périmètre territorial appliqué à toute lecture et écriture.
 */
final class StructureService
{
    public const DUPLICATE_RADIUS_M = 100;
    /** Emprise du Niger (avec marge) : un point hors de ce cadre est une erreur de saisie. */
    private const NIGER_BOX = ['lat' => [11.3, 23.7], 'lng' => [0.0, 16.2]];
    private const EDITABLE = ['official_code', 'name', 'type_code', 'other_type_label', 'legal_status', 'zone', 'commune_id',
        'locality_label', 'latitude', 'longitude', 'gps_accuracy_m', 'authorization_date', 'opening_date'];

    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    // ===================================================================== Lecture

    /** @return array{0:list<array>, 1:int} */
    public function list(array $actor, array $f, array $page): array
    {
        [$scopeSql, $params] = $actor['scope']->communeClause('s.commune_id');
        $where = ['s.deleted_at IS NULL', $scopeSql];
        if (($q = $f['q'] ?? null) !== null) {
            $where[] = '(s.name_normalized LIKE ? OR s.official_code LIKE ?)';
            $params[] = '%' . addcslashes(Text::normalize($q), '%_\\') . '%';
            $params[] = addcslashes($q, '%_\\') . '%';
        }
        foreach (['commune_id' => 's.commune_id', 'department_id' => 'c.department_id', 'region_id' => 'd.region_id'] as $k => $col) {
            if (($v = $f[$k] ?? null) !== null) {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        if (($v = $f['status'] ?? null) !== null) {
            $where[] = 's.status = ?';
            $params[] = $v;
        }
        if (($v = $f['type_code'] ?? null) !== null) {
            $where[] = 't.code = ?';
            $params[] = $v;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM (' . $this->selectSql() . " WHERE $whereSql) x", $params);
        $rows = $this->db->fetchAll($this->selectSql() . " WHERE $whereSql ORDER BY r.name, d.name, c.name, s.name_normalized, s.id
                LIMIT {$page['per_page']} OFFSET {$page['offset']}", $params);
        return [array_map([$this, 'present'], $rows), $total];
    }

    public function get(array $actor, string $uuid): array
    {
        return $this->present($this->findVisible($actor, $uuid));
    }

    public function history(array $actor, string $uuid): array
    {
        $s = $this->findVisible($actor, $uuid);
        return array_map(static function (array $h): array {
            $h['changes'] = $h['changes_json'] === null ? null : json_decode($h['changes_json'], true);
            unset($h['changes_json']);
            return $h;
        }, $this->db->fetchAll(
            "SELECT h.action, h.changes_json, h.reason, h.created_at, u.matricule AS user_matricule, CONCAT(u.first_name, ' ', u.last_name) AS user_name
               FROM structure_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.structure_id = ? ORDER BY h.id", [$s['id']]));
    }

    /** Fiche par identifiant interne (sans contrôle de périmètre : appels déjà autorisés). */
    public function detail(int $id): array
    {
        return $this->present($this->db->fetchOne($this->selectSql() . ' WHERE s.id = ?', [$id]));
    }

    // ===================================================================== Création et modification

    /**
     * Création par un gestionnaire (structure ACTIVE, code officiel obligatoire).
     * Doublons probables ⇒ 409 DUPLICATE_SUSPECTED, sauf si confirm_duplicates = true.
     */
    public function create(array $actor, array $in, string $ip): array
    {
        $data = $this->validate($in, null, true);
        $this->assertCommuneInScope($actor, $data['commune_id']);
        $candidates = $this->duplicateCandidates($data, null);
        if ($candidates !== [] && ($in['confirm_duplicates'] ?? false) !== true) {
            throw new HttpException(409, 'DUPLICATE_SUSPECTED',
                "Une ou plusieurs structures semblables existent déjà. Vérifier, puis confirmer la création si ce n'est pas un doublon.",
                ['candidates' => $candidates]);
        }
        $id = $this->db->transaction(function () use ($data, $actor, $candidates, $ip): int {
            $id = $this->insert($data + ['status' => 'ACTIVE', 'uuid' => Text::uuid4(), 'created_by' => $actor['id'],
                    'approved_by' => $actor['id'], 'approved_at' => date('Y-m-d H:i:s')]);
            $this->logHistory($id, $actor['id'], 'CREATION', $this->publicFields($data),
                $candidates === [] ? null : 'Créée malgré ' . count($candidates) . ' doublon(s) potentiel(s) signalé(s)');
            $this->audit->log($actor['id'], 'STRUCTURE_CREATE', 'structure', (string) $id, null, $this->publicFields($data), null, $ip);
            return $id;
        });
        return $this->detail($id);
    }

    /**
     * Import Excel : contrôle d'une ligne avec les règles de la saisie manuelle (code facultatif).
     * @return array{0:?array, 1:list<array{field:string, message:string}>} données normalisées (null si invalide), erreurs
     */
    public function checkImportRow(array $in): array
    {
        try {
            return [$this->validate($in, null, false), []];
        } catch (HttpException $e) {
            return [null, $e->details['errors'] ?? [['field' => '', 'message' => $e->getMessage()]]];
        }
    }

    /**
     * Import Excel : création d'une structure déjà contrôlée (dans la transaction de l'import).
     * Avec code officiel : ACTIVE ; sans code : PROPOSEE (à codifier puis approuver depuis la fiche).
     */
    public function insertImported(array $actor, array $data, string $importUuid, ?string $note, string $ip): int
    {
        $active = $data['official_code'] !== null;
        $id = $this->insert($data + ['uuid' => Text::uuid4(), 'created_by' => $actor['id'], 'status' => $active ? 'ACTIVE' : 'PROPOSEE']
            + ($active ? ['approved_by' => $actor['id'], 'approved_at' => date('Y-m-d H:i:s')] : ['proposed_by' => $actor['id']]));
        $this->logHistory($id, $actor['id'], $active ? 'CREATION' : 'PROPOSITION', $this->publicFields($data),
            trim("Import Excel $importUuid" . ($note !== null ? " — $note" : '')));
        $this->audit->log($actor['id'], 'STRUCTURE_CREATE', 'structure', (string) $id, null, $this->publicFields($data) + ['import' => $importUuid], null, $ip);
        return $id;
    }

    /**
     * Proposition d'une structure découverte sur le terrain (agent). Idempotente : le même UUID renvoie
     * la même structure. Autorisée dans une commune de la zone de l'agent (périmètre ou zone de campagne en cours).
     * @return array{structure:array, possible_duplicates:list<array>, replayed:bool}
     */
    public function propose(array $actor, array $in, string $ip): array
    {
        $uuid = is_string($in['uuid'] ?? null) ? strtolower($in['uuid']) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw $this->validation([['field' => 'uuid', 'message' => "UUID v4 généré par l'application obligatoire."]]);
        }
        $existing = $this->db->fetchOne('SELECT id, proposed_by FROM structures WHERE uuid = ?', [$uuid]);
        if ($existing !== null) {
            if ((int) $existing['proposed_by'] !== $actor['id']) {
                throw new HttpException(409, 'CONFLICT', 'Cet identifiant de structure est déjà utilisé.');
            }
            $s = $this->detail((int) $existing['id']);
            return ['structure' => $s, 'possible_duplicates' => $this->duplicateCandidates($this->asData($s), (int) $existing['id']), 'replayed' => true];
        }
        if (isset($in['gps']) && is_array($in['gps'])) {
            $in += ['latitude' => $in['gps']['lat'] ?? null, 'longitude' => $in['gps']['lng'] ?? null, 'gps_accuracy_m' => $in['gps']['accuracy'] ?? null];
        }
        unset($in['official_code']); // la codification relève du superviseur, à l'approbation
        $data = $this->validate($in, null, false);
        if (!$this->agentWorksIn($actor, $data['commune_id'])) {
            throw new HttpException(403, 'OUT_OF_SCOPE', 'Vous ne pouvez proposer une structure que dans une commune de votre zone de travail.');
        }
        $candidates = $this->duplicateCandidates($data, null);
        $id = $this->db->transaction(function () use ($data, $uuid, $actor, $candidates, $ip): int {
            $id = $this->insert($data + ['status' => 'PROPOSEE', 'uuid' => $uuid, 'created_by' => $actor['id'], 'proposed_by' => $actor['id']]);
            $this->logHistory($id, $actor['id'], 'PROPOSITION', $this->publicFields($data),
                $candidates === [] ? null : count($candidates) . ' doublon(s) potentiel(s) signalé(s) au superviseur');
            $this->audit->log($actor['id'], 'STRUCTURE_PROPOSE', 'structure', (string) $id, null, $this->publicFields($data), null, $ip);
            return $id;
        });
        return ['structure' => $this->detail($id), 'possible_duplicates' => $candidates, 'replayed' => false];
    }

    public function update(array $actor, string $uuid, array $in, string $ip): array
    {
        $s = $this->findVisible($actor, $uuid);
        if (!in_array($s['status'], ['ACTIVE', 'PROPOSEE', 'FERMEE'], true)) {
            throw new HttpException(409, 'CONFLICT', 'Une structure rejetée ou fusionnée ne peut plus être modifiée.');
        }
        $current = $this->asData($this->present($s));
        $data = $this->validate(array_intersect_key($in, array_flip(self::EDITABLE)) + $current, (int) $s['id'], $s['status'] !== 'PROPOSEE');
        $this->assertCommuneInScope($actor, $data['commune_id']);
        $changes = [];
        foreach ($this->publicFields($data) as $k => $v) {
            if (($current[$k] ?? null) != $v) {
                $changes[$k] = ['avant' => $current[$k] ?? null, 'apres' => $v];
            }
        }
        if ($changes === []) {
            return $this->present($s);
        }
        if (isset($changes['commune_id']) && $this->hasCollections((int) $s['id'])) {
            throw new HttpException(409, 'CONFLICT', "La commune d'une structure déjà collectée ne peut pas être changée (statistiques territoriales).");
        }
        $reason = is_string($in['reason'] ?? null) ? mb_substr($in['reason'], 0, 500) : null;
        $this->db->transaction(function () use ($s, $data, $changes, $actor, $reason, $ip): void {
            $this->db->execute(
                'UPDATE structures SET official_code = ?, name = ?, name_normalized = ?, structure_type_id = ?, other_type_label = ?, legal_status = ?,
                        zone = ?, commune_id = ?, locality_id = ?, locality_label = ?, latitude = ?, longitude = ?, gps_accuracy_m = ?,
                        authorization_date = ?, opening_date = ? WHERE id = ?',
                [$data['official_code'], $data['name'], $data['name_normalized'], $data['structure_type_id'], $data['other_type_label'],
                    $data['legal_status'], $data['zone'], $data['commune_id'], $this->locality($data, $actor['id']), $data['locality_label'],
                    $data['latitude'], $data['longitude'], $data['gps_accuracy_m'], $data['authorization_date'], $data['opening_date'], $s['id']]);
            $this->logHistory((int) $s['id'], $actor['id'], 'MODIFICATION', $changes, $reason);
            $this->audit->log($actor['id'], 'STRUCTURE_UPDATE', 'structure', (string) $s['id'], null, $changes, $reason, $ip);
        });
        return $this->detail((int) $s['id']);
    }

    // ===================================================================== Décisions sur les propositions

    /** Approbation : la structure proposée devient ACTIVE et reçoit son code officiel. */
    public function approve(array $actor, string $uuid, array $in, string $ip): array
    {
        $s = $this->findVisible($actor, $uuid);
        if ($s['status'] !== 'PROPOSEE') {
            throw new HttpException(409, 'CONFLICT', 'Seule une structure proposée peut être approuvée.');
        }
        $errors = [];
        $code = $this->validateCode($in['official_code'] ?? $s['official_code'], true, (int) $s['id'], $errors);
        if ($errors !== []) {
            throw $this->validation($errors);
        }
        $comment = is_string($in['comment'] ?? null) ? mb_substr($in['comment'], 0, 500) : null;
        $this->db->transaction(function () use ($s, $code, $actor, $comment, $ip): void {
            $this->db->execute("UPDATE structures SET status = 'ACTIVE', official_code = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$code, $actor['id'], $s['id']]);
            $this->logHistory((int) $s['id'], $actor['id'], 'APPROBATION', ['official_code' => $code], $comment);
            $this->audit->log($actor['id'], 'STRUCTURE_APPROVE', 'structure', (string) $s['id'], null, ['official_code' => $code], null, $ip);
        });
        return $this->detail((int) $s['id']);
    }

    public function reject(array $actor, string $uuid, array $in, string $ip): array
    {
        $s = $this->findVisible($actor, $uuid);
        if ($s['status'] !== 'PROPOSEE') {
            throw new HttpException(409, 'CONFLICT', 'Seule une structure proposée peut être rejetée.');
        }
        $reason = $this->requiredReason($in);
        $this->db->transaction(function () use ($s, $reason, $actor, $ip): void {
            $this->db->execute("UPDATE structures SET status = 'REJETEE' WHERE id = ?", [$s['id']]);
            $this->logHistory((int) $s['id'], $actor['id'], 'REJET', null, $reason);
            $this->audit->log($actor['id'], 'STRUCTURE_REJECT', 'structure', (string) $s['id'], null, null, $reason, $ip);
        });
        return $this->detail((int) $s['id']);
    }

    /**
     * Fusion d'un doublon dans la structure de référence. Refusée si le doublon a déjà des collectes
     * ou figure dans une campagne non clôturée (le retirer d'abord du ciblage).
     */
    public function merge(array $actor, string $uuid, array $in, string $ip): array
    {
        $source = $this->findVisible($actor, $uuid);
        $target = $this->findVisible($actor, is_string($in['target_uuid'] ?? null) ? $in['target_uuid'] : '', 'target_uuid');
        $reason = $this->requiredReason($in);
        if ((int) $source['id'] === (int) $target['id']) {
            throw new HttpException(409, 'CONFLICT', 'Une structure ne peut pas être fusionnée avec elle-même.');
        }
        if (!in_array($source['status'], ['ACTIVE', 'PROPOSEE', 'FERMEE'], true) || $target['status'] !== 'ACTIVE') {
            throw new HttpException(409, 'CONFLICT', 'La fusion exige une structure de référence ACTIVE et un doublon ni fusionné ni rejeté.');
        }
        if ($this->hasCollections((int) $source['id'])) {
            throw new HttpException(409, 'CONFLICT', "Le doublon a déjà des collectes : la fusion doit être arbitrée au cas par cas (aucune donnée n'est déplacée automatiquement).");
        }
        $targeted = $this->db->fetchValue(
            "SELECT c.code FROM campaign_structures cs JOIN campaigns c ON c.id = cs.campaign_id
              WHERE cs.structure_id = ? AND c.status NOT IN ('CLOTUREE','ARCHIVEE') LIMIT 1", [$source['id']]);
        if ($targeted !== null) {
            throw new HttpException(409, 'CONFLICT', "Le doublon est ciblé par la campagne $targeted : le retirer d'abord du ciblage.");
        }
        $this->db->transaction(function () use ($source, $target, $reason, $actor, $ip): void {
            $this->db->execute("UPDATE structures SET status = 'FUSIONNEE', merged_into_id = ? WHERE id = ?", [$target['id'], $source['id']]);
            $this->logHistory((int) $source['id'], $actor['id'], 'FUSION', ['fusionnee_dans' => $target['uuid']], $reason);
            $this->logHistory((int) $target['id'], $actor['id'], 'FUSION', ['a_absorbe' => $source['uuid']], $reason);
            $this->audit->log($actor['id'], 'STRUCTURE_MERGE', 'structure', (string) $source['id'], null, ['target' => $target['uuid']], $reason, $ip);
        });
        return $this->detail((int) $source['id']);
    }

    /** Fermeture (structure qui a cessé son activité) ou réouverture. */
    public function setClosed(array $actor, string $uuid, bool $closed, array $in, string $ip): array
    {
        $s = $this->findVisible($actor, $uuid);
        if ($s['status'] !== ($closed ? 'ACTIVE' : 'FERMEE')) {
            throw new HttpException(409, 'CONFLICT', $closed ? 'Seule une structure active peut être fermée.' : 'Seule une structure fermée peut être rouverte.');
        }
        $reason = $this->requiredReason($in);
        $this->db->transaction(function () use ($s, $closed, $reason, $actor, $ip): void {
            $this->db->execute('UPDATE structures SET status = ? WHERE id = ?', [$closed ? 'FERMEE' : 'ACTIVE', $s['id']]);
            $this->logHistory((int) $s['id'], $actor['id'], $closed ? 'FERMETURE' : 'REOUVERTURE', null, $reason);
            $this->audit->log($actor['id'], $closed ? 'STRUCTURE_CLOSE' : 'STRUCTURE_REOPEN', 'structure', (string) $s['id'], null, null, $reason, $ip);
        });
        return $this->detail((int) $s['id']);
    }

    // ===================================================================== Doublons

    /**
     * Structures existantes (non rejetées ni fusionnées) qui ressemblent à $data :
     * même commune et nom identique ou très proche ; point GPS à moins de 100 m.
     * (Le code identique est, lui, bloquant : contrôlé dans validateCode.)
     * @return list<array{uuid:string, official_code:?string, name:string, commune:string, status:string, reasons:list<string>, distance_m:?int}>
     */
    public function duplicateCandidates(array $data, ?int $excludeId): array
    {
        $found = [];
        $add = function (array $row, string $reason, ?int $distance = null) use (&$found): void {
            $id = (int) $row['id'];
            $found[$id] ??= ['uuid' => $row['uuid'], 'official_code' => $row['official_code'], 'name' => $row['name'],
                'commune' => $row['commune'], 'status' => $row['status'], 'reasons' => [], 'distance_m' => null];
            $found[$id]['reasons'][] = $reason;
            if ($distance !== null) {
                $found[$id]['distance_m'] = $distance;
            }
        };
        $base = "SELECT s.id, s.uuid, s.official_code, s.name, s.name_normalized, s.latitude, s.longitude, s.status, c.name AS commune
                   FROM structures s JOIN communes c ON c.id = s.commune_id
                  WHERE s.deleted_at IS NULL AND s.status NOT IN ('REJETEE','FUSIONNEE') AND s.id <> ?";

        $norm = $data['name_normalized'];
        foreach ($this->db->fetchAll("$base AND s.commune_id = ?", [$excludeId ?? 0, $data['commune_id']]) as $row) {
            if ($row['name_normalized'] === $norm) {
                $add($row, 'Même nom dans la même commune');
            } elseif (self::similarNames($norm, $row['name_normalized'])) {
                $add($row, 'Nom très proche dans la même commune');
            }
        }
        if ($data['latitude'] !== null && $data['longitude'] !== null) {
            $lat = (float) $data['latitude'];
            $lng = (float) $data['longitude'];
            $dLat = self::DUPLICATE_RADIUS_M / 111_320;
            $dLng = $dLat / max(0.1, cos(deg2rad($lat)));
            foreach ($this->db->fetchAll("$base AND s.latitude BETWEEN ? AND ? AND s.longitude BETWEEN ? AND ?",
                [$excludeId ?? 0, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]) as $row) {
                $d = self::distanceMeters($lat, $lng, (float) $row['latitude'], (float) $row['longitude']);
                if ($d <= self::DUPLICATE_RADIUS_M) {
                    $add($row, sprintf('Point GPS à %d m', round($d)), (int) round($d));
                }
            }
        }
        return array_values($found);
    }

    /** Noms normalisés proches : mots génériques ignorés, puis inclusion ou faible distance d'édition. */
    public static function similarNames(string $a, string $b): bool
    {
        $strip = static fn(string $s): string => trim((string) preg_replace('/\s+/', ' ',
            (string) preg_replace('/\b(LE|LA|LES|DE|DU|DES|D|L|ET|CENTRE|ATELIER|GROUPEMENT)\b/', ' ', $s)));
        $a2 = $strip($a);
        $b2 = $strip($b);
        if ($a2 === '' || $b2 === '') {
            return false;
        }
        if ($a2 === $b2 || (strlen($a2) >= 6 && strlen($b2) >= 6 && (str_contains($a2, $b2) || str_contains($b2, $a2)))) {
            return true;
        }
        $max = max(strlen($a2), strlen($b2));
        return $max >= 6 && levenshtein($a2, $b2) <= max(1, intdiv($max, 6));
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * 6_371_000 * asin(min(1.0, sqrt($h)));
    }

    // ===================================================================== Contrôles

    /** Contrôle et normalise les champs d'une structure. $requireCode : code obligatoire (structure ACTIVE). */
    private function validate(array $in, ?int $selfId, bool $requireCode): array
    {
        $errors = [];
        $str = static fn($v) => is_scalar($v) ? Text::clean((string) $v) : '';
        $name = $str($in['name'] ?? '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = ['field' => 'name', 'message' => 'Nom obligatoire (3 à 150 caractères).'];
        }
        $code = $this->validateCode($in['official_code'] ?? null, $requireCode, $selfId, $errors);

        $typeCode = $str($in['type_code'] ?? '');
        $type = $this->db->fetchOne('SELECT id, is_other FROM structure_types WHERE code = ? AND active = 1', [$typeCode]);
        $otherLabel = $str($in['other_type_label'] ?? '');
        if ($type === null) {
            $errors[] = ['field' => 'type_code', 'message' => 'Type de structure obligatoire (a à k, question 1.9).'];
        } elseif ((int) $type['is_other'] === 1 && $otherLabel === '') {
            $errors[] = ['field' => 'other_type_label', 'message' => 'Préciser le type pour « Autre structure ».'];
        } elseif ((int) $type['is_other'] === 0) {
            $otherLabel = '';
        }
        $legal = $str($in['legal_status'] ?? '');
        if ($legal !== '' && !in_array($legal, ['PUBLIC', 'PRIVE'], true)) {
            $errors[] = ['field' => 'legal_status', 'message' => 'Statut : PUBLIC ou PRIVE.'];
        }
        $zone = $str($in['zone'] ?? '');
        if ($zone !== '' && !in_array($zone, ['URBAINE', 'RURALE'], true)) {
            $errors[] = ['field' => 'zone', 'message' => 'Zone : URBAINE ou RURALE.'];
        }
        $communeId = isset($in['commune_id']) && (is_int($in['commune_id']) || ctype_digit((string) $in['commune_id'])) ? (int) $in['commune_id'] : 0;
        if ($this->db->fetchValue('SELECT id FROM communes WHERE id = ? AND valid_to IS NULL', [$communeId]) === null) {
            $errors[] = ['field' => 'commune_id', 'message' => 'Commune obligatoire (référentiel RENACOM).'];
        }
        $locality = $str($in['locality_label'] ?? '');
        if (mb_strlen($locality) > 150) {
            $errors[] = ['field' => 'locality_label', 'message' => 'Village ou quartier : 150 caractères au plus.'];
        }

        $lat = $in['latitude'] ?? null;
        $lng = $in['longitude'] ?? null;
        $acc = $in['gps_accuracy_m'] ?? null;
        $latEmpty = $lat === null || $lat === '';
        $lngEmpty = $lng === null || $lng === '';
        if ($latEmpty !== $lngEmpty) {
            $errors[] = ['field' => 'latitude', 'message' => 'Latitude et longitude vont ensemble.'];
            $lat = $lng = null;
        } elseif (!$latEmpty) {
            if (!is_numeric($lat) || !is_numeric($lng)
                || $lat < self::NIGER_BOX['lat'][0] || $lat > self::NIGER_BOX['lat'][1] || $lng < self::NIGER_BOX['lng'][0] || $lng > self::NIGER_BOX['lng'][1]) {
                $errors[] = ['field' => 'latitude', 'message' => 'Coordonnées GPS hors du territoire nigérien.'];
                $lat = $lng = null;
            } else {
                $lat = round((float) $lat, 6);
                $lng = round((float) $lng, 6);
            }
        } else {
            $lat = $lng = null;
        }
        if ($acc !== null && $acc !== '' && (!is_numeric($acc) || $acc < 0 || $acc > 99999)) {
            $errors[] = ['field' => 'gps_accuracy_m', 'message' => 'Précision GPS invalide.'];
        }
        $dates = [];
        foreach (['authorization_date', 'opening_date'] as $k) {
            $v = $str($in[$k] ?? '');
            $dates[$k] = null;
            if ($v === '') {
                continue;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($d === false || $d->format('Y-m-d') !== $v || $v > date('Y-m-d') || $v < '1900-01-01') {
                $errors[] = ['field' => $k, 'message' => 'Date invalide ou future (format AAAA-MM-JJ).'];
            } else {
                $dates[$k] = $v;
            }
        }
        if ($errors !== []) {
            throw $this->validation($errors);
        }
        return [
            'official_code' => $code, 'name' => $name, 'name_normalized' => mb_substr(Text::normalize($name), 0, 150),
            'structure_type_id' => (int) $type['id'], 'type_code' => $typeCode, 'other_type_label' => $otherLabel ?: null,
            'legal_status' => $legal ?: null, 'zone' => $zone ?: null, 'commune_id' => $communeId,
            'locality_label' => $locality ?: null, 'latitude' => $lat, 'longitude' => $lng,
            'gps_accuracy_m' => ($acc === null || $acc === '' || $lat === null) ? null : round((float) $acc, 1),
            'authorization_date' => $dates['authorization_date'], 'opening_date' => $dates['opening_date'],
        ];
    }

    private function validateCode(mixed $code, bool $required, ?int $selfId, array &$errors): ?string
    {
        $code = is_scalar($code) ? (string) preg_replace('/\s+/', '', (string) $code) : '';
        if ($code === '') {
            if ($required) {
                $errors[] = ['field' => 'official_code', 'message' => 'Code officiel obligatoire (10 chiffres).'];
            }
            return null;
        }
        if (preg_match('/^\d{10}$/', $code) !== 1) {
            $errors[] = ['field' => 'official_code', 'message' => 'Le code officiel comporte exactement 10 chiffres.'];
            return null;
        }
        $other = $this->db->fetchOne('SELECT name FROM structures WHERE official_code = ? AND id <> ?', [$code, $selfId ?? 0]);
        if ($other !== null) {
            $errors[] = ['field' => 'official_code', 'message' => "Code $code déjà attribué à « {$other['name']} »."];
        }
        return $code;
    }

    private function assertCommuneInScope(array $actor, int $communeId): void
    {
        if (!$actor['scope']->coversCommune($communeId)) {
            throw new HttpException(403, 'OUT_OF_SCOPE', 'Cette commune est hors de votre périmètre.');
        }
    }

    /** Commune dans le périmètre de l'agent, ou dans ses zones d'une campagne non clôturée. */
    private function agentWorksIn(array $actor, int $communeId): bool
    {
        return $actor['scope']->coversCommune($communeId) || $this->db->fetchValue(
            "SELECT 1 FROM agent_areas a JOIN campaigns c ON c.id = a.campaign_id
              WHERE a.agent_id = ? AND a.commune_id = ? AND c.status IN ('PREPARATION','OUVERTE','SUSPENDUE') LIMIT 1",
            [$actor['id'], $communeId]) !== null;
    }

    private function findVisible(array $actor, string $uuid, string $field = 'uuid'): array
    {
        [$scopeSql, $params] = $actor['scope']->communeClause('s.commune_id');
        $s = $this->db->fetchOne($this->selectSql() . " WHERE s.uuid = ? AND s.deleted_at IS NULL AND $scopeSql", [$uuid, ...$params]);
        if ($s === null) {
            throw new HttpException(404, 'NOT_FOUND', $field === 'uuid' ? 'Structure introuvable.' : 'Structure de référence introuvable.');
        }
        return $s;
    }

    private function hasCollections(int $structureId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM collections WHERE structure_id = ? LIMIT 1', [$structureId]) !== null;
    }

    // ===================================================================== Interne

    private function insert(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO structures (uuid, official_code, name, name_normalized, structure_type_id, other_type_label, legal_status, zone, commune_id,
                                     locality_id, locality_label, latitude, longitude, gps_accuracy_m, authorization_date, opening_date, status,
                                     proposed_by, approved_by, approved_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['uuid'], $d['official_code'], $d['name'], $d['name_normalized'], $d['structure_type_id'], $d['other_type_label'], $d['legal_status'],
                $d['zone'], $d['commune_id'], $this->locality($d, $d['created_by']), $d['locality_label'], $d['latitude'], $d['longitude'],
                $d['gps_accuracy_m'], $d['authorization_date'], $d['opening_date'], $d['status'], $d['proposed_by'] ?? null,
                $d['approved_by'] ?? null, $d['approved_at'] ?? null, $d['created_by']]);
    }

    /** Village/quartier saisi : rattaché au référentiel des localités (créé « proposé, terrain » s'il est nouveau). */
    private function locality(array $d, ?int $userId): ?int
    {
        if ($d['locality_label'] === null) {
            return null;
        }
        $norm = mb_substr(Text::normalize($d['locality_label']), 0, 150);
        if ($norm === '') {
            return null;
        }
        $this->db->execute('INSERT IGNORE INTO localities (commune_id, name, name_normalized, created_by) VALUES (?, ?, ?, ?)',
            [$d['commune_id'], $d['locality_label'], $norm, $userId]);
        return (int) $this->db->fetchValue('SELECT id FROM localities WHERE commune_id = ? AND name_normalized = ?', [$d['commune_id'], $norm]);
    }

    private function logHistory(int $structureId, ?int $userId, string $action, ?array $changes, ?string $reason): void
    {
        $this->db->execute('INSERT INTO structure_history (structure_id, user_id, action, changes_json, reason) VALUES (?, ?, ?, ?, ?)',
            [$structureId, $userId, $action, $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $reason]);
    }

    private function requiredReason(array $in): string
    {
        $r = is_string($in['reason'] ?? null) ? Text::clean($in['reason']) : '';
        if (mb_strlen($r) < 3 || mb_strlen($r) > 500) {
            throw $this->validation([['field' => 'reason', 'message' => 'Motif obligatoire (3 à 500 caractères).']]);
        }
        return $r;
    }

    private function selectSql(): string
    {
        return 'SELECT s.*, t.code AS type_code, t.label AS type_label, c.name AS commune, c.department_id, d.name AS department, d.region_id, r.name AS region,
                       m.uuid AS merged_into_uuid
                  FROM structures s JOIN structure_types t ON t.id = s.structure_type_id JOIN communes c ON c.id = s.commune_id
                  JOIN departments d ON d.id = c.department_id JOIN regions r ON r.id = d.region_id
                  LEFT JOIN structures m ON m.id = s.merged_into_id';
    }

    private function publicFields(array $d): array
    {
        return array_intersect_key($d, array_flip(['official_code', 'name', 'type_code', 'other_type_label', 'legal_status', 'zone', 'commune_id',
            'locality_label', 'latitude', 'longitude', 'gps_accuracy_m', 'authorization_date', 'opening_date']));
    }

    /** Champs modifiables d'une fiche présentée (pour fusionner avec une modification partielle). */
    private function asData(array $p): array
    {
        $out = array_intersect_key($p, array_flip(self::EDITABLE));
        $out['name_normalized'] = Text::normalize($p['name']);
        return $out;
    }

    private function present(array $s): array
    {
        return [
            'uuid' => $s['uuid'],
            'official_code' => $s['official_code'],
            'name' => $s['name'],
            'type_code' => $s['type_code'],
            'type_label' => $s['type_label'],
            'other_type_label' => $s['other_type_label'],
            'legal_status' => $s['legal_status'],
            'zone' => $s['zone'],
            'commune_id' => (int) $s['commune_id'],
            'commune' => $s['commune'],
            'department_id' => (int) $s['department_id'],
            'department' => $s['department'],
            'region_id' => (int) $s['region_id'],
            'region' => $s['region'],
            'locality_label' => $s['locality_label'],
            'latitude' => $s['latitude'] === null ? null : (float) $s['latitude'],
            'longitude' => $s['longitude'] === null ? null : (float) $s['longitude'],
            'gps_accuracy_m' => $s['gps_accuracy_m'] === null ? null : (float) $s['gps_accuracy_m'],
            'authorization_date' => $s['authorization_date'],
            'opening_date' => $s['opening_date'],
            'status' => $s['status'],
            'merged_into_uuid' => $s['merged_into_uuid'] ?? null,
            'created_at' => $s['created_at'],
            'updated_at' => $s['updated_at'],
        ];
    }

    private function validation(array $errors): HttpException
    {
        return new HttpException(422, 'VALIDATION_FAILED', 'Certaines informations sont invalides.', ['errors' => $errors]);
    }
}
