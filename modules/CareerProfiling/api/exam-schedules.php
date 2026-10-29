<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../StudentManagement/lib/Sections.php';
require_once __DIR__ . '/../lib/ExamSchedule.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

$pdo = Database::get();
$validStrands = ['STEM', 'ABM', 'ICT', 'HUMSS'];
$validGrades = ['11', '12'];
const MAKEUP_MAX_STUDENTS = 50;

/**
 * Choosing the students of a make-up session shows their names, so it needs View Only or Full on Student information
 * as well as Full on Assessment Scheduling (checked by the caller).
 */
function maySeeStudents(array $user): bool
{
    return Rbac::atLeast(Rbac::levelsFor(Rbac::roleKey($user))['studentInfo'] ?? 'none', 'limited');
}

/** ", make-up for 2 students: 2026000003 / 2026000004" for the audit detail (Student Numbers only). */
function makeupDetail(array $schoolIds): string
{
    $n = count($schoolIds);
    return ', make-up for ' . $n . ' student' . ($n === 1 ? '' : 's') . ': ' . implode(' / ', $schoolIds);
}

/** SQL (no placeholders): student s has finished the assessment (the same test as api/verify-access-code.php). */
function finishedSql(): string
{
    return 'EXISTS (SELECT 1 FROM assessments a WHERE a.student_id = s.user_id AND a.is_latest = TRUE AND a.completed_at IS NOT NULL)';
}

/**
 * The chosen students of a make-up session, for the list. Names only for someone with Student information access and
 * only for students in their sections (StaffScope); everyone else is counted in 'others'.
 *
 * @return array{students: array<int,array>, others: int}
 */
function makeupStudents(PDO $pdo, int $scheduleId, bool $withNames, ?array $scope): array
{
    $stmt = $pdo->prepare(
        'SELECT s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.section, ' . finishedSql() . ' AS finished
         FROM exam_schedule_students ess JOIN students s ON s.user_id = ess.student_id
         WHERE ess.schedule_id = ? ORDER BY s.school_id'
    );
    $stmt->execute([$scheduleId]);
    $students = [];
    $others = 0;
    foreach ($stmt->fetchAll() as $r) {
        if (!$withNames || !StaffScope::allows($scope, $r['strand'], $r['section'])) {
            $others++;
            continue;
        }
        $students[] = [
            'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
            'schoolId' => $r['school_id'],
            'strand' => $r['strand'],
            'section' => $r['section'],
            'completed' => (bool) $r['finished'],
        ];
    }
    return ['students' => $students, 'others' => $others];
}

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
    // A make-up session expects exactly its chosen students.
    if ($s['schedule_type'] === 'makeup') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS expected, COUNT(*) FILTER (WHERE ' . finishedSql() . ') AS completed
             FROM exam_schedule_students ess JOIN students s ON s.user_id = ess.student_id WHERE ess.schedule_id = ?'
        );
        $stmt->execute([$s['id']]);
        $row = $stmt->fetch();
        return ['expected' => (int) $row['expected'], 'completed' => (int) $row['completed']];
    }

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
    if ($s['schedule_type'] === 'makeup') {
        $stmt = $pdo->prepare('SELECT s.school_id FROM exam_schedule_students ess JOIN students s ON s.user_id = ess.student_id WHERE ess.schedule_id = ?');
        $stmt->execute([$s['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
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
        'SELECT id, academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, schedule_type
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
        'scheduleType' => $r['schedule_type'], // make-ups may share the CGC Office (ExamSchedule::mayShareRoom)
    ], $pdo->query(
        'SELECT id, exam_date, start_time, end_time, room, schedule_type FROM exam_schedules WHERE NOT ' . ExamSchedule::endedSql() . ' ORDER BY exam_date, start_time'
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
        // access code stops working too (api/verify-access-code.php). Nothing is
        // ever deleted for this; the row simply moves out of the active list.
        'isArchived' => ExamSchedule::hasEnded($s['exam_date'], $s['end_time']),
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
    // Their group's sessions and their own make-up sessions (ExamSchedule::matchSql).
    $stmt = $pdo->prepare(
        'SELECT es.exam_date, es.start_time, es.end_time, es.room, es.schedule_type
         FROM exam_schedules es JOIN students s ON s.user_id = ?
         WHERE NOT ' . ExamSchedule::endedSql('es') . ' AND ' . ExamSchedule::matchSql($pdo) . '
         ORDER BY es.exam_date, es.start_time'
    );
    $stmt->execute([$user['id']]);
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pickStudents'])) {
    // The student search on the Create form (make-up session): registered, active students of the current school
    // year in this staff member's sections, at most 10. A Student Number (digits) is searched in SQL; a name is
    // matched only after the scope filter, so only those rows are decrypted.
    $user = Rbac::requireAccess('examinations', 'full');
    if (!maySeeStudents($user)) {
        jsonResponse(['error' => 'Insufficient permissions for this action.'], 403);
    }
    $q = trim(preg_replace('/\s+/', ' ', (string) ($_GET['q'] ?? '')));
    if (!ExamSchedule::makeupAvailable($pdo) || mb_strlen($q) < 3 || mb_strlen($q) > 60) {
        jsonResponse(['students' => []]);
    }
    $byNumber = preg_match('/^\d+$/', $q) === 1;
    $stmt = $pdo->prepare(
        'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section, ' . finishedSql() . ' AS finished
         FROM students s JOIN users u ON u.id = s.user_id
         WHERE s.academic_year = ? AND u.is_active = TRUE' . ($byNumber ? ' AND s.school_id LIKE ?' : '') . '
         ORDER BY finished, s.school_id' // students who can still take it come first
    );
    $stmt->execute($byNumber ? [AcademicYear::current(), '%' . $q . '%'] : [AcademicYear::current()]);
    $scope = StaffScope::forUser($pdo, $user);
    $needle = mb_strtolower($q);
    $found = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!StaffScope::allows($scope, $r['strand'], $r['section'])) {
            continue;
        }
        $first = Crypto::dec($r['first_name_enc']);
        $last = Crypto::dec($r['last_name_enc']);
        $name = "$last, $first";
        if (!$byNumber && !str_contains(mb_strtolower($name), $needle) && !str_contains(mb_strtolower("$first $last"), $needle)) {
            continue;
        }
        $found[] = [
            'userId' => (int) $r['user_id'],
            'name' => $name,
            'schoolId' => $r['school_id'],
            'gradeLevel' => $r['grade_level'],
            'strand' => $r['strand'],
            'section' => $r['section'],
            'completed' => (bool) $r['finished'],
        ];
        if (count($found) === 10) {
            break;
        }
    }
    jsonResponse(['students' => $found]);
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

    $withNames = maySeeStudents($user);
    $scope = StaffScope::forUser($pdo, $user);
    $schedules = array_map(function ($s) use ($pdo, $withNames, $scope) {
        $row = scheduleRow($s);
        $counts = scheduleCounts($pdo, $s);
        $row['expectedCount'] = $counts['expected'];
        $row['completedCount'] = $counts['completed'];
        if ($s['schedule_type'] === 'makeup') {
            $chosen = makeupStudents($pdo, (int) $s['id'], $withNames, $scope);
            $row['students'] = $chosen['students'];
            $row['otherStudents'] = $chosen['others'];
        }
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
        // The page shows "Chosen students (make-up)" only when the create below would accept it.
        'canChooseStudents' => ExamSchedule::makeupAvailable($pdo) && $withNames
            && Rbac::atLeast(Rbac::levelsFor(Rbac::roleKey($user))['examinations'], 'full'),
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

        $gradeLevel = null;
        $strand = null;
        $section = null;
        $chosen = []; // a make-up session's students, by user id
        if (($body['target'] ?? '') === 'students') {
            // Make-up session: for chosen students only (absent on the day, or needing another date). The group
            // columns stay empty; the students are listed in exam_schedule_students (ExamSchedule::matchSql).
            if (!maySeeStudents($user)) {
                jsonResponse(['success' => false, 'error' => 'Insufficient permissions for this action.'], 403);
            }
            if (!ExamSchedule::makeupAvailable($pdo)) {
                jsonResponse(['success' => false, 'error' => 'Make-up sessions are not set up yet. Please ask the administrator.'], 409);
            }
            $ids = is_array($body['studentIds'] ?? null) ? $body['studentIds'] : [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
            if (!$ids) {
                jsonResponse(['success' => false, 'error' => 'Choose at least one student.'], 400);
            }
            if (count($ids) > MAKEUP_MAX_STUDENTS) {
                jsonResponse(['success' => false, 'error' => 'Choose at most ' . MAKEUP_MAX_STUDENTS . ' students for one make-up session.'], 400);
            }
            $stmt = $pdo->prepare(
                'SELECT s.user_id, s.school_id, s.strand, s.section, ' . finishedSql() . ' AS finished
                 FROM students s JOIN users u ON u.id = s.user_id
                 WHERE s.user_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') AND s.academic_year = ? AND u.is_active = TRUE'
            );
            $stmt->execute([...$ids, $academicYear]);
            $scope = StaffScope::forUser($pdo, $user);
            foreach ($stmt->fetchAll() as $r) {
                if (StaffScope::allows($scope, $r['strand'], $r['section'])) {
                    $chosen[(int) $r['user_id']] = $r;
                }
            }
            // Missing, another school year, deactivated, or outside this staff member's sections: the same answer.
            if (count($chosen) !== count($ids)) {
                jsonResponse(['success' => false, 'error' => 'Student not found.'], 404);
            }
            foreach ($chosen as $r) {
                if ($r['finished']) {
                    jsonResponse(['success' => false, 'error' => "Student {$r['school_id']} has already taken the assessment. Taking it again needs an approved retake request."], 409);
                }
            }
            $scheduleType = 'makeup';
        } else {
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
            // Retakes were removed, so every group schedule is a regular exam session.
            $scheduleType = 'initial';
        }
        $notes = trim((string) ($body['notes'] ?? ''));

        // One room can't hold two sessions at the same time. Compared ignoring
        // case/extra spaces, and across every academic year (it's a real room).
        // Only make-up sessions in the CGC Office may overlap each other (ExamSchedule::mayShareRoom).
        $clash = $pdo->prepare(
            "SELECT start_time, end_time, schedule_type FROM exam_schedules
             WHERE exam_date = ? AND LOWER(REGEXP_REPLACE(TRIM(room), '\\s+', ' ', 'g')) = ?
               AND start_time < ? AND end_time > ? ORDER BY start_time"
        );
        $clash->execute([$examDate, ExamSchedule::roomKey($room), $endTime, $startTime]);
        foreach ($clash->fetchAll() as $existingBooking) {
            if (!ExamSchedule::mayShareRoom($scheduleType, $existingBooking['schedule_type'], $room)) {
                jsonResponse(['success' => false, 'error' => trim(preg_replace('/\s+/', ' ', $room)) . ' is already booked from ' . substr($existingBooking['start_time'], 0, 5) . ' to ' . substr($existingBooking['end_time'], 0, 5) . ' on that date. Pick another room or time.'], 409);
            }
        }

        $accessCode = strtoupper(bin2hex(random_bytes(3)));

        // The session and its students are saved together, or not at all.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, notes_enc, schedule_type, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([
                $academicYear, $examDate, $startTime, $endTime, $room, $gradeLevel, $strand, $section,
                $accessCode, $notes !== '' ? Crypto::enc($notes) : null, $scheduleType, $user['id'],
            ]);
            $newId = (int) $stmt->fetchColumn();
            $addStudent = $pdo->prepare('INSERT INTO exam_schedule_students (schedule_id, student_id) VALUES (?, ?)');
            foreach (array_keys($chosen) as $studentId) {
                $addStudent->execute([$newId, $studentId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[exam-schedules] create failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'The schedule could not be saved. Please try again.'], 500);
        }

        // Student Numbers, never names (the detail is encrypted, but a number is enough to trace who it was for).
        // Joined with " / ": the Audit Log shows each comma-separated part of a schedule detail on its own line.
        $makeupDetail = $chosen ? makeupDetail(array_column(array_values($chosen), 'school_id')) : '';
        AuditLogger::log($user['id'], $user['role'], 'create_exam_schedule', 'exam_schedule', (string) $newId,
            "$examDate $startTime-$endTime, room $room, AY $academicYear" . ($strand ? ", strand $strand" : '') . ($section ? ", section $section" : '') . $makeupDetail);

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
        // A make-up's student list goes with it (ON DELETE CASCADE), so read the Student Numbers first for the log.
        $wasFor = [];
        if (ExamSchedule::makeupAvailable($pdo)) {
            $forStmt = $pdo->prepare('SELECT s.school_id FROM exam_schedule_students ess JOIN students s ON s.user_id = ess.student_id WHERE ess.schedule_id = ? ORDER BY s.school_id');
            $forStmt->execute([$id]);
            $wasFor = $forStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM exam_schedules WHERE id = ? RETURNING exam_date, start_time, end_time, room, academic_year, strand, section, schedule_type');
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
                . ($gone['strand'] ? ", strand {$gone['strand']}" : '') . ($gone['section'] ? ", section {$gone['section']}" : '')
                . ($gone['schedule_type'] === 'makeup' ? ($wasFor ? makeupDetail($wasFor) : ', make-up session') : ''));
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
