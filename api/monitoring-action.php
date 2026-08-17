<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

$body = readJsonBody();
$flagId = (int) ($body['flagId'] ?? 0);
$action = (string) ($body['action'] ?? '');
$note = trim((string) ($body['note'] ?? ''));

if ($flagId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing flagId.'], 400);
}
if (!in_array($action, ['approve', 'escalate', 'dismiss'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);
}

$flagStmt = $pdo->prepare('SELECT id, student_id, status FROM monitoring_flags WHERE id = ?');
$flagStmt->execute([$flagId]);
$flag = $flagStmt->fetch();
if (!$flag) {
    jsonResponse(['success' => false, 'error' => 'Flag not found.'], 404);
}

$statusMap = ['approve' => 'approved', 'escalate' => 'escalated', 'dismiss' => 'dismissed'];
$newStatus = $statusMap[$action];
$resolvesFlag = $action !== 'escalate'; // escalating moves it to the counseling queue, it isn't "resolved" yet
$counselorId = ($action === 'escalate' && $user['role'] === 'counselor') ? $user['id'] : null;

$update = $pdo->prepare(
    'UPDATE monitoring_flags
     SET status = ?, note = ?, counselor_id = COALESCE(?, counselor_id), resolved_at = ?
     WHERE id = ?'
);
$update->execute([
    $newStatus,
    $note !== '' ? $note : null,
    $counselorId,
    $resolvesFlag ? date('Y-m-d H:i:sO') : null,
    $flagId,
]);

AuditLogger::log(
    $user['id'], $user['role'], 'monitoring_' . $action, 'monitoring_flag', (string) $flagId,
    $note !== '' ? $note : "Student user #{$flag['student_id']}"
);

jsonResponse(['success' => true, 'status' => $newStatus]);
