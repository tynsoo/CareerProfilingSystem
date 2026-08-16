<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

class AuditLogger
{
    public static function log(
        ?int $actorUserId,
        ?string $actorRole,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $detail = null
    ): void {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, actor_role, action, target_type, target_id, detail_enc, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorUserId,
            $actorRole,
            $action,
            $targetType,
            $targetId,
            $detail !== null ? Crypto::enc($detail) : null,
            self::clientIp(),
        ]);
    }

    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip === null) {
            return null;
        }
        // Postgres inet type rejects IPv6 with a zone id (e.g. fe80::1%eth0) — strip it defensively.
        return explode('%', $ip)[0];
    }
}
