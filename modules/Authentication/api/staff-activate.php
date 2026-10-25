<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/LoginLock.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/StaffSetupCode.php';

// Last step of staff sign-up: the person enters the temporary access code emailed when the
// Head of Guidance approved them, and creates their own password. Works only for an approved
// staff account that has not been activated yet.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$identifier = trim((string) ($body['identifier'] ?? ''));
$code = StaffSetupCode::normalize((string) ($body['code'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($identifier === '' || $code === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'Enter your username or email, the access code and a new password.'], 400);
}

$generic = ['success' => false, 'error' => 'That code is not valid. Check it and try again, or ask the Head of Guidance to send a new one.'];

// Brute-force guard: per account and per IP, whatever the outcome.
// The real visitor address (behind Render's proxy too; modules/AuditLog/lib/AuditLogger.php), so one visitor cannot use up the limit for everyone.
$ip = AuditLogger::clientIpFrom($_SERVER) ?? 'unknown';
if (RateLimiter::tooMany('staff-activate:' . strtolower($identifier), StaffSetupCode::MAX_WRONG_TRIES * 2, 60)
    || RateLimiter::tooMany('staff-activate-ip:' . $ip, 30, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many attempts. Please wait a while and try again.'], 429);
}

$pdo = Database::get();
$stmt = $pdo->prepare(
    "SELECT id, role, username FROM users
     WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?))
       AND role = 'counselor' AND approval_status = 'approved' AND is_active = TRUE AND email_verified_at IS NULL
     ORDER BY id LIMIT 1"
);
$stmt->execute([$identifier, $identifier]);
$account = $stmt->fetch();
if (!$account || !StaffSetupCode::isValid($pdo, (int) $account['id'], $code)) {
    AuditLogger::log($account ? (int) $account['id'] : null, $account ? 'counselor' : null, 'staff_activation_failed', 'user', $identifier, 'Wrong or expired access code');
    jsonResponse($generic, 400);
}

$errors = PasswordPolicy::errors($pdo, $password);
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash = ?, email_verified_at = NOW(), failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_BCRYPT), $account['id']]);
    LoginLock::clear($pdo, LoginLock::forAccount((int) $account['id']));
    StaffSetupCode::spend($pdo, (int) $account['id']);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Could not activate the account. Please try again.'], 500);
}

AuditLogger::log((int) $account['id'], 'counselor', 'staff_account_activated', 'user', (string) $account['id'], 'Created password with the temporary access code');
jsonResponse(['success' => true, 'username' => $account['username']]);
