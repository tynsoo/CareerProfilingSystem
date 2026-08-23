<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
// Students log in with their School ID, which IS their username (see
// api/register.php) — so a single username lookup covers every role,
// students and admin/counselor staff accounts alike.
$identifier = trim((string) ($body['schoolId'] ?? $body['identifier'] ?? ''));
if ($identifier === '') {
    jsonResponse(['success' => false, 'error' => 'Username or School ID is required.'], 400);
}

$pdo = Database::get();

// Always respond with the same generic success message, whether or not the
// account exists, so this endpoint can't be used to enumerate accounts.
$generic = ['success' => true, 'message' => 'If that account exists, a reset link has been sent to its email on file.'];

$stmt = $pdo->prepare('SELECT id, role, username, email FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE');
$stmt->execute([$identifier]);
$user = $stmt->fetch();

if (!$user) {
    jsonResponse($generic);
}

if ($user['role'] === 'student') {
    // No real email was collected at registration for older accounts — fall
    // back to the standard institutional address format the rest of the app
    // already assumes (see the masked-email display on the change-password flow).
    $email = $user['email'] ?: ($user['username'] . '@mymail.mapua.edu.ph');
    $nameStmt = $pdo->prepare('SELECT first_name_enc FROM students WHERE user_id = ?');
    $nameStmt->execute([$user['id']]);
    $nameRow = $nameStmt->fetch();
    $firstName = $nameRow ? Crypto::dec($nameRow['first_name_enc']) : $user['username'];
} else {
    // Staff accounts always have an email on file (required when the admin
    // creates the account) — if somehow missing, there's nowhere to send a
    // link, so fall through to the same generic non-committal response.
    if (!$user['email']) {
        jsonResponse($generic);
    }
    $email = $user['email'];
    $firstName = $user['username'];
}

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

$insert = $pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL \'30 minutes\')'
);
$insert->execute([$user['id'], $tokenHash]);

$resetLink = rtrim((string) getenv('APP_URL'), '/') . '/forgot-password.html?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Reset your password',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">We received a request to reset your ProfilePath password. Click the button below to choose a new one.</p>',
    'Reset Password',
    $resetLink,
    'This link expires in 30 minutes.'
);
$bodyText = "Hi $firstName,\n\nWe received a request to reset your ProfilePath password. This link expires in 30 minutes:\n$resetLink\n\nIf you didn't request this, you can ignore this email.";

$sent = Mailer::send($email, $firstName, 'Reset your ProfilePath password', $bodyHtml, $bodyText);

AuditLogger::log($user['id'], $user['role'], 'request_password_reset', 'user', (string) $user['id']);

$response = $generic;
if (!$sent && getenv('APP_ENV') === 'local') {
    // Local dev has no real Brevo credentials — surface the link directly so the flow stays testable.
    $response['debugResetLink'] = $resetLink;
}
jsonResponse($response);
