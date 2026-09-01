<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students take the RIASEC assessment.'], 403);
}

$body = readJsonBody();
$submitted = strtoupper(trim((string) ($body['code'] ?? '')));

if ($submitted === '') {
    jsonResponse(['success' => false, 'error' => 'Enter the assessment access code.'], 400);
}

$pdo = Database::get();

// Prefer the code from the Exam Schedule that actually matches this
// student's group (strand/section/grade/AY, NULL columns = wildcard) over
// the old single blanket code — this is what makes Exam Scheduling a real
// gate instead of a purely informational panel. Falls back to the global
// security_policies code only when this student's group has no exam
// schedule created for it at all, so access doesn't hard-lock the moment
// this feature ships into a database with no schedules yet (as of this
// change, exam_schedules is empty on the live DB).
$studentStmt = $pdo->prepare('SELECT strand, section, grade_level, academic_year FROM students WHERE user_id = ?');
$studentStmt->execute([$user['id']]);
$student = $studentStmt->fetch();

$candidates = [];
if ($student && $student['academic_year']) {
    $stmt = $pdo->prepare(
        'SELECT id, access_code FROM exam_schedules
         WHERE academic_year = ?
           AND (grade_level IS NULL OR grade_level = ?)
           AND (strand IS NULL OR strand = ?)
           AND (section IS NULL OR section = ?)'
    );
    $stmt->execute([$student['academic_year'], $student['grade_level'], $student['strand'], $student['section']]);
    $candidates = $stmt->fetchAll();
}

$matchedScheduleId = null;
if ($candidates) {
    // This student's group has at least one scheduled exam — the code
    // must match one of those now, not the old blanket code. Looped and
    // hash_equals-compared in PHP (never in SQL) for the same
    // timing-safe-comparison reason as the global code below.
    foreach ($candidates as $c) {
        if (hash_equals((string) $c['access_code'], $submitted)) {
            $matchedScheduleId = (int) $c['id'];
            break;
        }
    }
    if ($matchedScheduleId === null) {
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
        jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
    }
} else {
    // No exam schedule exists yet for this student's group — fall back to
    // the single global code, same behavior as before this change.
    $actual = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'assessment.accessCode'")->fetchColumn();
    if ($actual === '' || !hash_equals($actual, $submitted)) {
        AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
        jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
    }
}

$_SESSION['assessmentUnlocked'] = true;
AuditLogger::log(
    $user['id'],
    'student',
    'access_code_verified',
    'assessment',
    null,
    $matchedScheduleId !== null ? "Matched exam schedule #$matchedScheduleId" : null
);
jsonResponse(['success' => true]);
