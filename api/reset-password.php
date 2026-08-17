<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$token = (string) ($body['token'] ?? '');
$password = (string) ($body['password'] ?? '');

if ($token === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'Missing token or password.'], 400);
}

$pdo = Database::get();

function passwordPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'password.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'minLength' => (int) ($rows['password.minLength'] ?? 8),
        'requireUpper' => ($rows['password.requireUpper'] ?? 'true') === 'true',
        'requireLower' => ($rows['password.requireLower'] ?? 'true') === 'true',
        'requireNumber' => ($rows['password.requireNumber'] ?? 'true') === 'true',
        'requireSymbol' => ($rows['password.requireSymbol'] ?? 'true') === 'true',
    ];
}

$policy = passwordPolicy($pdo);
$errors = [];
if (strlen($password) < $policy['minLength']) {
    $errors[] = "Password must be at least {$policy['minLength']} characters.";
}
if ($policy['requireUpper'] && !preg_match('/[A-Z]/', $password)) {
    $errors[] = 'Password must contain an uppercase letter.';
}
if ($policy['requireLower'] && !preg_match('/[a-z]/', $password)) {
    $errors[] = 'Password must contain a lowercase letter.';
}
if ($policy['requireNumber'] && !preg_match('/[0-9]/', $password)) {
    $errors[] = 'Password must contain a number.';
}
if ($policy['requireSymbol'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
    $errors[] = 'Password must contain a symbol.';
}
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare(
    'SELECT id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$reset = $stmt->fetch();

if (!$reset) {
    jsonResponse(['success' => false, 'error' => 'This reset link is invalid or has expired. Please request a new one.'], 400);
}

$pdo->beginTransaction();
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$hash, $reset['user_id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$reset['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to reset password. Please try again.'], 500);
}

AuditLogger::log($reset['user_id'], 'student', 'reset_password', 'user', (string) $reset['user_id']);

jsonResponse(['success' => true]);
