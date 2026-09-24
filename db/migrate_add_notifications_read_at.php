<?php
// Adds the per-user "notifications read up to" marker used by the staff
// Notifications page. Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_read_at TIMESTAMPTZ');

echo "users.notifications_read_at is in place.\n";
