<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';

// The sections already on this academic year's roster: how many students each has, how many
// of them have registered, and when the list was last uploaded. Shown under Class Roster in
// Account Management so staff can see what has been uploaded before uploading again.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('rac', 'full');
$pdo = Database::get();
$ay = AcademicYear::current();

$stmt = $pdo->prepare(
    'SELECT r.strand, r.section, COUNT(*) AS students, COUNT(s.user_id) AS registered, MAX(r.uploaded_at) AS last_uploaded
     FROM assessment_roster r
     LEFT JOIN students s ON s.school_id = r.school_id
     WHERE r.academic_year = ?
     GROUP BY r.strand, r.section
     ORDER BY r.strand, r.section'
);
$stmt->execute([$ay]);

jsonResponse([
    'academicYear' => $ay,
    'sections' => array_map(fn($r) => [
        'strand' => $r['strand'],
        'section' => $r['section'],
        'students' => (int) $r['students'],
        'registered' => (int) $r['registered'],
        'lastUploaded' => $r['last_uploaded'],
    ], $stmt->fetchAll()),
]);
