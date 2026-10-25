<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/BookingLink.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

// "Invite" on Student Information: sends one student the CGC's counseling booking link by email, and shows the same invitation
// in the student's notifications. Only staff with Full on Counseling Notes (set in the permission table) can invite.
// The link is the one saved in Security Configuration.
$user = Rbac::requireAccess('counselingNotes', 'full');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = Database::get();
$body = readJsonBody();
$studentId = (int) ($body['studentId'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT s.user_id, s.strand, s.section, s.first_name_enc, u.email, u.email_verified_at, u.is_active
     FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?'
);
$stmt->execute([$studentId]);
$student = $stmt->fetch();
if (!$student) {
    jsonResponse(['success' => false, 'error' => 'Student not found.'], 404);
}
if (!StaffScope::allows(StaffScope::forUser($pdo, $user), $student['strand'], $student['section'])) {
    jsonResponse(['success' => false, 'error' => 'This student is not in one of your sections.'], 403);
}
if (!$student['is_active']) {
    jsonResponse(['success' => false, 'error' => 'This account is deactivated.'], 409);
}

$setting = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
$setting->execute([BookingLink::KEY]);
$bookingUrl = BookingLink::validate((string) ($setting->fetchColumn() ?: ''))['url'];
if ($bookingUrl === '') {
    jsonResponse(['success' => false, 'error' => 'The CGC booking link is not set yet. Add it in Security Configuration first.'], 409);
}

$rateKey = 'counseling-invite:' . $studentId;
if (RateLimiter::tooMany($rateKey, 1, 1440)) {
    jsonResponse(['success' => false, 'error' => 'This student was already invited today.'], 429);
}

$first = (string) Crypto::dec($student['first_name_enc']);
$canEmail = $student['email'] !== null && $student['email_verified_at'] !== null;
$emailSent = false;
if ($canEmail) {
    $safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
    $message = 'The Center for Guidance and Counseling invites you to a counseling session. Use the button below to pick a time that works for you.';
    $html = EmailTemplate::render(
        'You are invited to book a counseling session',
        "<p style=\"margin:0 0 12px 0;\">Hi $safeFirst,</p><p style=\"margin:0;\">$message</p>",
        'Book a counseling session',
        htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8'),
        ''
    );
    $emailSent = Mailer::send($student['email'], $first, 'You are invited to book a counseling session', $html, "Hi $first,\n\n$message\n\n$bookingUrl");
}

// The notification is the record: it appears on the student's Notifications whether or not the email got through.
$insert = $pdo->prepare('INSERT INTO counseling_invites (student_id, invited_by, booking_url, email_sent) VALUES (?, ?, ?, ?) RETURNING id');
$insert->execute([$studentId, $user['id'], $bookingUrl, (int) $emailSent]);
$inviteId = (int) $insert->fetchColumn();

AuditLogger::log($user['id'], $user['role'], 'invite_to_counseling', 'student', (string) $studentId, $emailSent ? 'Booking link emailed and notified' : 'Notified; email not sent');

jsonResponse(['success' => true, 'inviteId' => $inviteId, 'emailSent' => $emailSent, 'hasEmail' => $canEmail]);