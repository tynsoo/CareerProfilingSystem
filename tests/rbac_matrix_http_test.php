<?php
// Checks every row of Table 5 (access of each user role under the default permissions) through the running site.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/rbac_matrix_http_test.php http://127.0.0.1:8099
// Each probe is sent by a Student, a Guidance Facilitator, a Guidance Counselor and the Administrator. A 401, or a 403
// whose message is a permission message ("Insufficient permissions", "Forbidden", "Only ...", "view-only"), means the role was refused;
// any other answer (200, or 400/404/409 for the deliberately incomplete bodies used here, or a 403 for a business rule
// such as a missing access code) means the role got past the permission check. The bodies are incomplete on purpose so that no record is
// created. Seeds synthetic users (prefix "qa_rbac_") and removes only those it created.
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
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) {
            $ch = curl_init($this->base . '/api/session.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
            $this->csrf = json_decode((string) curl_exec($ch), true)['csrfToken'] ?? '';
        }
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
        $r = (string) curl_exec($ch);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $r];
    }
    function login(string $u, string $p, string $portal): int { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r['code']; }
}

// ---- seed -------------------------------------------------------------
$pw = 'Qa-Test-Pass1!'; $hash = password_hash($pw, PASSWORD_BCRYPT);
$ids = [];
$mkUser = function (string $role, string $name, ?string $pos = null) use ($pdo, $hash, &$ids) {
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$name]);
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position) VALUES (?,?,?,?,?,'approved',?,?) RETURNING id");
    $s->execute([$role, $name, $hash, "$name@example.test", date('c'), $name, $pos]);
    return $ids[] = (int) $s->fetchColumn();
};
$stu = $mkUser('student', 'qa_rbac_stu');
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?,?,?,?,'STEM','11','QA-R')")
    ->execute([$stu, 'qa_rbac_stu', Crypto::enc('Qa'), Crypto::enc('Rbac')]);
$mkUser('counselor', 'qa_rbac_fac', 'facilitator');
$mkUser('counselor', 'qa_rbac_cou', 'counselor');
$mkUser('admin', 'qa_rbac_adm');

// Table 5 of Chapter 4, updated to the permission table agreed on 2026-10-24 (Rbac::DEFAULTS, Rbac::ADMIN_PAGES, Rbac::FACILITATOR_NEVER). Y = allowed, N = refused, V = "View only" (the page opens, a change is refused).
// Columns: Student, Guidance Facilitator, Guidance Counselor, Administrator.
// Each probe: [method, path, body, which columns it tests ('view' or 'change' for V cells)].
$rows = [
    ['Take the RIASEC assessment and complete the worksheet', 'YNNN', [
        ['POST', '/api/assessment-submit.php', ['answers' => []]],
        ['POST', '/api/worksheet-submit.php', ['career' => '']]]],
    ['Send counseling and retake requests', 'YNNN', [
        ['POST', '/api/help-requests.php', ['subject' => '', 'message' => '']]]],
    ['View student profiles and the results of students', 'NYYY', [
        ['GET', '/api/students.php', null],
        ['GET', "/api/recommendations.php?studentId=$stu", null, 'staffonly']]],
    ['Record and read counseling notes', 'NYYY', [
        ['GET', "/api/counseling-notes.php?studentId=$stu", null],
        ['POST', '/api/counseling-notes.php', ['studentId' => 0, 'note' => '']]]],
    ['Decide retake requests and invite students to counseling', 'NNYY', [
        ['POST', '/api/retake-requests.php', ['type' => 'decide', 'id' => 0, 'decision' => 'none'], 'staffonly'],
        ['POST', '/api/counseling-invite.php', ['studentId' => 0]]]],
    ['Review flagged students in Monitoring', 'NVYY', [
        ['GET', '/api/monitoring.php', null, 'view'],
        ['POST', '/api/monitoring-action.php', ['flagId' => 0, 'action' => 'none'], 'change']]],
    ['Set assessment schedules and access codes', 'NYYY', [
        ['POST', '/api/exam-schedules.php', ['type' => 'none']]]],
    ['Upload class rosters', 'NNVY', [
        ['GET', '/api/sections.php?all=1', null, 'view'],
        ['POST', '/api/roster-upload.php', '{}', 'change']]],
    // Panel revision S15: by default staff see the questions and their change history, only the administrator changes them.
    // Needs the default rows in the test database: on an older profilepath_test where questionBank is still saved as
    // 'full' for counselor and facilitator, set both to View Only first (Security Configuration), or this row fails.
    ['Maintain the question bank', 'NVVY', [
        ['GET', '/api/assessment-questions.php?all=1', null, 'staffview'], // students load the questions to answer them
        ['GET', '/api/assessment-questions.php?history=1', null, 'view'], // the Change history: staff only, students refused
        ['POST', '/api/assessment-questions.php', ['type' => 'none'], 'change']]],
    ['View analytics, download student lists, and export the Analytics Report as a PDF', 'NNYY', [
        ['GET', '/api/analytics.php', null]]],
    ['Send the Analytics Report by email', 'NNNY', [
        ['POST', '/api/analytics-report.php', ['email' => 'not-an-address']]]],
    ['Post announcements', 'NVVY', [
        ['GET', '/api/announcement-templates.php', null, 'view'],
        ['POST', '/api/announcements.php', ['type' => 'create'], 'change']]],
    ['Maintain the career dataset', 'NVVY', [
        ['GET', '/api/programs.php', null, 'staffview'],
        ['POST', '/api/programs.php', [], 'change']]],
    ['Approve staff sign-ups', 'NNNY', [
        ['POST', '/api/staff-approvals.php', ['id' => 0, 'action' => 'none']]]],
    ['Set role permissions and security policies', 'NNNY', [
        ['POST', '/api/security-config.php', ['type' => 'rbac', 'changes' => []]],
        ['POST', '/api/security-config.php', ['type' => 'policy']]]],
    ['View the Audit Log', 'NNNY', [
        ['GET', '/api/audit-log.php', null]]],
];

$roles = ['Student' => ['qa_rbac_stu', 'student'], 'Guidance Facilitator' => ['qa_rbac_fac', 'staff'], 'Guidance Counselor' => ['qa_rbac_cou', 'staff'], 'Administrator' => ['qa_rbac_adm', 'staff']];
$clients = [];
try {
    echo "=== Sign in as each role ===\n";
    foreach ($roles as $label => [$user, $portal]) {
        $clients[$label] = new Client($base);
        $code = $clients[$label]->login($user, $pw, $portal);
        check("$label signs in", $code === 200, (string) $code);
    }
    $refused = fn(int $c, string $b = '') => $c === 401 || ($c === 403 && ($b === '' || preg_match('/Insufficient permissions|"error":"Forbidden"|"error":"Only |view-only|administrator can/i', $b)));
    foreach ($rows as [$function, $expect, $probes]) {
        echo "\n=== $function (Table 5: " . implode(' / ', str_split($expect)) . ") ===\n";
        foreach (array_keys($roles) as $i => $label) {
            $cell = $expect[$i];
            foreach ($probes as $p) {
                $kind = $p[3] ?? 'any';
                // Students read the program list for registration and their results, so that view and the staff-only
                // decision probes are not Table 5 cells for the Student column.
                if (in_array($kind, ['staffonly', 'staffview'], true) && $label === 'Student') { continue; }
                $kind = $kind === 'staffview' ? 'view' : $kind;
                // For a View only cell, the view probe must be allowed and the change probe refused.
                $want = match (true) {
                    $cell === 'V' && $kind === 'view' => true,
                    $cell === 'V' => false,
                    $cell === 'Y' && $kind === 'view' => true,
                    $cell === 'N' && $kind === 'view' => false,
                    default => $cell === 'Y',
                };
                $r = $clients[$label]->req($p[0], $p[1], $p[2]);
                $got = !$refused($r['code'], $r['body']) && $r['code'] < 500;
                check(sprintf('%s: %s %s -> %s', $label, $p[0], strtok($p[1], '?'), $want ? 'allowed' : 'refused'),
                    $got === $want && $r['code'] < 500, $r['code'] . ' ' . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 90));
            }
        }
    }
    echo "\n=== Rows of Table 8 (Administrator) with a single probe ===\n";
    $r = $clients['Guidance Counselor']->req('POST', '/api/students.php', ['type' => 'resetAssessment', 'userId' => 0]);
    check('Guidance Counselor cannot reset a student assessment (Administrator only)', $refused($r['code']), (string) $r['code']);
    $r = $clients['Administrator']->req('POST', '/api/students.php', ['type' => 'resetAssessment', 'userId' => 0]);
    check('Administrator gets past the check for resetting an assessment', !$refused($r['code']) && $r['code'] < 500, (string) $r['code']);
    // Observation only (decision D10): who activates and deactivates student accounts. Printed, not counted.
    $r = $clients['Guidance Counselor']->req('POST', '/api/students.php', ['type' => 'toggleActive', 'userId' => 0]);
    echo "  INFO: Guidance Counselor, deactivate/activate a student account -> HTTP {$r['code']} (" . ($refused($r['code']) ? 'refused' : 'allowed') . ")\n";
} finally {
    foreach ($ids as $id) {
        foreach (['DELETE FROM announcements WHERE created_by = ?', 'DELETE FROM audit_log WHERE actor_user_id = ?', 'DELETE FROM sessions WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $pdo->prepare($sql)->execute([$id]); } catch (PDOException $e) { echo "  cleanup note: " . $e->getMessage() . "\n"; }
        }
    }
}
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
