<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
    jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
}

// "Mark all as read" — everything up to now counts as read; see the note in
// api/notifications.php for why this is one timestamp rather than per-item.
$stmt = Database::get()->prepare('UPDATE users SET notifications_read_at = NOW() WHERE id = ?');
$stmt->execute([$user['id']]);

jsonResponse(['success' => true]);
