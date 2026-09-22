<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['error' => 'Students only'], 403);
}
$studentId = (int) $user['id'];
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ?details=1 — full program info for the Saved Careers page. The default
    // response (ids only) stays as-is for results.html's Save/Saved button state.
    if (isset($_GET['details'])) {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.title_enc, p.holland_code_enc, p.description_enc, p.status,
                    c.code AS college_code, c.name AS college_name, sp.saved_at
             FROM saved_programs sp
             JOIN programs p ON p.id = sp.program_id
             JOIN colleges c ON c.id = p.college_id
             WHERE sp.student_id = ?
             ORDER BY sp.saved_at DESC'
        );
        $stmt->execute([$studentId]);
        jsonResponse(['programs' => array_map(fn($r) => [
            'id' => (int) $r['id'],
            'title' => Crypto::dec($r['title_enc']),
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
            'isActive' => $r['status'] === 'Active',
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
            'savedAt' => $r['saved_at'],
        ], $stmt->fetchAll())]);
    }

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
