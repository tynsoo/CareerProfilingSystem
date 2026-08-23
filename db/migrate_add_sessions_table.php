<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sessions (
        id              VARCHAR(128) PRIMARY KEY,
        data            TEXT NOT NULL DEFAULT '',
        last_activity   TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
echo "sessions table created (or already present).\n";
