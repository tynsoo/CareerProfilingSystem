<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/BookingLink.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../lib/CounselingTracking.php';

// "Invite" on Student Information: sends one student the CGC's counseling booking link by email, and shows the same invitation
// in the student's notifications. Only staff with Full on Update counseling status (set in the permission table) can invite.
// The link is the one saved on the Staff Help Center. A student with an active invitation (Invited or Booked) is not
// invited again: the answer says so, and staff update that invitation's status instead.
$user = Rbac::requireAccess('counselingStatus', 'full');
// Guidance Facilitators keep the counseling notes but never send invitations (DFD process 4.0), whatever the table says.
if (Rbac::isFacilitator($user)) {
    jsonResponse(['success' => false, 'error' => 'Only the Guidance Counselor and the Administrator can send counseling invitations.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = Database::get();
CounselingTracking::ensureSchema($pdo);
$body = readJsonBody();

// Booking status (panel revision S11): the CGC books sessions in its own system, which cannot tell ProfilePath whether the
// student booked or came. The Guidance Counselor or the Administrator records it here: booked (with the session date if
// known), attended, did not attend, declined or cancelled. Each change is kept on the invitation and in the Audit Log.
if (($body['type'] ?? '') === 'status') {
    $inviteId = (int) ($body['inviteId'] ?? 0);
    $status = (string) ($body['status'] ?? '');
    $sessionDate = trim((string) ($body['sessionDate'] ?? ''));
    if (!isset(CounselingTracking::BOOKING_STATUSES[$status])) {
        jsonResponse(['success' => false, 'error' => 'Choose a booking status.'], 400);
    }
    if ($sessionDate !== '' && (DateTime::createFromFormat('!Y-m-d', $sessionDate) === false || DateTime::createFromFormat('!Y-m-d', $sessionDate)->format('Y-m-d') !== $sessionDate)) {
        jsonResponse(['success' => false, 'error' => 'Enter the session date as a date.'], 400);
    }
    $inv = $pdo->prepare('SELECT ci.id, ci.status, ci.student_id, s.strand, s.section FROM counseling_invites ci JOIN students s ON s.user_id = ci.student_id WHERE ci.id = ?');
    $inv->execute([$inviteId]);
    $invite = $inv->fetch();
    if (!$invite) {
        jsonResponse(['success' => false, 'error' => 'Invitation not found.'], 404);
    }
    if (!StaffScope::allows(StaffScope::forUser($pdo, $user), $invite['strand'], $invite['section'])) {
        jsonResponse(['success' => false, 'error' => 'This student is not in one of your sections.'], 403);
    }
    $pdo->prepare('UPDATE counseling_invites SET status = ?, session_date = ?, status_updated_at = NOW(), status_updated_by = ? WHERE id = ?')
        ->execute([$status, $sessionDate !== '' ? $sessionDate : null, $user['id'], $inviteId]);
    AuditLogger::log($user['id'], $user['role'], 'update_counseling_status', 'student', (string) $invite['student_id'],
        'Invitation #' . $inviteId . ': ' . CounselingTracking::bookingLabel($invite['status']) . ' -> ' . CounselingTracking::bookingLabel($status)
            . ($sessionDate !== '' ? " (session $sessionDate)" : ''));
    jsonResponse(['success' => true, 'status' => $status, 'statusLabel' => CounselingTracking::bookingLabel($status)]);
}

$studentId = (int) ($body['studentId'] ?? 0);
// Sent from a flag in the Counseling Queue: the invitation is tied to that flag, so the queue shows its booking status.
$flagId = (int) ($body['flagId'] ?? 0);
if ($flagId > 0) {
    $f = $pdo->prepare('SELECT 1 FROM monitoring_flags WHERE id = ? AND student_id = ?');
    $f->execute([$flagId, $studentId]);
    if (!$f->fetchColumn()) {
        $flagId = 0;
    }
}

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
    jsonResponse(['success' => false, 'error' => 'The CGC booking link is not set yet. The Administrator adds it under CGC Counseling Booking Link on the Staff Help Center.'], 409);
}

// One active invitation per student: while the latest one is Invited or Booked (sent by staff, or the student's own Avail
// Counseling click), no second one is saved. The same lock as api/counseling-booking-link.php, so the two cannot cross.
$pdo->beginTransaction();
$pdo->prepare("SELECT pg_advisory_xact_lock(hashtext('counseling-avail:' || ?::text))")->execute([$studentId]);
$activeStmt = $pdo->prepare(
    "SELECT ci.id, ci.status, ci.flag_id, ci.created_at, ci.invited_by = ci.student_id AS self_requested
     FROM counseling_invites ci WHERE ci.student_id = ? ORDER BY ci.id DESC LIMIT 1"
);
$activeStmt->execute([$studentId]);
$active = $activeStmt->fetch();
if ($active && in_array($active['status'], CounselingTracking::ACTIVE_STATUSES, true)) {
    // Sent from a flag in the Counseling Queue: tie the active invitation to that flag, so the queue shows its status.
    if ($flagId > 0 && $active['flag_id'] === null) {
        $pdo->prepare('UPDATE counseling_invites SET flag_id = ? WHERE id = ?')->execute([$flagId, $active['id']]);
    }
    $pdo->commit();
    $since = (new DateTime((string) $active['created_at']))->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('M j, Y');
    jsonResponse(['success' => false, 'active' => true, 'inviteId' => (int) $active['id'], 'status' => $active['status'],
        'error' => 'This student already has an active counseling invitation (' . CounselingTracking::STATUS_DISPLAY[$active['status']]
            . ($active['self_requested'] ? ' via Avail Counseling' : '') . " since $since). Update its status instead of inviting again."], 409);
}

$rateKey = 'counseling-invite:' . $studentId;
if (RateLimiter::tooMany($rateKey, 1, 1440)) {
    $pdo->rollBack();
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
$insert = $pdo->prepare('INSERT INTO counseling_invites (student_id, invited_by, booking_url, email_sent, flag_id) VALUES (?, ?, ?, ?, ?) RETURNING id');
$insert->execute([$studentId, $user['id'], $bookingUrl, (int) $emailSent, $flagId > 0 ? $flagId : null]);
$inviteId = (int) $insert->fetchColumn();
$pdo->commit();

AuditLogger::log($user['id'], $user['role'], 'invite_to_counseling', 'student', (string) $studentId, $emailSent ? 'Booking link emailed and notified' : 'Notified; email not sent');

jsonResponse(['success' => true, 'inviteId' => $inviteId, 'emailSent' => $emailSent, 'hasEmail' => $canEmail]);