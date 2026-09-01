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

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = urldecode($path ?? '/');

// Root path: hand off to the built-in server's own default behavior,
// which finds and executes index.php in the docroot — that script
// already contains the real logic (redirect a logged-in user to their
// own dashboard, everyone else to login), which this router has no
// business reimplementing or bypassing.
if ($path === '/' || $path === '') {
    return false;
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
