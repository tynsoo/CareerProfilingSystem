<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/AnalyticsXlsx.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

// The analytics report as an Excel workbook (summary, completion-by-section chart, RIASEC chart, student list).
// The workbook is protected against editing. Each download gets its own random password, which is emailed to
// the person who downloaded it: they can unprotect the file with it if they need to change it, and nobody
// who is only passed a copy can. The password is not stored anywhere; it is only inside the email.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

// Every download sends an email: keep one person from using it to flood an inbox.
if (RateLimiter::tooMany('analytics-xlsx:' . (int) $user['id'], 10, 60)) {
    jsonResponse(['error' => 'Too many downloads in the last hour. Please try again later.'], 429);
}

$strand = trim((string) ($_GET['strand'] ?? ''));
if ($strand !== '' && !in_array($strand, ['STEM', 'ABM', 'ICT', 'HUMSS'], true)) {
    $strand = '';
}
$section = trim((string) ($_GET['section'] ?? ''));
$academicYear = AcademicYear::resolve($pdo, (string) ($_GET['academicYear'] ?? ''));

$account = $pdo->prepare('SELECT email, full_name, username FROM users WHERE id = ?');
$account->execute([(int) $user['id']]);
$me = $account->fetch();
$email = trim((string) ($me['email'] ?? ''));
if ($email === '') {
    // The password has to reach the person somehow; without an email on the account it would be lost.
    jsonResponse(['error' => 'Your account has no email address, so the edit password could not be sent to you. Ask the system administrator to add one.'], 409);
}
$name = trim((string) ($me['full_name'] ?? '')) !== '' ? $me['full_name'] : $me['username'];

$scope = StaffScope::forUser($pdo, $user);
$password = AnalyticsXlsx::generatePassword();
$xlsx = AnalyticsXlsx::build($pdo, $scope, $strand, $section, $password, (string) $name, $email, $academicYear);

$fileName = 'ProfilePath-report-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $academicYear) . ($strand !== '' ? '-' . $strand : '') . ($section !== '' ? '-' . preg_replace('/[^A-Za-z0-9]+/', '', $section) : '') . '-' . date('Y-m-d') . '.xlsx';

$safeName = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
$safeFile = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
$html = EmailTemplate::renderCode(
    'Edit password for your report',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p>"
        . "<p style=\"margin:0 0 12px 0;\">You downloaded <strong>$safeFile</strong> from ProfilePath. The workbook is protected so it can't be changed by accident.</p>"
        . '<p style="margin:0;">You only need the password below if you want to edit it: in Excel choose Review, then Unprotect Sheet, and enter it. Keep it private. Anyone you share the file with can read it but not change it unless they have this password.</p>',
    $password,
    'A new password is made every time you download the report.'
);
$text = "Hi $name,\n\nYou downloaded $fileName from ProfilePath. The workbook is protected so it can't be changed by accident.\n\nEdit password: $password\n\nIn Excel choose Review, then Unprotect Sheet, and enter it. Keep it private. A new password is made every time you download the report.";
$sent = Mailer::send($email, (string) $name, 'Edit password for your ProfilePath report', $html, $text);

if (!$sent && getenv('APP_ENV') !== 'local') {
    // Never hand over a locked file whose password did not get sent.
    AuditLogger::log($user['id'], $user['role'], 'export_analytics_xlsx_failed', 'analytics', null, 'Edit password email could not be sent');
    jsonResponse(['error' => 'The edit password could not be emailed, so the report was not downloaded. Please try again in a moment.'], 502);
}

AuditLogger::log($user['id'], $user['role'], 'export_analytics_xlsx', 'analytics', null, "$fileName" . ($sent ? ', edit password emailed' : ', local: password not emailed'));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($xlsx));
header('X-Password-Emailed-To: ' . $email);
if (!$sent) {
    // Local development has no email service: expose the password so the flow can still be tested.
    header('X-Edit-Password: ' . $password);
}
echo $xlsx;
exit;
