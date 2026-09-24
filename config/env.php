<?php
/**
 * Minimal .env loader — no external dependency, since packagist.org
 * is unreachable from the dev sandbox (see .gitignore note on vendor/).
 */
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/../.env');

// The school operates in Philippine time. Without this PHP falls back to UTC
// (the Docker image default), so a "today 3 PM" value from a datetime-local
// input was read as 3 PM UTC = 11 PM Manila and could land on tomorrow.
const APP_TIMEZONE = 'Asia/Manila';
date_default_timezone_set(APP_TIMEZONE);
