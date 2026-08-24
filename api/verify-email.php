<?php

require_once __DIR__ . '/_bootstrap.php';

// POST, driven by JS from verify-email.html — not a bare GET the emailed
// link itself performs. Many email clients silently prefetch/scan links for
// safety before the user ever clicks, and a plain HTTP GET would burn a
// single-use token on that scan; those scanners don't execute page
// JavaScript, so gating the actual verification behind a fetch() call (the
// same pattern api/reset-password.php already uses from
// forgot-password.html/activate-account.html) survives that prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$token = (string) ($body['token'] ?? '');

if ($token === '') {
    jsonResponse(['success' => false, 'error' => 'Missing verification token.'], 400);
}

$pdo = Database::get();
$tokenHash = hash('sha256', $token);

$stmt = $pdo->prepare(
    'SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
    jsonResponse(['success' => false, 'error' => 'This verification link is invalid or has expired. Request a new one from the login page.'], 400);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
    $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$row['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to verify your email. Please try again.'], 500);
}

AuditLogger::log((int) $row['user_id'], 'student', 'verify_email', 'user', (string) $row['user_id']);

jsonResponse(['success' => true]);
