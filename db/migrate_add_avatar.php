<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_data_url TEXT');
echo "avatar_data_url column added to users (or already present).\n";
