<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;
use App\Modules\Auth\AuthService;

/**
 * Gestion des comptes (docs/api/README.md §5 « Utilisateurs », docs/phase1/02 UC-02).
 *
 * Règles appliquées côté serveur, quelle que soit l'interface :
 * - visibilité : un utilisateur ne voit que les comptes dont tout le périmètre est inclus dans le sien
 *   (un compte hors périmètre répond 404, son existence n'est pas révélée) ;
 * - pas d'élévation de privilèges : on n'attribue que des rôles de rang inférieur au sien (l'administrateur
 *   national peut tout attribuer), dans un périmètre inclus dans le sien ;
 * - cohérence rôle ↔ niveau de périmètre (ROLE_LEVELS) et rôle ↔ superviseur (SUPERVISOR_ROLES) ;
 * - on ne modifie ni son propre rôle, ni son propre périmètre, ni son propre statut ;
 * - toute action est journalisée (audit_logs).
 */
final class UserService
{
    public const ROLE_RANK = ['ADMIN' => 4, 'SUP_REG' => 3, 'SUP_DEP' => 2, 'VIEWER' => 1, 'AGENT' => 1];

    /** Niveaux de périmètre autorisés par rôle. */
    public const ROLE_LEVELS = [
        'ADMIN' => ['NATIONAL'],
        'SUP_REG' => ['REGION'],
        'SUP_DEP' => ['DEPARTMENT'],
        'AGENT' => ['DEPARTMENT', 'COMMUNE'],
        'VIEWER' => ['NATIONAL', 'REGION', 'DEPARTMENT'],
    ];

    /** Rôles admissibles comme supérieur hiérarchique direct. */
    public const SUPERVISOR_ROLES = [
        'AGENT' => ['SUP_DEP', 'SUP_REG'],
        'SUP_DEP' => ['SUP_REG'],
        'SUP_REG' => ['ADMIN'],
        'VIEWER' => [],
        'ADMIN' => [],
    ];

    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    // ===================================================================== Acteur

    /** Contexte de l'utilisateur qui agit (à partir de l'utilisateur authentifié). */
    public function actor(array $authUser): array
    {
        $rank = 0;
        foreach ($authUser['roles'] as $r) {
            $rank = max($rank, self::ROLE_RANK[$r] ?? 0);
        }
        return [
            'id' => (int) $authUser['id'],
            'is_admin' => in_array('ADMIN', $authUser['roles'], true),
            'rank' => $rank,
            'roles' => array_values($authUser['roles']),
            'scope' => Scope::forUser($this->db, (int) $authUser['id']),
        ];
    }

    // ===================================================================== Lecture

    /** @return array{0:list<array>, 1:int} lignes et total */
    public function list(array $actor, array $filters, array $page): array
    {
        [$visSql, $params] = $actor['scope']->userVisibilityClause('u.id');
        $where = ['u.deleted_at IS NULL', $visSql];

        if (($q = $filters['q'] ?? null) !== null) {
            $where[] = '(u.matricule LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (($role = $filters['role'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles fr JOIN roles r ON r.id = fr.role_id WHERE fr.user_id = u.id AND r.code = ?)';
            $params[] = $role;
        }
        if (($status = $filters['status'] ?? null) !== null) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if (($regionId = $filters['region_id'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM user_scopes fs WHERE fs.user_id = u.id AND fs.region_id = ?)';
            $params[] = $regionId;
        }
        if (($deptId = $filters['department_id'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM user_scopes fs WHERE fs.user_id = u.id AND fs.department_id = ?)';
            $params[] = $deptId;
        }
        if (($sup = $filters['supervisor_uuid'] ?? null) !== null) {
            $where[] = 'u.supervisor_id = (SELECT id FROM users WHERE uuid = ?)';
            $params[] = $sup;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.uuid, u.matricule, u.last_name, u.first_name, u.sex, u.phone, u.email, u.status,
                    u.must_change_password, u.locked_until, u.last_login_at, u.created_at,
                    s.uuid AS supervisor_uuid, CONCAT(s.first_name, ' ', s.last_name) AS supervisor_name
               FROM users u LEFT JOIN users s ON s.id = u.supervisor_id
              WHERE $whereSql ORDER BY u.last_name, u.first_name, u.id LIMIT {$page['per_page']} OFFSET {$page['offset']}", $params);

        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $roles = $this->rolesFor($ids);
        $scopes = $this->scopesFor($ids);
        return [array_map(fn(array $r) => $this->present($r, $roles[(int) $r['id']] ?? [], $scopes[(int) $r['id']] ?? []), $rows), $total];
    }

    public function get(array $actor, string $uuid): array
    {
        $u = $this->findVisible($actor, $uuid);
        return $this->detail((int) $u['id']);
    }

    /** Fiche complète d'un compte (sans contrôle : réservé aux appels déjà autorisés). */
    public function detail(int $id): array
    {
        $r = $this->db->fetchOne(
            "SELECT u.id, u.uuid, u.matricule, u.last_name, u.first_name, u.sex, u.phone, u.email, u.status,
                    u.must_change_password, u.locked_until, u.last_login_at, u.created_at,
                    s.uuid AS supervisor_uuid, CONCAT(s.first_name, ' ', s.last_name) AS supervisor_name
               FROM users u LEFT JOIN users s ON s.id = u.supervisor_id WHERE u.id = ?", [$id]);
        $out = $this->present($r, $this->rolesFor([$id])[$id] ?? [], $this->scopesFor([$id])[$id] ?? []);
        $out['subordinates'] = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE supervisor_id = ? AND deleted_at IS NULL', [$id]);
        $out['active_devices'] = (int) $this->db->fetchValue('SELECT COUNT(*) FROM devices WHERE user_id = ? AND revoked_at IS NULL', [$id]);
        return $out;
    }

    // ===================================================================== Écriture

    /** @return array{user:array, temporary_password:string} */
    public function create(array $actor, array $in, string $ip): array
    {
        $errors = [];
        $data = $this->validateIdentity($in, $errors, null);
        $role = $this->validateRole($in['role'] ?? null, $errors);
        $scopeRows = $role !== null ? $this->normalizeScopes($in['scopes'] ?? null, $role, $errors) : [];
        $this->throwIfErrors($errors);

        $scope = Scope::fromRows($this->db, $scopeRows);
        $this->assertCanGrant($actor, $role, $scope);
        $supervisorId = $this->resolveSupervisor($in['supervisor_uuid'] ?? null, $role, $scope);

        $password = AuthService::generateTemporaryPassword();
        $id = $this->db->transaction(function (Database $db) use ($data, $role, $scopeRows, $supervisorId, $password, $actor, $ip): int {
            $id = $this->insertUser($data, $role, $scopeRows, $supervisorId, $password, $actor['id']);
            $this->audit->log($actor['id'], 'USER_CREATE', 'user', (string) $id, null,
                $data + ['role' => $role, 'scopes' => $scopeRows, 'supervisor_id' => $supervisorId], null, $ip);
            return $id;
        });
        return ['user' => $this->detail($id), 'temporary_password' => $password];
    }

    /**
     * Insertion brute d'un compte déjà validé (utilisée aussi par l'import en masse, dans sa transaction).
     * @param list<array> $scopeRows lignes normalisées (normalizeScopes)
     */
    public function insertUser(array $data, string $role, array $scopeRows, ?int $supervisorId, string $temporaryPassword, int $createdBy): int
    {
        $id = $this->db->insert(
            'INSERT INTO users (uuid, matricule, last_name, first_name, sex, phone, email, password_hash, must_change_password, status, supervisor_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [Text::uuid4(), $data['matricule'], $data['last_name'], $data['first_name'], $data['sex'], $data['phone'], $data['email'],
                AuthService::hashTemporaryPassword($temporaryPassword), 'ACTIF', $supervisorId, $createdBy]);
        $this->db->execute('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?', [$id, $role]);
        $this->insertScopes($id, $scopeRows);
        return $id;
    }

    /** Modification partielle : identité, rôle (+ périmètre), superviseur. Le matricule est immuable (identifiant de connexion et clé des imports). */
    public function update(array $actor, string $uuid, array $in, string $ip): array
    {
        $u = $this->findVisible($actor, $uuid);
        $id = (int) $u['id'];
        $this->assertCanManage($actor, $u);
        if (array_key_exists('matricule', $in) && mb_strtoupper(Text::clean((string) $in['matricule'])) !== $u['matricule']) {
            throw $this->validation([['field' => 'matricule', 'message' => 'Le matricule ne peut pas être modifié.']]);
        }

        $errors = [];
        $data = $this->validateIdentity($in + $u, $errors, $id);
        $currentRole = $this->rolesFor([$id])[$id][0] ?? null;
        $role = array_key_exists('role', $in) ? $this->validateRole($in['role'], $errors) : $currentRole;
        $roleChanged = $role !== $currentRole;
        $scopesGiven = array_key_exists('scopes', $in);
        if (($roleChanged || $scopesGiven) && $id === $actor['id']) {
            throw new HttpException(403, 'FORBIDDEN', 'Vous ne pouvez pas modifier votre propre rôle ni votre propre périmètre.');
        }
        $currentRows = $this->db->fetchAll('SELECT level, region_id, department_id, commune_id FROM user_scopes WHERE user_id = ? ORDER BY id', [$id]);
        $scopeRows = $currentRows;
        if ($role !== null && ($scopesGiven || $roleChanged)) {
            $scopeRows = $this->normalizeScopes($scopesGiven ? $in['scopes'] : $currentRows, $role, $errors);
        }
        $this->throwIfErrors($errors);

        $scope = Scope::fromRows($this->db, $scopeRows);
        if ($roleChanged || $scopesGiven) {
            $this->assertCanGrant($actor, $role, $scope);
            $this->assertSubordinatesFit($id, $role, $scope);
        }
        if (array_key_exists('supervisor_uuid', $in)) {
            $supervisorId = $this->resolveSupervisor($in['supervisor_uuid'], $role, $scope, $id);
        } else {
            $supervisorId = $u['supervisor_id'] !== null ? (int) $u['supervisor_id'] : null;
            if ($supervisorId !== null && ($roleChanged || $scopesGiven)) {
                // Le superviseur actuel doit rester compatible avec le nouveau rôle ou périmètre.
                $this->resolveSupervisor($this->db->fetchValue('SELECT uuid FROM users WHERE id = ?', [$supervisorId]), $role, $scope, $id);
            }
        }

        $before = $this->detail($id);
        $this->db->transaction(function (Database $db) use ($id, $data, $role, $roleChanged, $scopeRows, $scopesGiven, $supervisorId): void {
            $db->execute('UPDATE users SET last_name = ?, first_name = ?, sex = ?, phone = ?, email = ?, supervisor_id = ? WHERE id = ?',
                [$data['last_name'], $data['first_name'], $data['sex'], $data['phone'], $data['email'], $supervisorId, $id]);
            if ($roleChanged) {
                $db->execute('DELETE FROM user_roles WHERE user_id = ?', [$id]);
                $db->execute('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?', [$id, $role]);
            }
            if ($roleChanged || $scopesGiven) {
                $db->execute('DELETE FROM user_scopes WHERE user_id = ?', [$id]);
                $this->insertScopes($id, $scopeRows);
            }
        });
        $after = $this->detail($id);
        $this->audit->log($actor['id'], 'USER_UPDATE', 'user', (string) $id, $before, $after, null, $ip);
        return $after;
    }

    public function setScopes(array $actor, string $uuid, mixed $scopes, string $ip): array
    {
        return $this->update($actor, $uuid, ['scopes' => $scopes], $ip);
    }

    public function setStatus(array $actor, string $uuid, bool $active, ?string $reason, string $ip): array
    {
        $u = $this->findVisible($actor, $uuid);
        $id = (int) $u['id'];
        $this->assertCanManage($actor, $u);
        if ($id === $actor['id']) {
            throw new HttpException(403, 'FORBIDDEN', 'Vous ne pouvez pas modifier le statut de votre propre compte.');
        }
        $status = $active ? 'ACTIF' : 'INACTIF';
        if ($u['status'] !== $status) {
            $this->db->transaction(function (Database $db) use ($id, $active, $status, $u, $actor, $reason, $ip): void {
                $db->execute('UPDATE users SET status = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?', [$status, $id]);
                if (!$active) {
                    // Désactivation : toutes les sessions (web et tablettes) sont fermées immédiatement.
                    $db->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
                }
                $this->audit->log($actor['id'], $active ? 'USER_ACTIVATE' : 'USER_DEACTIVATE', 'user', (string) $id,
                    ['status' => $u['status']], ['status' => $status], $reason, $ip);
            });
        }
        return $this->detail($id);
    }

    /** @return array{user:array, temporary_password:string} */
    public function resetPassword(array $actor, string $uuid, string $ip): array
    {
        $u = $this->findVisible($actor, $uuid);
        $id = (int) $u['id'];
        $this->assertCanManage($actor, $u);
        if ($id === $actor['id']) {
            throw new HttpException(403, 'FORBIDDEN', 'Utilisez le changement de mot de passe pour votre propre compte.');
        }
        $password = AuthService::generateTemporaryPassword();
        $this->db->transaction(function (Database $db) use ($id, $password, $actor, $ip): void {
            $db->execute('UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?',
                [AuthService::hashTemporaryPassword($password), $id]);
            $db->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            $this->audit->log($actor['id'], 'USER_PASSWORD_RESET', 'user', (string) $id, null, null, null, $ip);
        });
        return ['user' => $this->detail($id), 'temporary_password' => $password];
    }

    // ===================================================================== Appareils

    public function devices(array $actor, string $uuid): array
    {
        $u = $this->findVisible($actor, $uuid);
        return $this->db->fetchAll(
            'SELECT id, device_uid, model, os_version, app_version, last_seen_at, revoked_at, created_at
               FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC', [$u['id']]);
    }

    public function revokeDevice(array $actor, int $deviceId, ?string $reason, string $ip): void
    {
        $d = $this->db->fetchOne('SELECT d.id, d.revoked_at, u.uuid FROM devices d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$deviceId]);
        if ($d === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Appareil introuvable.');
        }
        $u = $this->findVisible($actor, $d['uuid']); // 404 si hors périmètre
        $this->assertCanManage($actor, $u);
        if ($d['revoked_at'] !== null) {
            return;
        }
        $this->db->transaction(function (Database $db) use ($deviceId, $actor, $reason, $ip): void {
            $db->execute('UPDATE devices SET revoked_at = NOW(), revoked_by = ? WHERE id = ?', [$actor['id'], $deviceId]);
            $db->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$deviceId]);
            $this->audit->log($actor['id'], 'DEVICE_REVOKE', 'device', (string) $deviceId, null, null, $reason, $ip);
        });
    }

    // ===================================================================== Validation (partagée avec l'import)

    /**
     * Contrôle et normalise l'identité. $errors reçoit des {field, message}.
     * @return array{matricule:string, last_name:string, first_name:string, sex:string, phone:?string, email:?string}
     */
    public function validateIdentity(array $in, array &$errors, ?int $selfId, bool $checkUnique = true): array
    {
        $str = static fn($v) => is_scalar($v) ? Text::clean((string) $v) : '';
        $matricule = mb_strtoupper($str($in['matricule'] ?? ''));
        $last = mb_strtoupper($str($in['last_name'] ?? ''));
        $first = $str($in['first_name'] ?? '');
        $sex = mb_strtoupper($str($in['sex'] ?? ''));
        $phoneRaw = $str($in['phone'] ?? '');
        $emailRaw = mb_strtolower($str($in['email'] ?? ''));

        if (preg_match('#^[A-Z0-9][A-Z0-9._/-]{1,39}$#', $matricule) !== 1) {
            $errors[] = ['field' => 'matricule', 'message' => 'Matricule obligatoire : 2 à 40 caractères (lettres, chiffres, . _ / -).'];
        }
        if ($last === '' || mb_strlen($last) > 100) {
            $errors[] = ['field' => 'last_name', 'message' => 'Nom obligatoire (100 caractères au plus).'];
        }
        if ($first === '' || mb_strlen($first) > 100) {
            $errors[] = ['field' => 'first_name', 'message' => 'Prénom obligatoire (100 caractères au plus).'];
        }
        if (!in_array($sex, ['M', 'F'], true)) {
            $errors[] = ['field' => 'sex', 'message' => 'Sexe obligatoire : M ou F.'];
        }
        $phone = null;
        if ($phoneRaw !== '') {
            $phone = Text::normalizePhone($phoneRaw);
            if ($phone === null) {
                $errors[] = ['field' => 'phone', 'message' => 'Téléphone invalide : 8 chiffres, avec ou sans +227.'];
            }
        }
        $email = null;
        if ($emailRaw !== '') {
            if (mb_strlen($emailRaw) > 190 || filter_var($emailRaw, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = ['field' => 'email', 'message' => 'Adresse e-mail invalide.'];
            } else {
                $email = $emailRaw;
            }
        }
        if ($checkUnique) {
            // Les comptes supprimés logiquement occupent toujours leur matricule, téléphone et e-mail (index uniques).
            foreach (['matricule' => $matricule, 'phone' => $phone, 'email' => $email] as $col => $v) {
                if ($v !== null && $v !== '' && $this->db->fetchValue("SELECT id FROM users WHERE $col = ? AND id <> ?", [$v, $selfId ?? 0]) !== null) {
                    $errors[] = ['field' => $col, 'message' => match ($col) {
                        'matricule' => "Le matricule « $v » est déjà attribué.",
                        'phone' => "Le téléphone $v est déjà attribué à un autre compte.",
                        'email' => "L'adresse $v est déjà attribuée à un autre compte.",
                    }];
                }
            }
        }
        return ['matricule' => $matricule, 'last_name' => $last, 'first_name' => $first, 'sex' => $sex, 'phone' => $phone, 'email' => $email];
    }

    public function validateRole(mixed $role, array &$errors): ?string
    {
        if (!is_string($role) || !isset(self::ROLE_LEVELS[$role])) {
            $errors[] = ['field' => 'role', 'message' => 'Rôle obligatoire : ' . implode(', ', array_keys(self::ROLE_LEVELS)) . '.'];
            return null;
        }
        return $role;
    }

    /**
     * Contrôle les lignes de périmètre {level, region_id|department_id|commune_id} pour le rôle donné,
     * vérifie l'existence des unités et complète les identifiants parents. @return list<array>
     */
    public function normalizeScopes(mixed $scopes, string $role, array &$errors): array
    {
        if (!is_array($scopes) || $scopes === [] || !array_is_list($scopes) || count($scopes) > 100) {
            $errors[] = ['field' => 'scopes', 'message' => 'Au moins un périmètre territorial est obligatoire (100 au plus).'];
            return [];
        }
        $allowed = self::ROLE_LEVELS[$role];
        $idOf = static fn(array $s, string $k): ?int =>
            isset($s[$k]) && (is_int($s[$k]) || (is_string($s[$k]) && ctype_digit($s[$k]))) ? (int) $s[$k] : null;
        $out = [];
        foreach ($scopes as $i => $s) {
            $level = is_array($s) ? ($s['level'] ?? null) : null;
            if (!in_array($level, $allowed, true)) {
                $errors[] = ['field' => "scopes.$i.level", 'message' => "Niveau de périmètre non admis pour le rôle $role (admis : " . implode(', ', $allowed) . ').'];
                continue;
            }
            $row = null;
            switch ($level) {
                case 'NATIONAL':
                    $row = ['region_id' => null, 'department_id' => null, 'commune_id' => null];
                    break;
                case 'REGION':
                    $id = $idOf($s, 'region_id');
                    if ($id !== null && $this->db->fetchValue('SELECT id FROM regions WHERE id = ? AND valid_to IS NULL', [$id]) !== null) {
                        $row = ['region_id' => $id, 'department_id' => null, 'commune_id' => null];
                    }
                    break;
                case 'DEPARTMENT':
                    $id = $idOf($s, 'department_id');
                    $d = $id === null ? null : $this->db->fetchOne('SELECT region_id FROM departments WHERE id = ? AND valid_to IS NULL', [$id]);
                    if ($d !== null) {
                        $row = ['region_id' => (int) $d['region_id'], 'department_id' => $id, 'commune_id' => null];
                    }
                    break;
                case 'COMMUNE':
                    $id = $idOf($s, 'commune_id');
                    $c = $id === null ? null : $this->db->fetchOne(
                        'SELECT c.department_id, d.region_id FROM communes c JOIN departments d ON d.id = c.department_id WHERE c.id = ? AND c.valid_to IS NULL', [$id]);
                    if ($c !== null) {
                        $row = ['region_id' => (int) $c['region_id'], 'department_id' => (int) $c['department_id'], 'commune_id' => $id];
                    }
                    break;
            }
            if ($row === null) {
                $errors[] = ['field' => "scopes.$i", 'message' => 'Unité territoriale introuvable pour ce niveau de périmètre.'];
                continue;
            }
            $row = ['level' => $level] + $row;
            $out[implode('|', $row)] = $row; // doublons ignorés
        }
        $out = array_values($out);
        if ($role === 'ADMIN' && count($out) !== 1) {
            $errors[] = ['field' => 'scopes', 'message' => "L'administrateur a un périmètre national unique."];
        }
        if (in_array('NATIONAL', array_column($out, 'level'), true) && count($out) > 1) {
            $errors[] = ['field' => 'scopes', 'message' => 'Un périmètre national ne se combine avec aucun autre.'];
        }
        return $out;
    }

    /** L'acteur peut-il attribuer ce rôle et ce périmètre ? */
    public function assertCanGrant(array $actor, string $role, Scope $scope): void
    {
        if ($actor['is_admin']) {
            return;
        }
        if (self::ROLE_RANK[$role] >= $actor['rank']) {
            throw new HttpException(403, 'FORBIDDEN', "Vous ne pouvez pas attribuer le rôle $role.");
        }
        if (!$actor['scope']->contains($scope)) {
            throw new HttpException(403, 'OUT_OF_SCOPE', 'Le périmètre demandé dépasse votre propre périmètre.');
        }
    }

    /**
     * Résout et contrôle le superviseur désigné (uuid) pour un compte de rôle $role et de périmètre $scope.
     * Le superviseur doit être actif, de rôle admissible, et son périmètre doit couvrir celui du compte.
     */
    public function resolveSupervisor(mixed $uuid, string $role, Scope $scope, ?int $selfId = null): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $s = is_string($uuid) ? $this->supervisorCandidate('u.uuid = ?', $uuid) : null;
        $message = $this->supervisorProblem($s, $role, $scope, $selfId);
        if ($message !== null) {
            throw $this->validation([['field' => 'supervisor_uuid', 'message' => $message]]);
        }
        return $s['id'];
    }

    /** @return array{id:int, status:string, roles:list<string>}|null */
    public function supervisorCandidate(string $where, string $value): ?array
    {
        $s = $this->db->fetchOne(
            "SELECT u.id, u.status, GROUP_CONCAT(r.code) AS roles FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
              WHERE $where AND u.deleted_at IS NULL GROUP BY u.id, u.status", [$value]);
        return $s === null ? null : ['id' => (int) $s['id'], 'status' => $s['status'], 'roles' => explode(',', (string) $s['roles'])];
    }

    /** Motif de refus d'un superviseur, ou null s'il convient. @param array{id:int, status:string, roles:list<string>}|null $s */
    public function supervisorProblem(?array $s, string $role, Scope $scope, ?int $selfId = null, ?Scope $supervisorScope = null): ?string
    {
        if ($s === null) {
            return 'Superviseur introuvable.';
        }
        if ($selfId !== null && $s['id'] === $selfId) {
            return 'Un compte ne peut pas être son propre superviseur.';
        }
        if ($s['status'] !== 'ACTIF') {
            return 'Le superviseur désigné est désactivé.';
        }
        $admissible = self::SUPERVISOR_ROLES[$role];
        if (array_intersect($s['roles'], $admissible) === []) {
            return $admissible === []
                ? "Le rôle $role n'a pas de superviseur hiérarchique."
                : "Le superviseur d'un compte $role doit avoir le rôle " . implode(' ou ', $admissible) . '.';
        }
        $supervisorScope ??= Scope::forUser($this->db, $s['id']);
        if (!$supervisorScope->contains($scope)) {
            return 'Le périmètre du superviseur ne couvre pas celui du compte.';
        }
        return null;
    }

    // ===================================================================== Interne

    private function assertSubordinatesFit(int $id, ?string $role, Scope $scope): void
    {
        $subs = $this->db->fetchAll(
            'SELECT u.id, u.matricule, r.code AS role FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.supervisor_id = ? AND u.deleted_at IS NULL', [$id]);
        $bad = [];
        foreach ($subs as $s) {
            if (!in_array($role, self::SUPERVISOR_ROLES[$s['role']] ?? [], true) || !$scope->contains(Scope::forUser($this->db, (int) $s['id']))) {
                $bad[] = $s['matricule'];
            }
        }
        if ($bad !== []) {
            throw new HttpException(409, 'SUBORDINATES_OUT_OF_SCOPE',
                "Ce compte supervise des comptes qui ne seraient plus couverts. Réaffecter d'abord leur superviseur.",
                ['matricules' => array_slice($bad, 0, 50), 'count' => count($bad)]);
        }
    }

    private function findVisible(array $actor, string $uuid): array
    {
        [$visSql, $params] = $actor['scope']->userVisibilityClause('u.id');
        $u = $this->db->fetchOne("SELECT u.* FROM users u WHERE u.uuid = ? AND u.deleted_at IS NULL AND ($visSql)", [$uuid, ...$params]);
        if ($u === null) {
            $u = $this->db->fetchOne('SELECT * FROM users WHERE uuid = ? AND id = ?', [$uuid, $actor['id']]); // on se voit toujours soi-même
        }
        if ($u === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Utilisateur introuvable.');
        }
        return $u;
    }

    private function assertCanManage(array $actor, array $target): void
    {
        if ($actor['is_admin'] || (int) $target['id'] === $actor['id']) {
            return;
        }
        $rank = 0;
        foreach ($this->rolesFor([(int) $target['id']])[(int) $target['id']] ?? [] as $r) {
            $rank = max($rank, self::ROLE_RANK[$r] ?? 0);
        }
        if ($rank >= $actor['rank']) {
            throw new HttpException(403, 'FORBIDDEN', 'Vous ne pouvez pas gérer un compte de rang égal ou supérieur au vôtre.');
        }
    }

    private function insertScopes(int $userId, array $rows): void
    {
        foreach ($rows as $s) {
            $this->db->execute('INSERT INTO user_scopes (user_id, level, region_id, department_id, commune_id) VALUES (?, ?, ?, ?, ?)',
                [$userId, $s['level'], $s['region_id'], $s['department_id'], $s['commune_id']]);
        }
    }

    /** @return array<int, list<string>> */
    private function rolesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT ur.user_id, r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.code', $ids) as $r) {
            $out[(int) $r['user_id']][] = $r['code'];
        }
        return $out;
    }

    /** @return array<int, list<array>> */
    private function scopesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT s.user_id, s.level, s.region_id, r.name AS region, s.department_id, d.name AS department, s.commune_id, c.name AS commune
               FROM user_scopes s LEFT JOIN regions r ON r.id = s.region_id LEFT JOIN departments d ON d.id = s.department_id
               LEFT JOIN communes c ON c.id = s.commune_id WHERE s.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id', $ids) as $s) {
            $uid = (int) $s['user_id'];
            unset($s['user_id']);
            $s['label'] = match ($s['level']) {
                'NATIONAL' => 'National',
                'REGION' => 'Région ' . $s['region'],
                'DEPARTMENT' => 'Département ' . $s['department'] . ' (' . $s['region'] . ')',
                'COMMUNE' => 'Commune ' . $s['commune'] . ' (' . $s['department'] . ')',
            };
            $out[$uid][] = $s;
        }
        return $out;
    }

    private function present(array $r, array $roles, array $scopes): array
    {
        return [
            'uuid' => $r['uuid'],
            'matricule' => $r['matricule'],
            'last_name' => $r['last_name'],
            'first_name' => $r['first_name'],
            'full_name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'sex' => $r['sex'],
            'phone' => $r['phone'],
            'email' => $r['email'],
            'status' => $r['status'],
            'role' => $roles[0] ?? null,
            'roles' => $roles,
            'scopes' => $scopes,
            'supervisor' => $r['supervisor_uuid'] === null ? null : ['uuid' => $r['supervisor_uuid'], 'name' => $r['supervisor_name']],
            'must_change_password' => (bool) $r['must_change_password'],
            'locked' => $r['locked_until'] !== null && strtotime($r['locked_until']) > time(),
            'last_login_at' => $r['last_login_at'],
            'created_at' => $r['created_at'],
        ];
    }

    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw $this->validation($errors);
        }
    }

    private function validation(array $errors): HttpException
    {
        return new HttpException(422, 'VALIDATION_FAILED', 'Certaines informations sont invalides.', ['errors' => $errors]);
    }
}
