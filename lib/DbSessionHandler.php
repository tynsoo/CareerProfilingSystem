<?php

require_once __DIR__ . '/Database.php';

/**
 * Stores PHP sessions in Postgres instead of local disk. Render's free web
 * service tier spins the container down after ~15 minutes idle and boots a
 * fresh one on the next request — local disk (including whatever
 * session.save_path points at) does not survive that, so file-based
 * sessions get silently wiped mid-use. Postgres is the one thing in this
 * deployment that actually persists across restarts.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::get();
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();
        return $data !== false ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->pdo->prepare(
            'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, NOW())
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = NOW()'
        )->execute([$id, $data]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_activity < NOW() - (? * INTERVAL '1 second')");
        $stmt->execute([$max_lifetime]);
        return $stmt->rowCount();
    }
}
