<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CompletionTarget.php';

// The completion target behind the dashboard's red/green section graph. Any staff member can read
// it; only the administrator (Head of Guidance) changes it.
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Rbac::requireRole('admin', 'counselor');
    jsonResponse(['target' => CompletionTarget::get($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireRole('admin');
    $body = readJsonBody();
    $target = $body['target'] ?? null;
    if (is_string($target) && ctype_digit($target)) {
        $target = (int) $target;
    }
    if (!CompletionTarget::isValid($target)) {
        jsonResponse(['success' => false, 'error' => 'Enter a whole number from 1 to 100, or 0 to turn the target off.'], 400);
    }
    $old = CompletionTarget::get($pdo);
    CompletionTarget::set($pdo, $target, (int) $user['id']);
    AuditLogger::log($user['id'], $user['role'], 'update_completion_target', 'security_policies', CompletionTarget::KEY, (CompletionTarget::isOn($old) ? "$old%" : 'off') . ' -> ' . (CompletionTarget::isOn($target) ? "$target%" : 'off'));
    jsonResponse(['success' => true, 'target' => $target]);
}

jsonResponse(['error' => 'Method not allowed'], 405);