<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/ExamSchedule.php';
require_once __DIR__ . '/../lib/RetakeRequest.php';
require_once __DIR__ . '/../lib/AssessmentProgress.php';
require_once __DIR__ . '/../lib/RiasecInterpretation.php';

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
    // Not taken yet: the RIASEC button is on only while one of the group's sessions is open.
    // unlocked: the code was already entered for a session that is still open (modules/CareerProfiling/lib/AssessmentProgress.php),
    // so the student continues without entering it again.
    jsonResponse([
        'completed' => false,
        'window' => ExamSchedule::windowFor(ExamSchedule::sessionsForStudent($pdo, (int) $user['id'])),
        'unlocked' => AssessmentProgress::access($pdo, (int) $user['id'])['unlocked'],
    ]);
}

$retake = RetakeRequest::stateFor($pdo, (int) $user['id']);

$scores = [
    'R' => (int) $row['score_r'],
    'I' => (int) $row['score_i'],
    'A' => (int) $row['score_a'],
    'S' => (int) $row['score_s'],
    'E' => (int) $row['score_e'],
    'C' => (int) $row['score_c'],
];
jsonResponse([
    'completed' => true,
    'completedAt' => $row['completed_at'],
    // 'none', 'pending', 'approved' (the student may retake now) or 'declined'
    'retake' => $retake,
    'retakeApproved' => $retake['status'] === 'approved',
    'scores' => $scores,
    // The stored code: equal scores in R, I, A, S, E, C order (display order only).
    'topTypes' => json_decode($row['top_types'], true),
    // What the scores establish, ties included (the same text the staff pages and downloads use).
    'interpretation' => RiasecInterpretation::interpret($scores),
]);
