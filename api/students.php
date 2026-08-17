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

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 8;
$search = trim((string) ($_GET['search'] ?? ''));
$strandFilter = (string) ($_GET['strand'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$counselingFilter = (string) ($_GET['counseling'] ?? '');

$rows = $pdo->query(
    'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.registered_at,
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
