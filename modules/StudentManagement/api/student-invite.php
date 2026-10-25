<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

// "Invite" on the Student Information dashboard: emails a student who is on the class roster but has not made an account
// yet, with the link to register. POST { rosterId } invites one student; POST { all: true, strand?, section? } invites
// everyone on the current roster who is still unregistered (within the staff member's sections). A student is invited at
// most once a day. The roster email is only used here and is never sent back to the page.
$user = Rbac::requireRole('admin', 'counselor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = Database::get();
$body = readJsonBody();
$ay = AcademicYear::current();
$scope = StaffScope::forUser($pdo, $user);
$link = rtrim((string) getenv('APP_URL'), '/') . '/student-register';

$conds = ['r.academic_year = ?', "r.email IS NOT NULL", "r.email <> ''",
          'NOT EXISTS (SELECT 1 FROM students s WHERE LOWER(s.school_id) = LOWER(r.school_id))'];
$params = [$ay];
$single = isset($body['rosterId']);
if ($single) {
    $conds[] = 'r.id = ?';
    $params[] = (int) $body['rosterId'];
} elseif (!empty($body['all'])) {
    $strand = trim((string) ($body['strand'] ?? ''));
    $section = trim((string) ($body['section'] ?? ''));
    if ($strand !== '') { $conds[] = 'r.strand = ?'; $params[] = $strand; }
    if ($section !== '') { $conds[] = 'LOWER(r.section) = LOWER(?)'; $params[] = $section; }
} else {
    jsonResponse(['success' => false, 'error' => 'Choose a student to invite.'], 400);
}

$stmt = $pdo->prepare('SELECT r.id, r.name_enc, r.strand, r.section, r.email FROM assessment_roster r WHERE ' . implode(' AND ', $conds) . ' ORDER BY r.id');
$stmt->execute($params);
$rows = array_values(array_filter($stmt->fetchAll(), fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));

if ($single && !$rows) {
    jsonResponse(['success' => false, 'error' => 'This student cannot be invited: they may already have an account, or the roster has no email for them.'], 409);
}

/** "SOLIS, CHRISTEEN MAE" -> "Christeen" (roster names are LASTNAME, FIRSTNAME MIDDLE). */
function firstNameOf(string $rosterName): string
{
    $after = str_contains($rosterName, ',') ? trim(substr($rosterName, strpos($rosterName, ',') + 1)) : trim($rosterName);
    $first = preg_split('/\s+/', $after)[0] ?? '';
    return mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');
}

$sent = $skippedToday = $failed = 0;
foreach (array_slice($rows, 0, 500) as $r) { // a section is a few dozen students; this only stops a runaway request
    $key = 'student-invite:' . (int) $r['id'];
    if (RateLimiter::tooMany($key, 1, 1440)) {
        $skippedToday++;
        continue;
    }
    $first = firstNameOf((string) Crypto::dec($r['name_enc']));
    $safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
    $subject = 'Create your ProfilePath account';
    $message = 'The Center for Guidance and Counseling invites you to create your ProfilePath account so you can take the career assessment. Register with your Student Number and your school email, the one this message was sent to.';
    $html = EmailTemplate::render('Create your ProfilePath account', "<p style=\"margin:0 0 12px 0;\">Hi $safeFirst,</p><p style=\"margin:0;\">$message</p>", 'Create my account', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), '');
    $ok = Mailer::send($r['email'], $first, $subject, $html, "Hi $first,\n\n$message\n\n$link");
    if ($ok) {
        $sent++;
    } else {
        $failed++;
        // Not delivered, so it shouldn't use up this student's one email for the day.
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute([$key]);
    }
}

AuditLogger::log($user['id'], $user['role'], 'invite_unregistered_students', 'student', $single ? (string) (int) $body['rosterId'] : 'all', "$sent sent, $skippedToday already invited today, $failed failed");

jsonResponse(['success' => true, 'listed' => count($rows), 'sent' => $sent, 'alreadyInvitedToday' => $skippedToday, 'failed' => $failed]);