<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';

$user = Auth::requireLogin();
if ($user['role'] !== 'admin') {
    jsonResponse(['error' => 'Forbidden'], 403);
}
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        "SELECT id, username, email, is_active, created_at FROM users WHERE role = 'counselor' ORDER BY created_at DESC"
    )->fetchAll();

    jsonResponse(['accounts' => array_map(fn($r) => [
        'id' => (int) $r['id'],
        'username' => $r['username'],
        'email' => $r['email'],
        'isActive' => (bool) $r['is_active'],
        'createdAt' => $r['created_at'],
    ], $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();

    // Toggle an existing counselor's active status.
    if (($body['type'] ?? '') === 'toggleActive') {
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'counselor'");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'error' => 'Account not found.'], 404);
        }
        $newState = !$row['is_active'];
        $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$newState, $id]);
        AuditLogger::log($user['id'], 'admin', $newState ? 'activate_staff_account' : 'deactivate_staff_account', 'user', (string) $id);
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }

    // Create a new counselor account.
    $username = trim((string) ($body['username'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));

    if ($username === '' || $email === '') {
        jsonResponse(['success' => false, 'error' => 'Username and email are required.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
    }

    $existing = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(?)');
    $existing->execute([$username]);
    if ($existing->fetch()) {
        jsonResponse(['success' => false, 'error' => 'That username is already taken.'], 409);
    }

    $pdo->beginTransaction();
    try {
        // The account has no usable password until the counselor sets one via the
        // emailed activation link — this random hash is never meant to be entered.
        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $insert = $pdo->prepare(
            "INSERT INTO users (role, username, password_hash, email, is_active) VALUES ('counselor', ?, ?, ?, TRUE) RETURNING id"
        );
        $insert->execute([$username, $placeholderHash, $email]);
        $newUserId = (int) $insert->fetchColumn();

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $pdo->prepare(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '3 days')"
        )->execute([$newUserId, $tokenHash]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Failed to create account. Please try again.'], 500);
    }

    $activationLink = rtrim((string) getenv('APP_URL'), '/') . '/forgot-password.html?token=' . $rawToken;
    $bodyHtml = "<p>Hi $username,</p><p>An administrator created a Guidance Counselor account for you on ProfilePath. "
        . "Set your password to activate it — this link expires in 3 days:</p>"
        . "<p><a href=\"$activationLink\">$activationLink</a></p>";
    $bodyText = "Hi $username,\n\nAn administrator created a Guidance Counselor account for you on ProfilePath. "
        . "Set your password to activate it — this link expires in 3 days:\n$activationLink";
    $sent = Mailer::send($email, $username, 'Activate your ProfilePath account', $bodyHtml, $bodyText);

    AuditLogger::log($user['id'], 'admin', 'create_staff_account', 'user', (string) $newUserId, "Counselor account: $username");

    $response = ['success' => true, 'id' => $newUserId];
    if (!$sent && getenv('APP_ENV') === 'local') {
        // Local dev has no real Brevo credentials — surface the link directly so the flow stays testable.
        $response['debugActivationLink'] = $activationLink;
    }
    jsonResponse($response);
}

jsonResponse(['error' => 'Method not allowed'], 405);
