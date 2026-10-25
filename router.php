<?php
/**
 * Router script for PHP's built-in server (`php -S host:port router.php`).
 * Lets every page be reached without its ".html" extension in the URL
 * (e.g. /announcements instead of /announcements.html), while images, CSS
 * and JS are served exactly as before, unaffected by this file.
 *
 * The code is grouped by module: each page and API endpoint lives in
 * modules/<Module>/pages or modules/<Module>/api (or shared/pages, shared/api).
 * Their URLs stayed the same: /announcements and /api/announcements.php are
 * found here by name (moduleFile()), so links, bookmarks and emailed links
 * keep working. modules/ and shared/ themselves are never served directly.
 *
 * How PHP's built-in server treats a router script: for every request, it
 * calls this script first. Returning false hands the request back to the
 * server's own default static-file/PHP-execution handling; returning true
 * (or just letting the script finish after echoing output) means this
 * script fully handled the response itself.
 */

require_once __DIR__ . '/modules/Authentication/lib/Auth.php';
require_once __DIR__ . '/shared/lib/SecurityHeaders.php';

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
    'student-accounts' => 'admin-dashboard',
];

// The page or API file with this name in its module folder (or in shared/), or null.
function moduleFile(string $kind, string $file): ?string
{
    $found = array_merge(glob(__DIR__ . "/modules/*/$kind/$file") ?: [], glob(__DIR__ . "/shared/$kind/$file") ?: []);
    return $found[0] ?? null;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = urldecode($path ?? '/');

if (preg_match('~^/([a-z-]+?)(?:\.html)?/?$~', $path, $renamed) && isset(RENAMED_PAGES[$renamed[1]])) {
    $query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    header('Location: /' . RENAMED_PAGES[$renamed[1]] . ($query !== '' ? '?' . $query : ''), true, 301);
    return true;
}

// Model, dataset, environment and documentation files are never served, wherever they are
// (the folders that hold them are also in PRIVATE_DIRS below).
if (preg_match('~\.(model|arff|env|md)$~i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "404 Not Found";
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

// API endpoint: /api/login.php runs modules/Authentication/api/login.php. Each endpoint checks its own
// session and role (shared/api/_bootstrap.php). Required here at the top level, as if it had been
// requested directly, so its variables stay global as before.
if (preg_match('~^/api/([a-z0-9-]+)\.php$~', $path, $api) && ($apiFile = moduleFile('api', $api[1] . '.php')) !== null) {
    require $apiFile;
    return true;
}

// Folders that are never served over the web. The module code (modules/, shared/) is reached only through
// the page and API URLs. The database scripts (db/), tests, settings (config/), evaluation scripts and
// libraries (vendor/) are run from the command line only: run over the web, a script such as
// db/seed_admin.php would act on the live database. The Weka model files (models/), their export
// tool (tools/) and the methodology notes (docs/) are not public either.
const PRIVATE_DIRS = 'modules|shared|db|tests|config|evaluation|vendor|models|tools|docs';
if (preg_match('~^/+(' . PRIVATE_DIRS . ')(/|$)~i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "404 Not Found";
    return true;
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

// A request for a real file on disk — images/*, css/*, js/* — is left
// entirely to the built-in server's default handling, which serves static
// files as-is. Checked again on the resolved path, so a request such as
// /css/../db/seed_admin.php cannot reach the private folders above.
$fullPath = realpath(__DIR__ . $path);
$privateDirs = '~^' . preg_quote(str_replace('\\', '/', __DIR__), '~') . '/(' . PRIVATE_DIRS . ')(/|$)~i';
if ($fullPath !== false && is_file($fullPath) && !preg_match($privateDirs, str_replace('\\', '/', $fullPath))) {
    return false;
}

// Clean-URL page request: /foo or /foo.html -> modules/<Module>/pages/foo.html.
// Read and output the static HTML directly (these pages are plain HTML with
// client-side JS, not server-rendered PHP, so no include/execution is needed
// here). They are served at the site root, so their relative links to css/,
// js/, images/ and api/ work as before.
if (preg_match('~^/([a-z0-9-]+)(?:\.html)?/?$~', $path, $page) && ($htmlFile = moduleFile('pages', $page[1] . '.html')) !== null) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($htmlFile);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "404 Not Found";
return true;
