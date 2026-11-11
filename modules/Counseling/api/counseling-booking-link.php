<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/BookingLink.php';
require_once __DIR__ . '/../lib/CounselingTracking.php';

// "Avail Counseling" on the Student Help Center: records that the signed-in student asked for counseling and returns the
// CGC's counseling booking link, which the page opens at once (no email round trip). The link is the one the guidance staff
// saved on the Staff Help Center (security_policies booking.url, modules/Counseling/lib/BookingLink.php).
//
// The request is kept as a counseling invitation with the status "invited" (invited_by = the student, so staff see that
// the student asked through Avail Counseling). Opening the page books nothing: the student books on the CGC page, and the
// Guidance Counselor or the Administrator records the booking afterwards (api/counseling-invite.php type status).
// No duplicates: while the student has an open invitation (invited or booked, from staff or from this button), it is
// reused and nothing new is saved; a lock per student keeps a double click from saving two.
// Once a day: a student can use the button once in 24 hours, so the booking link is not opened over and over; a repeat
// click is refused with the time it frees up (counted only when the link was given, inside the same lock).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can use Avail Counseling.'], 403);
}
$pdo = Database::get();
CounselingTracking::ensureSchema($pdo);
$studentId = (int) $user['id'];

$setting = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
$setting->execute([BookingLink::KEY]);
$bookingUrl = BookingLink::validate((string) ($setting->fetchColumn() ?: ''), BookingLink::requestHost())['url'];
if ($bookingUrl === '') {
    jsonResponse(['success' => false, 'error' => 'Online counseling booking is not available yet. Please contact the Guidance and Counseling Center, or send them a message below.'], 409);
}

$active = $pdo->prepare('SELECT u.is_active FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?');
$active->execute([$studentId]);
if (!$active->fetchColumn()) {
    jsonResponse(['success' => false, 'error' => 'Your account cannot book counseling right now. Please contact the Guidance and Counseling Center.'], 409);
}

$pdo->beginTransaction();
try {
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext('counseling-avail:' || ?::text))")->execute([$studentId]);
    $dailyKey = 'counseling-avail:' . $studentId;
    if (RateLimiter::isLimited($dailyKey, 1, 1440)) {
        $last = $pdo->prepare('SELECT MAX(created_at) + INTERVAL \'1440 minutes\' FROM rate_limit_hits WHERE rate_key = ?');
        $last->execute([$dailyKey]);
        $againAt = $last->fetchColumn();
        $pdo->rollBack();
        jsonResponse(['success' => false, 'repeat' => true, 'againAt' => $againAt,
            'error' => 'You already used Avail Counseling today. You can use it again after ' . (new DateTime((string) $againAt))->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('M j, g:i A') . '. If you still need to book, check the CGC booking page you opened, or contact the Guidance and Counseling Center.'], 429);
    }
    $open = $pdo->prepare(
        "SELECT id FROM counseling_invites WHERE student_id = ? AND status IN ('invited', 'booked') ORDER BY id DESC LIMIT 1"
    );
    $open->execute([$studentId]);
    $inviteId = (int) ($open->fetchColumn() ?: 0);
    $created = false;
    if ($inviteId === 0) {
        // Tie it to the student's flag that is For Counseling, if any, so the Counseling Queue shows its booking status.
        $flag = $pdo->prepare("SELECT id FROM monitoring_flags WHERE student_id = ? AND status = 'escalated' ORDER BY id DESC LIMIT 1");
        $flag->execute([$studentId]);
        $flagId = $flag->fetchColumn();
        $insert = $pdo->prepare(
            "INSERT INTO counseling_invites (student_id, invited_by, booking_url, email_sent, flag_id, status) VALUES (?, ?, ?, FALSE, ?, 'invited') RETURNING id"
        );
        $insert->execute([$studentId, $studentId, $bookingUrl, $flagId !== false ? (int) $flagId : null]);
        $inviteId = (int) $insert->fetchColumn();
        $created = true;
    }
    RateLimiter::hit($dailyKey);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

if ($created) {
    AuditLogger::log($user['id'], $user['role'], 'avail_counseling', 'student', (string) $studentId,
        'Invitation #' . $inviteId . ': student opened the CGC booking link through Avail Counseling (status Invited)');
}
jsonResponse(['success' => true, 'url' => $bookingUrl, 'inviteId' => $inviteId, 'created' => $created]);
