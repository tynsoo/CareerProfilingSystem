<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';

$user = Auth::requireLogin();
$pdo = Database::get();

/** "Lopez, Carla R. (Guidance Counselor)" for a staff account; null when there is none (no role). */
function staffLabel(?string $role, ?string $fullName, ?string $username, ?string $position): ?string
{
    if ($role === null) {
        return null;
    }
    $name = trim((string) $fullName) !== '' ? $fullName : $username;
    return $name . ' (' . ($role === 'admin' ? 'Administrator' : StaffPosition::label($position)) . ')';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();

    // Staff with Full on Update counseling status (permission table): resolve an existing request.
    if (($body['type'] ?? '') === 'resolve') {
        $user = Rbac::requireAccess('counselingStatus', 'full');
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Missing id.'], 400);
        }
        // Only a request that exists and is from a student in this person's sections (StaffScope). Anything else
        // answers "not found", so request ids from other sections cannot be probed.
        $find = $pdo->prepare('SELECT s.strand, s.section FROM help_requests hr LEFT JOIN students s ON s.user_id = hr.student_id WHERE hr.id = ?');
        $find->execute([$id]);
        $request = $find->fetch();
        if (!$request || !StaffScope::allows(StaffScope::forUser($pdo, $user), $request['strand'], $request['section'])) {
            jsonResponse(['success' => false, 'error' => 'Request not found.'], 404);
        }
        // Its place in the queue before it leaves it, for the audit log (the same count as the POST branch below).
        $queueStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM help_requests
             WHERE status = 'open' AND sent_at <= (SELECT sent_at FROM help_requests WHERE id = ?)"
        );
        $queueStmt->execute([$id]);
        $queueNumber = (int) $queueStmt->fetchColumn();
        // Only an open request: a second click (or a list that was out of date) must not replace who resolved it and when.
        $update = $pdo->prepare("UPDATE help_requests SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'open'");
        $update->execute([$user['id'], $id]);
        if ($update->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'This request was already resolved.'], 409);
        }
        AuditLogger::log($user['id'], $user['role'], 'resolve_help_request', 'help_request', (string) $id, "Request #$id, #$queueNumber in queue");
        jsonResponse(['success' => true]);
    }

    // Student: submit a new request.
    if ($user['role'] !== 'student') {
        jsonResponse(['success' => false, 'error' => 'Only students can submit counseling requests.'], 403);
    }
    $subject = trim((string) ($body['subject'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    if ($subject === '' || $message === '') {
        jsonResponse(['success' => false, 'error' => 'Subject and message are required.'], 400);
    }
    // The student agrees, for this request, that the Guidance Office may read it with their results (panel revision S14).
    if (($body['consent'] ?? false) !== true) {
        jsonResponse(['success' => false, 'error' => 'Please agree to share this request with the Guidance Office first.'], 400);
    }

    $name = $user['firstName'] . ' ' . $user['lastName'];
    $insert = $pdo->prepare(
        'INSERT INTO help_requests (student_id, school_id_snapshot, name_enc, subject_enc, message_enc, status)
         VALUES (?, ?, ?, ?, ?, \'open\') RETURNING id'
    );
    $insert->execute([$user['id'], $user['schoolId'], Crypto::enc($name), Crypto::enc($subject), Crypto::enc($message)]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], 'student', 'submit_help_request', 'help_request', (string) $id, $subject . ' (consent to share given)');

    // Simple FIFO queue number: how many open requests (including this one)
    // were submitted at or before this one, oldest first. Not a booked time
    // slot — just where the student stands in line.
    $queueStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM help_requests
         WHERE status = 'open' AND sent_at <= (SELECT sent_at FROM help_requests WHERE id = ?)"
    );
    $queueStmt->execute([$id]);
    $queueNumber = (int) $queueStmt->fetchColumn();

    jsonResponse(['success' => true, 'id' => $id, 'queueNumber' => $queueNumber]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // View counseling history (permission table): View Only lists who asked, when and about what (the subject is a fixed
    // choice), but not the message the student wrote; Full shows the message too.
    $user = Rbac::requireAccess('counselingHistory', 'limited');
    $seesMessages = Rbac::accessLevel('counselingHistory', Rbac::roleKey($user)) === 'full';

    $status = $_GET['status'] ?? 'All';
    // Who resolved it comes from users (staff full_name is plain text, "Last, First M.").
    $sql = 'SELECT hr.id, hr.student_id, hr.name_enc, hr.subject_enc, hr.message_enc, hr.sent_at, hr.status, hr.resolved_at, s.strand, s.section,
                   ru.role AS resolver_role, ru.full_name AS resolver_name, ru.username AS resolver_username, ru.staff_position AS resolver_position
            FROM help_requests hr LEFT JOIN students s ON s.user_id = hr.student_id LEFT JOIN users ru ON ru.id = hr.resolved_by';
    $params = [];
    if (in_array($status, ['open', 'resolved'], true)) {
        $sql .= ' WHERE hr.status = ?';
        $params[] = $status;
    }
    // Open requests first in queue order (#1 on top), then resolved ones, the most recently resolved first.
    $sql .= " ORDER BY CASE WHEN hr.status = 'open' THEN 0 ELSE 1 END,
                       CASE WHEN hr.status = 'open' THEN hr.sent_at END ASC,
                       hr.resolved_at DESC NULLS LAST, hr.sent_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Staff assigned to sections see only those sections' students' requests (StaffScope; no sections = everyone).
    $scope = StaffScope::forUser($pdo, $user);
    $fetchedRows = array_values(array_filter($stmt->fetchAll(), fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));

    // Queue position among 'open' requests only, oldest first — the same
    // FIFO count a student sees for their own request (see the POST branch).
    // The position is in the whole Guidance Office queue, so it stays the number the student was given.
    $openByAge = $pdo->query(
        "SELECT hr.id, s.strand, s.section FROM help_requests hr LEFT JOIN students s ON s.user_id = hr.student_id
         WHERE hr.status = 'open' ORDER BY hr.sent_at ASC"
    )->fetchAll();
    $queuePositions = array_flip(array_map(fn($r) => (int) $r['id'], $openByAge));

    // The latest counseling invite of each listed student (from this page or Student Information). An invite in the last
    // 24 hours blocks another one (counseling-invite.php: RateLimiter 1 per 1440 minutes), so the page shows when it frees up.
    // Only for staff who can invite (the same rule as counseling-invite.php): View Only staff do not need who invited whom.
    $canInvite = Rbac::accessLevel('counselingStatus', Rbac::roleKey($user)) === 'full' && !Rbac::isFacilitator($user);
    $lastInvites = [];
    $studentIds = array_values(array_unique(array_filter(array_map(fn($r) => $r['student_id'] !== null ? (int) $r['student_id'] : null, $fetchedRows))));
    if ($canInvite && $studentIds) {
        $inviteStmt = $pdo->prepare(
            "SELECT DISTINCT ON (ci.student_id) ci.student_id, ci.created_at, ci.created_at > NOW() - INTERVAL '1440 minutes' AS recent,
                    ci.created_at + INTERVAL '1440 minutes' AS again_at,
                    u.role, u.full_name, u.username, u.staff_position, ci.invited_by = ci.student_id AS self_requested
             FROM counseling_invites ci LEFT JOIN users u ON u.id = ci.invited_by
             WHERE ci.student_id IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ')
             ORDER BY ci.student_id, ci.id DESC'
        );
        $inviteStmt->execute($studentIds);
        foreach ($inviteStmt->fetchAll() as $inv) {
            $lastInvites[(int) $inv['student_id']] = [
                'at' => $inv['created_at'],
                // The student's own Avail Counseling click is kept as an invitation too (counseling-booking-link.php).
                'by' => $inv['self_requested'] ? 'the student (Avail Counseling)' : staffLabel($inv['role'], $inv['full_name'], $inv['username'], $inv['staff_position']),
                'recent' => (bool) $inv['recent'],
                'againAt' => $inv['again_at'],
            ];
        }
    }

    $rows = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'studentId' => $r['student_id'] !== null ? (int) $r['student_id'] : null,
        'strand' => $r['strand'],
        'section' => $r['section'],
        'name' => Crypto::dec($r['name_enc']),
        'subject' => Crypto::dec($r['subject_enc']),
        'message' => $seesMessages ? Crypto::dec($r['message_enc']) : null,
        'sentAt' => $r['sent_at'],
        'status' => $r['status'],
        'resolvedAt' => $r['resolved_at'],
        'resolvedBy' => staffLabel($r['resolver_role'], $r['resolver_name'], $r['resolver_username'], $r['resolver_position']),
        'queueNumber' => $r['status'] === 'open' ? $queuePositions[(int) $r['id']] + 1 : null,
        'lastInvite' => $r['student_id'] !== null ? ($lastInvites[(int) $r['student_id']] ?? null) : null,
    ], $fetchedRows);

    // Open requests this person can see (not the other sections' count), whatever the status filter.
    $openCount = count(array_filter($openByAge, fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));

    jsonResponse(['requests' => $rows, 'openCount' => $openCount, 'seesMessages' => $seesMessages]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
