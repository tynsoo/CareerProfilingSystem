<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

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
    // What this person reads (permission table): Full on View counseling history = every note in full. View Only = every
    // note's date and author, but the text only of their own notes (notes are sensitive: need to know). No Access, but
    // allowed to write notes = only their own notes.
    $user = Auth::requireLogin();
    $roleKey = Rbac::roleKey($user);
    $history = Rbac::accessLevel('counselingHistory', $roleKey);
    $writes = Rbac::atLeast(Rbac::accessLevel('notesCreate', $roleKey), 'full');
    if ($history === 'none' && !$writes) {
        AuditLogger::log($user['id'], $user['role'], 'access_denied', 'student', (string) (int) ($_GET['studentId'] ?? 0), 'Refused: counseling notes');
        jsonResponse(['error' => 'Insufficient permissions for this action.'], 403);
    }

    $studentId = (int) ($_GET['studentId'] ?? 0);
    if ($studentId <= 0) {
        jsonResponse(['error' => 'Missing studentId.'], 400);
    }
    if (!studentInScope($pdo, $user, $studentId)) {
        jsonResponse(['error' => 'Student not found.'], 404);
    }

    $stmt = $pdo->prepare(
        'SELECT cn.id, cn.note_enc, cn.author_id, cn.created_at, COALESCE(u.full_name, u.username) AS author_username
         FROM counseling_notes cn
         LEFT JOIN users u ON u.id = cn.author_id
         WHERE cn.student_id = ?
         ORDER BY cn.created_at DESC'
    );
    $stmt->execute([$studentId]);

    $rows = $stmt->fetchAll();
    if ($history === 'none') {
        $rows = array_values(array_filter($rows, fn($r) => (int) $r['author_id'] === (int) $user['id']));
    }
    // A note whose text this person may not read is sent as null (never decrypted for them).
    $notes = array_map(function ($r) use ($history, $user) {
        $readable = $history === 'full' || (int) $r['author_id'] === (int) $user['id'];
        return [
            'id' => (int) $r['id'],
            'note' => $readable ? Crypto::dec($r['note_enc']) : null,
            'author' => $r['author_username'] ?? 'Unknown',
            'createdAt' => $r['created_at'],
        ];
    }, $rows);
    // Reading counseling notes is written to the Audit Log (panel revision S14): who read whose notes, and how many
    // they could read in full. Once per staff member and student per hour, so reopening the page does not flood the log.
    $readableCount = count(array_filter($notes, fn($n) => $n['note'] !== null));
    if ($notes && !RateLimiter::tooMany('notes-view-log:' . (int) $user['id'] . ':' . $studentId, 1, 60)) {
        AuditLogger::log($user['id'], $user['role'], 'view_counseling_notes', 'student', (string) $studentId,
            count($notes) . ' note(s), ' . $readableCount . ' readable in full');
    }

    jsonResponse(['notes' => $notes, 'history' => $history]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('notesCreate', 'full'); // Create counseling notes (permission table)
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
