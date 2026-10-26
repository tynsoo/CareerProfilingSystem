<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../../CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../lib/CareerMatcher.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/ResultEmail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can submit the Career Worksheet.'], 403);
}

$body = readJsonBody();
$career = CareerMatcher::sanitize((string) ($body['career'] ?? ''));
$electives = $body['electives'] ?? [];

if ($career === null) {
    jsonResponse(['success' => false, 'error' => 'Please type the career you want to take (letters and numbers only, up to 80 characters).'], 400);
}
if (!is_array($electives) || count($electives) === 0) {
    jsonResponse(['success' => false, 'error' => 'Please select at least one elective.'], 400);
}
$electives = array_values(array_map('strval', $electives));

$pdo = Database::get();
$studentId = (int) $user['id'];

$assessmentStmt = $pdo->prepare(
    'SELECT id, attempt_number, score_r, score_i, score_a, score_s, score_e, score_c, top_types
     FROM assessments WHERE student_id = ? AND is_latest = TRUE'
);
$assessmentStmt->execute([$studentId]);
$assessment = $assessmentStmt->fetch();
if (!$assessment) {
    jsonResponse(['success' => false, 'error' => 'Complete the RIASEC assessment before submitting the worksheet.'], 400);
}

// RIASEC totals (10-50 scale); used only to link the typed career to a program.
$scores = [
    'R' => (int) $assessment['score_r'], 'I' => (int) $assessment['score_i'], 'A' => (int) $assessment['score_a'],
    'S' => (int) $assessment['score_s'], 'E' => (int) $assessment['score_e'], 'C' => (int) $assessment['score_c'],
];

$allPrograms = CareerMatcher::loadPrograms($pdo);

// Link the typed career to a program: that program is the student's Preferred Course
// (null if nothing is close enough; the CBF result does not depend on it either way).
$programId = CareerMatcher::resolve($career, $allPrograms, $scores);

$pdo->beginTransaction();
try {
    $attemptNumber = (int) $assessment['attempt_number'];

    $worksheetInsert = $pdo->prepare(
        'INSERT INTO worksheets (student_id, attempt_number, stated_program_id, stated_career, electives, top_types)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (student_id, attempt_number) DO UPDATE
         SET stated_program_id = EXCLUDED.stated_program_id, stated_career = EXCLUDED.stated_career,
             electives = EXCLUDED.electives, top_types = EXCLUDED.top_types, submitted_at = NOW()
         RETURNING id'
    );
    $worksheetInsert->execute([
        $studentId, $attemptNumber, $programId, $career, Careers::toLiteral($electives), $assessment['top_types'],
    ]);
    $worksheetId = (int) $worksheetInsert->fetchColumn();

    // CBF (RIASEC profile vs every program's RIASEC code, cosine similarity), the Preferred
    // Course compared with it, and the low-confidence monitoring flag (modules/ContentBasedFiltering/lib/CBFData.php).
    $saved = CBFData::saveRecommendation($pdo, $studentId, CBFData::studentProfile($pdo, $studentId, $electives), $programId, (int) $assessment['id'], $worksheetId);
    if ($saved === null) {
        throw new RuntimeException('No active programs.');
    }
    $recommendationId = $saved['recommendationId'];
    $topScore = $saved['topScore'];

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[worksheet-submit] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Failed to save worksheet. Please try again.'], 500);
}

AuditLogger::log($studentId, 'student', 'submit_worksheet', 'worksheet', (string) $worksheetId, "Top match score: $topScore");

// Automatic result email, no staff action: a thank-you with the Best RIASEC Match, or a request to see the
// Guidance Office when the Preferred Course differs from it (status from modules/ContentBasedFiltering/lib/RecommendationPipeline.php).
ResultEmail::send($pdo, $studentId, $saved['status'] === 'mismatch', $saved['bestRiasecMatchTitles'], $saved['allProgramsTied'] ?? false);

jsonResponse(['success' => true, 'worksheetId' => $worksheetId, 'recommendationId' => $recommendationId]);
