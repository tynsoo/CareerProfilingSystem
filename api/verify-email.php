<?php

require_once __DIR__ . '/_bootstrap.php';

// Reached directly by clicking the link in the verification email — a plain
// browser navigation, not a fetch() call — so this redirects to a page
// rather than returning JSON.
$token = (string) ($_GET['token'] ?? '');

function redirectTo(string $status): never
{
    header('Location: login.html?verified=' . $status);
    exit;
}

if ($token === '') {
    redirectTo('0');
}

$pdo = Database::get();
$tokenHash = hash('sha256', $token);

$stmt = $pdo->prepare(
    'SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
    redirectTo('0');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
    $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$row['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirectTo('0');
}

AuditLogger::log((int) $row['user_id'], 'student', 'verify_email', 'user', (string) $row['user_id']);

redirectTo('1');
