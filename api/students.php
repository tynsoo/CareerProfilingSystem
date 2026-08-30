<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
    jsonResponse(['error' => 'Forbidden'], 403);
}
$pdo = Database::get();

// Single-student lookup mode: student-profile.html loads a real record by
// schoolId instead of everything being smuggled through URL query params.
$schoolIdLookup = trim((string) ($_GET['schoolId'] ?? ''));
if ($schoolIdLookup !== '') {
    $stmt = $pdo->prepare(
        'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section,
                s.academic_year, s.registered_at, a.top_types, a.completed_at, a.score_r, a.score_i, a.score_a,
                a.score_s, a.score_e, a.score_c
         FROM students s
         LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
         WHERE LOWER(s.school_id) = LOWER(?)'
    );
    $stmt->execute([$schoolIdLookup]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['error' => 'Student not found'], 404);
    }
    $hasAssessment = $row['completed_at'] !== null;
    $counseledStmt = $pdo->prepare(
        "SELECT 1 FROM help_requests WHERE student_id = ? AND subject = 'Request for Academic Advising' LIMIT 1"
    );
    $counseledStmt->execute([(int) $row['user_id']]);
    $counseled = (bool) $counseledStmt->fetchColumn();

    // Full attempt history (not just is_latest), for the "Assessment
    // Attempts" section — cross-referenced with retake_grants so a retake
    // attempt is labeled as such, matching Phase 4's retake workflow.
    $attemptsStmt = $pdo->prepare(
        'SELECT id, attempt_number, top_types, completed_at, score_r, score_i, score_a, score_s, score_e, score_c
         FROM assessments WHERE student_id = ? ORDER BY attempt_number DESC'
    );
    $attemptsStmt->execute([(int) $row['user_id']]);
    $retakeAttemptNumbers = $pdo->prepare(
        'SELECT completed_attempt_number FROM retake_grants WHERE student_id = ? AND completed_attempt_number IS NOT NULL'
    );
    $retakeAttemptNumbers->execute([(int) $row['user_id']]);
    $retakeSet = array_flip(array_map('intval', $retakeAttemptNumbers->fetchAll(PDO::FETCH_COLUMN)));

    $attempts = array_map(fn($a) => [
        'attemptNumber' => (int) $a['attempt_number'],
        'completedAt' => $a['completed_at'],
        'riasec' => implode(', ', json_decode($a['top_types'], true)),
        'scores' => [
            'R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
            'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c'],
        ],
        'isRetake' => isset($retakeSet[(int) $a['attempt_number']]),
    ], $attemptsStmt->fetchAll());

    jsonResponse(['student' => [
        'userId' => (int) $row['user_id'],
        'schoolId' => $row['school_id'],
        'firstName' => Crypto::dec($row['first_name_enc']),
        'lastName' => Crypto::dec($row['last_name_enc']),
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
        'gradeLevel' => $row['grade_level'],
        'section' => $row['section'],
        'academicYear' => $row['academic_year'],
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

$page = max(1, (int) ($_GET['page'] ?? 1));
// ?all=1 (used by the Announcements student-picker) returns everyone
// matching the filters in one response instead of paginating.
$pageSize = isset($_GET['all']) ? PHP_INT_MAX : 8;
$search = trim((string) ($_GET['search'] ?? ''));
$strandFilter = (string) ($_GET['strand'] ?? '');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$counselingFilter = (string) ($_GET['counseling'] ?? '');

$rows = $pdo->query(
    'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section, s.registered_at,
            a.top_types, a.completed_at
     FROM students s
     LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     ORDER BY s.registered_at DESC'
)->fetchAll();

// A student "availed" counseling if they've submitted a Schedule Advising request
// from Help Center (results.html links there with a fixed subject line). This is
// distinct from monitoring escalation, which is a counselor-initiated review of a
// low-confidence recommendation, not the student asking for advising themselves.
$counseledIds = array_flip($pdo->query(
    "SELECT DISTINCT student_id FROM help_requests
     WHERE subject = 'Request for Academic Advising' AND student_id IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN));

$labels = ['R' => 'Realistic', 'I' => 'Investigate', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];

$students = array_map(function ($r) use ($counseledIds) {
    $hasAssessment = $r['completed_at'] !== null;
    $status = $hasAssessment ? 'Completed' : 'Pending';
    $topTypes = $hasAssessment ? json_decode($r['top_types'], true) : [];
    $counseling = $hasAssessment ? (isset($counseledIds[(int) $r['user_id']]) ? 'Availed' : 'Did Not Avail') : '';

    return [
        'userId' => (int) $r['user_id'],
        'schoolId' => $r['school_id'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'gradeLevel' => $r['grade_level'],
        'section' => $r['section'],
        'status' => $status,
        'riasec' => implode(', ', $topTypes),
        'counseling' => $counseling,
        'registeredAt' => $r['registered_at'],
    ];
}, $rows);

$totalStudents = count($students);
$completedCount = count(array_filter($students, fn($s) => $s['status'] === 'Completed'));
$pendingCount = $totalStudents - $completedCount;
$counselingCount = count(array_filter($students, fn($s) => $s['counseling'] === 'Availed'));

$filtered = $students;
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filtered = array_values(array_filter($filtered, fn($s) => str_contains(mb_strtolower($s['name']), $needle) || str_contains(mb_strtolower($s['schoolId']), $needle)));
}
if ($strandFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['strand'] === $strandFilter));
}
if ($sectionFilter !== '') {
    $needleSection = mb_strtolower($sectionFilter);
    $filtered = array_values(array_filter($filtered, fn($s) => mb_strtolower($s['section']) === $needleSection));
}
if ($statusFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === $statusFilter));
}
if ($counselingFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['counseling'] === $counselingFilter));
}

$total = count($filtered);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$pageRows = array_slice($filtered, $offset, $pageSize);

jsonResponse([
    'students' => $pageRows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'startIndex' => $total > 0 ? $offset + 1 : 0,
    'summary' => [
        'totalStudents' => $totalStudents,
        'completedCount' => $completedCount,
        'pendingCount' => $pendingCount,
        'counselingCount' => $counselingCount,
    ],
]);
