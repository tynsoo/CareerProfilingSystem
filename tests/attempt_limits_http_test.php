<?php
// Repeated-attempt limits that Table 29 marked "Partly tested": the assessment access code (3 wrong codes per 5 minutes) and the
// staff activation code (cancelled after 5 wrong tries).
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/attempt_limits_http_test.php http://127.0.0.1:8099
// Seeds synthetic users (prefix "qa_lim_") and one exam schedule (room QA-LIMIT), and removes only those.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../modules/Authentication/lib/StaffSetupCode.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'qa'); }
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) {
            $ch = curl_init($this->base . '/api/session.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
            $this->csrf = json_decode((string) curl_exec($ch), true)['csrfToken'] ?? '';
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $r = (string) curl_exec($ch);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $r];
    }
}

$pw = 'Qa-Test-Pass1!';
$ids = [];
$ay = AcademicYear::current();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
foreach (['qa_lim_stu', 'qa_lim_staff', 'qa_lim_staff2', 'qa_lim_staff3'] as $n) { $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$n]); }
$pdo->exec("DELETE FROM exam_schedules WHERE room = 'QA-LIMIT'");
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name) VALUES ('student', 'qa_lim_stu', ?, 'qa_lim_stu@example.test', NOW(), 'approved', 'QA') RETURNING id");
$s->execute([password_hash($pw, PASSWORD_BCRYPT)]);
$stu = $ids[] = (int) $s->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, 'qa_lim_stu', ?, ?, 'STEM', '11', 'QA-LIM', ?)")
    ->execute([$stu, Crypto::enc('Qa'), Crypto::enc('Limit'), $ay]);
$code = 'QALIM7';
$pdo->prepare("INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code) VALUES (?, ?, '00:00', '23:59:59', 'QA-LIMIT', '11', 'STEM', 'QA-LIM', ?)")
    ->execute([$ay, $today, $code]);

try {
    echo "=== Assessment access code: 3 wrong codes every 5 minutes (verify-access-code.php) ===\n";
    $c = new Client($base);
    $r = $c->req('POST', '/api/login.php', ['username' => 'qa_lim_stu', 'password' => $pw, 'portal' => 'student']); $c->csrf = null;
    check('the test student signs in', $r['code'] === 200, (string) $r['code']);
    foreach ([1, 2, 3] as $i) {
        $r = $c->req('POST', '/api/verify-access-code.php', ['code' => "WRONG$i"]);
        check("wrong code $i of 3 is refused as incorrect (403)", $r['code'] === 403 && str_contains($r['body'], 'Incorrect'), $r['code'] . ' ' . $r['body']);
    }
    $r = $c->req('POST', '/api/verify-access-code.php', ['code' => 'WRONG4']);
    check('a 4th attempt within 5 minutes is blocked (429)', $r['code'] === 429, $r['code'] . ' ' . $r['body']);
    $r = $c->req('POST', '/api/verify-access-code.php', ['code' => $code]);
    check('while blocked, even the correct code is refused (429)', $r['code'] === 429, (string) $r['code']);
    $logged = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE actor_user_id = $stu AND action = 'access_code_throttled'")->fetchColumn();
    check('the block is recorded in the Audit Log', $logged >= 1, (string) $logged);
    // The 5-minute window is simulated by ageing the recorded attempts (not by waiting).
    $pdo->prepare("UPDATE rate_limit_hits SET created_at = NOW() - INTERVAL '6 minutes' WHERE rate_key = ?")->execute(['access-code:' . $stu]);
    $r = $c->req('POST', '/api/verify-access-code.php', ['code' => $code]);
    check('after the 5 minutes, the correct code opens the assessment (200)', $r['code'] === 200, $r['code'] . ' ' . $r['body']);

    echo "\n=== Staff activation code: cancelled after 5 wrong tries (staff-activate.php) ===\n";
    $mkStaff = function (string $name) use ($pdo, &$ids): int {
        $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$name]);
        $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, approval_status, full_name, staff_position) VALUES ('counselor', ?, 'x', ?, 'approved', 'QA', 'counselor') RETURNING id");
        $s->execute([$name, "$name@example.test"]);
        return $ids[] = (int) $s->fetchColumn();
    };
    $activate = fn(Client $k, string $who, string $code) => $k->req('POST', '/api/staff-activate.php', ['identifier' => $who, 'code' => $code, 'password' => 'Qa-New-Pass1!', 'privacyConsent' => true]);

    $a = $mkStaff('qa_lim_staff');
    $real = StaffSetupCode::issue($pdo, $a);
    check('the issued code has 8 characters', strlen($real) === StaffSetupCode::LENGTH && StaffSetupCode::LENGTH === 8, $real);
    $k = new Client($base);
    $codes = [];
    foreach ([1, 2, 3, 4] as $i) { $codes[] = $activate($k, 'qa_lim_staff', "ZZZZZZZ$i")['code']; }
    check('4 wrong codes are refused with the same answer (400)', $codes === [400, 400, 400, 400], implode(',', $codes));
    $r = $activate($k, 'qa_lim_staff', $real);
    check('after 4 wrong tries the correct code still activates the account (200)', $r['code'] === 200, $r['code'] . ' ' . $r['body']);

    $b = $mkStaff('qa_lim_staff2');
    $real = StaffSetupCode::issue($pdo, $b);
    $k = new Client($base);
    $codes = [];
    foreach ([1, 2, 3, 4, 5] as $i) { $codes[] = $activate($k, 'qa_lim_staff2', "ZZZZZZZ$i")['code']; }
    check('5 wrong codes are refused with the same answer (400)', $codes === [400, 400, 400, 400, 400], implode(',', $codes));
    $r = $activate($k, 'qa_lim_staff2', $real);
    check('after the 5th wrong try the correct code no longer works (code cancelled)', $r['code'] === 400, $r['code'] . ' ' . $r['body']);
    check('the answer is the same generic one, so it does not show that the code was cancelled', str_contains($r['body'], 'That access code is not valid or has expired'), $r['body']);
    $logged = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE actor_user_id = $b AND action = 'staff_activation_failed'")->fetchColumn();
    check('the wrong tries are recorded in the Audit Log', $logged === 6, (string) $logged);
    $fresh = StaffSetupCode::issue($pdo, $b);
    $r = $activate($k, 'qa_lim_staff2', $fresh);
    check('a new code sent by the Head of Guidance works and starts a new count (200)', $r['code'] === 200, $r['code'] . ' ' . $r['body']);
    $pdo->prepare("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-activate%'")->execute();
    $c3 = $mkStaff('qa_lim_staff3');
    StaffSetupCode::issue($pdo, $c3);
    $k = new Client($base);
    $codes = [];
    for ($i = 1; $i <= 11; $i++) { $codes[] = $activate($k, 'qa_lim_staff3', 'ZZZZZZZ' . ($i % 10))['code']; }
    check('the request limit still stops a script after 10 attempts in an hour (429 on the 11th)', $codes[10] === 429 && count(array_unique(array_slice($codes, 0, 10))) === 1, implode(',', $codes));
} finally {
    $pdo->exec("DELETE FROM exam_schedules WHERE room = 'QA-LIMIT'");
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-activate%' OR rate_key LIKE 'access-code:%'");
    foreach ($ids as $id) {
        foreach (['DELETE FROM assessment_progress WHERE student_id = ?', 'DELETE FROM email_verification_tokens WHERE user_id = ?', "DELETE FROM rate_limit_hits WHERE rate_key = 'staff-code-wrong:' || ?", 'DELETE FROM audit_log WHERE actor_user_id = ?', 'DELETE FROM sessions WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $pdo->prepare($sql)->execute([$id]); } catch (PDOException $e) { echo "  cleanup note: " . $e->getMessage() . "\n"; }
        }
    }
}
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
