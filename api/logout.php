<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::currentUser();
if ($user !== null) {
    AuditLogger::log((int) $user['id'], $user['role'], 'logout', 'user', $user['username'], null);
}

Auth::logout();
jsonResponse(['success' => true]);
