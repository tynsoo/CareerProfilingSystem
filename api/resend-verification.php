<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
if ($username === '') {
    jsonResponse(['success' => false, 'error' => 'Username is required.'], 400);
}

$pdo = Database::get();

// Always the same generic response, whether or not the account exists or
// is already verified — same anti-enumeration reasoning as forgot-password.php.
$generic = ['success' => true, 'message' => 'If that account needs verification, a new link has been sent to its email on file.'];

$stmt = $pdo->prepare(
    "SELECT u.id, u.email, s.first_name_enc FROM users u
     JOIN students s ON s.user_id = u.id
     WHERE LOWER(u.username) = LOWER(?) AND u.role = 'student' AND u.email_verified_at IS NULL"
);
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !$user['email']) {
    jsonResponse($generic);
}

$firstName = Crypto::dec($user['first_name_enc']);

$pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare(
    "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
)->execute([$user['id'], $tokenHash]);

$verifyLink = rtrim((string) getenv('APP_URL'), '/') . '/api/verify-email.php?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Verify your email to finish signing up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">Confirm this is your email address to activate your ProfilePath account.</p>',
    'Verify Email Address',
    $verifyLink,
    'This link expires in 48 hours.'
);
$bodyText = "Hi $firstName,\n\nConfirm this is your email address to activate your ProfilePath account:\n$verifyLink\n\nThis link expires in 48 hours.";
Mailer::send($user['email'], $firstName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

jsonResponse($generic);
