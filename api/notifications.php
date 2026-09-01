<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::get();

$items = [];

if ($user['role'] === 'admin' || $user['role'] === 'counselor') {
    // Recently registered students
    $stmt = $pdo->query(
        "SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.registered_at
         FROM students s
         WHERE s.registered_at > NOW() - INTERVAL '7 days'
         ORDER BY s.registered_at DESC LIMIT 5"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $items[] = [
            'type' => 'registration',
            'text' => "New student registered — $name just signed up.",
            // student-profile.html (and api/students.php's single-student
            // lookup) reads ?schoolId=, never ?id= — this previously
            // linked with the wrong param name, so clicking it always
            // landed on "Student not found."
            'link' => 'student-profile?schoolId=' . urlencode($row['school_id']),
            'ts' => $row['registered_at'],
        ];
    }

    // Pending monitoring flags awaiting review
    $stmt = $pdo->query(
        "SELECT mf.id, mf.reason, mf.created_at, s.first_name_enc, s.last_name_enc
         FROM monitoring_flags mf
         JOIN students s ON s.user_id = mf.student_id
         WHERE mf.status = 'pending'
         ORDER BY mf.created_at DESC LIMIT 5"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $reasonLabel = $row['reason'] === 'low_confidence' ? 'low RIASEC confidence' : $row['reason'];
        $items[] = [
            'type' => 'flag',
            'text' => "Assessment flagged — $name ($reasonLabel).",
            'link' => 'monitoring',
            'ts' => $row['created_at'],
        ];
    }

    // Open counseling requests awaiting a response
    $stmt = $pdo->query(
        "SELECT id, name, subject, sent_at FROM help_requests
         WHERE status = 'open' ORDER BY sent_at DESC LIMIT 5"
    );
    foreach ($stmt->fetchAll() as $row) {
        $who = $row['name'] ?: 'A student';
        $items[] = [
            'type' => 'help_request',
            'text' => "Counseling request — $who: " . ($row['subject'] ?: 'No subject'),
            'link' => 'help-requests',
            'ts' => $row['sent_at'],
        ];
    }
} else {
    // Student: recent resolutions of things they submitted
    $stmt = $pdo->prepare(
        "SELECT id, subject, resolved_at FROM help_requests
         WHERE student_id = ? AND status = 'resolved' AND resolved_at > NOW() - INTERVAL '14 days'
         ORDER BY resolved_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'type' => 'help_resolved',
            'text' => 'Your counseling request "' . ($row['subject'] ?: 'General inquiry') . '" has been resolved.',
            'link' => 'help-center',
            'ts' => $row['resolved_at'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT id, status, resolved_at FROM monitoring_flags
         WHERE student_id = ? AND status IN ('approved', 'escalated') AND resolved_at > NOW() - INTERVAL '14 days'
         ORDER BY resolved_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'type' => 'flag_resolved',
            'text' => 'A counselor reviewed your assessment.',
            'link' => 'results',
            'ts' => $row['resolved_at'],
        ];
    }

    // Recently published announcements sent to everyone or to this student.
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.publish_at FROM announcements a
         WHERE a.publish_at <= NOW() AND a.publish_at > NOW() - INTERVAL '14 days'
           AND (a.target_type = 'all' OR EXISTS (
                 SELECT 1 FROM announcement_recipients ar
                 WHERE ar.announcement_id = a.id AND ar.student_id = ?
               ))
         ORDER BY a.publish_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'type' => 'announcement',
            'text' => 'Announcement — ' . $row['title'],
            'link' => 'assessment',
            'ts' => $row['publish_at'],
        ];
    }

    // Newly created exam schedules matching this student's own
    // strand/section/grade level/AY (same matching rule as
    // api/exam-schedules.php?mine=1). NULL on any of those three columns
    // means the schedule applies to everyone in that dimension.
    $stmt = $pdo->prepare(
        "SELECT es.id, es.exam_date, es.room, es.created_at FROM exam_schedules es
         JOIN students s ON s.user_id = ?
         WHERE es.created_at > NOW() - INTERVAL '14 days'
           AND es.academic_year = s.academic_year
           AND (es.grade_level IS NULL OR es.grade_level = s.grade_level)
           AND (es.strand IS NULL OR es.strand = s.strand)
           AND (es.section IS NULL OR es.section = s.section)
         ORDER BY es.created_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'type' => 'schedule_published',
            'text' => 'Exam scheduled — ' . $row['exam_date'] . ' in ' . $row['room'] . '.',
            'link' => 'assessment',
            'ts' => $row['created_at'],
        ];
    }

    // Staff-granted retakes (see retake_grants / api/retake-grants.php).
    $stmt = $pdo->prepare(
        "SELECT id, granted_at FROM retake_grants
         WHERE student_id = ? AND status = 'granted' AND completed_attempt_number IS NULL
           AND granted_at > NOW() - INTERVAL '14 days'
         ORDER BY granted_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'type' => 'retake_granted',
            'text' => 'You have been granted a retake of the RIASEC assessment.',
            'link' => 'assessment',
            'ts' => $row['granted_at'],
        ];
    }
}

usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$items = array_slice($items, 0, 8);

jsonResponse(['items' => $items, 'count' => count($items)]);
