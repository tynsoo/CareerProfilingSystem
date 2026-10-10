<?php
/**
 * Router script for PHP's built-in server (`php -S host:port router.php`).
 * Lets every page be reached without its ".html" extension in the URL
 * (e.g. /announcements instead of /announcements.html), while every other
 * request — API endpoints (.php), images, CSS, JS — is served exactly as
 * before, unaffected by this file.
 *
 * How PHP's built-in server treats a router script: for every request, it
 * calls this script first. Returning false hands the request back to the
 * server's own default static-file/PHP-execution handling; returning true
 * (or just letting the script finish after echoing output) means this
 * script fully handled the response itself.
 */

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/SecurityHeaders.php';

SecurityHeaders::send();

// Pages that require an authenticated session with a specific role. Each of
// these pages also redirects client-side once its own JS runs (checking
// currentUser.role), but that left the page's HTML shell itself reachable
// with a 200 to anyone, logged in or not — the API endpoints behind these
// pages were always properly session/role-gated, only the static shell
// wasn't. Enforced here, before the file is ever read off disk, using the
// same role requirement each page's own client-side guard already checks.
const PROTECTED_PAGES = [
    'admin-dashboard' => ['admin', 'counselor'],
    'add-career' => ['admin', 'counselor'],
    'all-recommended-careers' => ['admin', 'counselor'],
    'analytics' => ['admin', 'counselor'],
    'announcements' => ['admin', 'counselor'],
    'audit-log' => ['admin', 'counselor'],
    'career-dataset' => ['admin', 'counselor'],
    'assessment-scheduling' => ['admin', 'counselor'],
    'counseling-requests' => ['admin', 'counselor'],
    'staff-help-center' => ['admin', 'counselor'],
    'staff-notifications' => ['admin', 'counselor'],
    'monitoring' => ['admin', 'counselor'],
    'monitoring-details' => ['admin', 'counselor'],
    'staff-profile' => ['admin', 'counselor'],
    'question-bank' => ['admin', 'counselor'],
    'security-configuration' => ['admin', 'counselor'],
    'student-profile' => ['admin', 'counselor'],
    'student-accounts' => ['admin', 'counselor'],
    'account-management' => ['admin', 'counselor'],
    'assessment' => ['student'],
    'assessment-instructions' => ['student'],
    'change-password' => ['student'],
    'career-worksheet' => ['student'],
    'student-help-center' => ['student'],
    'student-notifications' => ['student'],
    'results' => ['student'],
    'saved-careers' => ['student'],
    'riasec-assessment' => ['student'],
    'student-settings' => ['student'],
    'worksheet-results' => ['student'],
];

// Pages that were renamed so each name says who it is for. Old bookmarks, emailed links and notifications that
// still point at an old name are sent to the new one (the query string is kept).
const RENAMED_PAGES = [
    'login' => 'student-login',
    'registration' => 'student-register',
    'forgot-password' => 'student-forgot-password',
    'help-center' => 'student-help-center',
    'notifications' => 'student-notifications',
    'profile' => 'staff-profile',
    'admin-help-center' => 'staff-help-center',
    'admin-notifications' => 'staff-notifications',
    'help-requests' => 'counseling-requests',
    'exam-schedules' => 'assessment-scheduling',
];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = urldecode($path ?? '/');

// dev/ holds the local student-preview tool (php -S localhost:8080 dev/preview.php). The real site never serves it.
if ($path === '/dev' || strpos($path, '/dev/') === 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "404 Not Found";
    return true;
}

if (preg_match('~^/([a-z-]+?)(?:\.html)?/?$~', $path, $renamed) && isset(RENAMED_PAGES[$renamed[1]])) {
    $query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    header('Location: /' . RENAMED_PAGES[$renamed[1]] . ($query !== '' ? '?' . $query : ''), true, 301);
    return true;
}

// Root path: hand off to the built-in server's own default behavior,
// which finds and executes index.php in the docroot — that script
// already contains the real logic (redirect a logged-in user to their
// own dashboard, everyone else to login), which this router has no
// business reimplementing or bypassing.
if ($path === '/' || $path === '') {
    return false;
}

// Checked against the basename with any ".html" stripped, so this catches
// both the clean URL (/admin-dashboard) and a direct request for the file
// itself (/admin-dashboard.html) before either ever reaches the "serve the
// file as-is" branch below.
$slug = basename($path, '.html');
if (isset(PROTECTED_PAGES[$slug])) {
    $user = Auth::currentUser();
    if ($user === null || !in_array($user['role'], PROTECTED_PAGES[$slug], true)) {
        // Staff pages send a signed-out visitor to the staff sign-in, student pages to the student one.
        header('Location: ' . (in_array('admin', PROTECTED_PAGES[$slug], true) ? '/staff-login' : '/student-login'));
        return true;
    }
}

// A request for a real file on disk — api/*.php, images/*, css/*, js/*,
// or even a page's original *.html path if something still links to it
// directly — is left entirely to the built-in server's default handling,
// which serves static files as-is and executes .php files normally.
$fullPath = __DIR__ . $path;
if (file_exists($fullPath) && !is_dir($fullPath)) {
    return false;
}

// Clean-URL page request: /foo -> foo.html on disk. Read and output the
// static HTML directly (these pages are plain HTML with client-side JS,
// not server-rendered PHP, so no include/execution is needed here).
$htmlCandidate = __DIR__ . rtrim($path, '/') . '.html';
if (is_file($htmlCandidate)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($htmlCandidate);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "404 Not Found";
return true;
