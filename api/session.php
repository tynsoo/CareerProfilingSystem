<?php

require_once __DIR__ . '/_bootstrap.php';

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

jsonResponse(['user' => $user]);
