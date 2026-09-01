<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'Username and password are required.'], 400);
}

$pdo = Database::get();

function lockoutPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'lockout.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'enabled' => ($rows['lockout.enabled'] ?? 'true') === 'true',
        'maxAttempts' => (int) ($rows['lockout.maxAttempts'] ?? 5),
        'lockoutMinutes' => (int) ($rows['lockout.lockoutMinutes'] ?? 15),
    ];
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
$stmt->execute([$username]);
$user = $stmt->fetch();

$genericError = ['success' => false, 'error' => 'Invalid username or password.'];

if (!$user || !$user['is_active']) {
    AuditLogger::log(null, null, 'login_failed', 'user', $username, 'Unknown or inactive username');
    jsonResponse($genericError, 401);
}

$policy = lockoutPolicy($pdo);
if ($policy['enabled'] && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $username, 'Account locked');
    jsonResponse(['success' => false, 'error' => 'Account temporarily locked due to repeated failed attempts. Try again later.'], 423);
}

if (!password_verify($password, $user['password_hash'])) {
    $attempts = (int) $user['failed_login_attempts'] + 1;
    $lockedUntil = null;
    if ($policy['enabled'] && $attempts >= $policy['maxAttempts']) {
        $lockedUntil = date('c', time() + $policy['lockoutMinutes'] * 60);
        $attempts = 0; // reset counter once locked, so the next window starts fresh
    }
    $update = $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
    $update->execute([$attempts, $lockedUntil, $user['id']]);

    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $username, 'Incorrect password');
    jsonResponse($genericError, 401);
}

// Only students self-register through the public page and need this check —
// admin/counselor accounts are staff-vetted through a different path.
if ($user['role'] === 'student' && $user['email_verified_at'] === null) {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $username, 'Email not verified');
    jsonResponse([
        'success' => false,
        'error' => 'Please verify your email before signing in — check your inbox for the verification link.',
        'needsVerification' => true,
    ], 403);
}

// Success — reset lockout state.
$reset = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
$reset->execute([$user['id']]);

$sessionData = [
    'id' => (int) $user['id'],
    'role' => $user['role'],
    'username' => $user['username'],
    'avatarUrl' => $user['avatar_data_url'],
];

$redirect = 'admin-dashboard';
if ($user['role'] === 'student') {
    $studentStmt = $pdo->prepare('SELECT school_id, first_name_enc, last_name_enc, strand, grade_level, section, registered_at FROM students WHERE user_id = ?');
    $studentStmt->execute([$user['id']]);
    $student = $studentStmt->fetch();
    if ($student) {
        $sessionData['schoolId'] = $student['school_id'];
        $sessionData['firstName'] = Crypto::dec($student['first_name_enc']);
        $sessionData['lastName'] = Crypto::dec($student['last_name_enc']);
        $sessionData['strand'] = $student['strand'];
        $sessionData['gradeLevel'] = $student['grade_level'];
        $sessionData['section'] = $student['section'];
        $sessionData['registeredAt'] = $student['registered_at'];
    }
    $redirect = 'assessment';
}

Auth::login($sessionData);
AuditLogger::log((int) $user['id'], $user['role'], 'login', 'user', $user['username'], 'Successful login');

jsonResponse(['success' => true, 'role' => $user['role'], 'redirect' => $redirect, 'user' => $sessionData]);
