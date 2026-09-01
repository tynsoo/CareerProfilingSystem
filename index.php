<?php

require_once __DIR__ . '/lib/Auth.php';

$user = Auth::currentUser();

if ($user === null) {
    header('Location: login');
} elseif ($user['role'] === 'student') {
    header('Location: assessment');
} else {
    header('Location: admin-dashboard');
}
exit;
