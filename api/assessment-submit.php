<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can submit the RIASEC assessment.'], 403);
}
// Never trust the client to have honestly gone through the access-code gate
// in assessment-instructions.html — re-check the session flag api/verify-access-code.php sets.
if (empty($_SESSION['assessmentUnlocked'])) {
    jsonResponse(['success' => false, 'error' => 'Enter the assessment access code before submitting.'], 403);
}

$body = readJsonBody();
$answers = $body['answers'] ?? null;

if (!is_array($answers) || count($answers) !== 60) {
    jsonResponse(['success' => false, 'error' => 'Expected exactly 60 answers.'], 400);
}
foreach ($answers as $a) {
    if (!is_int($a) || $a < 1 || $a > 5) {
        jsonResponse(['success' => false, 'error' => 'Each answer must be an integer from 1 to 5.'], 400);
    }
}

$pdo = Database::get();

// Same ordering as api/assessment-questions.php's GET, so answers[i] lines up with questions[i].
$questions = $pdo->query(
    "SELECT dimension FROM assessment_questions WHERE is_active = TRUE
     ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index"
)->fetchAll(PDO::FETCH_COLUMN);

if (count($questions) !== 60) {
    jsonResponse(['success' => false, 'error' => 'Question bank is not in its expected 60-question state.'], 500);
}

$totals = ['R' => 0, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0];
foreach ($questions as $i => $dimension) {
    $totals[$dimension] += $answers[$i];
}

$labels = ['R' => 'Realistic', 'I' => 'Investigate', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
$ranked = $totals;
arsort($ranked);
$topTypes = array_map(fn($code) => $labels[$code], array_slice(array_keys($ranked), 0, 3));

$studentId = (int) $user['id'];

// The DB layer already supports repeat attempts (attempt_number/is_latest),
// but nothing previously stopped a student from submitting a 2nd or 3rd
// attempt on their own — this is the missing gate: any attempt beyond the
// first requires an active, staff-granted retake (see retake_grants /
// api/retake-grants.php). Checked before the transaction so a student
// without a grant never gets past their first attempt.
$attemptCountStmt = $pdo->prepare('SELECT COUNT(*) FROM assessments WHERE student_id = ?');
$attemptCountStmt->execute([$studentId]);
$hasPriorAttempt = ((int) $attemptCountStmt->fetchColumn()) > 0;

$activeGrantId = null;
if ($hasPriorAttempt) {
    $grantStmt = $pdo->prepare(
        "SELECT id FROM retake_grants WHERE student_id = ? AND status = 'granted' AND completed_attempt_number IS NULL ORDER BY granted_at DESC LIMIT 1"
    );
    $grantStmt->execute([$studentId]);
    $activeGrantId = $grantStmt->fetchColumn();
    if ($activeGrantId === false) {
        AuditLogger::log($studentId, 'student', 'assessment_retake_denied', 'assessment', null, 'No active retake grant');
        jsonResponse(['success' => false, 'error' => 'You have already completed the assessment. Contact your Guidance Counselor if you need a retake.'], 403);
    }
    $activeGrantId = (int) $activeGrantId;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE assessments SET is_latest = FALSE WHERE student_id = ? AND is_latest = TRUE')->execute([$studentId]);

    $attemptStmt = $pdo->prepare('SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM assessments WHERE student_id = ?');
    $attemptStmt->execute([$studentId]);
    $attemptNumber = (int) $attemptStmt->fetchColumn();

    $insert = $pdo->prepare(
        'INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types)
         VALUES (?, ?, TRUE, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $insert->execute([
        $studentId, $attemptNumber,
        $totals['R'], $totals['I'], $totals['A'], $totals['S'], $totals['E'], $totals['C'],
        json_encode($topTypes),
    ]);
    $assessmentId = (int) $insert->fetchColumn();

    if ($activeGrantId !== null) {
        $pdo->prepare("UPDATE retake_grants SET status = 'completed', completed_attempt_number = ?, completed_at = NOW() WHERE id = ?")
            ->execute([$attemptNumber, $activeGrantId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to save assessment. Please try again.'], 500);
}

AuditLogger::log($studentId, 'student', 'submit_assessment', 'assessment', (string) $assessmentId, "Attempt #$attemptNumber, top: " . implode(', ', $topTypes));

jsonResponse(['success' => true, 'scores' => $totals, 'topTypes' => $topTypes]);
