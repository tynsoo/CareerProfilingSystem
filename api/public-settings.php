<?php

// Non-sensitive settings any logged-in user (any role) can read — unlike
// api/security-config.php, which is admin/counselor only. Keep this list
// short and deliberately limited to display-only values.

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Auth::requireLogin();
$pdo = Database::get();

$rows = $pdo->query(
    "SELECT key, value FROM security_policies WHERE key IN ('officeHours.text', 'academicYear.current')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

jsonResponse([
    'officeHours' => ['text' => $rows['officeHours.text'] ?? 'Mon–Fri, 8:00 AM–5:00 PM'],
    'academicYear' => ['current' => $rows['academicYear.current'] ?? ''],
]);
