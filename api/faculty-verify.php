<?php

// Public endpoint — no login required. Faculty aren't system users (no
// role in users.role, no password); they identify themselves with a
// faculty code + a per-assignment access code, matching the confirmed
// "access-code only, no login account" decision. Mirrors
// api/verify-access-code.php's shape, but the code here is Crypto::enc()'d
// at rest (per the spec's explicit encryption requirement for the faculty
// code) rather than compared as plaintext, so every candidate row is
// decrypted and hash_equals()'d in PHP — never compared in SQL.

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = Database::get();
$body = readJsonBody();
$facultyCode = trim((string) ($body['facultyCode'] ?? ''));
$submitted = strtoupper(trim((string) ($body['code'] ?? '')));

if ($facultyCode === '' || $submitted === '') {
    jsonResponse(['success' => false, 'error' => 'Enter your Faculty ID and access code.'], 400);
}

$stmt = $pdo->prepare('SELECT id FROM faculty WHERE LOWER(faculty_code) = LOWER(?) AND is_active = TRUE');
$stmt->execute([$facultyCode]);
$facultyId = $stmt->fetchColumn();

if ($facultyId === false) {
    AuditLogger::log(null, 'faculty', 'faculty_verify_failed', 'faculty', null, "Unknown or inactive faculty code: $facultyCode");
    jsonResponse(['success' => false, 'error' => 'Invalid Faculty ID or access code.'], 403);
}
$facultyId = (int) $facultyId;

$stmt = $pdo->prepare(
    'SELECT esf.schedule_id, esf.access_code_enc, es.academic_year, es.exam_date, es.start_time, es.end_time, es.room, es.grade_level, es.strand, es.section
     FROM exam_schedule_faculty esf JOIN exam_schedules es ON es.id = esf.schedule_id
     WHERE esf.faculty_id = ?'
);
$stmt->execute([$facultyId]);
$assignments = $stmt->fetchAll();

$matched = null;
foreach ($assignments as $a) {
    // hash_equals prevents a timing side-channel from leaking how many
    // leading characters of the code matched.
    if (hash_equals(Crypto::dec($a['access_code_enc']), $submitted)) {
        $matched = $a;
        break;
    }
}

if ($matched === null) {
    AuditLogger::log(null, 'faculty', 'faculty_verify_failed', 'faculty', (string) $facultyId, 'Incorrect access code');
    jsonResponse(['success' => false, 'error' => 'Invalid Faculty ID or access code.'], 403);
}

$scheduleId = (int) $matched['schedule_id'];
$_SESSION['facultyUnlocked'][$scheduleId] = true;
AuditLogger::log(null, 'faculty', 'faculty_verify_success', 'exam_schedule', (string) $scheduleId, "Faculty code: $facultyCode");

// Read-only roster: registered students matching this schedule's scope
// (NULL grade_level/strand/section = wildcard for that dimension), plus
// whether each has completed their latest assessment. Encrypted names are
// decrypted and sorted here in PHP, not in SQL (per the schema's
// documented convention that _enc columns are never used in ORDER BY).
$conds = ['s.academic_year = ?'];
$params = [$matched['academic_year']];
if ($matched['grade_level'] !== null) { $conds[] = 's.grade_level = ?'; $params[] = $matched['grade_level']; }
if ($matched['strand'] !== null) { $conds[] = 's.strand = ?'; $params[] = $matched['strand']; }
if ($matched['section'] !== null) { $conds[] = 's.section = ?'; $params[] = $matched['section']; }
$stmt = $pdo->prepare(
    'SELECT s.school_id, s.first_name_enc, s.last_name_enc,
        EXISTS(SELECT 1 FROM assessments a WHERE a.student_id = s.user_id AND a.is_latest = TRUE) AS completed
     FROM students s WHERE ' . implode(' AND ', $conds)
);
$stmt->execute($params);
$students = array_map(fn($r) => [
    'schoolId' => $r['school_id'],
    'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
    'completed' => $r['completed'] === true || $r['completed'] === 't',
], $stmt->fetchAll());
usort($students, fn($a, $b) => strcmp($a['name'], $b['name']));

jsonResponse([
    'success' => true,
    'schedule' => [
        'examDate' => $matched['exam_date'],
        'startTime' => substr($matched['start_time'], 0, 5),
        'endTime' => substr($matched['end_time'], 0, 5),
        'room' => $matched['room'],
        'gradeLevel' => $matched['grade_level'],
        'strand' => $matched['strand'],
        'section' => $matched['section'],
    ],
    'students' => $students,
]);
