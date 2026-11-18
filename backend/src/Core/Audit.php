<?php

declare(strict_types=1);

namespace App\Core;

/** Journal d'audit (table audit_logs) : qui a fait quoi, sur quoi, avant/après, pourquoi. */
final class Audit
{
    public function __construct(private readonly Database $db)
    {
    }

    public function log(?int $userId, string $action, string $entityType, ?string $entityId,
        ?array $before = null, ?array $after = null, ?string $reason = null, ?string $ip = null): void
    {
        $json = static fn(?array $v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->execute(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, before_json, after_json, reason, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $action, $entityType, $entityId, $json($before), $json($after),
                $reason === null ? null : mb_substr($reason, 0, 500), $ip === null ? null : mb_substr($ip, 0, 45)]);
    }
}
