<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$existing = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$existing->execute(['admin']);
if ($existing->fetch()) {
    echo "Admin user already exists.\n";
    exit;
}

// Dev-only default password — must be changed before any real deployment.
$defaultPassword = 'ChangeMe123!';
$hash = password_hash($defaultPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('INSERT INTO users (role, username, password_hash, email, is_active) VALUES (?, ?, ?, ?, TRUE)');
$stmt->execute(['admin', 'admin', $hash, 'admin@profilepath.local']);

echo "Admin user created. Username: admin / Password: $defaultPassword (change this before deploying)\n";
