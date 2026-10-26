<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/QuestionBank.php';
require_once __DIR__ . '/../lib/RiasecInterpretation.php';
require_once __DIR__ . '/../lib/RetakeRequest.php';
require_once __DIR__ . '/../lib/AssessmentProgress.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can submit the RIASEC assessment.'], 403);
}
// Never trust the client to have honestly gone through the access-code gate in
// assessment-instructions.html. The unlock is kept in the database (modules/CareerProfiling/lib/AssessmentProgress.php,
// valid until the session ends plus a short grace period) and in the session.
$access = AssessmentProgress::access(Database::get(), (int) $user['id']);
if ($access['closed']) {
    jsonResponse(['success' => false, 'error' => 'The assessment session has ended. Your answers are saved: please ask your guidance counselor for a new session.'], 403);
}
if (!$access['unlocked'] && empty($_SESSION['assessmentUnlocked'])) {
    jsonResponse(['success' => false, 'error' => 'Enter the assessment access code before submitting.'], 403);
}

$body = readJsonBody();
$answers = $body['answers'] ?? null;

if (!is_array($answers) || count($answers) === 0) {
    jsonResponse(['success' => false, 'error' => 'No answers were sent.'], 400);
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

// Staff can add and remove questions, so the count is whatever is active now. The assessment is only open while every
// type has the same number of active questions; refuse a submission that was taken against a different set.
$perType = array_count_values($questions);
if (!QuestionBank::balance($perType)['ok']) {
    jsonResponse(['success' => false, 'error' => 'The assessment is being updated by the Guidance Office. Please try again later.'], 409);
}
if (count($answers) !== count($questions)) {
    jsonResponse(['success' => false, 'error' => 'The questions were updated while you were answering. Please start the assessment again.'], 409);
}

// Each type's score is put on the same 10-50 scale every earlier result used (50 = 5 on every question of that type).
$raw = ['R' => 0, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0];
foreach ($questions as $i => $dimension) {
    $raw[$dimension] += $answers[$i];
}
$totals = [];
foreach ($raw as $dimension => $sum) {
    $totals[$dimension] = QuestionBank::scaleScore($sum, (int) ($perType[$dimension] ?? 0));
}

$labels = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
$ranked = $totals;
arsort($ranked);
$topTypes = array_map(fn($code) => $labels[$code], array_slice(array_keys($ranked), 0, 3));

$studentId = (int) $user['id'];

// Each student takes the assessment once, unless the Guidance Office approved a retake (the earlier result is kept as
// an older attempt; attempt_number and is_latest tell them apart).
$attemptCountStmt = $pdo->prepare('SELECT COUNT(*) FROM assessments WHERE student_id = ?');
$attemptCountStmt->execute([$studentId]);
$retake = RetakeRequest::openFor($pdo, $studentId);
$retakeId = $retake !== null && $retake['status'] === 'approved' ? (int) $retake['id'] : null;
if (((int) $attemptCountStmt->fetchColumn()) > 0 && $retakeId === null) {
    AuditLogger::log($studentId, 'student', 'assessment_resubmit_denied', 'assessment', null, 'Assessment already completed');
    jsonResponse(['success' => false, 'error' => 'You have already completed the assessment.'], 403);
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

    if ($retakeId !== null) {
        // The approval is used up, and a flag raised on the earlier result no longer applies.
        $pdo->prepare("UPDATE retake_requests SET status = 'completed', completed_attempt_number = ?, completed_at = NOW() WHERE id = ? AND status = 'approved'")->execute([$attemptNumber, $retakeId]);
        $pdo->prepare("UPDATE monitoring_flags SET status = 'dismissed', resolved_at = NOW(), note = 'Replaced by a retake' WHERE student_id = ? AND status = 'pending'")->execute([$studentId]);
    }
    AssessmentProgress::clear($pdo, $studentId);   // submitted: nothing in progress any more

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to save assessment. Please try again.'], 500);
}

unset($_SESSION['assessmentUnlocked']); // one start, one submission
AuditLogger::log($studentId, 'student', 'submit_assessment', 'assessment', (string) $assessmentId, "Attempt #$attemptNumber, top: " . implode(', ', $topTypes));

jsonResponse(['success' => true, 'scores' => $totals, 'topTypes' => $topTypes, 'interpretation' => RiasecInterpretation::interpret($totals)]);
