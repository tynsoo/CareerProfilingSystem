<?php

declare(strict_types=1);

/**
 * Student-side PREVIEW. Lets a teammate edit and see the student pages with no database, no .env and no keys:
 *
 *     php -S localhost:8080 dev/preview.php          (run from the project folder)
 *
 * then open http://localhost:8080/ . Every /api/*.php request is answered by dev/PreviewApi.php with sample data
 * (the real scoring and recommendation code is used where it needs no database). The staff pages are not part of
 * this preview. This file is never used by the live site (router.php blocks /dev/).
 */

require_once __DIR__ . '/PreviewApi.php';

date_default_timezone_set('Asia/Manila'); // the real site shows Manila time

$root = dirname(__DIR__);
session_name('ppreview');
session_start();

$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($path === '/' || $path === '') {
    header('Location: /preview');
    return true;
}

// The scenario chooser.
if ($path === '/preview') {
    require __DIR__ . '/home.php';
    return true;
}
if ($path === '/preview/set') {
    $scenario = in_array($_GET['scenario'] ?? '', ['new', 'done', 'complete'], true) ? $_GET['scenario'] : 'new';
    $window = in_array($_GET['window'] ?? '', ['open', 'upcoming', 'ended', 'none'], true) ? $_GET['window'] : 'open';
    PreviewApi::reset($scenario, $window);
    $go = (string) ($_GET['go'] ?? '/preview');
    header('Location: ' . (preg_match('~^/[a-z0-9-]*$~', $go) ? $go : '/preview'));
    return true;
}

// The stand-in API.
if (preg_match('~^/api/([a-z0-9-]+)\.php$~', $path, $m)) {
    PreviewApi::handle($m[1]);
    return true;
}

// CSS, JavaScript, images and fonts are served as they are.
$full = $root . $path;
if (is_file($full) && !preg_match('~\.(php|html)$~', $path) && strpos($path, '/dev/') !== 0 && strpos($path, '/db/') !== 0 && strpos($path, '/lib/') !== 0) {
    return false;
}

// Pages: /student-login, /results, ... (with or without .html).
$slug = basename($path, '.html');
if (in_array($slug, PreviewApi::PAGES, true) && is_file($root . '/' . $slug . '.html')) {
    $html = (string) file_get_contents($root . '/' . $slug . '.html');
    $pv = PreviewApi::state();
    $bar = '<div id="previewBar" style="position:fixed;left:12px;bottom:12px;z-index:99999;background:#001c43;color:#fff;font:12px/1.4 Inter,Arial,sans-serif;padding:8px 12px;border-radius:9999px;box-shadow:0 6px 20px rgba(0,0,0,.25)">'
        . 'Preview &middot; sample data &middot; <a href="/preview" style="color:#fff;font-weight:600;text-decoration:underline">switch scenario</a></div>';
    header('Content-Type: text/html; charset=UTF-8');
    echo str_ireplace('</body>', $bar . '</body>', $html);
    return true;
}

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>Not in the preview</title>'
    . '<body style="font-family:Arial,sans-serif;padding:48px;max-width:560px;margin:auto"><h1>Not part of the preview</h1>'
    . '<p>The preview covers the student side only. <a href="/preview">Back to the preview home</a>.</p></body>';
return true;
