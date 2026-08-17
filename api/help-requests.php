<?php

require_once __DIR__ . '/_bootstrap.php';

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
        jsonResponse(['success' => false, 'error' => 'Only students can submit help requests.'], 403);
    }
    $subject = trim((string) ($body['subject'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    if ($subject === '' || $message === '') {
        jsonResponse(['success' => false, 'error' => 'Subject and message are required.'], 400);
    }

    $name = $user['firstName'] . ' ' . $user['lastName'];
    $insert = $pdo->prepare(
        'INSERT INTO help_requests (student_id, school_id_snapshot, name, subject, message_enc, status)
         VALUES (?, ?, ?, ?, ?, \'open\') RETURNING id'
    );
    $insert->execute([$user['id'], $user['schoolId'], $name, $subject, Crypto::enc($message)]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], 'student', 'submit_help_request', 'help_request', (string) $id, $subject);

    jsonResponse(['success' => true, 'id' => $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $status = $_GET['status'] ?? 'All';
    $sql = 'SELECT id, school_id_snapshot, name, subject, message_enc, sent_at, status, resolved_at FROM help_requests';
    $params = [];
    if (in_array($status, ['open', 'resolved'], true)) {
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY sent_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'schoolId' => $r['school_id_snapshot'],
        'name' => $r['name'],
        'subject' => $r['subject'],
        'message' => Crypto::dec($r['message_enc']),
        'sentAt' => $r['sent_at'],
        'status' => $r['status'],
        'resolvedAt' => $r['resolved_at'],
    ], $stmt->fetchAll());

    $openCount = (int) $pdo->query("SELECT COUNT(*) FROM help_requests WHERE status = 'open'")->fetchColumn();

    jsonResponse(['requests' => $rows, 'openCount' => $openCount]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
