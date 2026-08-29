<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Rbac::requireAccess('announcements', 'limited');
$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] === 'student') {
        // Only published announcements that are either sent to everyone or
        // specifically target this student.
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.body_enc, a.target_type, a.publish_at, a.created_at
             FROM announcements a
             WHERE a.publish_at <= NOW()
               AND (a.target_type = 'all' OR EXISTS (
                     SELECT 1 FROM announcement_recipients ar
                     WHERE ar.announcement_id = a.id AND ar.student_id = ?
                   ))
             ORDER BY a.publish_at DESC"
        );
        $stmt->execute([(int) $user['id']]);
    } else {
        // Admin/counselor management view: everything, including unpublished
        // (scheduled) announcements.
        $stmt = $pdo->query(
            "SELECT a.id, a.title, a.body_enc, a.target_type, a.publish_at, a.created_at,
                    u.username AS created_by_username,
                    (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.id) AS recipient_count
             FROM announcements a
             LEFT JOIN users u ON u.id = a.created_by
             ORDER BY a.publish_at DESC"
        );
    }

    $announcements = array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'body' => Crypto::dec($r['body_enc']),
            'targetType' => $r['target_type'],
            'publishAt' => $r['publish_at'],
            'createdAt' => $r['created_at'],
            'isPublished' => strtotime($r['publish_at']) <= time(),
            'createdByUsername' => $r['created_by_username'] ?? null,
            'recipientCount' => isset($r['recipient_count']) ? (int) $r['recipient_count'] : null,
        ];
    }, $stmt->fetchAll());

    jsonResponse(['announcements' => $announcements]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'delete') {
        Rbac::requireAccess('announcements', 'full');
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        AuditLogger::log($user['id'], $user['role'], 'delete_announcement', 'announcement', (string) $id, null);
        jsonResponse(['success' => true]);
    }

    if ($type === 'create') {
        Rbac::requireAccess('announcements', 'full');

        $title = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));
        $targetType = (string) ($body['targetType'] ?? 'all');
        $publishAtRaw = trim((string) ($body['publishAt'] ?? ''));
        $studentIds = $body['studentIds'] ?? [];

        if ($title === '' || $bodyText === '') {
            jsonResponse(['success' => false, 'error' => 'Title and message are required.'], 400);
        }
        if (mb_strlen($title) > 255) {
            jsonResponse(['success' => false, 'error' => 'Title must be 255 characters or fewer.'], 400);
        }
        if (!in_array($targetType, ['all', 'specific'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid target type.'], 400);
        }
        if ($targetType === 'specific') {
            $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) $studentIds))));
            if (!$studentIds) {
                jsonResponse(['success' => false, 'error' => 'Select at least one student, or choose "Everyone".'], 400);
            }
        }

        // publishAt is optional — an empty value means "publish immediately".
        // Accepts the value a datetime-local input sends (no timezone), which
        // PHP's strtotime() reads as server-local time, matching NOW() below.
        if ($publishAtRaw === '') {
            $publishAt = null; // -> NOW() at insert time
        } else {
            $ts = strtotime($publishAtRaw);
            if ($ts === false) {
                jsonResponse(['success' => false, 'error' => 'Invalid publish date/time.'], 400);
            }
            $publishAt = date('Y-m-d H:i:sP', $ts);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO announcements (title, body_enc, created_by, target_type, publish_at)
                 VALUES (?, ?, ?, ?, COALESCE(?, NOW())) RETURNING id'
            );
            $insert->execute([$title, Crypto::enc($bodyText), $user['id'], $targetType, $publishAt]);
            $announcementId = (int) $insert->fetchColumn();

            if ($targetType === 'specific') {
                $recipientStmt = $pdo->prepare(
                    'INSERT INTO announcement_recipients (announcement_id, student_id) VALUES (?, ?)'
                );
                foreach ($studentIds as $sid) {
                    $recipientStmt->execute([$announcementId, $sid]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Failed to save the announcement. Please try again.'], 500);
        }

        AuditLogger::log(
            $user['id'], $user['role'], 'create_announcement', 'announcement', (string) $announcementId,
            "\"$title\" -> " . ($targetType === 'all' ? 'everyone' : count($studentIds) . ' student(s)')
        );
        jsonResponse(['success' => true, 'id' => $announcementId]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
