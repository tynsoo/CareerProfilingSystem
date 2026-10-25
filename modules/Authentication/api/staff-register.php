<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffName.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffEmail.php';
require_once __DIR__ . '/../../UserManagement/lib/Consent.php';

// Public staff (Guidance Counselor / Guidance Facilitator) sign-up. No password is chosen here:
// the account starts 'pending' with an unusable password, the Head of Guidance (admin) approves it
// in Account Management, and the approval emails a one-time temporary access code. The person
// enters that code on the activation page and creates their own password (api/staff-activate.php),
// so the approver never sees a password. Admins are never created here.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// A public form: keep one IP from using it to flood the approval list.
// The real visitor address (behind Render's proxy too; modules/AuditLog/lib/AuditLogger.php), so one visitor cannot use up the limit for everyone.
$ip = AuditLogger::clientIpFrom($_SERVER) ?? 'unknown';
if (RateLimiter::tooMany('staff-register:' . $ip, 5, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many sign-up attempts. Please try again later.'], 429);
}

$pdo = Database::get();
$body = readJsonBody();
// Last name, first name and middle initial come as three inputs and are stored as "Last, First M."
$composed = StaffName::compose((string) ($body['lastName'] ?? ''), (string) ($body['firstName'] ?? ''), (string) ($body['middleInitial'] ?? ''));
$fullName = $composed ?? StaffName::normalize((string) ($body['fullName'] ?? ''));
$position = (string) ($body['position'] ?? '');
$email = trim((string) ($body['email'] ?? ''));
$username = trim((string) ($body['username'] ?? ''));
$privacyConsent = $body['privacyConsent'] ?? false;

if (trim((string) ($body['lastName'] ?? '')) === '' && trim((string) ($body['fullName'] ?? '')) === '' || $email === '' || $username === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
if (isset($body['lastName']) && $composed === null) {
    jsonResponse(['success' => false, 'error' => 'Enter your last name and first name using letters only, and a single letter for your middle initial.'], 400);
}
if (!StaffPosition::isValid($position)) {
    jsonResponse(['success' => false, 'error' => 'Choose whether you are a Guidance Counselor or a Guidance Facilitator.'], 400);
}
if ($privacyConsent !== true) {
    jsonResponse(['success' => false, 'error' => 'You must agree to the Data Privacy Policy to create an account.'], 400);
}
if (mb_strlen($fullName) > 150) {
    jsonResponse(['success' => false, 'error' => 'Name must be 150 characters or fewer.'], 400);
}
if (!StaffName::isValid($fullName)) {
    jsonResponse(['success' => false, 'error' => StaffName::FORMAT_MESSAGE], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
}
// School email only (@mcl.edu.ph).
$emailCheck = StaffEmail::check($email);
if (!$emailCheck['ok']) {
    jsonResponse(['success' => false, 'error' => $emailCheck['error']], 400);
}
if (!StaffEmail::isValidUsername($username)) {
    jsonResponse(['success' => false, 'error' => StaffEmail::USERNAME_MESSAGE], 400);
}

$existing = $pdo->prepare('SELECT username, email FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)');
$existing->execute([$email, $username]);
$clash = $existing->fetch();
if ($clash) {
    $emailTaken = strtolower((string) $clash['email']) === strtolower($email);
    jsonResponse(['success' => false, 'error' => $emailTaken ? 'An account with this email already exists.' : 'That username is already taken. Please choose another.'], 409);
}

// Nobody can sign in with this: the real password is created after approval, with the emailed code.
$unusable = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

// One transaction: the account and the receipt of its privacy consent (modules/UserManagement/lib/Consent.php) are saved together.
$pdo->beginTransaction();
try {
    $userStmt = $pdo->prepare(
        // Counselors and facilitators share the 'counselor' system role; staff_position
        // decides what a facilitator may change (view-only, see modules/Authentication/lib/Rbac.php).
        "INSERT INTO users (role, username, password_hash, email, full_name, staff_position, is_active, approval_status)
         VALUES ('counselor', ?, ?, ?, ?, ?, TRUE, 'pending') RETURNING id"
    );
    $userStmt->execute([$username, $unusable, $email, $fullName, $position]);
    $userId = (int) $userStmt->fetchColumn();
    Consent::record($pdo, $userId, 'staff_signup');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[staff-register] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Sign-up failed. Please try again.'], 500);
}

AuditLogger::log($userId, 'counselor', 'staff_signup', 'user', (string) $userId, "Staff sign-up awaiting approval: $fullName (" . StaffPosition::label($position) . ')');

$safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
$bodyHtml = EmailTemplate::render(
    'We received your sign-up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p>"
        . '<p style="margin:0;">Thanks for signing up for ProfilePath. The Head of Guidance will review your request. Once it is approved, we will email you a temporary access code so you can create your password and sign in.</p>',
    'Go to ProfilePath',
    rtrim((string) getenv('APP_URL'), '/') . '/staff-login',
    ''
);
$bodyText = "Hi $fullName,\n\nThanks for signing up for ProfilePath. The Head of Guidance will review your request. Once it is approved, we will email you a temporary access code so you can create your password and sign in.";
Mailer::send($email, $fullName, 'We received your ProfilePath sign-up', $bodyHtml, $bodyText);

jsonResponse(['success' => true]);
