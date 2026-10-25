<?php
// Black-box HTTP tests against a running server that uses a *_test database.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/http_security_test.php http://127.0.0.1:8099
// Seeds synthetic users (prefix "qa_") and removes only those it created.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'qa'); }
    function req(string $method, string $path, $body = null, bool $withCsrf = true): array {
        if ($withCsrf && $this->csrf === null) { $this->csrf = json_decode($this->req('GET', '/api/session.php', null, false)['body'], true)['csrfToken'] ?? null; }
        $ch = curl_init($this->base . $path);
        $h = ['Content-Type: application/json'];
        if ($withCsrf && $this->csrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
        $r = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['code' => $code, 'headers' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
    }
    function json(array $r): array { return json_decode($r['body'], true) ?? []; }
    function login(string $u, string $p, string $portal = ''): array { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r; }
}

// ---- seed -------------------------------------------------------------
$pw = 'Qa-Test-Pass1!'; $hash = password_hash($pw, PASSWORD_BCRYPT);
$ids = [];
$mkUser = function (string $role, string $name, ?string $pos = null) use ($pdo, $hash, &$ids) {
    $pdo->prepare("DELETE FROM counseling_notes WHERE student_id IN (SELECT id FROM users WHERE username = ?) OR author_id IN (SELECT id FROM users WHERE username = ?)")->execute([$name, $name]);
    $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE username = ?)")->execute([$name]);
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$name]);
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position) VALUES (?,?,?,?,?,'approved',?,?) RETURNING id");
    $s->execute([$role, $name, $hash, "$name@example.test", date('c'), $name, $pos]);
    return $ids[] = (int) $s->fetchColumn();
};
$mkStudent = function (string $name, string $strand, string $sec) use ($pdo, $mkUser) {
    $id = $mkUser('student', $name);
    $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?,?,?,?,?,'11',?)")
        ->execute([$id, $name, Crypto::enc('Qa'), Crypto::enc($name), $strand, $sec]);
    return $id;
};
$counselor = $mkUser('counselor', 'qa_counselor', 'counselor');
$facil = $mkUser('counselor', 'qa_facilitator', 'facilitator');
$stuA = $mkStudent('qa_stu_a', 'STEM', 'QA-A');
$stuB = $mkStudent('qa_stu_b', 'ABM', 'QA-B');
$pdo->prepare("INSERT INTO staff_sections (user_id, strand, section) VALUES (?, 'STEM', 'QA-A')")->execute([$counselor]);
$pdo->prepare("INSERT INTO counseling_notes (student_id, note_enc, author_id) VALUES (?,?,?)")->execute([$stuB, Crypto::enc('QA PRIVATE NOTE'), $counselor]);

try {
    echo "=== Unauthenticated access ===\n";
    $c = new Client($base);
    foreach (['students.php', 'security-config.php', 'audit-log.php', 'staff-approvals.php', 'counseling-notes.php?studentId=1', 'recommendations.php', 'analytics.php', 'monitoring.php', 'announcements.php', 'sections.php?all=1', 'programs.php', 'my-account.php'] as $ep) {
        $r = $c->req('GET', "/api/$ep", null, false);
        check("GET $ep without login -> 401/403", in_array($r['code'], [401, 403], true), (string) $r['code']);
    }

    echo "\n=== CSRF ===\n";
    $r = $c->req('POST', '/api/login.php', ['username' => 'qa_stu_a', 'password' => $pw], false);
    check('POST login without CSRF token -> 403', $r['code'] === 403);
    $c2 = new Client($base); $c2->csrf = 'deadbeef'; $r = $c2->req('POST', '/api/login.php', ['username' => 'x', 'password' => 'y']);
    check('POST with wrong CSRF token -> 403', $r['code'] === 403);

    echo "\n=== Authentication ===\n";
    $r = $c->login('qa_stu_a', 'wrong'); check('wrong password -> 401 generic message', $r['code'] === 401 && ($c->json($r)['error'] ?? '') === 'Invalid username or password.');
    $r = $c->login('no_such_user', 'wrong'); check('unknown user gives the same message', $r['code'] === 401 && ($c->json($r)['error'] ?? '') === 'Invalid username or password.');
    $r = $c->login('qa_stu_a', $pw, 'staff'); check('student on staff portal rejected', $r['code'] === 403);
    $r = $c->login("' OR '1'='1", "' OR '1'='1"); check('SQLi login payload rejected cleanly', $r['code'] === 401 && stripos($r['body'], 'SQLSTATE') === false);
    $r = $c->login("admin' --", 'x'); check('SQLi comment payload rejected cleanly', $r['code'] === 401 && stripos($r['body'], 'SQLSTATE') === false);
    $r = $c->login('qa_stu_a', $pw, 'student');
    check('valid student login ok', $r['code'] === 200 && ($c->json($r)['success'] ?? false) === true);
    check('session cookie is HttpOnly + SameSite', (bool) preg_match('/Set-Cookie:.*HttpOnly/i', $r['headers']) && (bool) preg_match('/Set-Cookie:.*SameSite=Lax/i', $r['headers']));
    check('login response leaks no password hash', stripos($r['body'], 'password') === false);
    check('security headers present', stripos($r['headers'], 'X-Frame-Options: DENY') !== false && stripos($r['headers'], 'Content-Security-Policy') !== false && stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false);

    echo "\n=== Student authorization (server side) ===\n";
    foreach (['students.php', 'security-config.php', 'audit-log.php', 'staff-approvals.php', 'analytics.php', 'monitoring.php', "counseling-notes.php?studentId=$stuB", 'staff-assignments.php'] as $ep) {
        $r = $c->req('GET', "/api/$ep");
        check("student GET $ep denied", in_array($r['code'], [401, 403], true), $r['code'] . ' ' . substr($r['body'], 0, 80));
    }
    $r = $c->req('GET', "/api/recommendations.php?studentId=$stuB");
    check('student cannot read another student\'s recommendations via studentId', stripos($r['body'], 'qa_stu_b') === false && $r['code'] !== 500, $r['code'] . ' ' . substr($r['body'], 0, 80));
    $r = $c->req('POST', '/api/students.php', ['type' => 'resetAssessment', 'userId' => $stuB]); check('student cannot reset another student', in_array($r['code'], [401, 403], true), (string) $r['code']);
    $r = $c->req('POST', '/api/security-config.php', ['type' => 'rbac', 'changes' => []]); check('student cannot POST security-config', in_array($r['code'], [401, 403], true), (string) $r['code']);
    $r = $c->req('POST', '/api/staff-approvals.php', ['id' => $counselor, 'action' => 'approve']); check('student cannot approve staff', in_array($r['code'], [401, 403], true), (string) $r['code']);

    echo "\n=== Counselor scoped to QA-A: section scope and notes ===\n";
    $k = new Client($base); $k->login('qa_counselor', $pw, 'staff');
    $r = $k->req('GET', '/api/students.php?all=1&search=qa_stu');
    check('scoped counselor sees own-section student', stripos($r['body'], 'qa_stu_a') !== false, substr($r['body'], 0, 100));
    check('scoped counselor does NOT see other-section student in list', stripos($r['body'], 'qa_stu_b') === false);
    $r = $k->req('GET', "/api/students.php?id=$stuB"); check('scoped counselor cannot open other-section student by id', stripos($r['body'], 'qa_stu_b') === false, $r['code'] . ' ' . substr($r['body'], 0, 120));
    $r = $k->req('GET', "/api/counseling-notes.php?studentId=$stuB"); check('scoped counselor cannot read notes of an out-of-scope student', stripos($r['body'], 'QA PRIVATE NOTE') === false, $r['code'] . ' ' . substr($r['body'], 0, 120));
    $r = $k->req('POST', '/api/counseling-notes.php', ['studentId' => $stuB, 'note' => 'written by out-of-scope staff']); check('scoped counselor cannot write notes for an out-of-scope student', $r['code'] >= 400, $r['code'] . ' ' . substr($r['body'], 0, 100));
    $r = $k->req('GET', "/api/recommendations.php?studentId=$stuB"); check('scoped counselor cannot read recommendations of an out-of-scope student', $r['code'] >= 400 || stripos($r['body'], 'qa_stu_b') === false, $r['code'] . ' ' . substr($r['body'], 0, 100));
    $r = $k->req('POST', '/api/security-config.php', ['type' => 'rbac', 'changes' => [['module' => 'career', 'role' => 'counselor', 'level' => 'full']]]); check('counselor cannot raise own permissions', $r['code'] >= 400 || ($k->json($r)['success'] ?? false) !== true, $r['code'] . ' ' . substr($r['body'], 0, 100));
    $r = $k->req('POST', '/api/security-config.php', ['type' => 'passwordPolicy', 'minLength' => 6]); check('counselor cannot change password policy', $r['code'] >= 400 || ($k->json($r)['success'] ?? false) !== true, $r['code'] . ' ' . substr($r['body'], 0, 100));

    echo "\n=== Facilitator restrictions (direct requests) ===\n";
    $f = new Client($base); $f->login('qa_facilitator', $pw, 'staff');
    $r = $f->req('GET', "/api/counseling-notes.php?studentId=$stuA"); check('facilitator cannot view counseling notes', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/counseling-notes.php', ['studentId' => $stuA, 'note' => 'facilitator write']); check('facilitator cannot create counseling notes', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('GET', '/api/staff-approvals.php'); check('facilitator cannot list staff approvals', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/staff-approvals.php', ['id' => $counselor, 'action' => 'reject']); check('facilitator cannot reject staff', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/counseling-invite.php', ['studentId' => $stuA]); check('facilitator cannot send counseling invitations', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/roster-upload.php', '{}'); check('facilitator cannot upload rosters (view-only)', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/sections.php', ['type' => 'create', 'strand' => 'STEM', 'code' => 'QA-X']); check('facilitator cannot modify sections (view-only)', $r['code'] === 403, (string) $r['code']);
    $r = $f->req('POST', '/api/announcements.php', ['type' => 'create', 'title' => 'qa', 'body' => 'qa']); check('facilitator cannot post announcements under default matrix', $r['code'] === 403, (string) $r['code']);

    echo "\n=== SQL injection / XSS payloads in inputs ===\n";
    $c->login('qa_stu_a', $pw, 'student');
    $r = $c->req('POST', '/api/help-requests.php', ['subject' => '<script>alert(1)</script>', 'message' => "x' OR 1=1; DROP TABLE users;--"]);
    check('help request with script + SQL payload handled (parameterised)', $r['code'] < 500, $r['code'] . ' ' . substr($r['body'], 0, 100));
    check('users table intact after SQL payload', (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0);
    $r = $k->req('GET', '/api/students.php?all=1&search=' . urlencode("' OR 1=1 --")); check('SQLi in student search: no DB error leak', $r['code'] < 500 && stripos($r['body'], 'SQLSTATE') === false, (string) $r['code']);
    $r = $k->req('GET', '/api/students.php?strand=' . urlencode("STEM' OR '1'='1")); check('SQLi in strand filter: no DB error leak', $r['code'] < 500 && stripos($r['body'], 'SQLSTATE') === false, (string) $r['code']);
    $r = $c->req('POST', '/api/career-match.php', ['career' => "'; SELECT pg_sleep(0)--"]); check('SQLi in career input: no 500', $r['code'] < 500, (string) $r['code']);
    $r = $c->req('POST', '/api/career-match.php', ['career' => '']); check('empty career input handled', $r['code'] < 500, (string) $r['code']);

    echo "\n=== Lockout (5 attempts, soft growing lock: 1 min the first time today, modules/Authentication/lib/LoginLock.php) ===\n";
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'login-fail:%' OR rate_key LIKE 'login-lock:%'");
    $l = new Client($base);
    for ($i = 0; $i < 5; $i++) { $l->login('qa_stu_b', 'bad' . $i); }
    $r = $l->login('qa_stu_b', $pw, 'student'); check('correct password refused while locked (423)', $r['code'] === 423, (string) $r['code']);
    $lu = $pdo->query("SELECT locked_until FROM users WHERE username='qa_stu_b'")->fetchColumn();
    $mins = round((strtotime((string) $lu) - time()) / 60);
    check('first lock of the day is about 1 minute', $mins >= 1 && $mins <= 2, "$mins min");

    echo "\n=== Logout invalidates the session ===\n";
    $x = new Client($base); $x->login('qa_stu_a', $pw, 'student');
    $before = $x->req('GET', '/api/my-account.php');
    $x->req('POST', '/api/logout.php', []);
    $after = $x->req('GET', '/api/my-account.php');
    check('session works before logout', $before['code'] === 200, (string) $before['code']);
    check('session rejected after logout', $after['code'] === 401, (string) $after['code']);
} finally {
    foreach ($ids as $id) {
        $pdo->prepare("DELETE FROM counseling_notes WHERE student_id = ? OR author_id = ?")->execute([$id, $id]);
        $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
}
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
