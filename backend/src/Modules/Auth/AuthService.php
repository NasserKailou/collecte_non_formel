<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Text;
use App\Http\HttpException;

/**
 * Authentification et jetons (docs/api/README.md §2, docs/phase1/03 §5).
 *
 * - Mots de passe : Argon2id (bcrypt en repli si Argon2 indisponible), re-hachage transparent.
 * - Jetons opaques de 256 bits ; seule leur empreinte SHA-256 est stockée.
 * - Rotation des jetons de rafraîchissement par « famille » : rejouer un ancien jeton
 *   révoque toute la famille (vol de jeton présumé).
 * - Verrouillage du compte après N échecs ; limitation par adresse IP ; temps de calcul
 *   homogène pour un identifiant inconnu (pas d'énumération des comptes).
 */
final class AuthService
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly Database $db, private readonly array $config)
    {
    }

    public static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    /** Alphabet sans caractères ambigus à la lecture (0/O, 1/l/I) : mots de passe remis sur papier ou par téléphone. */
    private const TEMP_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Mot de passe provisoire aléatoire, ex. « Kx7m-Pq4r-Tz9w » (12 caractères, ≈ 70 bits d'entropie). */
    public static function generateTemporaryPassword(): string
    {
        do {
            $chars = '';
            for ($i = 0; $i < 12; $i++) {
                $chars .= self::TEMP_ALPHABET[random_int(0, strlen(self::TEMP_ALPHABET) - 1)];
            }
        } while (!preg_match('/[A-Za-z]/', $chars) || !preg_match('/\d/', $chars));
        return implode('-', str_split($chars, 4));
    }

    /**
     * Hachage d'un mot de passe provisoire *aléatoire* : Argon2id à coût réduit.
     * Justification : avec ≈ 70 bits d'entropie, une attaque hors ligne reste impraticable même à coût
     * réduit ; le coût standard (≈ 0,2 s par compte) rendrait l'import de centaines d'agents trop long
     * pour une requête HTTP. Le mot de passe doit être changé à la première connexion et le hachage est
     * alors refait au coût standard (password_needs_rehash dans login()).
     */
    public static function hashTemporaryPassword(string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1])
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 8]);
    }

    public function assertPasswordPolicy(string $password): void
    {
        $min = (int) $this->config['password_min_length'];
        if (mb_strlen($password) < $min || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new HttpException(422, 'WEAK_PASSWORD', "Le mot de passe doit contenir au moins $min caractères, dont des lettres et des chiffres.");
        }
    }

    /**
     * @param array{uid?:string, model?:string, os_version?:string, app_version?:string}|null $device obligatoire pour le mobile
     * @return array{access_token:string, refresh_token:string, expires_in:int, user:array, must_change_password:bool, offline_policy:array}
     */
    public function login(string $identifier, string $password, ?array $device, string $ip, ?string $userAgent): array
    {
        $identifier = trim($identifier);
        $channel = $device !== null ? 'MOBILE' : 'WEB';
        $window = (int) $this->config['failure_window_minutes'];

        $ipFailures = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_logs WHERE ip = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE', [$ip, $window]);
        if ($ipFailures >= (int) $this->config['max_failures_per_ip']) {
            $this->logLogin(null, $identifier, $ip, $userAgent, false, 'IP_RATE_LIMIT', $channel);
            throw new HttpException(429, 'RATE_LIMITED', 'Trop de tentatives depuis cette adresse. Réessayer plus tard.', [], ['Retry-After' => (string) ($window * 60)]);
        }

        $user = $identifier === '' ? null : $this->db->fetchOne(
            'SELECT * FROM users WHERE deleted_at IS NULL AND (matricule = ? OR phone = ? OR email = ?) LIMIT 1',
            [$identifier, Text::normalizePhone($identifier) ?? $identifier, $identifier]);

        if ($user === null) {
            self::$dummyHash ??= self::hashPassword(bin2hex(random_bytes(8)));
            password_verify($password, self::$dummyHash); // temps de réponse homogène
            $this->logLogin(null, $identifier, $ip, $userAgent, false, 'UNKNOWN_IDENTIFIER', $channel);
            throw $this->invalidCredentials();
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            $this->logLogin((int) $user['id'], $identifier, $ip, $userAgent, false, 'LOCKED', $channel);
            throw new HttpException(401, 'ACCOUNT_LOCKED', 'Compte temporairement verrouillé après plusieurs échecs. Réessayer à ' . date('H:i', strtotime($user['locked_until'])) . '.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            $this->registerFailure($user);
            $this->logLogin((int) $user['id'], $identifier, $ip, $userAgent, false, 'BAD_PASSWORD', $channel);
            throw $this->invalidCredentials();
        }
        if ($user['status'] !== 'ACTIF') {
            $this->logLogin((int) $user['id'], $identifier, $ip, $userAgent, false, 'DISABLED', $channel);
            throw new HttpException(401, 'ACCOUNT_DISABLED', 'Ce compte est désactivé. Contacter votre superviseur.');
        }

        return $this->db->transaction(function (Database $db) use ($user, $password, $device, $identifier, $ip, $userAgent, $channel): array {
            $userId = (int) $user['id'];
            $newHash = password_needs_rehash($user['password_hash'], self::algorithm()) ? self::hashPassword($password) : $user['password_hash'];
            $db->execute('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), password_hash = ? WHERE id = ?', [$newHash, $userId]);

            $deviceId = $device !== null ? $this->registerDevice($userId, $device) : null;
            $tokens = $this->issueTokens($userId, $deviceId, Text::uuid4());
            $this->logLogin($userId, $identifier, $ip, $userAgent, true, null, $channel);

            return $tokens + [
                'user' => $this->profile($userId),
                'must_change_password' => (bool) $user['must_change_password'],
                'offline_policy' => $this->config['offline_policy'],
            ];
        });
    }

    /** @return array{access_token:string, refresh_token:string, expires_in:int} */
    public function refresh(string $refreshToken): array
    {
        $result = $this->db->transaction(function (Database $db) use ($refreshToken): array {
            $t = $db->fetchOne(
                "SELECT t.*, u.status, u.deleted_at, d.revoked_at AS device_revoked_at
                   FROM api_tokens t JOIN users u ON u.id = t.user_id LEFT JOIN devices d ON d.id = t.device_id
                  WHERE t.token_hash = ? AND t.type = 'REFRESH' FOR UPDATE", [hash('sha256', $refreshToken)]);
            if ($t === null) {
                return ['error' => new HttpException(401, 'UNAUTHENTICATED', 'Jeton de rafraîchissement invalide.')];
            }
            if ($t['revoked_at'] !== null) {
                // Rejeu d'un jeton déjà utilisé : toute la famille est révoquée (la révocation est conservée).
                $this->revokeFamily($t['family_id']);
                $db->execute("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, reason) VALUES (?, 'TOKEN_REUSE', 'api_token_family', ?, ?)",
                    [$t['user_id'], $t['family_id'], "Rejeu d'un jeton de rafraîchissement révoqué"]);
                return ['error' => new HttpException(401, 'TOKEN_REUSED', 'Session invalidée par mesure de sécurité. Se reconnecter.')];
            }
            if (strtotime($t['expires_at']) <= time() || $t['status'] !== 'ACTIF' || $t['deleted_at'] !== null || $t['device_revoked_at'] !== null) {
                return ['error' => new HttpException(401, 'TOKEN_EXPIRED', 'Session expirée. Se reconnecter.')];
            }
            $this->revokeFamily($t['family_id']);
            return $this->issueTokens((int) $t['user_id'], $t['device_id'] !== null ? (int) $t['device_id'] : null, $t['family_id']);
        });
        // L'exception est levée après validation de la transaction, pour que la révocation soit enregistrée.
        if (isset($result['error'])) {
            throw $result['error'];
        }
        return $result;
    }

    /**
     * Résout un jeton d'accès en utilisateur authentifié, ou null s'il est invalide, expiré ou révoqué.
     * @return array{id:int, uuid:string, family_id:string, device_id:?int, must_change_password:bool, roles:list<string>, permissions:list<string>}|null
     */
    public function authenticate(string $accessToken): ?array
    {
        $t = $this->db->fetchOne(
            "SELECT t.id AS token_id, t.user_id, t.family_id, t.device_id, t.expires_at, t.revoked_at, t.last_used_at,
                    u.uuid, u.status, u.deleted_at, u.must_change_password, d.revoked_at AS device_revoked_at
               FROM api_tokens t JOIN users u ON u.id = t.user_id LEFT JOIN devices d ON d.id = t.device_id
              WHERE t.token_hash = ? AND t.type = 'ACCESS'", [hash('sha256', $accessToken)]);
        if ($t === null || $t['revoked_at'] !== null || strtotime($t['expires_at']) <= time()
            || $t['status'] !== 'ACTIF' || $t['deleted_at'] !== null || $t['device_revoked_at'] !== null) {
            return null;
        }
        if ($t['last_used_at'] === null || strtotime($t['last_used_at']) < time() - 300) {
            $this->db->execute('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$t['token_id']]);
        }
        $userId = (int) $t['user_id'];
        return [
            'id' => $userId,
            'uuid' => $t['uuid'],
            'family_id' => $t['family_id'],
            'device_id' => $t['device_id'] !== null ? (int) $t['device_id'] : null,
            'must_change_password' => (bool) $t['must_change_password'],
            'roles' => $this->roles($userId),
            'permissions' => $this->permissions($userId),
        ];
    }

    public function logout(array $authUser): void
    {
        $this->revokeFamily($authUser['family_id']);
    }

    public function changePassword(array $authUser, string $current, string $new): void
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM users WHERE id = ?', [$authUser['id']]);
        if (!password_verify($current, $hash)) {
            throw new HttpException(422, 'BAD_PASSWORD', 'Mot de passe actuel incorrect.');
        }
        if (password_verify($new, $hash)) {
            throw new HttpException(422, 'SAME_PASSWORD', "Le nouveau mot de passe doit être différent de l'ancien.");
        }
        $this->assertPasswordPolicy($new);
        $this->db->transaction(function (Database $db) use ($authUser, $new): void {
            $db->execute('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?',
                [self::hashPassword($new), $authUser['id']]);
            // Les autres sessions de l'utilisateur sont fermées ; la session courante est conservée.
            $db->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND family_id <> ? AND revoked_at IS NULL',
                [$authUser['id'], $authUser['family_id']]);
            $db->execute("INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, 'PASSWORD_CHANGE', 'user', ?)",
                [$authUser['id'], (string) $authUser['id']]);
        });
    }

    /** Profil exposé par /me et à la connexion. */
    public function profile(int $userId): array
    {
        $u = $this->db->fetchOne('SELECT uuid, matricule, last_name, first_name, sex, phone, email, must_change_password FROM users WHERE id = ?', [$userId]);
        $scopes = $this->db->fetchAll(
            'SELECT s.level, s.region_id, r.name AS region, s.department_id, d.name AS department, s.commune_id, c.name AS commune
               FROM user_scopes s LEFT JOIN regions r ON r.id = s.region_id LEFT JOIN departments d ON d.id = s.department_id
               LEFT JOIN communes c ON c.id = s.commune_id WHERE s.user_id = ? ORDER BY s.id', [$userId]);
        return [
            'uuid' => $u['uuid'],
            'matricule' => $u['matricule'],
            'full_name' => trim($u['first_name'] . ' ' . $u['last_name']),
            'last_name' => $u['last_name'],
            'first_name' => $u['first_name'],
            'sex' => $u['sex'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'must_change_password' => (bool) $u['must_change_password'],
            'roles' => $this->roles($userId),
            'permissions' => $this->permissions($userId),
            'scopes' => $scopes,
        ];
    }

    // -----------------------------------------------------------------------

    private function issueTokens(int $userId, ?int $deviceId, string $familyId): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $accessTtl = (int) $this->config['access_ttl'];
        $refreshTtl = (int) $this->config['refresh_ttl'];
        $sql = 'INSERT INTO api_tokens (user_id, device_id, type, token_hash, family_id, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)';
        $this->db->execute($sql, [$userId, $deviceId, 'ACCESS', hash('sha256', $access), $familyId, $accessTtl]);
        $this->db->execute($sql, [$userId, $deviceId, 'REFRESH', hash('sha256', $refresh), $familyId, $refreshTtl]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $accessTtl];
    }

    private function revokeFamily(string $familyId): void
    {
        $this->db->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL', [$familyId]);
    }

    private function registerDevice(int $userId, array $device): int
    {
        $uid = (string) ($device['uid'] ?? '');
        if (preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $uid) !== 1) {
            throw new HttpException(422, 'VALIDATION_FAILED', "Identifiant d'appareil invalide.", ['field' => 'device.uid']);
        }
        $existing = $this->db->fetchOne('SELECT id, revoked_at FROM devices WHERE user_id = ? AND device_uid = ?', [$userId, $uid]);
        $meta = [
            mb_substr((string) ($device['model'] ?? ''), 0, 100) ?: null,
            mb_substr((string) ($device['os_version'] ?? ''), 0, 50) ?: null,
            mb_substr((string) ($device['app_version'] ?? ''), 0, 20) ?: null,
        ];
        if ($existing === null) {
            return $this->db->insert('INSERT INTO devices (user_id, device_uid, model, os_version, app_version, last_seen_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [$userId, $uid, ...$meta]);
        }
        if ($existing['revoked_at'] !== null) {
            throw new HttpException(403, 'DEVICE_REVOKED', "Cet appareil a été révoqué par l'administrateur.");
        }
        $this->db->execute('UPDATE devices SET model = ?, os_version = ?, app_version = ?, last_seen_at = NOW() WHERE id = ?', [...$meta, $existing['id']]);
        return (int) $existing['id'];
    }

    private function registerFailure(array $user): void
    {
        $failures = (int) $user['failed_attempts'] + 1;
        if ($failures >= (int) $this->config['max_failures_per_identifier']) {
            $this->db->execute('UPDATE users SET failed_attempts = 0, locked_until = NOW() + INTERVAL ? MINUTE WHERE id = ?',
                [(int) $this->config['lock_minutes'], $user['id']]);
        } else {
            $this->db->execute('UPDATE users SET failed_attempts = ? WHERE id = ?', [$failures, $user['id']]);
        }
    }

    private function logLogin(?int $userId, string $identifier, string $ip, ?string $ua, bool $success, ?string $reason, string $channel): void
    {
        $this->db->execute('INSERT INTO login_logs (user_id, identifier, ip, user_agent, channel, success, reason) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, mb_substr($identifier, 0, 190), mb_substr($ip, 0, 45), $ua === null ? null : mb_substr($ua, 0, 255), $channel, $success ? 1 : 0, $reason]);
    }

    private function invalidCredentials(): HttpException
    {
        return new HttpException(401, 'INVALID_CREDENTIALS', 'Identifiant ou mot de passe incorrect.');
    }

    /** @return list<string> */
    private function roles(int $userId): array
    {
        return array_column($this->db->fetchAll('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code', [$userId]), 'code');
    }

    /** @return list<string> */
    private function permissions(int $userId): array
    {
        return array_column($this->db->fetchAll(
            'SELECT DISTINCT p.code FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? ORDER BY p.code', [$userId]), 'code');
    }
}
