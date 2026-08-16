<?php
require_once __DIR__ . '/../lib/Database.php';

$sql = file_get_contents(__DIR__ . '/schema.sql');
$pdo = Database::get();
$pdo->exec($sql);
echo "Schema applied successfully.\n";

$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
echo count($tables) . " tables created:\n";
foreach ($tables as $t) {
    echo "  - $t\n";
}
