<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$schoolId = trim((string) ($body['schoolId'] ?? ''));
if ($schoolId === '') {
    jsonResponse(['success' => false, 'error' => 'School ID is required.'], 400);
}

$pdo = Database::get();

// Always respond with the same generic success message, whether or not the
// School ID exists, so this endpoint can't be used to enumerate accounts.
$generic = ['success' => true, 'message' => 'If that School ID has an account, a reset link has been sent to its email on file.'];

$stmt = $pdo->prepare(
    'SELECT u.id, u.email, s.school_id, s.first_name_enc
     FROM users u JOIN students s ON s.user_id = u.id
     WHERE LOWER(s.school_id) = LOWER(?) AND u.is_active = TRUE'
);
$stmt->execute([$schoolId]);
$user = $stmt->fetch();

if (!$user) {
    jsonResponse($generic);
}

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
    ->execute([$user['id']]);

$insert = $pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL \'30 minutes\')'
);
$insert->execute([$user['id'], $tokenHash]);

// No real email was collected at registration — fall back to the standard
// institutional address format the rest of the app already assumes (see
// the masked-email display on the change-password flow).
$email = $user['email'] ?: ($user['school_id'] . '@mymail.mapua.edu.ph');
$firstName = Crypto::dec($user['first_name_enc']);

$resetLink = rtrim((string) getenv('APP_URL'), '/') . '/forgot-password.html?token=' . $rawToken;

$bodyHtml = "<p>Hi $firstName,</p><p>We received a request to reset your ProfilePath password. This link expires in 30 minutes:</p>"
    . "<p><a href=\"$resetLink\">$resetLink</a></p><p>If you didn't request this, you can ignore this email.</p>";
$bodyText = "Hi $firstName,\n\nWe received a request to reset your ProfilePath password. This link expires in 30 minutes:\n$resetLink\n\nIf you didn't request this, you can ignore this email.";

$sent = Mailer::send($email, $firstName, 'Reset your ProfilePath password', $bodyHtml, $bodyText);

AuditLogger::log($user['id'], 'student', 'request_password_reset', 'user', (string) $user['id']);

$response = $generic;
if (!$sent && getenv('APP_ENV') === 'local') {
    // Local dev has no real Brevo credentials — surface the link directly so the flow stays testable.
    $response['debugResetLink'] = $resetLink;
}
jsonResponse($response);
