<?php

require_once __DIR__ . '/modules/Authentication/lib/Auth.php';
require_once __DIR__ . '/shared/lib/SecurityHeaders.php';

SecurityHeaders::send();

$user = Auth::currentUser();

if ($user === null) {
    header('Location: student-login');
} elseif ($user['role'] === 'student') {
    header('Location: assessment');
} else {
    header('Location: admin-dashboard');
}
exit;
