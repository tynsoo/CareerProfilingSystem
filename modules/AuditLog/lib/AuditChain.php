<?php

require_once __DIR__ . '/../../../config/env.php';

/**
 * Tamper protection for the audit log. Every entry gets a seal (row_hash): an HMAC-SHA256 of its own
 * contents and the seal of the entry before it (prev_hash), so the entries form a chain. Changing an
 * entry breaks its seal; removing one from the middle breaks the link of the entry after it. The secret
 * is built from APP_AES_KEY, which is not stored in the database, so someone with database access alone
 * cannot make new seals to hide a change.
 *
 * Limits: removing the newest entries leaves a shorter chain that still checks out, and someone who has
 * the app's key can rebuild the chain. The daily cleanup (modules/AuditLog/lib/AuditRetention.php) removes the oldest
 * entries and keeps the last removed seal as the anchor the chain now starts from.
 *
 * Needs the prev_hash and row_hash columns (db/migrate_add_audit_hash_chain.php). Until they exist,
 * AuditLogger writes entries without seals and verify() reports that it is not set up.
 */
class AuditChain
{
    /** Where the chain starts: the seal of the last entry the cleanup removed (or ZERO). */
    public const ANCHOR_KEY = 'audit.chainAnchor';

    /** The start of a chain nothing was removed from. */
    public const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Advisory lock taken while an entry is added, so two entries never link to the same one before. */
    private const LOCK_ID = 7310202601;

    /** The columns a seal covers, read the same way when sealing and when checking. */
    private const ROW_SQL = 'id, (EXTRACT(EPOCH FROM created_at) * 1000000)::bigint AS created_us, actor_user_id, actor_role,
        action, target_type, target_id, detail_enc, ip_address::text AS ip';

    private static ?bool $enabled = null;

    /** Whether the seal columns exist (checked once per request). */
    public static function enabled(PDO $pdo): bool
    {
        if (self::$enabled === null) {
            self::$enabled = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'audit_log' AND column_name = 'row_hash'"
            )->fetchColumn();
        }
        return self::$enabled;
    }

    /**
     * The seal of one entry. Each value is written with its length (and null apart from ''), so no two
     * different entries read the same.
     *
     * @param array<string,mixed> $row the ROW_SQL columns
     * @param string|null $key the secret (tests pass a made-up one; the app uses key())
     */
    public static function seal(string $prev, array $row, ?string $key = null): string
    {
        $parts = [$prev];
        foreach (['id', 'created_us', 'actor_user_id', 'actor_role', 'action', 'target_type', 'target_id', 'detail_enc', 'ip'] as $field) {
            $parts[] = $row[$field] ?? null;
        }
        $text = '';
        foreach ($parts as $value) {
            $text .= $value === null ? 'n;' : 's' . strlen((string) $value) . ':' . $value . ';';
        }
        return hash_hmac('sha256', $text, $key ?? self::key());
    }

    /**
     * Adds an entry with its seal. Inside the caller's transaction when there is one (the lock then lasts
     * until that transaction ends), otherwise in its own.
     *
     * @param array{0:?int,1:?string,2:string,3:?string,4:?string,5:?string,6:?string} $values
     *        actor_user_id, actor_role, action, target_type, target_id, detail_enc, ip_address
     */
    public static function append(PDO $pdo, array $values): void
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            self::lock($pdo);
            $prev = $pdo->query('SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
            $prev = is_string($prev) ? $prev : self::anchor($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO audit_log (actor_user_id, actor_role, action, target_type, target_id, detail_enc, ip_address, prev_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING ' . self::ROW_SQL
            );
            $insert->execute([...$values, $prev]);
            $row = $insert->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare('UPDATE audit_log SET row_hash = ? WHERE id = ?')->execute([self::seal($prev, $row), $row['id']]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Seals the entries that have no seal yet, in id order. Used by the migration (existing entries) and
     * safe to run again. Must run inside a transaction.
     *
     * An unsealed entry with sealed ones after it is refused unless $resealAfterGap: sealing it would
     * also re-seal the entries after it, which could hide an edit (someone blanks a seal, then asks for
     * a re-run). The one innocent cause is entries written during the deploy, before the new code ran.
     *
     * @return int how many entries were sealed
     */
    public static function sealUnsealed(PDO $pdo, bool $resealAfterGap = false): int
    {
        self::lock($pdo);
        $first = $pdo->query('SELECT MIN(id) FROM audit_log WHERE row_hash IS NULL')->fetchColumn();
        if ($first === null || $first === false) {
            return 0;
        }
        $sealedAfter = $pdo->prepare('SELECT 1 FROM audit_log WHERE id > ? AND row_hash IS NOT NULL LIMIT 1');
        $sealedAfter->execute([$first]);
        if ($sealedAfter->fetchColumn() && !$resealAfterGap) {
            throw new RuntimeException("Entry #$first has no seal but entries after it do.");
        }
        $before = $pdo->prepare('SELECT row_hash FROM audit_log WHERE id < ? ORDER BY id DESC LIMIT 1');
        $before->execute([$first]);
        $prev = $before->fetchColumn();
        $prev = is_string($prev) ? $prev : self::anchor($pdo);

        $batch = $pdo->prepare('SELECT ' . self::ROW_SQL . ' FROM audit_log WHERE id >= ? ORDER BY id LIMIT 500');
        $update = $pdo->prepare('UPDATE audit_log SET prev_hash = ?, row_hash = ? WHERE id = ?');
        $from = (int) $first;
        $sealed = 0;
        do {
            $batch->execute([$from]);
            $rows = $batch->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $hash = self::seal($prev, $row);
                $update->execute([$prev, $hash, $row['id']]);
                $prev = $hash;
                $from = (int) $row['id'] + 1;
                $sealed++;
            }
        } while (count($rows) === 500);
        return $sealed;
    }

    /**
     * Checks every entry from the oldest: its seal matches its contents, and it links to the entry
     * before it. Reads one consistent snapshot of the log.
     *
     * @return array{intact:bool,checked:int,brokenId:?int,problem:?string} problem: start | changed | removed | unsealed
     */
    public static function verify(PDO $pdo): array
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        try {
            if (!self::anchorIntact($pdo)) {
                $first = $pdo->query('SELECT MIN(id) FROM audit_log')->fetchColumn();
                if ($own) {
                    $pdo->commit();
                }
                return ['intact' => false, 'checked' => 0, 'brokenId' => is_numeric($first) ? (int) $first : null, 'problem' => 'start'];
            }
            $expected = self::anchor($pdo);
            $batch = $pdo->prepare('SELECT ' . self::ROW_SQL . ', prev_hash, row_hash FROM audit_log WHERE id > ? ORDER BY id LIMIT 1000');
            $after = PHP_INT_MIN;
            $checked = 0;
            $result = null;
            do {
                $batch->execute([$after]);
                $rows = $batch->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $problem = match (true) {
                        !is_string($row['row_hash']) || !is_string($row['prev_hash']) => 'unsealed',
                        !hash_equals(self::seal($row['prev_hash'], $row), $row['row_hash']) => 'changed',
                        !hash_equals($expected, $row['prev_hash']) => 'removed',
                        default => null,
                    };
                    if ($problem !== null) {
                        $result = ['intact' => false, 'checked' => $checked, 'brokenId' => (int) $row['id'], 'problem' => $problem];
                        break 2;
                    }
                    $expected = $row['row_hash'];
                    $after = (int) $row['id'];
                    $checked++;
                }
            } while (count($rows) === 1000);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $result ?? ['intact' => true, 'checked' => $checked, 'brokenId' => null, 'problem' => null];
    }

    /**
     * Removes the oldest entries, up to and including $lastId, and makes the last removed seal the new
     * anchor, so the entries left still check out. Must run inside a transaction (the cleanup's).
     *
     * @return int how many entries were removed
     */
    public static function removeUpTo(PDO $pdo, int $lastId): int
    {
        $hash = null;
        if (self::enabled($pdo)) {
            self::lock($pdo);
            $stmt = $pdo->prepare('SELECT row_hash FROM audit_log WHERE id = ?');
            $stmt->execute([$lastId]);
            $hash = $stmt->fetchColumn();
        }
        $delete = $pdo->prepare('DELETE FROM audit_log WHERE id <= ?');
        $delete->execute([$lastId]);
        if (is_string($hash)) {
            self::setAnchor($pdo, $hash);
        }
        return $delete->rowCount();
    }

    /** The seal the chain starts from (ZERO when nothing was removed yet, or when the stored one is not genuine). */
    public static function anchor(PDO $pdo): string
    {
        $value = self::storedAnchor($pdo);
        return $value !== null && self::anchorIsGenuine($value) ? substr($value, 0, 64) : self::ZERO;
    }

    /**
     * The anchor is kept with its own tag ("seal:tag"), so someone with database access cannot move the
     * start of the chain to hide entries they removed from the beginning. A missing anchor is fine (the
     * chain then starts at ZERO); one with a wrong tag is not.
     */
    private static function anchorIntact(PDO $pdo): bool
    {
        $value = self::storedAnchor($pdo);
        return $value === null || self::anchorIsGenuine($value);
    }

    private static function storedAnchor(PDO $pdo): ?string
    {
        $stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $stmt->execute([self::ANCHOR_KEY]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private static function anchorIsGenuine(string $value): bool
    {
        return preg_match('/^([0-9a-f]{64}):([0-9a-f]{64})$/', $value, $m) === 1 && hash_equals(self::anchorTag($m[1]), $m[2]);
    }

    private static function anchorTag(string $hash): string
    {
        return hash_hmac('sha256', 'anchor|' . $hash, self::key());
    }

    private static function setAnchor(PDO $pdo, string $hash): void
    {
        $pdo->prepare(
            'INSERT INTO security_policies (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()'
        )->execute([self::ANCHOR_KEY, $hash . ':' . self::anchorTag($hash)]);
    }

    private static function lock(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('AuditChain needs a transaction for its lock.');
        }
        $pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([self::LOCK_ID]);
    }

    /** The sealing secret: derived from APP_AES_KEY, so it is never the encryption key itself. */
    private static function key(): string
    {
        $raw = base64_decode((string) envValue('APP_AES_KEY'), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('APP_AES_KEY must be set to a base64-encoded 32-byte key');
        }
        return hash_hmac('sha256', 'ProfilePath audit log seal v1', $raw, true);
    }
}
