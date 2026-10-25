<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';
require_once __DIR__ . '/../lib/SessionEnd.php';

$user = Auth::currentUser();

// A student's section can be corrected by staff after they've logged in
// (api/students.php, type=updateSection), but the session copy is only built
// at login. Re-read it here so the change shows up without a fresh sign-in.
if ($user !== null && $user['role'] === 'student') {
    $stmt = Database::get()->prepare('SELECT section FROM students WHERE user_id = ?');
    $stmt->execute([(int) $user['id']]);
    $section = $stmt->fetchColumn();
    if ($section !== false && $section !== ($user['section'] ?? null)) {
        $user['section'] = $section;
        $_SESSION['user']['section'] = $section;
    }
}

// Guidance counselors and facilitators are the same role with the same access;
// the title beside their name is looked up here so it is right even for a
// session that began before positions existed.
if ($user !== null && $user['role'] === 'counselor') {
    $stmt = Database::get()->prepare('SELECT staff_position FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $user['positionLabel'] = StaffPosition::label($stmt->fetchColumn() ?: null);
}

// Which column of the access matrix applies to this person (Guidance Counselors and Facilitators have one each).
if ($user !== null) {
    $user['rbacRole'] = Rbac::roleKey($user);
}

// Staff are shown by their own name (they sign in with email, so the login name is internal).
if ($user !== null && $user['role'] !== 'student') {
    $stmt = Database::get()->prepare('SELECT full_name, username FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $user['displayName'] = trim((string) $row['full_name']) !== '' ? $row['full_name'] : $row['username'];
    }
}

$response = ['user' => $user, 'csrfToken' => Csrf::token()];
if ($user !== null) {
    $response['sessionTimeout'] = Auth::sessionTimeoutPolicy();
}
// This device was signed out by modules/Authentication/lib/SessionEnd.php (another device, account turned off, password changed): the sign-in
// page says why. Read only (nothing changes here); signing in again on this device gives it a new session.
if ($user === null && DbSessionHandler::$endedReason !== null) {
    $response['endedMessage'] = SessionEnd::MESSAGES[DbSessionHandler::$endedReason] ?? null;
}
jsonResponse($response);
