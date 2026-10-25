<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/Mismatch.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

// "Email these students" on Student Information: a reminder to everyone currently
// listed as Pending (hasn't taken the assessment), or a request to visit the Guidance
// Office for everyone whose Preferred Course isn't one of their Best RIASEC Match programs (Mismatched).
// A student is emailed at most once a day per kind, and students on the roster who
// haven't registered have no email, so they are skipped.
$user = Rbac::requireRole('admin', 'counselor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = Database::get();
$body = readJsonBody();
$kind = (string) ($body['kind'] ?? '');
$strand = trim((string) ($body['strand'] ?? ''));
$section = trim((string) ($body['section'] ?? ''));
if (!in_array($kind, ['pending', 'mismatch'], true)) {
    jsonResponse(['success' => false, 'error' => 'Choose Pending or Mismatched students to email.'], 400);
}

$conds = ["u.is_active = TRUE", "u.email IS NOT NULL", "u.email_verified_at IS NOT NULL"];
$params = [];
if ($strand !== '') {
    $conds[] = 's.strand = ?';
    $params[] = $strand;
}
if ($section !== '') {
    $conds[] = 'LOWER(s.section) = LOWER(?)';
    $params[] = $section;
}
$scope = StaffScope::forUser($pdo, $user);
$stmt = $pdo->prepare(
    'SELECT s.user_id, s.strand, s.section, u.email, s.first_name_enc,
            EXISTS (SELECT 1 FROM assessments a WHERE a.student_id = s.user_id AND a.is_latest = TRUE AND a.completed_at IS NOT NULL) AS completed
     FROM students s JOIN users u ON u.id = s.user_id
     WHERE ' . implode(' AND ', $conds) . '
     ORDER BY s.user_id'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$mismatchIds = $kind === 'mismatch' ? Mismatch::studentIds($pdo) : [];
$link = rtrim((string) getenv('APP_URL'), '/') . '/student-login';

$sent = $skippedToday = $failed = 0;
$targets = 0;
foreach ($rows as $r) {
    if (!StaffScope::allows($scope, $r['strand'], $r['section'])) {
        continue; // not one of this staff member's sections
    }
    $completed = (bool) $r['completed'];
    $uid = (int) $r['user_id'];
    if ($kind === 'pending' && $completed) {
        continue;
    }
    if ($kind === 'mismatch' && !($completed && isset($mismatchIds[$uid]))) {
        continue;
    }
    $targets++;
    if ($targets > 500) { // a section is a few dozen students; this only stops a runaway request
        break;
    }
    if (RateLimiter::tooMany("student-reminder:$kind:$uid", 1, 1440)) {
        $skippedToday++;
        continue;
    }

    $first = (string) Crypto::dec($r['first_name_enc']);
    $safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
    if ($kind === 'pending') {
        $subject = 'Reminder: your RIASEC career assessment';
        $heading = 'Your career assessment is waiting';
        $message = 'You have not taken the RIASEC career assessment yet. Please check My Assessment Schedule on your Assessments page for the date, time and room, and take the assessment when it opens. If you have any questions, please visit the Guidance Office.';
    } else {
        $subject = 'Please visit the Guidance Office';
        $heading = 'Please visit the Guidance Office';
        $message = 'Thank you for taking the RIASEC career assessment. Please visit the Guidance Office so a counselor can talk with you about your results and your career options.';
    }
    $html = EmailTemplate::render($heading, "<p style=\"margin:0 0 12px 0;\">Hi $safeFirst,</p><p style=\"margin:0;\">$message</p>", 'Go to ProfilePath', $link, '');
    $ok = Mailer::send($r['email'], $first, $subject, $html, "Hi $first,\n\n$message\n\n$link");
    if ($ok) {
        $sent++;
    } else {
        $failed++;
        // Not delivered, so it shouldn't use up this student's one email for the day.
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute(["student-reminder:$kind:$uid"]);
    }
}

AuditLogger::log($user['id'], $user['role'], 'send_student_reminders', 'student', $kind, "$sent sent, $skippedToday already emailed today, $failed failed");

jsonResponse([
    'success' => true,
    'kind' => $kind,
    'listed' => $targets,
    'sent' => $sent,
    'alreadyEmailedToday' => $skippedToday,
    'failed' => $failed,
]);
