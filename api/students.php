<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QuestionBank.php';
require_once __DIR__ . '/../lib/Sections.php';
require_once __DIR__ . '/../lib/AcademicYear.php';
require_once __DIR__ . '/../lib/Mismatch.php';
require_once __DIR__ . '/../lib/StaffScope.php';
require_once __DIR__ . '/../lib/CompletionTarget.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();
// Sections this staff member handles (null = everyone): see lib/StaffScope.php.
$scope = StaffScope::forUser($pdo, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    if (($body['type'] ?? '') === 'toggleActive') {
        $targetId = (int) ($body['userId'] ?? 0);
        $stmt = $pdo->prepare("SELECT u.id, u.is_active, s.strand, s.section FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'student'");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row || !StaffScope::allows($scope, $row['strand'], $row['section'])) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $newState = !$row['is_active'];
        // PDOStatement::execute() stringifies a bound PHP bool — false
        // becomes '' (not '0'), which Postgres's boolean type rejects
        // outright ("invalid input syntax for type boolean"). Bind an int
        // instead; Postgres accepts 0/1 for boolean.
        $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $targetId]);
        // login.php already rejects any user (any role) with is_active =
        // false, so deactivating here immediately blocks sign-in -- no
        // separate enforcement needed.
        AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_student_account' : 'deactivate_student_account', 'user', (string) $targetId);
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }
    // Admin only: delete a student's answers (assessment, worksheet and the recommendation built from
    // them) so they can take the assessment again, for the rare case guidance allows a retake.
    if (($body['type'] ?? '') === 'resetAssessment') {
        if ($user['role'] !== 'admin') {
            jsonResponse(['success' => false, 'error' => 'Only an administrator can reset a student\'s assessment.'], 403);
        }
        $targetId = (int) ($body['userId'] ?? 0);
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student'");
        $exists->execute([$targetId]);
        if (!$exists->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $pdo->beginTransaction();
        try {
            foreach (['monitoring_flags', 'recommendations', 'worksheets', 'assessments'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE student_id = ?")->execute([$targetId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[reset-assessment] failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not reset the assessment. Please try again.'], 500);
        }
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key IN (?, ?)')->execute(['access-code:' . $targetId, 'result-email:' . $targetId]);
        AuditLogger::log($user['id'], $user['role'], 'reset_student_assessment', 'user', (string) $targetId, 'Previous answers deleted so the student can retake it');
        jsonResponse(['success' => true]);
    }
    // Correct a student's section after registration. Only sections that belong
    // to the student's own strand are accepted (same allow-list as registration).
    // Exam gating and notifications read students.section on every request, so
    // the change applies immediately.
    if (($body['type'] ?? '') === 'updateSection') {
        $targetId = (int) ($body['userId'] ?? 0);
        $section = trim((string) ($body['section'] ?? ''));
        $stmt = $pdo->prepare('SELECT strand, section FROM students WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $student = $stmt->fetch();
        if (!$student || !StaffScope::allows($scope, $student['strand'], $student['section'])) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        if (!Sections::isValid($pdo, $student['strand'], $section)) {
            jsonResponse(['success' => false, 'error' => 'Invalid section for this student\'s strand.'], 400);
        }
        if ($student['section'] !== $section) {
            $pdo->prepare('UPDATE students SET section = ? WHERE user_id = ?')->execute([$section, $targetId]);
            AuditLogger::log($user['id'], $user['role'], 'update_student_section', 'user', (string) $targetId, "Section: {$student['section']} -> $section");
        }
        jsonResponse(['success' => true, 'section' => $section]);
    }
    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Single-student lookup mode: student-profile.html loads a real record by the
// student's internal user id (never the Student Number, so it doesn't end up in the web
// address or browser history) instead of everything being smuggled through
// URL query params.
$idLookup = (int) ($_GET['id'] ?? 0);
if ($idLookup > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.user_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section,
                s.academic_year, s.registered_at, u.is_active, a.top_types, a.completed_at, a.score_r, a.score_i, a.score_a,
                a.score_s, a.score_e, a.score_c
         FROM students s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
         WHERE s.user_id = ?'
    );
    $stmt->execute([$idLookup]);
    $row = $stmt->fetch();
    if (!$row || !StaffScope::allows($scope, $row['strand'], $row['section'])) {
        jsonResponse(['error' => 'Student not found'], 404);
    }
    $hasAssessment = $row['completed_at'] !== null;
    // subject is encrypted (subject_enc), so it can't be matched with a
    // plain SQL WHERE clause — decrypt and compare in PHP instead. Cheap
    // here since it's scoped to one student's own requests.
    $counseledStmt = $pdo->prepare('SELECT subject_enc FROM help_requests WHERE student_id = ?');
    $counseledStmt->execute([(int) $row['user_id']]);
    $counseled = false;
    foreach ($counseledStmt->fetchAll(PDO::FETCH_COLUMN) as $subjectEnc) {
        if (Crypto::dec($subjectEnc) === 'Request for Academic Advising') {
            $counseled = true;
            break;
        }
    }

    // Full attempt history (not just is_latest), for the "Assessment
    // Attempts" section. Attempts made before retakes were removed still show.
    $attemptsStmt = $pdo->prepare(
        'SELECT id, attempt_number, top_types, completed_at, score_r, score_i, score_a, score_s, score_e, score_c
         FROM assessments WHERE student_id = ? ORDER BY attempt_number DESC'
    );
    $attemptsStmt->execute([(int) $row['user_id']]);

    $attempts = array_map(fn($a) => [
        'attemptNumber' => (int) $a['attempt_number'],
        'completedAt' => $a['completed_at'],
        'riasec' => implode(', ', json_decode($a['top_types'], true)),
        'scores' => [
            'R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
            'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c'],
        ],
    ], $attemptsStmt->fetchAll());

    jsonResponse(['student' => [
        'userId' => (int) $row['user_id'],
        'firstName' => Crypto::dec($row['first_name_enc']),
        'lastName' => Crypto::dec($row['last_name_enc']),
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
        'gradeLevel' => $row['grade_level'],
        'section' => $row['section'],
        'allowedSections' => (function () use ($pdo, $row) {
            $sections = Sections::byStrand($pdo)[$row['strand']] ?? [];
            // Keep the student's current section selectable even if it's
            // since been deactivated — otherwise the dropdown silently
            // wouldn't offer their own existing value.
            if ($row['section'] && !in_array($row['section'], $sections, true)) {
                $sections[] = $row['section'];
            }
            return $sections;
        })(),
        'academicYear' => $row['academic_year'],
        'isActive' => (bool) $row['is_active'],
        'status' => $hasAssessment ? 'Completed' : 'Pending',
        'riasec' => $hasAssessment ? implode(', ', json_decode($row['top_types'], true)) : '',
        'scores' => $hasAssessment ? [
            'R' => (int) $row['score_r'], 'I' => (int) $row['score_i'], 'A' => (int) $row['score_a'],
            'S' => (int) $row['score_s'], 'E' => (int) $row['score_e'], 'C' => (int) $row['score_c'],
        ] : null,
        'counseling' => $hasAssessment ? ($counseled ? 'Availed' : 'Did Not Avail') : '',
        'registeredAt' => $row['registered_at'],
        'attempts' => $attempts,
    ]]);
}

// ?accounts=1: the Student Accounts page (login/activation status only, no
// assessment/counseling data) — a separate view from the Student Assessment
// Overview above, per the split the counselors asked for.
if (isset($_GET['accounts'])) {
    $search = trim((string) ($_GET['search'] ?? ''));
    $rows = $pdo->query(
        'SELECT u.id AS user_id, u.email, u.is_active, u.created_at,
                s.first_name_enc, s.last_name_enc, s.strand, s.section
         FROM users u
         JOIN students s ON s.user_id = u.id
         ORDER BY u.created_at DESC'
    )->fetchAll();

    $rows = array_values(array_filter($rows, fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));
    $accounts = array_map(fn($r) => [
        'userId' => (int) $r['user_id'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'section' => $r['section'],
        'email' => $r['email'],
        'isActive' => (bool) $r['is_active'],
        'createdAt' => $r['created_at'],
    ], $rows);

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $accounts = array_values(array_filter($accounts, fn($a) => str_contains(mb_strtolower($a['name']), $needle)));
    }

    jsonResponse(['accounts' => $accounts, 'total' => count($accounts)]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// ?all=1 (used by the Announcements student-picker) returns everyone
// matching the filters in one response instead of paginating.
$pageSize = isset($_GET['all']) ? PHP_INT_MAX : 8;
$search = trim((string) ($_GET['search'] ?? ''));
$strandFilter = (string) ($_GET['strand'] ?? '');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$counselingFilter = (string) ($_GET['counseling'] ?? '');
$registrationFilter = (string) ($_GET['registration'] ?? ''); // registered | notRegistered
$assessmentFilter = (string) ($_GET['assessment'] ?? '');     // assessed | notAssessed
$asCsv = ($_GET['format'] ?? '') === 'csv';

$rows = $pdo->query(
    'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section, s.registered_at,
            u.is_active, u.email, a.top_types, a.completed_at
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     ORDER BY s.registered_at DESC'
)->fetchAll();

// A student "availed" counseling if they've submitted a Schedule Advising request
// from Help Center (results.html links there with a fixed subject line). This is
// distinct from monitoring escalation, which is a counselor-initiated review of a
// low-confidence recommendation, not the student asking for advising themselves.
//
// subject is now encrypted (subject_enc), so it can't be matched with a plain
// SQL WHERE clause anymore — encryption produces different ciphertext every
// time, even for the same input, so this has to decrypt and compare in PHP.
$counseledIds = [];
$firstAdvisingAt = []; // student_id => timestamp of their earliest advising request (for the monthly trend)
$helpRequestRows = $pdo->query(
    'SELECT student_id, subject_enc, sent_at FROM help_requests WHERE student_id IS NOT NULL'
)->fetchAll();
foreach ($helpRequestRows as $hr) {
    if (Crypto::dec($hr['subject_enc']) === 'Request for Academic Advising') {
        $sid = (int) $hr['student_id'];
        $counseledIds[$sid] = true;
        $sentTs = strtotime((string) $hr['sent_at']);
        if (!isset($firstAdvisingAt[$sid]) || $sentTs < $firstAdvisingAt[$sid]) {
            $firstAdvisingAt[$sid] = $sentTs;
        }
    }
}

$allRegisteredSchoolIds = array_column($rows, 'school_id'); // before scoping: a student registered elsewhere is not 'Not Registered'
$rows = array_values(array_filter($rows, fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));
$mismatchIds = Mismatch::studentIds($pdo); // finished students whose chosen program isn't in their Top Matches

$students = array_map(function ($r) use ($counseledIds, $mismatchIds) {
    $hasAssessment = $r['completed_at'] !== null;
    $status = $hasAssessment ? 'Completed' : 'Pending';
    $topTypes = $hasAssessment ? json_decode($r['top_types'], true) : [];
    $counseling = $hasAssessment ? (isset($counseledIds[(int) $r['user_id']]) ? 'Availed' : 'Did Not Avail') : '';

    return [
        'userId' => (int) $r['user_id'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'gradeLevel' => $r['grade_level'],
        'section' => $r['section'],
        'isActive' => (bool) $r['is_active'],
        'status' => $status,
        'riasec' => implode(', ', $topTypes),
        'counseling' => $counseling,
        'mismatched' => $hasAssessment && isset($mismatchIds[(int) $r['user_id']]),
        'registeredAt' => $r['registered_at'],
        'assessmentDate' => $r['completed_at'],
        '_email' => $r['email'], // only for the CSV download; never sent in the list JSON
    ];
}, $rows);

// Students on the current Academic Year's uploaded roster who haven't
// registered an account yet — shown here (userId: null, status "Not
// Registered", no assessment/counseling data) so the overview reflects a
// roster upload, not just who happened to sign up. A roster row whose
// school_id already matches a registered student is skipped entirely;
// the real account's live data always wins over the one-time CSV snapshot.
$currentAy = AcademicYear::current();
if ($currentAy !== '') {
    $registeredSchoolIds = $allRegisteredSchoolIds;
    $rosterStmt = $pdo->prepare('SELECT school_id, name_enc, strand, section, email FROM assessment_roster WHERE academic_year = ?');
    $rosterStmt->execute([$currentAy]);
    foreach ($rosterStmt->fetchAll() as $rr) {
        if (in_array($rr['school_id'], $registeredSchoolIds, true) || !StaffScope::allows($scope, $rr['strand'], $rr['section'])) {
            continue;
        }
        $students[] = [
            'userId' => null,
            'name' => Crypto::dec($rr['name_enc']),
            'strand' => $rr['strand'],
            'gradeLevel' => null,
            'section' => $rr['section'],
            'isActive' => null,
            'status' => 'Not Registered',
            'riasec' => '',
            'counseling' => '',
            'mismatched' => false,
            'registeredAt' => null,
            'assessmentDate' => null,
            '_email' => $rr['email'],
        ];
    }
}

$totalStudents = count($students);
$completedCount = count(array_filter($students, fn($s) => $s['status'] === 'Completed'));
$pendingCount = $totalStudents - $completedCount;
$counselingCount = count(array_filter($students, fn($s) => $s['counseling'] === 'Availed'));
$mismatchedCount = count(array_filter($students, fn($s) => $s['mismatched']));

// A summary per section for the dashboard: how many students each section has and where they stand.
$sectionSummary = [];
foreach ($students as $s) {
    $key = $s['strand'] . '|' . $s['section'];
    if (!isset($sectionSummary[$key])) {
        $sectionSummary[$key] = [
            'strand' => $s['strand'], 'section' => $s['section'], 'total' => 0, 'completed' => 0, 'pending' => 0, 'notRegistered' => 0, 'mismatched' => 0,
            // Who is in each group, so staff can see the names and not just the counts.
            'people' => ['completed' => [], 'pending' => [], 'notRegistered' => [], 'mismatched' => []],
        ];
    }
    $sectionSummary[$key]['total']++;
    $person = ['name' => $s['name'], 'userId' => $s['userId']]; // userId is null for a roster student with no account yet
    if ($s['status'] === 'Completed') {
        $sectionSummary[$key]['completed']++;
        $sectionSummary[$key]['people']['completed'][] = $person;
    } elseif ($s['status'] === 'Not Registered') {
        $sectionSummary[$key]['notRegistered']++;
        $sectionSummary[$key]['people']['notRegistered'][] = $person;
    } else {
        $sectionSummary[$key]['pending']++;
        $sectionSummary[$key]['people']['pending'][] = $person;
    }
    if ($s['mismatched']) {
        $sectionSummary[$key]['mismatched']++;
        $sectionSummary[$key]['people']['mismatched'][] = $person;
    }
}
ksort($sectionSummary);
foreach ($sectionSummary as &$sec) {
    $sec['registered'] = $sec['total'] - $sec['notRegistered'];
    // Assessed out of everyone expected in the section (registered or on the roster).
    $sec['completionRate'] = $sec['total'] > 0 ? round($sec['completed'] / $sec['total'] * 100, 1) : 0.0;
    foreach ($sec['people'] as &$list) {
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    }
    unset($list);
}
unset($sec);
$filtered = $students;
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filtered = array_values(array_filter($filtered, fn($s) => str_contains(mb_strtolower($s['name']), $needle)));
}
if ($strandFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['strand'] === $strandFilter));
}
if ($sectionFilter !== '') {
    $needleSection = mb_strtolower($sectionFilter);
    $filtered = array_values(array_filter($filtered, fn($s) => mb_strtolower($s['section']) === $needleSection));
}
if ($statusFilter === 'Mismatched') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['mismatched']));
} elseif ($statusFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === $statusFilter));
}
if ($counselingFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['counseling'] === $counselingFilter));
}
if ($registrationFilter === 'registered') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] !== 'Not Registered'));
} elseif ($registrationFilter === 'notRegistered') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === 'Not Registered'));
}
if ($assessmentFilter === 'assessed') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === 'Completed'));
} elseif ($assessmentFilter === 'notAssessed') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] !== 'Completed')); // pending or not registered
}

// A downloadable copy of the list as filtered (every row, not just one page), for following up with
// students who haven't registered or haven't taken the assessment.
if ($asCsv) {
    usort($filtered, fn($a, $b) => [$a['strand'], $a['section'], $a['name']] <=> [$b['strand'], $b['section'], $b['name']]);
    // A cell that starts with = + - or @ would be run as a formula by Excel.
    $safe = fn($v) => is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false ? "'" . $v : (string) $v;
    $parts = array_filter([
        $registrationFilter === 'notRegistered' ? 'not-registered' : ($registrationFilter === 'registered' ? 'registered' : ''),
        $assessmentFilter === 'notAssessed' ? 'not-assessed' : ($assessmentFilter === 'assessed' ? 'assessed' : ''),
        $strandFilter, $sectionFilter,
    ]);
    $fileName = 'students-' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts)) ?: 'all') . '-' . date('Y-m-d') . '.csv';
    AuditLogger::log($user['id'], $user['role'], 'export_student_list', 'student', null, count($filtered) . " row(s): $fileName");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with Ñ and accents correctly
    fputcsv($out, ['Name', 'Strand', 'Section', 'Registered', 'Assessed', 'Assessment Date', 'Holland Code (Top 3, ranked)', 'Email'], escape: '\\');
    foreach ($filtered as $s) {
        fputcsv($out, [
            $safe($s['name']), $s['strand'], $s['section'],
            $s['status'] === 'Not Registered' ? 'No' : 'Yes',
            $s['status'] === 'Completed' ? 'Yes' : 'No',
            $s['assessmentDate'] ? date('Y-m-d', strtotime((string) $s['assessmentDate'])) : '',
            QuestionBank::hollandCode(array_values(array_filter(explode(', ', (string) ($s['riasec'] ?? ''))))),
            $safe((string) ($s['_email'] ?? '')),
        ], escape: '\\');
    }
    fclose($out);
    exit;
}

$total = count($filtered);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$pageRows = array_map(function ($s) { unset($s['_email']); return $s; }, array_slice($filtered, $offset, $pageSize));

jsonResponse([
    'students' => $pageRows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'startIndex' => $total > 0 ? $offset + 1 : 0,
    'sections' => array_values($sectionSummary),
    'completionTarget' => CompletionTarget::get($pdo),
    'summary' => [
        'totalStudents' => $totalStudents,
        'completedCount' => $completedCount,
        'pendingCount' => $pendingCount,
        'counselingCount' => $counselingCount,
        'mismatchedCount' => $mismatchedCount,
    ],
]);
