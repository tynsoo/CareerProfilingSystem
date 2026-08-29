<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::get();

$items = [];

if ($user['role'] === 'admin' || $user['role'] === 'counselor') {
    // Recently registered students
    $stmt = $pdo->query(
        "SELECT s.user_id, s.first_name_enc, s.last_name_enc, s.registered_at
         FROM students s
         WHERE s.registered_at > NOW() - INTERVAL '7 days'
         ORDER BY s.registered_at DESC LIMIT 5"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $items[] = [
            'type' => 'registration',
            'text' => "New student registered — $name just signed up.",
            'link' => 'student-profile.html?id=' . $row['user_id'],
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
            'link' => 'monitoring.html',
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
            'link' => 'help-requests.html',
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
            'link' => 'help-center.html',
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
            'link' => 'results.html',
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
            'link' => 'assessment.html',
            'ts' => $row['publish_at'],
        ];
    }
}

usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$items = array_slice($items, 0, 8);

jsonResponse(['items' => $items, 'count' => count($items)]);
