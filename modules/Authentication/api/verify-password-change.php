<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/SessionEnd.php';
require_once __DIR__ . '/../../../shared/lib/CodeInput.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$pending = $_SESSION['pending_password_change'] ?? null;
if (!$pending || (int) $pending['userId'] !== (int) $user['id']) {
    jsonResponse(['success' => false, 'error' => 'No password change is currently pending. Please start over.'], 400);
}

$body = readJsonBody();
// A pasted code often brings a space or line break along; only the digits count (shared/lib/CodeInput.php).
$code = CodeInput::digits((string) ($body['code'] ?? ''));
if ($code === '') {
    jsonResponse(['success' => false, 'error' => 'Enter the code from your email.'], 400);
}

$result = TwoFactor::verify($pdo, (int) $user['id'], $code);

switch ($result) {
    case 'ok':
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$pending['newPasswordHash'], $user['id']]);
        unset($_SESSION['pending_password_change']);
        // Other devices signed in with the old password are signed out; this one stays signed in.
        SessionEnd::endFor($pdo, (int) $user['id'], 'password_changed', session_id());
        AuditLogger::log($user['id'], $user['role'], 'change_password', 'user', (string) $user['id'], 'Verified via email code');
        jsonResponse(['success' => true]);
        break;

    case 'too_many_attempts':
        unset($_SESSION['pending_password_change']);
        AuditLogger::log($user['id'], $user['role'], 'change_password_failed', 'user', (string) $user['id'], 'Too many incorrect verification attempts');
        jsonResponse(['success' => false, 'error' => 'Too many incorrect attempts. Please start over.'], 429);
        break;

    case 'expired':
        AuditLogger::log($user['id'], $user['role'], 'change_password_failed', 'user', (string) $user['id'], 'Verification code expired');
        jsonResponse(['success' => false, 'error' => 'That code has expired. Password-change codes last ' . TwoFactor::EXPIRY_MINUTES . ' minutes. Click Resend code to get a new one.', 'expired' => true], 400);
        break;

    case 'none_pending':
        unset($_SESSION['pending_password_change']);
        jsonResponse(['success' => false, 'error' => 'No password change is currently pending. Please start over.'], 400);
        break;

    case 'invalid':
    default:
        AuditLogger::log($user['id'], $user['role'], 'change_password_failed', 'user', (string) $user['id'], 'Incorrect verification code');
        jsonResponse(['success' => false, 'error' => 'Incorrect code. Use the newest 6-digit code we emailed you; an older code stops working when a new one is sent.'], 401);
        break;
}
