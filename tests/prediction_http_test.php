<?php
/**
 * Prediction Module over HTTP, against running servers that use a *_test database:
 * staff-only access, blocked model files, and a server whose model file is broken.
 *
 *   DB_NAME=x_test php -S 127.0.0.1:8099 router.php
 *   DB_NAME=x_test PREDICTION_MODEL_FILE=/path/to/broken.json php -S 127.0.0.1:8098 router.php
 *   DB_NAME=x_test STAFF_USER=admin STAFF_PASS=... STUDENT_USER=... STUDENT_PASS=... STUDENT_ID=2 \
 *     php tests/prediction_http_test.php http://127.0.0.1:8099 http://127.0.0.1:8098
 *
 * The student account must have a submitted Career Worksheet (a saved recommendation).
 */
require_once __DIR__ . '/../shared/lib/Database.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n");
    exit(2);
}
[$base, $brokenBase] = [rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/'), rtrim($argv[2] ?? '', '/')];
$env = fn(string $k) => getenv($k) ?: exit("Set $k\n");
[$staffUser, $staffPass, $studentUser, $studentPass, $studentId] =
    [$env('STAFF_USER'), $env('STAFF_PASS'), $env('STUDENT_USER'), $env('STUDENT_PASS'), (int) $env('STUDENT_ID')];

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function req(string $base, string $method, string $path, ?array $body, string $jar, ?string &$csrf): array
{
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($csrf) {
        $h[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = (string) curl_exec($ch);
    return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($text, true) ?? [], 'text' => $text];
}
function login(string $base, string $user, string $pass, string $portal): array
{
    $jar = tempnam(sys_get_temp_dir(), 'pm');
    $csrf = null;
    $csrf = req($base, 'GET', '/api/session.php', null, $jar, $csrf)['json']['csrfToken'] ?? null;
    $r = req($base, 'POST', '/api/login.php', ['username' => $user, 'password' => $pass, 'portal' => $portal], $jar, $csrf);
    $csrf = null;
    $csrf = req($base, 'GET', '/api/session.php', null, $jar, $csrf)['json']['csrfToken'] ?? null;
    return [$jar, $csrf, $r['code']];
}

echo "=== Model files are not served ===\n";
$none = null;
foreach (['/config/prediction_model.json', '/models/ProfilePath_J48_Binary_393.model', '/tools/weka/export-j48.jar',
          '/evaluation/METHODOLOGY.md', '/tests/fixtures/profiles20_ProfilePath_J48_Binary_393.arff', '/docs/methodology-notes.md', '/README.md'] as $path) {
    $code = req($base, 'GET', $path, null, tempnam(sys_get_temp_dir(), 'pm'), $none)['code'];
    check("GET $path -> 404", $code === 404, "got $code");
}

echo "\n=== Staff get the Prediction Module; students never do ===\n";
[$sjar, $scsrf, $code] = login($base, $staffUser, $staffPass, 'staff');
check('staff login', $code === 200, "got $code");
$r = req($base, 'GET', "/api/recommendations.php?studentId=$studentId", null, $sjar, $scsrf);
$pd = $r['json']['predictionDetail'] ?? null;
check('staff: predictionDetail available', ($pd['status'] ?? null) === 'available', json_encode($pd['reason'] ?? $r['code']));
check('staff: 28 class probabilities summing to 1', count($pd['distribution'] ?? []) === 28 && abs(array_sum(array_column($pd['distribution'] ?? [], 'probability')) - 1) < 1e-9);
check('staff: top three, highest first, predicted course first', count($pd['top3'] ?? []) === 3
    && $pd['top3'][0]['label'] === $pd['predicted']['label'] && $pd['top3'][0]['probability'] >= $pd['top3'][2]['probability']);
check('staff: exactly three inputs are 1', array_sum($pd['inputs'] ?? []) === 3);
check('staff: model version and Weka version reported', !empty($pd['model']['version']) && ($pd['model']['wekaVersion'] ?? null) === '3.9.6');
check('staff: CBF results still present', !empty($r['json']['bestRiasecMatch']));

[$tjar, $tcsrf, $code] = login($base, $studentUser, $studentPass, 'student');
check('student login', $code === 200, "got $code");
$r = req($base, 'GET', '/api/recommendations.php', null, $tjar, $tcsrf);
check('student: own results returned', ($r['json']['hasRecommendation'] ?? false) === true);
check('student: no predictionDetail, no probabilities', !array_key_exists('predictionDetail', $r['json']) && !str_contains($r['text'], 'probability'));
$r = req($base, 'GET', "/api/recommendations.php?studentId=$studentId", null, $tjar, $tcsrf);
check('student asking for staff view (studentId=...) -> refused', in_array($r['code'], [400, 403], true) || !array_key_exists('predictionDetail', $r['json']), "got {$r['code']}");
$r = req($base, 'GET', "/api/recommendations.php?studentId=$studentId", null, tempnam(sys_get_temp_dir(), 'pm'), $none);
check('not signed in -> refused', $r['code'] === 401, "got {$r['code']}");

if ($brokenBase !== '') {
    echo "\n=== Server with a broken model file ===\n";
    [$bjar, $bcsrf] = login($brokenBase, $staffUser, $staffPass, 'staff');
    $r = req($brokenBase, 'GET', "/api/recommendations.php?studentId=$studentId", null, $bjar, $bcsrf);
    check('request still succeeds', $r['code'] === 200, "got {$r['code']}");
    check('predictionDetail unavailable with a reason', ($r['json']['predictionDetail']['status'] ?? null) === 'unavailable' && !empty($r['json']['predictionDetail']['reason']));
    check('CBF Best RIASEC Match and Alternatives still returned', !empty($r['json']['bestRiasecMatch']) && array_key_exists('alternatives', $r['json']));
}

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
