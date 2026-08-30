<?php

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::get();

function facultyRow(array $f): array
{
    return [
        'id' => (int) $f['id'],
        'facultyCode' => $f['faculty_code'],
        'name' => Crypto::dec($f['first_name_enc']) . ' ' . Crypto::dec($f['last_name_enc']),
        'firstName' => Crypto::dec($f['first_name_enc']),
        'lastName' => Crypto::dec($f['last_name_enc']),
        'email' => $f['email'],
        'isActive' => $f['is_active'] === true || $f['is_active'] === 't',
        'createdAt' => $f['created_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Rbac::requireAccess('examinations', 'limited');
    $rows = $pdo->query('SELECT id, faculty_code, first_name_enc, last_name_enc, email, is_active, created_at FROM faculty ORDER BY created_at DESC')->fetchAll();
    $faculty = array_map('facultyRow', $rows);

    // Current schedule assignments per faculty member, for display in the
    // directory list — small dataset, one query per faculty is simpler to
    // read than a single mega-join and matches this project's style.
    $stmt = $pdo->prepare(
        'SELECT es.id, es.exam_date, es.start_time, es.room
         FROM exam_schedule_faculty esf JOIN exam_schedules es ON es.id = esf.schedule_id
         WHERE esf.faculty_id = ? ORDER BY es.exam_date, es.start_time'
    );
    foreach ($faculty as &$f) {
        $stmt->execute([$f['id']]);
        $f['assignments'] = array_map(fn($r) => [
            'scheduleId' => (int) $r['id'],
            'examDate' => $r['exam_date'],
            'startTime' => substr($r['start_time'], 0, 5),
            'room' => $r['room'],
        ], $stmt->fetchAll());
    }
    unset($f);

    jsonResponse(['faculty' => $faculty]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('examinations', 'full');
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'create' || $type === 'update') {
        $facultyCode = trim((string) ($body['facultyCode'] ?? ''));
        $firstName = trim((string) ($body['firstName'] ?? ''));
        $lastName = trim((string) ($body['lastName'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));

        if ($facultyCode === '' || mb_strlen($facultyCode) > 50) {
            jsonResponse(['success' => false, 'error' => 'Faculty ID must be 1-50 characters.'], 400);
        }
        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            jsonResponse(['success' => false, 'error' => 'First and last name are required (max 100 characters each).'], 400);
        }
        if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
        }

        if ($type === 'create') {
            $dupStmt = $pdo->prepare('SELECT 1 FROM faculty WHERE LOWER(faculty_code) = LOWER(?) OR LOWER(email) = LOWER(?)');
            $dupStmt->execute([$facultyCode, $email]);
            if ($dupStmt->fetchColumn()) {
                jsonResponse(['success' => false, 'error' => 'A faculty member with that ID or email already exists.'], 409);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO faculty (faculty_code, first_name_enc, last_name_enc, email, created_by) VALUES (?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([$facultyCode, Crypto::enc($firstName), Crypto::enc($lastName), $email, $user['id']]);
            $newId = (int) $stmt->fetchColumn();

            AuditLogger::log($user['id'], $user['role'], 'create_faculty', 'faculty', (string) $newId, "$firstName $lastName ($facultyCode)");
            jsonResponse(['success' => true, 'id' => $newId]);
        }

        $id = (int) ($body['id'] ?? 0);
        $dupStmt = $pdo->prepare('SELECT 1 FROM faculty WHERE (LOWER(faculty_code) = LOWER(?) OR LOWER(email) = LOWER(?)) AND id != ?');
        $dupStmt->execute([$facultyCode, $email, $id]);
        if ($dupStmt->fetchColumn()) {
            jsonResponse(['success' => false, 'error' => 'Another faculty member already uses that ID or email.'], 409);
        }
        $stmt = $pdo->prepare(
            'UPDATE faculty SET faculty_code = ?, first_name_enc = ?, last_name_enc = ?, email = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$facultyCode, Crypto::enc($firstName), Crypto::enc($lastName), $email, $id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Faculty member not found.'], 404);
        }
        AuditLogger::log($user['id'], $user['role'], 'update_faculty', 'faculty', (string) $id, "$firstName $lastName ($facultyCode)");
        jsonResponse(['success' => true]);
    }

    if ($type === 'toggleActive') {
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT is_active FROM faculty WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            jsonResponse(['success' => false, 'error' => 'Faculty member not found.'], 404);
        }
        $newState = !($current === true || $current === 't');
        $pdo->prepare('UPDATE faculty SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$newState, $id]);
        AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_faculty' : 'deactivate_faculty', 'faculty', (string) $id);
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }

    if ($type === 'assign') {
        $facultyId = (int) ($body['facultyId'] ?? 0);
        $scheduleId = (int) ($body['scheduleId'] ?? 0);

        $fExists = $pdo->prepare('SELECT 1 FROM faculty WHERE id = ?');
        $fExists->execute([$facultyId]);
        if (!$fExists->fetchColumn()) {
            jsonResponse(['success' => false, 'error' => 'Faculty member not found.'], 404);
        }
        $sExists = $pdo->prepare('SELECT 1 FROM exam_schedules WHERE id = ?');
        $sExists->execute([$scheduleId]);
        if (!$sExists->fetchColumn()) {
            jsonResponse(['success' => false, 'error' => 'Exam schedule not found.'], 404);
        }

        // access_code_enc is Crypto::enc()'d (unlike exam_schedules.access_code,
        // stored plaintext) per the spec's explicit requirement that the
        // faculty code specifically be protected by the encryption
        // mechanism — never compared in SQL, only decrypt+hash_equals in
        // api/faculty-verify.php. Re-assigning the same pair regenerates
        // the code (UPSERT), which doubles as the "reassign" action.
        $accessCode = strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare(
            'INSERT INTO exam_schedule_faculty (schedule_id, faculty_id, access_code_enc, assigned_by)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (schedule_id, faculty_id) DO UPDATE SET access_code_enc = EXCLUDED.access_code_enc, assigned_at = NOW(), assigned_by = EXCLUDED.assigned_by'
        );
        $stmt->execute([$scheduleId, $facultyId, Crypto::enc($accessCode), $user['id']]);

        AuditLogger::log($user['id'], $user['role'], 'assign_faculty', 'exam_schedule', (string) $scheduleId, "Faculty #$facultyId assigned");
        jsonResponse(['success' => true, 'accessCode' => $accessCode]);
    }

    if ($type === 'unassign') {
        $facultyId = (int) ($body['facultyId'] ?? 0);
        $scheduleId = (int) ($body['scheduleId'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM exam_schedule_faculty WHERE schedule_id = ? AND faculty_id = ?');
        $stmt->execute([$scheduleId, $facultyId]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Assignment not found.'], 404);
        }
        AuditLogger::log($user['id'], $user['role'], 'unassign_faculty', 'exam_schedule', (string) $scheduleId, "Faculty #$facultyId unassigned");
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
