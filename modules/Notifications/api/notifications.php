<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

$user = Auth::requireLogin();
$pdo = Database::get();

// ?full=1 (used by the "view all notifications" pages) widens the lookback
// window and per-category/overall caps that the header dropdown otherwise
// keeps small.
$full = isset($_GET['full']);
$days = $full ? 90 : 14;
$each = $full ? 50 : 5;
$total = $full ? 50 : 8;

$items = [];
$isStaff = $user['role'] === 'admin' || $user['role'] === 'counselor';

if ($isStaff) {
    // Recently registered students
    $regDays = $full ? 90 : 7;
    $stmt = $pdo->query(
        "SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.registered_at
         FROM students s
         WHERE s.registered_at > NOW() - INTERVAL '$regDays days'
         ORDER BY s.registered_at DESC LIMIT $each"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $items[] = [
            'type' => 'registration',
            'title' => 'Student registered',
            'text' => "$name just signed up.",
            // student-profile.html (and api/students.php's single-student
            // lookup) read ?id=, the student's internal user id.
            'link' => 'student-profile?id=' . (int) $row['user_id'],
            'ts' => $row['registered_at'],
        ];
    }

    // Staff sign-ups waiting for approval (admins only: only they can approve).
    if ($user['role'] === 'admin') {
        $stmt = $pdo->query(
            "SELECT id, username, full_name, staff_position, email_verified_at, created_at FROM users
             WHERE role = 'counselor' AND approval_status = 'pending'
             ORDER BY created_at DESC LIMIT $each"
        );
        foreach ($stmt->fetchAll() as $row) {
            $who = ($row['full_name'] ?: $row['username']) . ' (' . ($row['staff_position'] === 'facilitator' ? 'Guidance Facilitator' : 'Guidance Counselor') . ')';
            $items[] = [
                'type' => 'registration',
                'title' => 'Staff sign-up awaiting approval',
                'text' => $who . ($row['email_verified_at'] === null ? ' (email not verified yet).' : ' verified their email and is waiting for approval.'),
                'link' => 'account-management',
                'ts' => $row['created_at'],
            ];
        }
    }

    // Monitoring flags: only for staff whose role can open Monitoring (the flag names who was flagged).
    // Guidance staff handle flags as a team, so these are not limited to assigned sections.
    if (Rbac::accessLevel('monitoring', Rbac::roleKey($user)) !== 'none') {
        // Waiting for review
        $stmt = $pdo->query(
            "SELECT mf.id, mf.reason, mf.created_at, s.first_name_enc, s.last_name_enc
             FROM monitoring_flags mf
             JOIN students s ON s.user_id = mf.student_id
             WHERE mf.status = 'pending'
             ORDER BY mf.created_at DESC LIMIT $each"
        );
        foreach ($stmt->fetchAll() as $row) {
            $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
            $reasonLabel = $row['reason'] === 'low_confidence' ? 'low RIASEC confidence' : $row['reason'];
            $items[] = [
                'type' => 'flag',
                'title' => 'Assessment flagged',
                'text' => "$name ($reasonLabel).",
                'link' => 'monitoring',
                'ts' => $row['created_at'],
            ];
        }

        // Escalated to counseling and not decided yet (escalating keeps resolved_at empty, so the
        // time comes from the audit entry written by api/monitoring-action.php).
        $stmt = $pdo->query(
            "SELECT mf.id, mf.created_at, s.first_name_enc, s.last_name_enc,
                    (SELECT MAX(al.created_at) FROM audit_log al
                     WHERE al.action = 'monitoring_escalate' AND al.target_type = 'monitoring_flag'
                       AND al.target_id = mf.id::text) AS escalated_at
             FROM monitoring_flags mf
             JOIN students s ON s.user_id = mf.student_id
             WHERE mf.status = 'escalated'
             ORDER BY mf.created_at DESC LIMIT $each"
        );
        foreach ($stmt->fetchAll() as $row) {
            $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
            $items[] = [
                'type' => 'flag_escalated',
                'title' => 'Flag escalated to counseling',
                'text' => "$name needs a counselor's follow-up.",
                'link' => 'monitoring-details?tab=counseling',
                'ts' => $row['escalated_at'] ?? $row['created_at'],
            ];
        }
    }

    // Retake requests waiting for an answer: only for staff who handle them (the Counseling Notes permission,
    // as in api/retake-requests.php) and only for students in their sections (modules/UserManagement/lib/StaffScope.php).
    try {
        $retakeRows = [];
        if (Rbac::accessLevel('counselingNotes', Rbac::roleKey($user)) !== 'none') {
            $stmt = $pdo->query(
                "SELECT rr.id, rr.requested_at, s.first_name_enc, s.last_name_enc, s.strand, s.section FROM retake_requests rr
                 JOIN students s ON s.user_id = rr.student_id
                 WHERE rr.status = 'pending' ORDER BY rr.requested_at DESC"
            );
            $retakeScope = StaffScope::forUser($pdo, $user);
            $retakeRows = array_slice(array_values(array_filter($stmt->fetchAll(),
                fn($r) => StaffScope::allows($retakeScope, $r['strand'], $r['section']))), 0, $each);
        }
        foreach ($retakeRows as $row) {
            $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
            $items[] = [
                'type' => 'retake_request',
                'title' => 'Retake requested',
                'text' => "$name asked to retake the assessment.",
                'link' => 'counseling-requests?tab=retakes',
                'ts' => $row['requested_at'],
            ];
        }
    } catch (Throwable $e) {
        // table not created yet (migration not run)
    }

    // Open counseling requests awaiting a response
    $stmt = $pdo->query(
        "SELECT id, name_enc, subject_enc, sent_at FROM help_requests
         WHERE status = 'open' ORDER BY sent_at DESC LIMIT $each"
    );
    foreach ($stmt->fetchAll() as $row) {
        $who = Crypto::dec($row['name_enc']) ?: 'A student';
        $items[] = [
            'type' => 'help_request',
            'title' => 'New counseling request',
            'text' => "$who: " . (Crypto::dec($row['subject_enc']) ?: 'No subject'),
            'link' => 'counseling-requests',
            'ts' => $row['sent_at'],
        ];
    }
} else {
    // A brand-new account starts with an empty inbox: only things that
    // happened after the student registered are ever shown.
    $sinceStmt = $pdo->prepare('SELECT registered_at FROM students WHERE user_id = ?');
    $sinceStmt->execute([$user['id']]);
    $since = (string) ($sinceStmt->fetchColumn() ?: '1970-01-01');

    // Student: recent resolutions of things they submitted
    $stmt = $pdo->prepare(
        "SELECT id, subject_enc, resolved_at FROM help_requests
         WHERE student_id = ? AND status = 'resolved' AND resolved_at > NOW() - INTERVAL '$days days'
           AND resolved_at >= ?
         ORDER BY resolved_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'help:' . $row['id'],
            'type' => 'help_resolved',
            'title' => 'Counseling request resolved',
            'text' => 'Your counseling request "' . (Crypto::dec($row['subject_enc']) ?: 'General inquiry') . '" has been resolved.',
            'link' => 'student-help-center',
            'ts' => $row['resolved_at'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT id, status, resolved_at FROM monitoring_flags
         WHERE student_id = ? AND status IN ('approved', 'escalated') AND resolved_at > NOW() - INTERVAL '$days days'
           AND resolved_at >= ?
         ORDER BY resolved_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'flag:' . $row['id'],
            'type' => 'flag_resolved',
            'title' => 'Assessment reviewed',
            'text' => 'A counselor reviewed your assessment.',
            'link' => 'results',
            'ts' => $row['resolved_at'],
        ];
    }

    // Announcements sent to everyone or to this student, published after
    // they registered.
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.body_enc, a.publish_at FROM announcements a
         WHERE a.status = 'sent' AND a.publish_at <= NOW() AND a.publish_at > NOW() - INTERVAL '$days days'
           AND a.publish_at >= ?
           AND (a.target_type = 'all' OR EXISTS (
                 SELECT 1 FROM announcement_recipients ar
                 WHERE ar.announcement_id = a.id AND ar.student_id = ?
               ))
         ORDER BY a.publish_at DESC LIMIT $each"
    );
    $stmt->execute([$since, $user['id']]);
    $markRead = $full
        ? $pdo->prepare('INSERT INTO announcement_reads (announcement_id, student_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
        : null;
    foreach ($stmt->fetchAll() as $row) {
        // Opening the full Notifications page counts as seeing the announcement
        // (the staff page's "% read"); the small bell dropdown does not.
        if ($markRead) {
            $markRead->execute([(int) $row['id'], (int) $user['id']]);
        }
        $body = trim((string) Crypto::dec($row['body_enc']));
        $items[] = [
            'key' => 'ann:' . $row['id'],
            'type' => 'announcement',
            'title' => $row['title'],
            'text' => mb_strimwidth($body, 0, 140, '…'),
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
         WHERE es.created_at > NOW() - INTERVAL '$days days'
           AND es.created_at >= ?
           AND es.academic_year = s.academic_year
           AND (es.grade_level IS NULL OR es.grade_level = s.grade_level)
           AND (es.strand IS NULL OR es.strand = s.strand)
           AND (es.section IS NULL OR es.section = s.section)
         ORDER BY es.created_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'exam:' . $row['id'],
            'type' => 'schedule_published',
            'title' => 'Assessment scheduled',
            'text' => $row['exam_date'] . ' in ' . $row['room'] . '.',
            'link' => 'assessment',
            'ts' => $row['created_at'],
        ];
    }

    // A counselor invited this student to book a counseling session: the link goes straight to the CGC booking page.
    try {
        $stmt = $pdo->prepare(
            "SELECT id, booking_url, created_at FROM counseling_invites
             WHERE student_id = ? AND created_at > NOW() - INTERVAL '$days days'
             ORDER BY created_at DESC LIMIT $each"
        );
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'key' => 'invite:' . $row['id'],
                'type' => 'counseling_invite',
                'title' => 'Counseling invitation',
                'text' => 'The Guidance Office invited you to book a counseling session.',
                'link' => $row['booking_url'],
                'ts' => $row['created_at'],
            ];
        }
    } catch (Throwable $e) {
        // table not created yet (migration not run): no invitations shown rather than the feed failing
    }

    // The Guidance Office answered a retake request.
    try {
        $stmt = $pdo->prepare(
            "SELECT id, status, decided_at FROM retake_requests
             WHERE student_id = ? AND status IN ('approved', 'declined', 'completed') AND decided_at IS NOT NULL
               AND decided_at > NOW() - INTERVAL '$days days'
             ORDER BY decided_at DESC LIMIT $each"
        );
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $declined = $row['status'] === 'declined';
            $items[] = [
                'key' => 'retake:' . $row['id'],
                'type' => 'retake_decision',
                'title' => $declined ? 'Retake request answered' : 'Retake approved',
                'text' => $declined ? 'The Guidance Office could not approve your retake request.' : 'You can retake the assessment. Open Assessments and choose Retake Assessment.',
                'link' => 'assessment',
                'ts' => $row['decided_at'],
            ];
        }
    } catch (Throwable $e) {
        // table not created yet (migration not run)
    }

    // Anything the student deleted stays gone. If the table isn't there yet
    // (migration not run), nothing is hidden rather than the feed failing.
    try {
        $dismissedStmt = $pdo->prepare('SELECT item_key FROM notification_dismissals WHERE user_id = ?');
        $dismissedStmt->execute([$user['id']]);
        $dismissed = array_flip($dismissedStmt->fetchAll(PDO::FETCH_COLUMN));
        $items = array_values(array_filter($items, fn($i) => !isset($dismissed[$i['key']])));
    } catch (Throwable $e) {
    }
}

usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$items = array_slice($items, 0, $total);

// Read state. Notifications here are computed live rather than stored, so
// each person's read state (staff and students alike) is a single per-user
// "read up to" timestamp (users.notifications_read_at): anything at or before
// it counts as read, anything newer as unread. If the column isn't there yet
// (migration not run) everything simply counts as unread instead of the
// whole feed failing to load.
$readAt = null;
try {
    $stmt = $pdo->prepare('SELECT notifications_read_at FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $value = $stmt->fetchColumn();
    $readAt = $value ? strtotime($value) : null;
} catch (Throwable $e) {
    $readAt = null;
}
$unreadCount = 0;
foreach ($items as &$item) {
    $item['unread'] = $readAt === null || strtotime($item['ts']) > $readAt;
    if ($item['unread']) {
        $unreadCount++;
    }
}
unset($item);

jsonResponse(['items' => $items, 'count' => count($items), 'unreadCount' => $unreadCount, 'tracksRead' => true]);
