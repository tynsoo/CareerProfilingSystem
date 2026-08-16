<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['error' => 'Students only'], 403);
}
$studentId = (int) $user['id'];
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ids = $pdo->prepare('SELECT program_id FROM saved_programs WHERE student_id = ? ORDER BY saved_at DESC');
    $ids->execute([$studentId]);
    jsonResponse(['programIds' => array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $programId = (int) ($body['programId'] ?? 0);
    if ($programId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing programId.'], 400);
    }

    $check = $pdo->prepare('SELECT 1 FROM saved_programs WHERE student_id = ? AND program_id = ?');
    $check->execute([$studentId, $programId]);
    $alreadySaved = (bool) $check->fetch();

    if ($alreadySaved) {
        $pdo->prepare('DELETE FROM saved_programs WHERE student_id = ? AND program_id = ?')->execute([$studentId, $programId]);
        jsonResponse(['success' => true, 'saved' => false]);
    }

    $pdo->prepare('INSERT INTO saved_programs (student_id, program_id) VALUES (?, ?)')->execute([$studentId, $programId]);
    jsonResponse(['success' => true, 'saved' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
