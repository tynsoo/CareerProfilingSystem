<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students take the RIASEC assessment.'], 403);
}

$body = readJsonBody();
$submitted = strtoupper(trim((string) ($body['code'] ?? '')));

if ($submitted === '') {
    jsonResponse(['success' => false, 'error' => 'Enter the assessment access code.'], 400);
}

$pdo = Database::get();
$actual = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'assessment.accessCode'")->fetchColumn();

// hash_equals prevents a timing side-channel from leaking how many leading
// characters of the code matched.
if ($actual === '' || !hash_equals($actual, $submitted)) {
    AuditLogger::log($user['id'], 'student', 'access_code_failed', 'assessment', null, 'Incorrect assessment access code');
    jsonResponse(['success' => false, 'error' => 'Incorrect access code.'], 403);
}

$_SESSION['assessmentUnlocked'] = true;
AuditLogger::log($user['id'], 'student', 'access_code_verified', 'assessment', null, null);
jsonResponse(['success' => true]);
