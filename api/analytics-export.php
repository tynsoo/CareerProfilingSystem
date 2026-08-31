<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

$validStrands = ['STEM', 'ABM', 'ICT', 'HUMSS'];
$academicYear = trim((string) ($_GET['academicYear'] ?? ''));
$strand = trim((string) ($_GET['strand'] ?? ''));
if (!in_array($strand, $validStrands, true)) {
    $strand = '';
}
$section = trim((string) ($_GET['section'] ?? ''));

if ($academicYear === '') {
    jsonResponse(['error' => 'academicYear is required.'], 400);
}

$conds = ['s.academic_year = ?'];
$params = [$academicYear];
if ($strand !== '') {
    $conds[] = 's.strand = ?';
    $params[] = $strand;
}
if ($section !== '') {
    $conds[] = 's.section = ?';
    $params[] = $section;
}

$stmt = $pdo->prepare(
    'SELECT s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section,
            a.top_types, a.completed_at, a.score_r, a.score_i, a.score_a, a.score_s, a.score_e, a.score_c
     FROM students s
     LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     WHERE ' . implode(' AND ', $conds) . '
     ORDER BY s.strand, s.section, s.last_name_enc'
);
$stmt->execute($params);

$safeAy = preg_replace('/[^A-Za-z0-9_-]/', '_', $academicYear);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="student-roster-' . $safeAy . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, [
    'Student Number', 'Last Name', 'First Name', 'Strand', 'Grade Level', 'Section',
    'Assessment Status', 'Top RIASEC Types', 'R', 'I', 'A', 'S', 'E', 'C',
], escape: '\\');

foreach ($stmt as $row) {
    $completed = $row['completed_at'] !== null;
    fputcsv($out, [
        $row['school_id'],
        Crypto::dec($row['last_name_enc']),
        Crypto::dec($row['first_name_enc']),
        $row['strand'],
        $row['grade_level'],
        $row['section'],
        $completed ? 'Completed' : 'Pending',
        $completed ? implode(', ', json_decode($row['top_types'], true)) : '',
        $completed ? $row['score_r'] : '',
        $completed ? $row['score_i'] : '',
        $completed ? $row['score_a'] : '',
        $completed ? $row['score_s'] : '',
        $completed ? $row['score_e'] : '',
        $completed ? $row['score_c'] : '',
    ], escape: '\\');
}
fclose($out);

AuditLogger::log(
    $user['id'],
    $user['role'],
    'export_student_roster',
    'analytics',
    $academicYear,
    "Exported roster for AY $academicYear" . ($strand ? ", strand=$strand" : '') . ($section ? ", section=$section" : '')
);
exit;
