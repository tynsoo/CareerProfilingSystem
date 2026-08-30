<?php

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::get();

function grantRow(array $g): array
{
    return [
        'id' => (int) $g['id'],
        'studentId' => (int) $g['student_id'],
        'schoolId' => $g['school_id'],
        'studentName' => Crypto::dec($g['first_name_enc']) . ' ' . Crypto::dec($g['last_name_enc']),
        'originalAttemptNumber' => (int) $g['original_attempt_number'],
        'originalCompletedAt' => $g['original_completed_at'],
        'reason' => Crypto::dec($g['reason_enc']),
        'scheduleId' => $g['schedule_id'] !== null ? (int) $g['schedule_id'] : null,
        'status' => $g['status'],
        'grantedAt' => $g['granted_at'],
        'completedAttemptNumber' => $g['completed_attempt_number'] !== null ? (int) $g['completed_attempt_number'] : null,
        'completedAt' => $g['completed_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Rbac::requireAccess('examinations', 'limited');
    $status = trim((string) ($_GET['status'] ?? ''));
    $validStatuses = ['granted', 'completed', 'revoked'];

    $sql = "SELECT rg.*, s.school_id, s.first_name_enc, s.last_name_enc,
                (SELECT a.completed_at FROM assessments a WHERE a.student_id = rg.student_id AND a.attempt_number = rg.original_attempt_number) AS original_completed_at
             FROM retake_grants rg JOIN students s ON s.user_id = rg.student_id";
    $params = [];
    if (in_array($status, $validStatuses, true)) {
        $sql .= ' WHERE rg.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY rg.granted_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grants = array_map('grantRow', $stmt->fetchAll());

    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM retake_grants WHERE status = 'granted'")->fetchColumn();

    jsonResponse(['grants' => $grants, 'pendingCount' => $pendingCount]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('examinations', 'full');
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'grant') {
        $studentId = (int) ($body['studentId'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));
        $scheduleId = !empty($body['scheduleId']) ? (int) $body['scheduleId'] : null;

        if ($reason === '' || mb_strlen($reason) > 500) {
            jsonResponse(['success' => false, 'error' => 'Enter a reason (1-500 characters).'], 400);
        }

        // The student must have a completed latest attempt to retake.
        $stmt = $pdo->prepare('SELECT attempt_number FROM assessments WHERE student_id = ? AND is_latest = TRUE');
        $stmt->execute([$studentId]);
        $originalAttempt = $stmt->fetchColumn();
        if ($originalAttempt === false) {
            jsonResponse(['success' => false, 'error' => 'This student has no completed assessment to retake.'], 400);
        }

        // Don't stack a second active grant on top of an unused one.
        $existing = $pdo->prepare("SELECT 1 FROM retake_grants WHERE student_id = ? AND status = 'granted' AND completed_attempt_number IS NULL");
        $existing->execute([$studentId]);
        if ($existing->fetchColumn()) {
            jsonResponse(['success' => false, 'error' => 'This student already has an active, unused retake grant.'], 409);
        }

        if ($scheduleId !== null) {
            $sExists = $pdo->prepare('SELECT 1 FROM exam_schedules WHERE id = ?');
            $sExists->execute([$scheduleId]);
            if (!$sExists->fetchColumn()) {
                jsonResponse(['success' => false, 'error' => 'Exam schedule not found.'], 404);
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO retake_grants (student_id, original_attempt_number, reason_enc, schedule_id, granted_by)
             VALUES (?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$studentId, (int) $originalAttempt, Crypto::enc($reason), $scheduleId, $user['id']]);
        $newId = (int) $stmt->fetchColumn();

        AuditLogger::log($user['id'], $user['role'], 'grant_retake', 'retake_grant', (string) $newId, "Student #$studentId, reason: $reason");
        jsonResponse(['success' => true, 'id' => $newId]);
    }

    if ($type === 'revoke') {
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE retake_grants SET status = 'revoked' WHERE id = ? AND status = 'granted' AND completed_attempt_number IS NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Grant not found, or it has already been used/revoked.'], 404);
        }
        AuditLogger::log($user['id'], $user['role'], 'revoke_retake', 'retake_grant', (string) $id);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
