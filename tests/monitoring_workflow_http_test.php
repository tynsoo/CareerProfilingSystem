<?php
// Black-box tests for the simplified Monitoring workflow:
//   - closing a flag For Counseling closes its open invitation too (Record Outcome -> Availed / Did Not Avail / Declined;
//     Dismiss -> Cancelled for a staff invitation, a student's own request stays open), so Student Information follows
//     and the student can ask again;
//   - Needs Review (rows, count and notification) is the current school year only, lowest similarity first; the
//     Counseling Queue and History keep every year;
//   - looking at the lists changes no flag.
// Test data only (usernames qamwf*), removed before and after.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/monitoring_workflow_http_test.php http://127.0.0.1:8099
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
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => substr((string) $r, $hs)];
    }
    function json(array $r): array { return json_decode($r['body'], true) ?? []; }
    function login(string $u, string $p, string $portal): int { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r['code']; }
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE 'qamwf%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM counseling_invites WHERE student_id = ? OR invited_by = ? OR status_updated_by = ?')->execute([$id, $id, $id]);
        $pdo->prepare('UPDATE monitoring_flags SET escalated_by = NULL, counselor_id = NULL, resolved_by = NULL WHERE escalated_by = ? OR counselor_id = ? OR resolved_by = ?')->execute([$id, $id, $id]);
        foreach (['monitoring_flags' => 'student_id', 'recommendations' => 'student_id', 'assessments' => 'student_id', 'students' => 'user_id', 'consent_records' => 'user_id', 'audit_log' => 'actor_user_id'] as $t => $col) {
            $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'counseling-invite:%' OR rate_key LIKE 'counseling-avail:%' OR rate_key LIKE 'login-ip:%'");
};
$cleanup();
CounselingTracking::ensureSchema($pdo);
$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT);
$ay = AcademicYear::current();
$lastAy = (AcademicYear::startYear($ay) - 1) . '-' . AcademicYear::startYear($ay);

$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, staff_position, is_active, approval_status) VALUES ('counselor', 'qamwfcoun', ?, 'qamwfcoun@mcl.edu.ph', NOW(), 'Qa, Counselor', 'counselor', TRUE, 'approved') RETURNING id");
$s->execute([$hash('Staff-Pass-1234!')]);
$counselorId = (int) $s->fetchColumn();
$mkStudent = function (string $u, string $last, ?string $year) use ($pdo, $hash): int {
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status) VALUES ('student', ?, ?, ?, NOW(), 'approved') RETURNING id");
    $s->execute([$u, $hash('Qa-Student-1234!'), "$u@live.mcl.edu.ph"]);
    $id = (int) $s->fetchColumn();
    $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, 'STEM', '12', 'QA-W', ?)")
        ->execute([$id, $u, Crypto::enc('Qa'), Crypto::enc($last), $year]);
    return $id;
};
// A flag with a result of the given similarity (the recommendation row holds only what Monitoring reads).
$mkFlag = function (int $sid, string $status, ?float $score = 0.70) use ($pdo, $counselorId): int {
    $recId = null;
    if ($score !== null) {
        $a = $pdo->prepare("INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types, completed_at)
                            VALUES (?, (SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM assessments WHERE student_id = ?), TRUE, 30, 30, 30, 30, 30, 30, '[]', NOW() - INTERVAL '2 days') RETURNING id");
        $a->execute([$sid, $sid]);
        $r = $pdo->prepare("INSERT INTO recommendations (student_id, scores, top_program_id, top_score, match_status, source_assessment_id) VALUES (?, '[]', (SELECT MIN(id) FROM programs), ?, 'match', ?) RETURNING id");
        $r->execute([$sid, $score, (int) $a->fetchColumn()]);
        $recId = (int) $r->fetchColumn();
    }
    $f = $pdo->prepare("INSERT INTO monitoring_flags (student_id, recommendation_id, reason, status, escalated_at, escalated_by, created_at)
                        VALUES (?, ?, 'low_confidence', ?, " . ($status === 'escalated' ? 'NOW()' : 'NULL') . ", ?, NOW() - INTERVAL '1 hour') RETURNING id");
    $f->execute([$sid, $recId, $status, $status === 'escalated' ? $counselorId : null]);
    return (int) $f->fetchColumn();
};
$invite = fn(int $id) => $pdo->query("SELECT status FROM counseling_invites WHERE id = $id")->fetchColumn();

$admin = new Client($base);
check('admin signs in', $admin->login('admin', 'ChangeMe123!', 'staff') === 200);
$admin->req('POST', '/api/security-config.php', ['type' => 'bookingLink', 'url' => 'https://example.com/cgc-booking']);
$coun = new Client($base);
check('the counselor signs in', $coun->login('qamwfcoun', 'Staff-Pass-1234!', 'staff') === 200);
$status = fn(int $id) => $coun->json($coun->raw('GET', "/api/students.php?id=$id"))['student']['counseling'] ?? null;

echo "=== Record Outcome closes the invitation ===\n";
$outcomes = ['counseled_kept_choice' => ['attended', 'Availed'], 'no_show' => ['no_show', 'Did Not Avail'], 'declined_counseling' => ['declined', 'Declined']];
$i = 0;
foreach ($outcomes as $outcome => [$booking, $shown]) {
    $sid = $mkStudent('qamwfout' . (++$i), 'Outcome' . $i, $ay);
    $flagId = $mkFlag($sid, 'escalated');
    $r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $sid, 'flagId' => $flagId]);
    $inviteId = (int) ($coun->json($r)['inviteId'] ?? 0);
    check("[$outcome] the counselor invites the student from the flag", $r['code'] === 200 && $inviteId > 0, $r['body']);
    check("[$outcome] Student Information shows Invited", $status($sid) === 'Invited', (string) $status($sid));
    $r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'resolve', 'outcome' => $outcome]);
    check("[$outcome] the flag is closed", $r['code'] === 200 && $pdo->query("SELECT status FROM monitoring_flags WHERE id = $flagId")->fetchColumn() === 'approved', $r['body']);
    check("[$outcome] the invitation becomes " . CounselingTracking::bookingLabel($booking), $invite($inviteId) === $booking && ($coun->json($r)['invite']['status'] ?? '') === $booking, (string) $invite($inviteId));
    check("[$outcome] Student Information shows $shown", $status($sid) === $shown, (string) $status($sid));
    check("[$outcome] the change is in the Audit Log", (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'update_counseling_status' AND target_id = '$sid' AND actor_user_id = $counselorId")->fetchColumn());
}
// A closed invitation no longer blocks a new one (the once-a-day limit on inviting is moved a day back first).
$pdo->exec("UPDATE rate_limit_hits SET created_at = created_at - INTERVAL '1441 minutes' WHERE rate_key = 'counseling-invite:$sid'");
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $sid]);
check('after the outcome the student can be invited again', $r['code'] === 200 && ($coun->json($r)['inviteId'] ?? 0) > 0, $r['body']);

echo "=== Dismissing a flag For Counseling ===\n";
$sid = $mkStudent('qamwfdis', 'Dismissed', $ay);
$flagId = $mkFlag($sid, 'escalated');
$inviteId = (int) ($coun->json($coun->req('POST', '/api/counseling-invite.php', ['studentId' => $sid, 'flagId' => $flagId]))['inviteId'] ?? 0);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'dismiss', 'note' => 'Not a valid flag (test).']);
check('the flag is dismissed', $r['code'] === 200, $r['body']);
check('the staff invitation is cancelled', $invite($inviteId) === 'cancelled', (string) $invite($inviteId));
check('Student Information shows Cancelled', $status($sid) === 'Cancelled', (string) $status($sid));

$sid = $mkStudent('qamwfself', 'SelfRequest', $ay);
$flagId = $mkFlag($sid, 'escalated');
$stu = new Client($base);
check('the student signs in', $stu->login('qamwfself', 'Qa-Student-1234!', 'student') === 200);
$r = $stu->req('POST', '/api/counseling-booking-link.php', []);
$selfId = (int) ($stu->json($r)['inviteId'] ?? 0);
check('the student uses Avail Counseling while the flag is For Counseling', $r['code'] === 200 && $selfId > 0, $r['body']);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'dismiss', 'note' => 'Not a valid flag (test).']);
check('the flag is dismissed', $r['code'] === 200 && array_key_exists('invite', $coun->json($r)) && $coun->json($r)['invite'] === null, $r['body']);
check("the student's own request stays Invited", $invite($selfId) === 'invited', (string) $invite($selfId));

$sid = $mkStudent('qamwfpend', 'PendingInvite', $ay);
$flagId = $mkFlag($sid, 'pending');
$inviteId = (int) ($coun->json($coun->req('POST', '/api/counseling-invite.php', ['studentId' => $sid]))['inviteId'] ?? 0);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'approve']);
check('approving a flag in Needs Review leaves an invitation as it is', $r['code'] === 200 && $invite($inviteId) === 'invited', (string) $invite($inviteId));

echo "=== Needs Review: this school year, lowest similarity first ===\n";
$now = $mkStudent('qamwfnow', 'ThisYear', $ay);
$noYear = $mkStudent('qamwfnoyr', 'NoYearSaved', null);
$past = $mkStudent('qamwfpast', 'LastYear', $lastAy);
$pastQ = $mkStudent('qamwfpastq', 'LastYearQueue', $lastAy);
$fNow = $mkFlag($now, 'pending', 0.75);
$fNoYear = $mkFlag($noYear, 'pending', 0.62);
$fPast = $mkFlag($past, 'pending', 0.60);
$fPastQ = $mkFlag($pastQ, 'escalated', 0.60);
$before = $pdo->query("SELECT id, status, resolved_at FROM monitoring_flags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$nr = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=needs-review'));
$ids = array_column($nr['rows'] ?? [], 'flagId');
check('a flag of this school year is listed', in_array($fNow, $ids, true));
check('a student with no school year saved counts as this year (as on Student Information)', in_array($fNoYear, $ids, true));
check("last year's flag is not in Needs Review", !in_array($fPast, $ids, true));
check('the count matches the rows', ($nr['summary']['needsReview'] ?? -1) === count($ids), json_encode($nr['summary'] ?? null));
check('lowest similarity first', array_search($fNoYear, $ids, true) < array_search($fNow, $ids, true));
check('no priority is sent', !array_key_exists('priority', ($nr['rows'][0] ?? [])));
$q = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=counseling'));
check("last year's student For Counseling stays in the Counseling Queue", in_array($fPastQ, array_column($q['rows'] ?? [], 'flagId'), true));
$n = $coun->json($coun->raw('GET', '/api/notifications.php'));
$links = array_column(array_filter($n['items'] ?? $n['notifications'] ?? [], fn($x) => ($x['type'] ?? '') === 'flag'), 'link');
check('the flag notification opens that student directly', in_array("monitoring-details?tab=needs-review&flag=$fNow", $links, true) || in_array("monitoring-details?tab=needs-review&flag=$fNoYear", $links, true), json_encode($links));
check("last year's flag is not notified", !in_array("monitoring-details?tab=needs-review&flag=$fPast", $links, true));
$coun->raw('GET', '/api/monitoring.php?tab=history');
$after = $pdo->query("SELECT id, status, resolved_at FROM monitoring_flags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('looking at the lists changes no flag', $before === $after);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
