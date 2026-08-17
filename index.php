<?php

require_once __DIR__ . '/lib/Auth.php';

$user = Auth::currentUser();

if ($user === null) {
    header('Location: login.html');
} elseif ($user['role'] === 'student') {
    header('Location: assessment.html');
} else {
    header('Location: admin-dashboard.html');
}
exit;
