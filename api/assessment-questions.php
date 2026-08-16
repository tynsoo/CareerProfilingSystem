<?php

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Auth::requireLogin();
    $includeInactive = isset($_GET['all']); // admin question-bank page passes ?all=1
    $sql = 'SELECT id, dimension, order_index, question_text_enc, is_active FROM assessment_questions';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = TRUE';
    }
    $sql .= " ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index";
    $rows = $pdo->query($sql)->fetchAll();

    $questions = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'dimension' => $r['dimension'],
        'orderIndex' => (int) $r['order_index'],
        'text' => Crypto::dec($r['question_text_enc']),
        'isActive' => (bool) $r['is_active'],
    ], $rows);

    jsonResponse(['questions' => $questions]);
}

if ($method === 'PUT') {
    Rbac::requireAccess('rac', 'full');

    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing question id.'], 400);
    }

    $existing = $pdo->prepare('SELECT * FROM assessment_questions WHERE id = ?');
    $existing->execute([$id]);
    $question = $existing->fetch();
    if (!$question) {
        jsonResponse(['success' => false, 'error' => 'Question not found.'], 404);
    }

    $text = array_key_exists('text', $body) ? trim((string) $body['text']) : Crypto::dec($question['question_text_enc']);
    $isActive = array_key_exists('isActive', $body) ? (bool) $body['isActive'] : $question['is_active'];

    if ($text === '') {
        jsonResponse(['success' => false, 'error' => 'Question text cannot be empty.'], 400);
    }

    $update = $pdo->prepare('UPDATE assessment_questions SET question_text_enc = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([Crypto::enc($text), $isActive, $id]);

    $user = Auth::currentUser();
    AuditLogger::log($user['id'], $user['role'], 'update_question', 'assessment_question', (string) $id, "Dimension {$question['dimension']}#{$question['order_index']}");

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
