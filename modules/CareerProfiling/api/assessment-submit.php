<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/QuestionBank.php';
require_once __DIR__ . '/../lib/AssessmentVersion.php';
require_once __DIR__ . '/../lib/RiasecProfile.php';
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
// modules/CareerProfiling/pages/assessment-instructions.html. The unlock is kept in the database (modules/CareerProfiling/lib/AssessmentProgress.php,
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
$setId = $body['questionSetId'] ?? null;

if (!is_array($answers) || count($answers) === 0) {
    jsonResponse(['success' => false, 'error' => 'No answers were sent.'], 400);
}
// Answers must name the question set they were given for (modules/CareerProfiling/lib/AssessmentVersion.php).
// A page loaded before versioning sends a plain list of numbers: it cannot prove which questions it
// showed. The set is checked below against the active questions, not against the PHP session, so a
// student who signs in again and continues (saved progress) can still submit.
if (!is_int($setId)) {
    jsonResponse(['success' => false, 'error' => 'The questions were updated while you were answering. Please start the assessment again.'], 409);
}

$pdo = Database::get();
try {
    $set = AssessmentVersion::loadSet($pdo, $setId);
    $current = AssessmentVersion::fingerprint(AssessmentVersion::activeQuestions($pdo));
} catch (Throwable $e) {
    error_log('[assessment-submit] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Failed to save assessment. Please try again.'], 500);
}
// Scored only against the set the student was shown, and only while it is still the active one.
if ($set === null || $set['fingerprint'] !== $current) {
    jsonResponse(['success' => false, 'error' => 'The questions were updated while you were answering. Please start the assessment again.'], 409);
}
try {
    $scored = AssessmentVersion::score($set['questions'], $answers);
} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
// Each type's score is put on the same 10-50 scale every earlier result used (50 = 5 on every question of that type).
$totals = $scored['scores'];

// Top three types, highest first; equal scores in R, I, A, S, E, C order (modules/CareerProfiling/lib/RiasecProfile.php).
$topTypes = array_map(fn($code) => RiasecProfile::NAMES[$code], array_slice(RiasecProfile::order($totals), 0, 3));

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
        'INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types,
                                  question_set_id, answers_enc, scoring_version)
         VALUES (?, ?, TRUE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $insert->execute([
        $studentId, $attemptNumber,
        $totals['R'], $totals['I'], $totals['A'], $totals['S'], $totals['E'], $totals['C'],
        json_encode($topTypes),
        $set['id'], Crypto::enc(json_encode($scored['answers'])), AssessmentVersion::SCORING_VERSION,
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

jsonResponse(['success' => true, 'scores' => $totals, 'topTypes' => $topTypes]);
