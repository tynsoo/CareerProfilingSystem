<?php

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Auth::requireLogin();
    $includeInactive = isset($_GET['all']);
    if ($includeInactive) {
        Rbac::requireAccess('career', 'limited');
    }

    $sql = 'SELECT p.id, p.title_enc, p.holland_code_enc, p.status, p.college_id, c.code AS college_code, c.name AS college_name
            FROM programs p JOIN colleges c ON c.id = p.college_id';
    if (!$includeInactive) {
        $sql .= " WHERE p.status = 'Active'";
    }
    $sql .= ' ORDER BY c.code, p.id';
    $rows = $pdo->query($sql)->fetchAll();

    $programs = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'title' => Crypto::dec($r['title_enc']),
        'hollandCode' => Crypto::dec($r['holland_code_enc']),
        'status' => $r['status'],
        'collegeId' => (int) $r['college_id'],
        'collegeCode' => $r['college_code'],
        'collegeName' => $r['college_name'],
    ], $rows);

    $response = ['programs' => $programs];

    if (isset($_GET['meta'])) {
        $response['colleges'] = array_map(fn($r) => [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
        ], $pdo->query('SELECT id, code, name FROM colleges ORDER BY code')->fetchAll());
    }

    if (isset($_GET['stats'])) {
        // Institution-wide count of students whose #1 recommendation is each program,
        // from the latest recommendation snapshot per student.
        $counts = $pdo->query(
            "SELECT top_program_id, COUNT(*) AS cnt FROM (
                SELECT DISTINCT ON (student_id) student_id, top_program_id
                FROM recommendations ORDER BY student_id, computed_at DESC
             ) latest GROUP BY top_program_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalStudentsWithRecs = array_sum($counts);
        $response['stats'] = [
            'matchedCounts' => array_map('intval', $counts),
            'totalStudentsWithRecommendations' => $totalStudentsWithRecs,
        ];
    }

    jsonResponse($response);
}

function readProgramInput(array $body): array
{
    $title = trim((string) ($body['title'] ?? ''));
    $hollandCode = strtoupper(trim((string) ($body['hollandCode'] ?? '')));
    $collegeId = (int) ($body['collegeId'] ?? 0);
    $status = ($body['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($title === '') {
        jsonResponse(['success' => false, 'error' => 'Program title is required.'], 400);
    }
    if (!preg_match('/^[RIASEC]{3}$/', $hollandCode)) {
        jsonResponse(['success' => false, 'error' => 'Holland code must be exactly 3 distinct letters from R, I, A, S, E, C.'], 400);
    }
    if (count(array_unique(str_split($hollandCode))) !== 3) {
        jsonResponse(['success' => false, 'error' => 'Holland code letters must be distinct.'], 400);
    }
    if ($collegeId <= 0) {
        jsonResponse(['success' => false, 'error' => 'A college is required.'], 400);
    }

    return [$title, $hollandCode, $collegeId, $status];
}

if ($method === 'POST') {
    $user = Rbac::requireAccess('career', 'full');
    $body = readJsonBody();
    [$title, $hollandCode, $collegeId, $status] = readProgramInput($body);

    $exists = $pdo->prepare('SELECT id FROM colleges WHERE id = ?');
    $exists->execute([$collegeId]);
    if (!$exists->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Unknown college.'], 400);
    }

    $insert = $pdo->prepare(
        'INSERT INTO programs (college_id, title_enc, holland_code_enc, status) VALUES (?, ?, ?, ?) RETURNING id'
    );
    $insert->execute([$collegeId, Crypto::enc($title), Crypto::enc($hollandCode), $status]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], $user['role'], 'create_program', 'program', (string) $id, $title);

    jsonResponse(['success' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $user = Rbac::requireAccess('career', 'full');
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing program id.'], 400);
    }

    $existing = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
    $existing->execute([$id]);
    if (!$existing->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Program not found.'], 404);
    }

    [$title, $hollandCode, $collegeId, $status] = readProgramInput($body);

    $collegeCheck = $pdo->prepare('SELECT id FROM colleges WHERE id = ?');
    $collegeCheck->execute([$collegeId]);
    if (!$collegeCheck->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Unknown college.'], 400);
    }

    $update = $pdo->prepare(
        'UPDATE programs SET college_id = ?, title_enc = ?, holland_code_enc = ?, status = ?, updated_at = NOW() WHERE id = ?'
    );
    $update->execute([$collegeId, Crypto::enc($title), Crypto::enc($hollandCode), $status, $id]);

    AuditLogger::log($user['id'], $user['role'], 'update_program', 'program', (string) $id, $title);

    jsonResponse(['success' => true]);
}

if ($method === 'DELETE') {
    // Soft-delete only: programs are referenced by worksheets/recommendations/saved_programs,
    // so removing one from circulation means marking it Inactive, not a hard DELETE.
    $user = Rbac::requireAccess('career', 'full');
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing program id.'], 400);
    }

    $update = $pdo->prepare("UPDATE programs SET status = 'Inactive', updated_at = NOW() WHERE id = ?");
    $update->execute([$id]);
    if ($update->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => 'Program not found.'], 404);
    }

    AuditLogger::log($user['id'], $user['role'], 'deactivate_program', 'program', (string) $id);

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
