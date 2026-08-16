<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$schoolId = trim((string) ($body['schoolId'] ?? ''));
$firstName = trim((string) ($body['firstName'] ?? ''));
$lastName = trim((string) ($body['lastName'] ?? ''));
$strand = (string) ($body['strand'] ?? '');
$gradeLevel = (string) ($body['gradeLevel'] ?? '');
$password = (string) ($body['password'] ?? '');

if ($schoolId === '' || $firstName === '' || $lastName === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
if (!in_array($strand, ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid strand.'], 400);
}
if (!in_array($gradeLevel, ['11', '12'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid grade level.'], 400);
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

$existing = $pdo->prepare('SELECT 1 FROM students WHERE LOWER(school_id) = LOWER(?)');
$existing->execute([$schoolId]);
if ($existing->fetch()) {
    jsonResponse(['success' => false, 'error' => 'An account with this School ID already exists.'], 409);
}

$pdo->beginTransaction();
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare('INSERT INTO users (role, username, password_hash, is_active) VALUES (?, ?, ?, TRUE) RETURNING id');
    $userStmt->execute(['student', $schoolId, $hash]);
    $userId = (int) $userStmt->fetchColumn();

    $studentStmt = $pdo->prepare(
        'INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $studentStmt->execute([$userId, $schoolId, Crypto::enc($firstName), Crypto::enc($lastName), $strand, $gradeLevel]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

AuditLogger::log($userId, 'student', 'register', 'user', (string) $userId, "New student account: $schoolId");

jsonResponse(['success' => true]);
