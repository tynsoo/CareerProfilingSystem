<?php
// Black-box tests for the Counseling Status on Student Information and the Student Profile: it follows the latest
// counseling invitation (staff Invite to counseling or the student's Avail Counseling click), "No request" without one,
// never "Did Not Avail" by default, and one active invitation per student.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/counseling_status_http_test.php http://127.0.0.1:8099
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/Counseling/lib/CounselingTracking.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
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
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE 'qacstat%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM counseling_invites WHERE student_id = ? OR invited_by = ?')->execute([$id, $id]);
        $pdo->prepare('UPDATE monitoring_flags SET escalated_by = NULL, counselor_id = NULL WHERE escalated_by = ? OR counselor_id = ?')->execute([$id, $id]);
        foreach (['assessments' => 'student_id', 'help_requests' => 'student_id', 'monitoring_flags' => 'student_id', 'students' => 'user_id', 'consent_records' => 'user_id', 'audit_log' => 'actor_user_id'] as $t => $col) {
            $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'counseling-invite:%' OR rate_key LIKE 'counseling-avail:%' OR rate_key LIKE 'login-ip:%'");
};
$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT);
$count = fn(int $id) => (int) $pdo->query("SELECT COUNT(*) FROM counseling_invites WHERE student_id = $id")->fetchColumn();
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, staff_position, is_active, approval_status) VALUES ('counselor', 'qacstatcoun', ?, 'qacstatcoun@mcl.edu.ph', NOW(), 'Qa Counselor', 'counselor', TRUE, 'approved') RETURNING id");
$s->execute([$hash('Staff-Pass-1234!')]);
$mkStudent = function (string $u, bool $assessed) use ($pdo, $hash): int {
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status) VALUES ('student', ?, ?, ?, NOW(), 'approved') RETURNING id");
    $s->execute([$u, $hash('Qa-Student-1234!'), "$u@live.mcl.edu.ph"]);
    $id = (int) $s->fetchColumn();
    $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, 'STEM', '12', 'QA-C', ?)")
        ->execute([$id, $u, Crypto::enc('Qa'), Crypto::enc($u), AcademicYear::current()]);
    if ($assessed) {
        $pdo->prepare("INSERT INTO assessments (student_id, attempt_number, top_types, completed_at, is_latest, score_r, score_i, score_a, score_s, score_e, score_c) VALUES (?, 1, '[\"S\",\"A\",\"I\"]', NOW(), TRUE, 10, 20, 30, 40, 5, 1)")->execute([$id]);
    }
    return $id;
};
$christeen = $mkStudent('qacstatnoexam', false); // like Christeen: signed up, no assessment yet
$finished = $mkStudent('qacstatdone', true);       // finished the assessment, never asked for counseling

$admin = new Client($base);
$admin->login('admin', 'ChangeMe123!', 'staff');
$admin->req('POST', '/api/security-config.php', ['type' => 'bookingLink', 'url' => 'https://example.com/cgc-booking']);
$coun = new Client($base);
check('the counselor signs in', $coun->login('qacstatcoun', 'Staff-Pass-1234!', 'staff')['code'] === 200);
$profile = fn(int $id) => $coun->json($coun->raw('GET', "/api/students.php?id=$id"))['student'] ?? [];
$listRow = function (int $id, string $filter = '') use ($coun) {
    $j = $coun->json($coun->raw('GET', '/api/students.php?all=1&section=QA-C' . ($filter !== '' ? '&counseling=' . urlencode($filter) : '')));
    foreach ($j['students'] ?? [] as $s) { if ($s['userId'] === $id) return $s; }
    return null;
};

echo "=== no request, never Did Not Avail by default ===\n";
check('a student without an assessment and no invitation: No request', ($listRow($christeen)['counseling'] ?? 'x') === '' && ($profile($christeen)['counseling'] ?? 'x') === '');
check('a finished student with no invitation: No request, not Did Not Avail', ($listRow($finished)['counseling'] ?? 'x') === '' && ($profile($finished)['counseling'] ?? 'x') === '');
check('the No request filter lists them', $listRow($finished, 'none') !== null && $listRow($finished, 'Did Not Avail') === null);

echo "=== Christeen's case: Avail Counseling shows on the staff side ===\n";
$stu = new Client($base);
check('the student signs in', $stu->login('qacstatnoexam', 'Qa-Student-1234!', 'student')['code'] === 200);
$r = $stu->req('POST', '/api/counseling-booking-link.php', []);
check('Avail Counseling gives the booking link', $r['code'] === 200 && ($stu->json($r)['url'] ?? '') === 'https://example.com/cgc-booking', $r['body']);
$row = $listRow($christeen);
check('Student Information: Invited, via Avail Counseling, active', ($row['counseling'] ?? '') === 'Invited' && ($row['counselingBy'] ?? '') === 'via Avail Counseling' && ($row['counselingActive'] ?? false) === true, json_encode($row));
$p = $profile($christeen);
check('Student Profile: Invited, with the invitation for the editor', ($p['counseling'] ?? '') === 'Invited' && ($p['counselingInvite']['selfRequested'] ?? false) === true && count($p['counselingInvite']['statuses'] ?? []) === 6, json_encode($p['counselingInvite'] ?? null));
check('the Invited filter finds them', $listRow($christeen, 'Invited') !== null);
check('nothing claims a booking', ($p['counselingInvite']['status'] ?? '') === 'invited' && $p['counselingInvite']['sessionDate'] === null);

echo "=== one active invitation ===\n";
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $christeen]);
check('Invite to counseling is refused while it is active, and saves nothing', $r['code'] === 409 && ($coun->json($r)['active'] ?? false) && $count($christeen) === 1, $r['body']);
check('the status was not overwritten', ($profile($christeen)['counselingInvite']['selfRequested'] ?? false) === true);

echo "=== staff move it through the statuses ===\n";
$inviteId = (int) $p['counselingInvite']['id'];
$set = fn(string $st, string $date = '') => $coun->req('POST', '/api/counseling-invite.php', ['type' => 'status', 'inviteId' => $inviteId, 'status' => $st, 'sessionDate' => $date]);
$r = $set('booked', '2026-11-20');
check('Booked (with the session date)', $r['code'] === 200 && ($profile($christeen)['counseling'] ?? '') === 'Booked' && ($listRow($christeen)['counselingActive'] ?? false) === true, $r['body']);
$set('no_show');
check('Did not attend shows Did Not Avail, and the invitation is closed', ($listRow($christeen)['counseling'] ?? '') === 'Did Not Avail' && ($listRow($christeen)['counselingActive'] ?? true) === false);
$set('attended');
check('Attended shows Availed', ($profile($christeen)['counseling'] ?? '') === 'Availed' && ($listRow($christeen, 'Availed') !== null));

echo "=== staff-initiated ===\n";
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $finished]);
check('staff invite a finished student', $r['code'] === 200, $r['body']);
$row = $listRow($finished);
check('Student Information: Invited, by the counselor', ($row['counseling'] ?? '') === 'Invited' && ($row['counselingBy'] ?? '') === 'by Qa Counselor', json_encode($row));
$pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key = 'counseling-invite:$finished'");
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $finished]);
check('a second staff invite (next day) is refused while it is active', $r['code'] === 409 && $count($finished) === 1, $r['body']);
$stuDone = new Client($base);
$stuDone->login('qacstatdone', 'Qa-Student-1234!', 'student');
$r = $stuDone->req('POST', '/api/counseling-booking-link.php', []);
check('the student then uses Avail Counseling: the link opens, the staff invitation is reused', $r['code'] === 200 && $count($finished) === 1
    && ($listRow($finished)['counselingBy'] ?? '') === 'by Qa Counselor');
$fid = (int) $pdo->query("SELECT id FROM counseling_invites WHERE student_id = $finished")->fetchColumn();
$coun->req('POST', '/api/counseling-invite.php', ['type' => 'status', 'inviteId' => $fid, 'status' => 'declined']);
check('Declined shows and closes it', ($listRow($finished)['counseling'] ?? '') === 'Declined' && ($listRow($finished)['counselingActive'] ?? true) === false);
$pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key = 'counseling-invite:$finished'");
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $finished]);
check('after it is closed, staff can invite again (a new invitation)', $r['code'] === 200 && $count($finished) === 2 && ($listRow($finished)['counseling'] ?? '') === 'Invited');
check('the closed one keeps its status', (int) $pdo->query("SELECT COUNT(*) FROM counseling_invites WHERE student_id = $finished AND status = 'declined'")->fetchColumn() === 1);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
