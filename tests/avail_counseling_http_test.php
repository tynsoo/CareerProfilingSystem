<?php
// Black-box tests for Avail Counseling on the Student Help Center: it returns the CGC booking link for the page to open,
// records the student as Invited on the staff side, never saves a second record while one is open, and works once a day.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/avail_counseling_http_test.php http://127.0.0.1:8099
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/Counseling/lib/CounselingTracking.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'qa'); }
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) { $this->csrf = json_decode($this->raw('GET', '/api/session.php')['body'], true)['csrfToken'] ?? null; }
        return $this->raw($method, $path, $body, true);
    }
    function raw(string $method, string $path, $body = null, bool $csrf = false): array {
        $ch = curl_init($this->base . $path);
        $h = ['Content-Type: application/json']; if ($csrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
        $r = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr((string) $r, 0, $hs), 'body' => substr((string) $r, $hs)];
    }
    function json(array $r): array { return json_decode($r['body'], true) ?? []; }
    function login(string $u, string $p, string $portal): array { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r; }
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE 'qaavail%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM counseling_invites WHERE student_id = ? OR invited_by = ?')->execute([$id, $id]);
        $pdo->prepare('UPDATE monitoring_flags SET escalated_by = NULL, counselor_id = NULL WHERE escalated_by = ? OR counselor_id = ?')->execute([$id, $id]);
        foreach (['help_requests' => 'student_id', 'monitoring_flags' => 'student_id', 'students' => 'user_id', 'consent_records' => 'user_id', 'audit_log' => 'actor_user_id'] as $t => $col) {
            $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'counseling-invite:%' OR rate_key LIKE 'counseling-avail:%' OR rate_key LIKE 'login-ip:%'");
};
$cleanup();
CounselingTracking::ensureSchema($pdo);
$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT);
$count = fn(int $id) => (int) $pdo->query("SELECT COUNT(*) FROM counseling_invites WHERE student_id = $id")->fetchColumn();

$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, staff_position, is_active, approval_status) VALUES ('counselor', 'qaavailcoun', ?, 'qaavailcoun@mcl.edu.ph', NOW(), 'Qa, Counselor', 'counselor', TRUE, 'approved') RETURNING id");
$s->execute([$hash('Staff-Pass-1234!')]);
$counselorId = (int) $s->fetchColumn();
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status) VALUES ('student', 'qaavailstud', ?, 'qaavailstud@live.mcl.edu.ph', NOW(), 'approved') RETURNING id");
$s->execute([$hash('Qa-Student-1234!')]);
$studentId = (int) $s->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, 'qaavail1', ?, ?, 'STEM', '12', 'QA-A')")
    ->execute([$studentId, Crypto::enc('Qa'), Crypto::enc('Avail')]);

$admin = new Client($base);
check('admin signs in', $admin->login('admin', 'ChangeMe123!', 'staff')['code'] === 200);
$coun = new Client($base);
check('the counselor signs in', $coun->login('qaavailcoun', 'Staff-Pass-1234!', 'staff')['code'] === 200);
$stu = new Client($base);
check('the student signs in', $stu->login('qaavailstud', 'Qa-Student-1234!', 'student')['code'] === 200);

echo "=== the booking link cannot point back at ProfilePath ===\n";
$host = parse_url($base, PHP_URL_HOST);
$r = $admin->req('POST', '/api/security-config.php', ['type' => 'bookingLink', 'url' => "https://$host/student-help-center"]);
check('a ProfilePath address is refused as the booking link', $r['code'] === 400, $r['body']);
$r = $admin->req('POST', '/api/security-config.php', ['type' => 'bookingLink', 'url' => 'https://example.com/cgc-booking']);
check('the CGC booking address is saved', $r['code'] === 200, $r['body']);

echo "=== Avail Counseling: redirect target and Invited status ===\n";
$r = $coun->req('POST', '/api/counseling-booking-link.php', []);
check('only students can use it', $r['code'] === 403, (string) $r['code']);
$r = $stu->req('POST', '/api/counseling-booking-link.php', []);
$j = $stu->json($r);
check('the student gets the CGC booking link to open', $r['code'] === 200 && ($j['url'] ?? '') === 'https://example.com/cgc-booking', $r['body']);
check('one invitation is recorded as Invited, by the student', $count($studentId) === 1
    && $pdo->query("SELECT status = 'invited' AND invited_by = $studentId AND email_sent = FALSE FROM counseling_invites WHERE student_id = $studentId")->fetchColumn());
check('it is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'avail_counseling' AND target_id = '$studentId'")->fetchColumn());

echo "=== once a day ===\n";
$r2 = $stu->req('POST', '/api/counseling-booking-link.php', []);
$j2 = $stu->json($r2);
check('a second click the same day is refused, with when it frees up', $r2['code'] === 429 && ($j2['repeat'] ?? false) === true && !isset($j2['url']) && !empty($j2['againAt']), $r2['body']);
check('and saves nothing', $count($studentId) === 1);
// Five clicks at once (a double click or several tabs): still one record, none of them let through.
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init("$base/api/counseling-booking-link.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_COOKIEFILE => $stu->jar, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $stu->csrf]]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
check('clicks at the same time are all refused and save nothing', $count($studentId) === 1 && count(array_filter($hs, fn($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE) === 429)) === 5);
$nextDay = fn() => $pdo->exec("UPDATE rate_limit_hits SET created_at = created_at - INTERVAL '1441 minutes' WHERE rate_key = 'counseling-avail:$studentId'");

echo "=== no duplicates (on later days) ===\n";
$nextDay();
$r2 = $stu->req('POST', '/api/counseling-booking-link.php', []);
check('the next day the link opens again', $r2['code'] === 200 && ($stu->json($r2)['url'] ?? '') === 'https://example.com/cgc-booking', $r2['body']);
check('and reuses the open invitation', $count($studentId) === 1 && ($stu->json($r2)['inviteId'] ?? 0) === ($j['inviteId'] ?? -1) && ($stu->json($r2)['created'] ?? true) === false);
$pdo->exec("UPDATE counseling_invites SET status = 'booked' WHERE student_id = $studentId");
$nextDay();
$stu->req('POST', '/api/counseling-booking-link.php', []);
check('a booked invitation is also reused', $count($studentId) === 1);
$pdo->exec("UPDATE counseling_invites SET status = 'attended' WHERE student_id = $studentId");
$nextDay();
$r = $stu->req('POST', '/api/counseling-booking-link.php', []);
check('after the last one was closed, a new request is recorded', $count($studentId) === 2 && ($stu->json($r)['created'] ?? false) === true);
check('the closed one keeps its status', (int) $pdo->query("SELECT COUNT(*) FROM counseling_invites WHERE student_id = $studentId AND status = 'attended'")->fetchColumn() === 1);

$nextDay();
$before = $count($studentId);
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init("$base/api/counseling-booking-link.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_COOKIEFILE => $stu->jar, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $stu->csrf]]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
$codes = array_map(fn($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $hs);
check('on a new day, five clicks at once let exactly one through', count(array_filter($codes, fn($c) => $c === 200)) === 1 && count(array_filter($codes, fn($c) => $c === 429)) === 4, implode(',', $codes));
check('and still no new record while one is open', $count($studentId) === $before);

echo "=== staff side ===\n";
$flag = $pdo->prepare("INSERT INTO monitoring_flags (student_id, reason, status, escalated_at, escalated_by) VALUES (?, 'manual_escalation', 'escalated', NOW(), ?) RETURNING id");
$flag->execute([$studentId, $counselorId]);
$flagId = (int) $flag->fetchColumn();
$pdo->exec("UPDATE counseling_invites SET status = 'cancelled' WHERE student_id = $studentId");
$nextDay();
$stu->req('POST', '/api/counseling-booking-link.php', []);
check('a request while a flag is For Counseling is tied to that flag', (int) $pdo->query("SELECT flag_id FROM counseling_invites WHERE student_id = $studentId ORDER BY id DESC LIMIT 1")->fetchColumn() === $flagId);
$list = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=counseling'));
$mine = array_values(array_filter($list['rows'] ?? [], fn($x) => $x['flagId'] === $flagId))[0] ?? null;
check('the Counseling Queue shows the student as Invited, through Avail Counseling', ($mine['invite']['status'] ?? '') === 'invited' && ($mine['invite']['selfRequested'] ?? false) === true, json_encode($mine['invite'] ?? null));
$stu->req('POST', '/api/help-requests.php', ['subject' => 'Counseling', 'message' => 'I would like to talk to a counselor.', 'consent' => true]);
$reqs = $coun->json($coun->raw('GET', '/api/help-requests.php'));
$req = array_values(array_filter($reqs['requests'] ?? [], fn($x) => $x['studentId'] === $studentId))[0] ?? null;
check('Counseling Requests shows the student was invited through Avail Counseling', ($req['lastInvite']['by'] ?? '') === 'the student (Avail Counseling)', json_encode($req['lastInvite'] ?? null));
$notes = $stu->json($stu->raw('GET', '/api/notifications.php'));
$inviteNotes = array_filter($notes['items'] ?? $notes['notifications'] ?? [], fn($n) => ($n['type'] ?? '') === 'counseling_invite');
check('the student is not told the Guidance Office invited them', count($inviteNotes) === 0, json_encode(array_values($inviteNotes)));
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $studentId]);
$notes = $stu->json($stu->raw('GET', '/api/notifications.php'));
$inviteNotes = array_filter($notes['items'] ?? $notes['notifications'] ?? [], fn($n) => ($n['type'] ?? '') === 'counseling_invite');
check('a real staff invitation still notifies the student', $r['code'] === 200 && count($inviteNotes) === 1, $r['body'] . ' ' . substr(json_encode($notes), 0, 300));

$pdo->exec("DELETE FROM security_policies WHERE key = 'booking.url'");
$nextDay();
$r = $stu->req('POST', '/api/counseling-booking-link.php', []);
$before = $count($studentId);
check('with no booking link saved, nothing opens and nothing is saved', $r['code'] === 409 && $count($studentId) === $before);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
