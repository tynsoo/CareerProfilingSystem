<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

$pdo = Database::get();

/** True when the staff member handles this student's section (modules/UserManagement/lib/StaffScope.php); notes of other sections are off limits. */
function studentInScope(PDO $pdo, array $user, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT strand, section FROM students WHERE user_id = ?');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row && StaffScope::allows(StaffScope::forUser($pdo, $user), $row['strand'], $row['section']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = Rbac::requireAccess('counselingNotes', 'limited');

    $studentId = (int) ($_GET['studentId'] ?? 0);
    if ($studentId <= 0) {
        jsonResponse(['error' => 'Missing studentId.'], 400);
    }
    if (!studentInScope($pdo, $user, $studentId)) {
        jsonResponse(['error' => 'Student not found.'], 404);
    }

    $stmt = $pdo->prepare(
        'SELECT cn.id, cn.note_enc, cn.created_at, COALESCE(u.full_name, u.username) AS author_username
         FROM counseling_notes cn
         LEFT JOIN users u ON u.id = cn.author_id
         WHERE cn.student_id = ?
         ORDER BY cn.created_at DESC'
    );
    $stmt->execute([$studentId]);

    $notes = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'note' => Crypto::dec($r['note_enc']),
        'author' => $r['author_username'] ?? 'Unknown',
        'createdAt' => $r['created_at'],
    ], $stmt->fetchAll());

    jsonResponse(['notes' => $notes]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('counselingNotes', 'full');
    $body = readJsonBody();

    $studentId = (int) ($body['studentId'] ?? 0);
    $note = trim((string) ($body['note'] ?? ''));

    if ($studentId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing studentId.'], 400);
    }
    if ($note === '') {
        jsonResponse(['success' => false, 'error' => 'Note cannot be empty.'], 400);
    }
    if (mb_strlen($note) > 2000) {
        jsonResponse(['success' => false, 'error' => 'Note must be 2000 characters or fewer.'], 400);
    }

    if (!studentInScope($pdo, $user, $studentId)) {
        jsonResponse(['success' => false, 'error' => 'Student not found.'], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO counseling_notes (student_id, note_enc, author_id) VALUES (?, ?, ?) RETURNING id'
    );
    $insert->execute([$studentId, Crypto::enc($note), $user['id']]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], $user['role'], 'add_counseling_note', 'student', (string) $studentId, null);

    jsonResponse(['success' => true, 'id' => $id]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
