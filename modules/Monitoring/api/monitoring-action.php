<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../Counseling/lib/CounselingTracking.php';

// Acting on a monitoring flag. The escalation process (who may do what, which statuses are recorded) is described in
// modules/Counseling/lib/CounselingTracking.php:
//   approve  / dismiss  a flag in Needs Review: anyone with Full on Flagged student monitoring.
//   escalate            a flag in Needs Review to For Counseling: the same.
//   resolve             a flag For Counseling, with a counseling outcome: the Guidance Counselor or the Administrator
//                       only (Full on Update counseling status, never a Guidance Facilitator).
// Every closing writes resolved_at and resolved_by; the note is stored encrypted on the flag and in the Audit Log.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();
CounselingTracking::ensureSchema($pdo);

$body = readJsonBody();
$flagId = (int) ($body['flagId'] ?? 0);
$action = (string) ($body['action'] ?? '');
$note = trim((string) ($body['note'] ?? ''));
$outcome = (string) ($body['outcome'] ?? '');

if ($flagId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing flagId.'], 400);
}
if (!in_array($action, ['approve', 'escalate', 'dismiss', 'resolve'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);
}
if (mb_strlen($note) > 500) {
    jsonResponse(['success' => false, 'error' => 'The note can be at most 500 characters.'], 400);
}

$flagStmt = $pdo->prepare('SELECT id, student_id, status FROM monitoring_flags WHERE id = ?');
$flagStmt->execute([$flagId]);
$flag = $flagStmt->fetch();
if (!$flag) {
    jsonResponse(['success' => false, 'error' => 'Flag not found.'], 404);
}

// Which status each action starts from. A flag someone already closed stays as it is (it is listed on History).
$allowedFrom = [
    'approve' => ['pending'],
    'dismiss' => ['pending', 'escalated'],
    'escalate' => ['pending'],
    'resolve' => ['escalated'],
][$action];
if (!in_array($flag['status'], $allowedFrom, true)) {
    $error = $flag['status'] === 'escalated' && $action === 'approve'
        ? 'This student is already For Counseling. Close the flag with Record Outcome instead.'
        : 'This flag was already handled. Refresh the list to see its current status.';
    jsonResponse(['success' => false, 'error' => $error], 409);
}

$isCounselingStaff = Rbac::atLeast(Rbac::accessLevel('counselingStatus', Rbac::roleKey($user)), 'full') && !Rbac::isFacilitator($user);
// A flag that is For Counseling is closed by the Guidance Counselor or the Administrator, who hold the session.
if ($flag['status'] === 'escalated' && !$isCounselingStaff) {
    jsonResponse(['success' => false, 'error' => 'Only the Guidance Counselor or the Administrator can close a flag that is For Counseling.'], 403);
}

if ($action === 'resolve') {
    if (!in_array($outcome, CounselingTracking::COUNSELING_OUTCOMES, true)) {
        jsonResponse(['success' => false, 'error' => 'Choose how the counseling ended.'], 400);
    }
    // Closed: the outcome says how (a student who declined or did not attend is closed too, not hidden as "dismissed").
    $newStatus = 'approved';
} else {
    $newStatus = ['approve' => 'approved', 'escalate' => 'escalated', 'dismiss' => 'dismissed'][$action];
    $outcome = ['approve' => 'no_counseling_needed', 'dismiss' => 'not_valid', 'escalate' => ''][$action];
}
if ($action === 'dismiss' && $note === '') {
    jsonResponse(['success' => false, 'error' => 'Add a short note on why this flag is dismissed. It is kept in the flag history.'], 400);
}

$now = date('Y-m-d H:i:sO');
// "AND status = ?" makes two staff clicking at the same time safe: only the first one changes it.
if ($action === 'escalate') {
    $update = $pdo->prepare(
        'UPDATE monitoring_flags
         SET status = ?, escalated_at = ?, escalated_by = ?, counselor_id = COALESCE(?, counselor_id)
         WHERE id = ? AND status = ?'
    );
    $update->execute(['escalated', $now, $user['id'], $user['role'] === 'counselor' ? $user['id'] : null, $flagId, $flag['status']]);
} else {
    $update = $pdo->prepare(
        'UPDATE monitoring_flags
         SET status = ?, outcome = ?, resolved_at = ?, resolved_by = ?, resolution_note_enc = ?
         WHERE id = ? AND status = ?'
    );
    $update->execute([$newStatus, $outcome, $now, $user['id'], $note !== '' ? Crypto::enc($note) : null, $flagId, $flag['status']]);
}
if ($update->rowCount() === 0) {
    jsonResponse(['success' => false, 'error' => 'This flag was already handled. Refresh the list to see its current status.'], 409);
}

$detail = "Student user #{$flag['student_id']}"
    . ($outcome !== '' ? ' | Outcome: ' . CounselingTracking::outcomeLabel($outcome) : '')
    . ($note !== '' ? " | Note: {$note}" : '');
AuditLogger::log($user['id'], $user['role'], 'monitoring_' . $action, 'monitoring_flag', (string) $flagId, $detail);

jsonResponse(['success' => true, 'status' => $newStatus, 'outcome' => $outcome !== '' ? $outcome : null]);
