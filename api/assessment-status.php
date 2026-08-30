<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['error' => 'Students only'], 403);
}

$pdo = Database::get();
$stmt = $pdo->prepare(
    'SELECT score_r, score_i, score_a, score_s, score_e, score_c, top_types, completed_at
     FROM assessments WHERE student_id = ? AND is_latest = TRUE'
);
$stmt->execute([(int) $user['id']]);
$row = $stmt->fetch();

if (!$row) {
    jsonResponse(['completed' => false, 'retakeAvailable' => false]);
}

// Whether this student has an active, unused staff-granted retake (see
// retake_grants / api/retake-grants.php) — surfaced here since students
// can't call the staff-only api/retake-grants.php themselves.
$grantStmt = $pdo->prepare(
    "SELECT 1 FROM retake_grants WHERE student_id = ? AND status = 'granted' AND completed_attempt_number IS NULL"
);
$grantStmt->execute([(int) $user['id']]);
$retakeAvailable = (bool) $grantStmt->fetchColumn();

jsonResponse([
    'completed' => true,
    'completedAt' => $row['completed_at'],
    'scores' => [
        'R' => (int) $row['score_r'],
        'I' => (int) $row['score_i'],
        'A' => (int) $row['score_a'],
        'S' => (int) $row['score_s'],
        'E' => (int) $row['score_e'],
        'C' => (int) $row['score_c'],
    ],
    'topTypes' => json_decode($row['top_types'], true),
    'retakeAvailable' => $retakeAvailable,
]);
