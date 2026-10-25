<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/RetakeRequest.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

// Retake by request.
//   Student:  GET -> { state, canRequest };  POST { type: 'request', reason }
//   Staff with the Counseling Notes permission (View Only to look, Full to decide):
//             GET ?status=pending|approved|declined|completed|all -> the requests;  POST { type: 'decide', id, decision: 'approve'|'decline', note? }
$user = Auth::requireLogin();
$pdo = Database::get();

if ($user['role'] === 'student') {
    $studentId = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $hasResult = $pdo->prepare('SELECT 1 FROM assessments WHERE student_id = ? AND is_latest = TRUE AND completed_at IS NOT NULL');
        $hasResult->execute([$studentId]);
        $state = RetakeRequest::stateFor($pdo, $studentId);
        jsonResponse(['state' => $state, 'canRequest' => (bool) $hasResult->fetchColumn() && !in_array($state['status'], ['pending', 'approved'], true)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJsonBody();
        if (($body['type'] ?? '') !== 'request') {
            jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
        }
        $latest = $pdo->prepare('SELECT attempt_number FROM assessments WHERE student_id = ? AND is_latest = TRUE AND completed_at IS NOT NULL');
        $latest->execute([$studentId]);
        $attempt = $latest->fetchColumn();
        if ($attempt === false) {
            jsonResponse(['success' => false, 'error' => 'Finish the assessment first. A retake can be requested once you have a result.'], 409);
        }
        if (RetakeRequest::openFor($pdo, $studentId) !== null) {
            jsonResponse(['success' => false, 'error' => 'You already have a retake request with the Guidance Office.'], 409);
        }
        $reason = RetakeRequest::cleanReason((string) ($body['reason'] ?? ''));
        if ($reason['error'] !== null) {
            jsonResponse(['success' => false, 'error' => $reason['error']], 400);
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO retake_requests (student_id, attempt_number, reason_enc) VALUES (?, ?, ?) RETURNING id');
            $stmt->execute([$studentId, (int) $attempt, Crypto::enc($reason['text'])]);
        } catch (PDOException $e) {
            // two requests at the same moment: the unique index lets only one through
            jsonResponse(['success' => false, 'error' => 'You already have a retake request with the Guidance Office.'], 409);
        }
        AuditLogger::log($studentId, 'student', 'request_retake', 'assessment', (string) $stmt->fetchColumn(), 'Retake requested');
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Method not allowed'], 405);
}

// ------------------------------------------------------------------ staff
if ($user['role'] !== 'admin' && $user['role'] !== 'counselor') {
    jsonResponse(['error' => 'Forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Rbac::requireAccess('counselingNotes', 'limited');
    $status = (string) ($_GET['status'] ?? 'pending');
    $where = '';
    $params = [];
    if (in_array($status, ['pending', 'approved', 'declined', 'completed'], true)) {
        $where = 'WHERE rr.status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare(
        "SELECT rr.id, rr.student_id, rr.attempt_number, rr.reason_enc, rr.status, rr.requested_at, rr.decided_at, rr.decision_note_enc,
                s.first_name_enc, s.last_name_enc, s.strand, s.section
         FROM retake_requests rr JOIN students s ON s.user_id = rr.student_id
         $where ORDER BY rr.requested_at DESC"
    );
    $stmt->execute($params);
    $scope = StaffScope::forUser($pdo, $user);
    $rows = array_values(array_filter($stmt->fetchAll(), fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));
    $requests = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'studentId' => (int) $r['student_id'],
        'name' => Crypto::dec($r['last_name_enc']) . ', ' . Crypto::dec($r['first_name_enc']),
        'strand' => $r['strand'],
        'section' => $r['section'],
        'attemptNumber' => (int) $r['attempt_number'],
        'reason' => (string) Crypto::dec($r['reason_enc']),
        'status' => $r['status'],
        'requestedAt' => $r['requested_at'],
        'decidedAt' => $r['decided_at'],
        'note' => $r['decision_note_enc'] !== null ? (string) Crypto::dec($r['decision_note_enc']) : null,
    ], $rows);
    $pendingCount = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
    if ($status !== 'pending') {
        // Waiting requests in this person's sections only, like the list itself.
        $waiting = $pdo->query(
            "SELECT s.strand, s.section FROM retake_requests rr JOIN students s ON s.user_id = rr.student_id WHERE rr.status = 'pending'"
        )->fetchAll();
        $pendingCount = count(array_filter($waiting, fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));
    }
    jsonResponse(['requests' => $requests, 'pendingCount' => $pendingCount]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('counselingNotes', 'full');
    $body = readJsonBody();
    if (($body['type'] ?? '') !== 'decide') {
        jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
    }
    $id = (int) ($body['id'] ?? 0);
    $decision = (string) ($body['decision'] ?? '');
    if (!in_array($decision, ['approve', 'decline'], true)) {
        jsonResponse(['success' => false, 'error' => 'Choose approve or decline.'], 400);
    }
    $note = trim(preg_replace('/\s+/', ' ', (string) ($body['note'] ?? '')));
    if (mb_strlen($note) > RetakeRequest::NOTE_MAX) {
        jsonResponse(['success' => false, 'error' => 'Please keep the note to ' . RetakeRequest::NOTE_MAX . ' characters or fewer.'], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT rr.id, rr.student_id, rr.status, s.strand, s.section, s.first_name_enc, u.email, u.email_verified_at
         FROM retake_requests rr JOIN students s ON s.user_id = rr.student_id JOIN users u ON u.id = rr.student_id WHERE rr.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || !StaffScope::allows(StaffScope::forUser($pdo, $user), $row['strand'], $row['section'])) {
        jsonResponse(['success' => false, 'error' => 'Request not found.'], 404);
    }
    if ($row['status'] !== 'pending') {
        jsonResponse(['success' => false, 'error' => 'This request was already answered.'], 409);
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'declined';
    $update = $pdo->prepare("UPDATE retake_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note_enc = ? WHERE id = ? AND status = 'pending'");
    $update->execute([$newStatus, $user['id'], $note !== '' ? Crypto::enc($note) : null, $id]);
    if ($update->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => 'This request was already answered.'], 409);
    }
    AuditLogger::log($user['id'], $user['role'], $decision === 'approve' ? 'approve_retake' : 'decline_retake', 'student', (string) $row['student_id'], $note !== '' ? "Request #$id. Note: $note" : "Request #$id");

    // Tell the student by email as well as in their notifications (an unsent email never blocks the decision).
    $emailSent = false;
    if ($row['email'] !== null && $row['email_verified_at'] !== null) {
        $first = (string) Crypto::dec($row['first_name_enc']);
        $safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
        $link = rtrim((string) getenv('APP_URL'), '/') . '/assessment';
        $noteHtml = $note !== '' ? '<p style="margin:12px 0 0 0;"><strong>Note from the Guidance Office:</strong> ' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        if ($decision === 'approve') {
            $subject = 'Your retake request was approved';
            $message = 'The Guidance Office approved your request to retake the RIASEC assessment. Sign in, open Assessments and choose Retake Assessment. Your earlier result stays on your record.';
        } else {
            $subject = 'About your retake request';
            $message = 'The Guidance Office reviewed your request to retake the RIASEC assessment and could not approve it this time. You can visit the Guidance Office to talk about it.';
        }
        $html = EmailTemplate::render($subject, "<p style=\"margin:0 0 12px 0;\">Hi $safeFirst,</p><p style=\"margin:0;\">$message</p>$noteHtml", 'Open ProfilePath', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), '');
        $emailSent = Mailer::send($row['email'], $first, $subject, $html, "Hi $first,\n\n$message" . ($note !== '' ? "\n\nNote from the Guidance Office: $note" : '') . "\n\n$link");
    }
    jsonResponse(['success' => true, 'status' => $newStatus, 'emailSent' => $emailSent]);
}

jsonResponse(['error' => 'Method not allowed'], 405);