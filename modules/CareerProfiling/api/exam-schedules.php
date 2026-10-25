<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../StudentManagement/lib/Sections.php';
require_once __DIR__ . '/../lib/ExamSchedule.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';

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

    // "Expected" is the roster for the exam's strand/section. Only when no
    // roster has been uploaded for that Academic Year at all do we fall back
    // to registered students in the same group, so it never reads "27 of 0".
    $hasRoster = $pdo->prepare('SELECT 1 FROM assessment_roster WHERE academic_year = ? LIMIT 1');
    $hasRoster->execute([$s['academic_year']]);
    if (!$hasRoster->fetchColumn()) {
        $fConds = ['academic_year = ?'];
        $fParams = [$s['academic_year']];
        if ($s['grade_level'] !== null) { $fConds[] = 'grade_level = ?'; $fParams[] = $s['grade_level']; }
        if ($s['strand'] !== null) { $fConds[] = 'strand = ?'; $fParams[] = $s['strand']; }
        if ($s['section'] !== null) { $fConds[] = 'section = ?'; $fParams[] = $s['section']; }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE ' . implode(' AND ', $fConds));
        $stmt->execute($fParams);
        $expected = (int) $stmt->fetchColumn();
    }

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

/** school_ids a session is expected to cover (same matching rules as scheduleCounts above). */
function scheduleStudentIds(PDO $pdo, array $s): array
{
    $ids = [];
    $rConds = ['academic_year = ?'];
    $rParams = [$s['academic_year']];
    if ($s['strand'] !== null) { $rConds[] = 'strand = ?'; $rParams[] = $s['strand']; }
    if ($s['section'] !== null) { $rConds[] = 'section = ?'; $rParams[] = $s['section']; }
    $stmt = $pdo->prepare('SELECT school_id FROM assessment_roster WHERE ' . implode(' AND ', $rConds));
    $stmt->execute($rParams);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[$id] = true; }

    $fConds = ['academic_year = ?'];
    $fParams = [$s['academic_year']];
    if ($s['grade_level'] !== null) { $fConds[] = 'grade_level = ?'; $fParams[] = $s['grade_level']; }
    if ($s['strand'] !== null) { $fConds[] = 'strand = ?'; $fParams[] = $s['strand']; }
    if ($s['section'] !== null) { $fConds[] = 'section = ?'; $fParams[] = $s['section']; }
    $stmt = $pdo->prepare('SELECT school_id FROM students WHERE ' . implode(' AND ', $fConds));
    $stmt->execute($fParams);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[$id] = true; }
    return array_keys($ids);
}

/**
 * Figures for the summary cards and the room-conflict banner: always about the
 * sessions that are still active (not yet ended), whichever list is showing.
 */
function scheduleSummary(PDO $pdo, string $academicYear, int $archivedCount): array
{
    $stmt = $pdo->prepare(
        'SELECT id, academic_year, exam_date, start_time, end_time, room, grade_level, strand, section
         FROM exam_schedules WHERE academic_year = ? AND NOT ' . ExamSchedule::endedSql() . ' ORDER BY exam_date, start_time'
    );
    $stmt->execute([$academicYear]);
    $active = $stmt->fetchAll();

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $weekEnd = $today->modify('+6 days')->format('Y-m-d');
    $todayStr = $today->format('Y-m-d');

    $covered = [];
    $rooms = [];
    $thisWeek = 0;
    foreach ($active as $s) {
        foreach (scheduleStudentIds($pdo, $s) as $id) { $covered[$id] = true; }
        $rooms[ExamSchedule::roomKey($s['room'])] = $s['room'];
        if ($s['exam_date'] >= $todayStr && $s['exam_date'] <= $weekEnd) { $thisWeek++; }
    }

    // Bookings across every academic year: a room is a physical place, so a
    // clash is a clash whichever year's session it belongs to.
    $bookings = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'examDate' => $r['exam_date'],
        'startTime' => substr($r['start_time'], 0, 5),
        'endTime' => substr($r['end_time'], 0, 5),
        'room' => $r['room'],
    ], $pdo->query(
        'SELECT id, exam_date, start_time, end_time, room FROM exam_schedules WHERE NOT ' . ExamSchedule::endedSql() . ' ORDER BY exam_date, start_time'
    )->fetchAll());

    return [
        'totalActive' => count($active),
        'thisWeek' => $thisWeek,
        'studentsCovered' => count($covered),
        'roomsInUse' => count($rooms),
        'roomNames' => array_values($rooms),
        'completedSessions' => $archivedCount,
        'bookings' => $bookings,
        'conflicts' => ExamSchedule::findConflicts($bookings),
    ];
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
        // Archived = the exam's end time has already passed (school time). Its
        // access code stops working too (modules/CareerProfiling/api/verify-access-code.php). Nothing is
        // ever deleted for this; the row simply moves out of the active list.
        'isArchived' => ExamSchedule::hasEnded($s['exam_date'], $s['end_time']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['mine'])) {
    // Student-facing: their own matched schedule(s) for the current AY,
    // for the "My Exam Schedule" panel on modules/CareerProfiling/pages/assessment.html. Purely
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
           AND NOT " . ExamSchedule::endedSql() . "
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
    // A past school year can be picked (only years that exist, never a future one); the current year is the default.
    $academicYear = AcademicYear::resolve($pdo, (string) ($_GET['academicYear'] ?? ''));
    // Active list by default; ?archived=1 shows the past ones. Past exams
    // stay in the table (audit logs and historical reports still need them).
    $showArchived = isset($_GET['archived']);
    $dateCondition = $showArchived ? ExamSchedule::endedSql() : 'NOT ' . ExamSchedule::endedSql();
    $orderBy = $showArchived ? 'exam_date DESC, start_time DESC' : 'exam_date, start_time';
    $stmt = $pdo->prepare(
        "SELECT id, academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, notes_enc, schedule_type
         FROM exam_schedules WHERE academic_year = ? AND $dateCondition ORDER BY $orderBy"
    );
    $stmt->execute([$academicYear]);
    $rows = $stmt->fetchAll();

    $archivedStmt = $pdo->prepare('SELECT COUNT(*) FROM exam_schedules WHERE academic_year = ? AND ' . ExamSchedule::endedSql());
    $archivedStmt->execute([$academicYear]);
    $archivedCount = (int) $archivedStmt->fetchColumn();

    $schedules = array_map(function ($s) use ($pdo) {
        $row = scheduleRow($s);
        $counts = scheduleCounts($pdo, $s);
        $row['expectedCount'] = $counts['expected'];
        $row['completedCount'] = $counts['completed'];
        return $row;
    }, $rows);

    jsonResponse([
        'academicYear' => $academicYear,
        'academicYears' => AcademicYear::available($pdo),
        'isPastYear' => AcademicYear::isPast($academicYear),
        'schedules' => $schedules,
        'archivedCount' => $archivedCount,
        'showingArchived' => $showArchived,
        'summary' => scheduleSummary($pdo, $academicYear, $archivedCount),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('examinations', 'full');
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'create') {
        $academicYear = trim((string) ($body['academicYear'] ?? ''));
        if ($academicYear === '') {
            $academicYear = AcademicYear::current();
        }
        // Past school years are looked at, not changed: new schedules are for the current year only.
        if ($academicYear !== AcademicYear::current()) {
            jsonResponse(['success' => false, 'error' => 'Schedules can only be created for the current school year.'], 400);
        }

        $examDate = trim((string) ($body['examDate'] ?? ''));
        $startTime = trim((string) ($body['startTime'] ?? ''));
        $endTime = trim((string) ($body['endTime'] ?? ''));
        $room = trim((string) ($body['room'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid assessment date.'], 400);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            jsonResponse(['success' => false, 'error' => 'Enter valid start/end times.'], 400);
        }
        if ($endTime <= $startTime) {
            jsonResponse(['success' => false, 'error' => 'End time must be after start time.'], 400);
        }
        // The date picker's own min/guard is client-side only — enforce the
        // same rule here (school time, see config/env.php's APP_TIMEZONE) so
        // a hand-built request can't schedule an exam that's already passed.
        $today = date('Y-m-d');
        if ($examDate < $today) {
            jsonResponse(['success' => false, 'error' => 'Assessment date can\'t be in the past.'], 400);
        }
        if ($examDate === $today && $startTime < date('H:i')) {
            jsonResponse(['success' => false, 'error' => 'Start time has already passed for today.'], 400);
        }
        if ($room === '' || mb_strlen($room) > 50) {
            jsonResponse(['success' => false, 'error' => 'Room must be 1-50 characters.'], 400);
        }

        $gradeLevel = trim((string) ($body['gradeLevel'] ?? ''));
        $gradeLevel = in_array($gradeLevel, $validGrades, true) ? $gradeLevel : null;
        $strand = strtoupper(trim((string) ($body['strand'] ?? '')));
        $strand = in_array($strand, $validStrands, true) ? $strand : null;
        // Never trust the client-side cascading dropdown alone: a section
        // must belong to the chosen strand's fixed list, and "All strands"
        // can't be paired with one specific section (every section belongs
        // to exactly one strand).
        $section = trim((string) ($body['section'] ?? ''));
        if ($section !== '') {
            if ($strand === null || !Sections::isValid($pdo, $strand, $section)) {
                jsonResponse(['success' => false, 'error' => 'Invalid section for the selected strand.'], 400);
            }
        } else {
            $section = null;
        }
        $notes = trim((string) ($body['notes'] ?? ''));
        // Retakes were removed, so every schedule is a regular exam session.
        $scheduleType = 'initial';

        // One room can't hold two sessions at the same time. Compared ignoring
        // case/extra spaces, and across every academic year (it's a real room).
        $clash = $pdo->prepare(
            "SELECT start_time, end_time FROM exam_schedules
             WHERE exam_date = ? AND LOWER(REGEXP_REPLACE(TRIM(room), '\\s+', ' ', 'g')) = ?
               AND start_time < ? AND end_time > ? LIMIT 1"
        );
        $clash->execute([$examDate, ExamSchedule::roomKey($room), $endTime, $startTime]);
        if ($existingBooking = $clash->fetch()) {
            jsonResponse(['success' => false, 'error' => trim(preg_replace('/\s+/', ' ', $room)) . ' is already booked from ' . substr($existingBooking['start_time'], 0, 5) . ' to ' . substr($existingBooking['end_time'], 0, 5) . ' on that date. Pick another room or time.'], 409);
        }

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
        // An exam that has already ended is archived; a fresh code for it would
        // be useless (its codes no longer work), so don't hand one out.
        $existing = $pdo->prepare('SELECT exam_date, end_time FROM exam_schedules WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if ($row && ExamSchedule::hasEnded($row['exam_date'], $row['end_time'])) {
            jsonResponse(['success' => false, 'error' => 'This assessment has already ended and is archived. Create a new schedule instead.'], 409);
        }
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
            $stmt = $pdo->prepare('DELETE FROM exam_schedules WHERE id = ? RETURNING exam_date, start_time, end_time, room, academic_year, strand, section');
            $stmt->execute([$id]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Cannot delete this schedule — it still has retake grants referencing it.'], 409);
        }
        $gone = $stmt->fetch();
        if (!$gone) {
            jsonResponse(['success' => false, 'error' => 'Schedule not found.'], 404);
        }
        // The schedule is gone after this, so the log keeps what it was (in the same words as create).
        AuditLogger::log($user['id'], $user['role'], 'delete_exam_schedule', 'exam_schedule', (string) $id,
            "{$gone['exam_date']} " . substr((string) $gone['start_time'], 0, 5) . '-' . substr((string) $gone['end_time'], 0, 5)
                . ", room {$gone['room']}, AY {$gone['academic_year']}"
                . ($gone['strand'] ? ", strand {$gone['strand']}" : '') . ($gone['section'] ? ", section {$gone['section']}" : ''));
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
