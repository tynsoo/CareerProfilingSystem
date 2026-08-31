<?php

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::get();
$validStrands = ['STEM', 'ABM', 'ICT', 'HUMSS'];
$validGrades = ['11', '12'];

/**
 * Expected/completed counts for one schedule row. assessment_roster has no
 * grade_level column (it's decoupled from `students` — see its schema
 * comment — and the CSV import format never carried one), so the expected
 * count only matches on strand/section; the completed count joins through
 * `students`, which does have grade_level, so that dimension is included
 * there. This mirrors the same expected-vs-completed asymmetry the
 * existing Assessment Statistics card already has (roster vs. real
 * students), not a new inconsistency.
 */
function scheduleCounts(PDO $pdo, array $s): array
{
    $rConds = ['academic_year = ?'];
    $rParams = [$s['academic_year']];
    if ($s['strand'] !== null) { $rConds[] = 'strand = ?'; $rParams[] = $s['strand']; }
    if ($s['section'] !== null) { $rConds[] = 'section = ?'; $rParams[] = $s['section']; }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_roster WHERE ' . implode(' AND ', $rConds));
    $stmt->execute($rParams);
    $expected = (int) $stmt->fetchColumn();

    $cConds = ['a.is_latest = TRUE', 's.academic_year = ?'];
    $cParams = [$s['academic_year']];
    if ($s['grade_level'] !== null) { $cConds[] = 's.grade_level = ?'; $cParams[] = $s['grade_level']; }
    if ($s['strand'] !== null) { $cConds[] = 's.strand = ?'; $cParams[] = $s['strand']; }
    if ($s['section'] !== null) { $cConds[] = 's.section = ?'; $cParams[] = $s['section']; }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE ' . implode(' AND ', $cConds));
    $stmt->execute($cParams);
    $completed = (int) $stmt->fetchColumn();

    return ['expected' => $expected, 'completed' => $completed];
}

function scheduleRow(array $s): array
{
    return [
        'id' => (int) $s['id'],
        'academicYear' => $s['academic_year'],
        'examDate' => $s['exam_date'],
        'startTime' => substr($s['start_time'], 0, 5),
        'endTime' => substr($s['end_time'], 0, 5),
        'room' => $s['room'],
        'gradeLevel' => $s['grade_level'],
        'strand' => $s['strand'],
        'section' => $s['section'],
        'accessCode' => $s['access_code'],
        'notes' => $s['notes_enc'] !== null ? Crypto::dec($s['notes_enc']) : '',
        'scheduleType' => $s['schedule_type'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['mine'])) {
    // Student-facing: their own matched schedule(s) for the current AY,
    // for the "My Exam Schedule" panel on assessment.html. Purely
    // informational — does not gate assessment entry (see Phase 2 note in
    // the plan: the existing global access-code flow is left unchanged).
    $user = Auth::requireLogin();
    if ($user['role'] !== 'student') {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
    $stmt = $pdo->prepare('SELECT strand, section, grade_level, academic_year FROM students WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $student = $stmt->fetch();
    if (!$student || !$student['academic_year']) {
        jsonResponse(['schedules' => []]);
    }
    $stmt = $pdo->prepare(
        "SELECT id, academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, notes_enc, schedule_type
         FROM exam_schedules
         WHERE academic_year = ?
           AND (grade_level IS NULL OR grade_level = ?)
           AND (strand IS NULL OR strand = ?)
           AND (section IS NULL OR section = ?)
         ORDER BY exam_date, start_time"
    );
    $stmt->execute([$student['academic_year'], $student['grade_level'], $student['strand'], $student['section']]);
    // Students only need date/time/room — never expose the access code
    // (that's for staff) or internal notes through this endpoint.
    $schedules = array_map(fn($s) => [
        'examDate' => $s['exam_date'],
        'startTime' => substr($s['start_time'], 0, 5),
        'endTime' => substr($s['end_time'], 0, 5),
        'room' => $s['room'],
        'scheduleType' => $s['schedule_type'],
    ], $stmt->fetchAll());
    jsonResponse(['schedules' => $schedules]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = Rbac::requireAccess('examinations', 'limited');
    $academicYear = trim((string) ($_GET['academicYear'] ?? ''));
    if ($academicYear === '') {
        $academicYear = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
    }
    $stmt = $pdo->prepare(
        'SELECT id, academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, notes_enc, schedule_type
         FROM exam_schedules WHERE academic_year = ? ORDER BY exam_date, start_time'
    );
    $stmt->execute([$academicYear]);
    $rows = $stmt->fetchAll();

    $schedules = array_map(function ($s) use ($pdo) {
        $row = scheduleRow($s);
        $counts = scheduleCounts($pdo, $s);
        $row['expectedCount'] = $counts['expected'];
        $row['completedCount'] = $counts['completed'];
        return $row;
    }, $rows);

    jsonResponse(['academicYear' => $academicYear, 'schedules' => $schedules]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('examinations', 'full');
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'create') {
        $academicYear = trim((string) ($body['academicYear'] ?? ''));
        if ($academicYear === '') {
            $academicYear = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
        }
        if ($academicYear === '') {
            jsonResponse(['success' => false, 'error' => 'Set the current Academic Year in Security Configuration first.'], 400);
        }

        $examDate = trim((string) ($body['examDate'] ?? ''));
        $startTime = trim((string) ($body['startTime'] ?? ''));
        $endTime = trim((string) ($body['endTime'] ?? ''));
        $room = trim((string) ($body['room'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid exam date.'], 400);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            jsonResponse(['success' => false, 'error' => 'Enter valid start/end times.'], 400);
        }
        if ($endTime <= $startTime) {
            jsonResponse(['success' => false, 'error' => 'End time must be after start time.'], 400);
        }
        if ($room === '' || mb_strlen($room) > 50) {
            jsonResponse(['success' => false, 'error' => 'Room must be 1-50 characters.'], 400);
        }

        $gradeLevel = trim((string) ($body['gradeLevel'] ?? ''));
        $gradeLevel = in_array($gradeLevel, $validGrades, true) ? $gradeLevel : null;
        $strand = strtoupper(trim((string) ($body['strand'] ?? '')));
        $strand = in_array($strand, $validStrands, true) ? $strand : null;
        $section = trim((string) ($body['section'] ?? ''));
        $section = ($section !== '' && mb_strlen($section) <= 20) ? $section : null;
        $notes = trim((string) ($body['notes'] ?? ''));
        $scheduleType = ($body['scheduleType'] ?? 'initial') === 'retake' ? 'retake' : 'initial';

        $accessCode = strtoupper(bin2hex(random_bytes(3)));

        $stmt = $pdo->prepare(
            'INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, notes_enc, schedule_type, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([
            $academicYear, $examDate, $startTime, $endTime, $room, $gradeLevel, $strand, $section,
            $accessCode, $notes !== '' ? Crypto::enc($notes) : null, $scheduleType, $user['id'],
        ]);
        $newId = (int) $stmt->fetchColumn();

        AuditLogger::log($user['id'], $user['role'], 'create_exam_schedule', 'exam_schedule', (string) $newId,
            "$examDate $startTime-$endTime, room $room, AY $academicYear" . ($strand ? ", strand $strand" : '') . ($section ? ", section $section" : ''));

        jsonResponse(['success' => true, 'id' => $newId, 'accessCode' => $accessCode]);
    }

    if ($type === 'regenerateCode') {
        $id = (int) ($body['id'] ?? 0);
        $accessCode = strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('UPDATE exam_schedules SET access_code = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$accessCode, $id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Schedule not found.'], 404);
        }
        AuditLogger::log($user['id'], $user['role'], 'regenerate_exam_schedule_code', 'exam_schedule', (string) $id, 'Access code regenerated');
        jsonResponse(['success' => true, 'accessCode' => $accessCode]);
    }

    if ($type === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        try {
            $stmt = $pdo->prepare('DELETE FROM exam_schedules WHERE id = ?');
            $stmt->execute([$id]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Cannot delete this schedule — it still has retake grants referencing it.'], 409);
        }
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'Schedule not found.'], 404);
        }
        AuditLogger::log($user['id'], $user['role'], 'delete_exam_schedule', 'exam_schedule', (string) $id);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
