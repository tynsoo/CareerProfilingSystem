<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();

    // Admin/counselor: resolve an existing request.
    if (($body['type'] ?? '') === 'resolve') {
        if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
            jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        }
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Missing id.'], 400);
        }
        $pdo->prepare("UPDATE help_requests SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ?")
            ->execute([$user['id'], $id]);
        AuditLogger::log($user['id'], $user['role'], 'resolve_help_request', 'help_request', (string) $id);
        jsonResponse(['success' => true]);
    }

    // Student: submit a new request.
    if ($user['role'] !== 'student') {
        jsonResponse(['success' => false, 'error' => 'Only students can submit counseling requests.'], 403);
    }
    $subject = trim((string) ($body['subject'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    if ($subject === '' || $message === '') {
        jsonResponse(['success' => false, 'error' => 'Subject and message are required.'], 400);
    }

    $name = $user['firstName'] . ' ' . $user['lastName'];
    $insert = $pdo->prepare(
        'INSERT INTO help_requests (student_id, school_id_snapshot, name_enc, subject_enc, message_enc, status)
         VALUES (?, ?, ?, ?, ?, \'open\') RETURNING id'
    );
    $insert->execute([$user['id'], $user['schoolId'], Crypto::enc($name), Crypto::enc($subject), Crypto::enc($message)]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], 'student', 'submit_help_request', 'help_request', (string) $id, $subject);

    // Simple FIFO queue number: how many open requests (including this one)
    // were submitted at or before this one, oldest first. Not a booked time
    // slot — just where the student stands in line.
    $queueStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM help_requests
         WHERE status = 'open' AND sent_at <= (SELECT sent_at FROM help_requests WHERE id = ?)"
    );
    $queueStmt->execute([$id]);
    $queueNumber = (int) $queueStmt->fetchColumn();

    jsonResponse(['success' => true, 'id' => $id, 'queueNumber' => $queueNumber]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $status = $_GET['status'] ?? 'All';
    $sql = 'SELECT hr.id, hr.student_id, hr.name_enc, hr.subject_enc, hr.message_enc, hr.sent_at, hr.status, hr.resolved_at, s.strand, s.section
            FROM help_requests hr LEFT JOIN students s ON s.user_id = hr.student_id';
    $params = [];
    if (in_array($status, ['open', 'resolved'], true)) {
        $sql .= ' WHERE hr.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY hr.sent_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $fetchedRows = $stmt->fetchAll();

    // Queue position among 'open' requests only, oldest first — the same
    // FIFO count a student sees for their own request (see the POST branch).
    $openIdsByAge = $pdo->query("SELECT id FROM help_requests WHERE status = 'open' ORDER BY sent_at ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
    $queuePositions = array_flip($openIdsByAge);

    $rows = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'studentId' => $r['student_id'] !== null ? (int) $r['student_id'] : null,
        'strand' => $r['strand'],
        'section' => $r['section'],
        'name' => Crypto::dec($r['name_enc']),
        'subject' => Crypto::dec($r['subject_enc']),
        'message' => Crypto::dec($r['message_enc']),
        'sentAt' => $r['sent_at'],
        'status' => $r['status'],
        'resolvedAt' => $r['resolved_at'],
        'queueNumber' => $r['status'] === 'open' ? $queuePositions[(int) $r['id']] + 1 : null,
    ], $fetchedRows);

    $openCount = count($openIdsByAge);

    jsonResponse(['requests' => $rows, 'openCount' => $openCount]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
