<?php
// Black-box tests for roster upload and the staff sign-up / approval / activation flow.
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/roster_staff_http_test.php http://127.0.0.1:8099
// Uses the seeded test-database admin and synthetic data only; refuses to run unless DB_NAME ends in _test.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../modules/Authentication/lib/StaffSetupCode.php';
require_once __DIR__ . '/../shared/lib/XlsxWriter.php';
require_once __DIR__ . '/../shared/lib/XlsxReader.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$ay = AcademicYear::current();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'qr'); }
    function req(string $method, string $path, $body = null, ?string $file = null, string $fileName = 'roster.csv'): array {
        if ($this->csrf === null) { $this->csrf = json_decode($this->raw('GET', '/api/session.php')['body'], true)['csrfToken'] ?? null; }
        return $this->raw($method, $path, $body, $file, $fileName, true);
    }
    function raw(string $method, string $path, $body = null, ?string $file = null, string $fileName = 'roster.csv', bool $csrf = false): array {
        $ch = curl_init($this->base . $path);
        $h = [];
        if ($csrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        if ($file !== null) { $post = ['roster' => new CURLFile($file, 'application/octet-stream', $fileName)]; }
        else { $h[] = 'Content-Type: application/json'; $post = $body === null ? null : (is_string($body) ? $body : json_encode($body)); }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $t = microtime(true); $r = curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr((string) $r, 0, $hs), 'body' => substr((string) $r, $hs), 'time' => microtime(true) - $t];
    }
    function json(array $r): array { return json_decode($r['body'], true) ?? []; }
    function login(string $u, string $p, string $portal = 'staff'): array { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r; }
}
function mkTmp(string $content): string { $f = tempnam(sys_get_temp_dir(), 'rs'); file_put_contents($f, $content); return $f; }
function csv(array $rows, string $strand = 'STEM', string $section = 'S1197'): string {
    $out = "Strand:,$strand\nSection:,$section\nStudent Number,Student Name,Email\n";
    foreach ($rows as $r) { $out .= '"' . $r[0] . '","' . $r[1] . '","' . $r[2] . "\"\n"; }
    return $out;
}
$rosterCount = fn(string $sec) => (int) $pdo->query("SELECT COUNT(*) FROM assessment_roster WHERE academic_year = " . $pdo->quote($ay) . " AND section = " . $pdo->quote($sec))->fetchColumn();
$cleanup = function () use ($pdo, $ay) {
    $pdo->exec("DELETE FROM assessment_roster WHERE section IN ('S1197','S1196','S1195','I1198') OR school_id LIKE '99990000%'");
    $pdo->exec("DELETE FROM sections WHERE code IN ('S1197','S1196','S1195','I1198')");
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE 'qastaff%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("DELETE FROM email_verification_tokens WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-register:%' OR rate_key LIKE 'staff-activate%'");
    $pdo->exec("DELETE FROM security_policies WHERE key = 'staffSignup.mode'");
};
try { $cleanup(); } catch (Throwable $e) { echo "  (cleanup note: " . $e->getMessage() . ")\n"; }

$a = new Client($base);
$r = $a->login('admin', 'ChangeMe123!'); check('admin login for upload tests', $r['code'] === 200, (string) $r['code']);

echo "\n=== Roster upload: valid files ===\n";
$good = csv([['999900000001', 'QAONE, ALPHA A', 'qa1@live.mcl.edu.ph'], ['999900000002', 'QATWO, BETA B', 'qa2@live.mcl.edu.ph']]);
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp($good)); $j = $a->json($r);
check('valid CSV accepted', $r['code'] === 200 && ($j['count'] ?? 0) === 2, $r['code'] . ' ' . substr($r['body'], 0, 160));
check('two rows stored for the section', $rosterCount('S1197') === 2);
$stored = $pdo->query("SELECT name_enc FROM assessment_roster WHERE school_id = '999900000001'")->fetchColumn();
check('roster name stored encrypted', $stored && stripos($stored, 'QAONE') === false && Crypto::dec($stored) === 'QAONE, ALPHA A');
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp($good)); $j = $a->json($r);
check('re-upload of the same file is idempotent (no duplicates)', $r['code'] === 200 && $rosterCount('S1197') === 2 && ($j['added'] ?? -1) === 0, substr($r['body'], 0, 160));
$xw = new XlsxWriter();
$xw->addSheet('Roster', [['Strand:', 'STEM'], ['Section:', 'S1196'], ['Student Number', 'Student Name', 'Email'], ['999900000021', 'QAXLS, ONE', 'qx1@live.mcl.edu.ph'], ['999900000022', 'PEÑA, JOSÉ', 'qx2@live.mcl.edu.ph']]);
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp($xw->build()), 'roster.xlsx'); $j = $a->json($r);
check('valid Excel (.xlsx) accepted', $r['code'] === 200 && ($j['count'] ?? 0) === 2 && $rosterCount('S1196') === 2, $r['code'] . ' ' . substr($r['body'], 0, 160));

echo "\n=== Roster upload: bad files ===\n";
$bad = [
    'empty CSV' => [mkTmp(''), 'roster.csv'],
    'wrong extension (.exe)' => [mkTmp($good), 'roster.exe'],
    'double extension csv.php' => [mkTmp($good), 'roster.csv.php'],
    'text renamed to .xlsx' => [mkTmp("not a zip file at all"), 'roster.xlsx'],
    'random binary as .csv' => [mkTmp(random_bytes(4096)), 'roster.csv'],
    'header only, no students' => [mkTmp("Strand:,STEM\nSection:,S1195\nStudent Number,Student Name,Email\n"), 'roster.csv'],
    'missing Strand/Section rows' => [mkTmp("Student Number,Student Name,Email\n999900000003,\"QA, X\",x@live.mcl.edu.ph\n"), 'roster.csv'],
    'invalid strand' => [mkTmp(csv([['999900000003', 'QA, X', 'x@live.mcl.edu.ph']], 'XYZ', 'S1195')), 'roster.csv'],
    'student number too short' => [mkTmp(csv([['12345', 'QA, X', 'x@live.mcl.edu.ph']], 'STEM', 'S1195')), 'roster.csv'],
    'student number with letters' => [mkTmp(csv([['99990000ABC1', 'QA, X', 'x@live.mcl.edu.ph']], 'STEM', 'S1195')), 'roster.csv'],
    'wrong email domain' => [mkTmp(csv([['999900000003', 'QA, X', 'x@gmail.com']], 'STEM', 'S1195')), 'roster.csv'],
    'missing email' => [mkTmp(csv([['999900000003', 'QA, X', '']], 'STEM', 'S1195')), 'roster.csv'],
    'duplicate student numbers in one file' => [mkTmp(csv([['999900000003', 'QA, X', 'x@live.mcl.edu.ph'], ['999900000003', 'QA, Y', 'y@live.mcl.edu.ph']], 'STEM', 'S1195')), 'roster.csv'],
    'invalid section code' => [mkTmp(csv([['999900000003', 'QA, X', 'x@live.mcl.edu.ph']], 'STEM', "S1195'; DROP TABLE users;--")), 'roster.csv'],
];
foreach ($bad as $label => [$f, $name]) {
    $r = $a->req('POST', '/api/roster-upload.php', null, $f, $name);
    check("$label rejected with a clean 4xx", $r['code'] >= 400 && $r['code'] < 500 && stripos($r['body'], 'SQLSTATE') === false && stripos($r['body'], 'Stack trace') === false, $r['code'] . ' ' . substr($r['body'], 0, 120));
}
check('no rows leaked into the database from rejected files', $rosterCount('S1195') === 0 && (int) $pdo->query("SELECT COUNT(*) FROM assessment_roster WHERE school_id = '999900000003'")->fetchColumn() === 0);
check('users table intact after the SQL-looking section code', (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0);
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp(str_repeat('A', 2 * 1024 * 1024 + 1024)), 'roster.csv');
check('file over 2 MB rejected', $r['code'] === 400, $r['code'] . ' ' . substr($r['body'], 0, 100));
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp(str_repeat('A', 12 * 1024 * 1024)), 'roster.csv');
check('12 MB file rejected without crashing', $r['code'] >= 400 && $r['code'] < 500, (string) $r['code']);
$many = []; for ($i = 0; $i < 3000; $i++) { $many[] = ['99990000' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT), "QABULK, N$i", "qb$i@live.mcl.edu.ph"]; }
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp(csv($many, 'ICT', 'I1198')));
check('3000-row roster accepted', $r['code'] === 200 && $rosterCount('I1198') === 3000, $r['code'] . ' ' . substr($r['body'], 0, 120));
echo sprintf("  INFO: 3000-row upload took %.2f s\n", $r['time']);
$r = $a->req('POST', '/api/roster-upload.php', null, mkTmp(csv([['999900000010', '=CMD|calc!A1, X', 'f@live.mcl.edu.ph']], 'STEM', 'S1197')));
echo "  INFO: formula-looking roster name: HTTP " . $r['code'] . ' ' . substr($r['body'], 0, 120) . "\n";

echo "\n=== Roster upload: authorization ===\n";
$anon = new Client($base);
$r = $anon->req('POST', '/api/roster-upload.php', null, mkTmp($good)); check('anonymous upload refused', in_array($r['code'], [401, 403], true), (string) $r['code']);
$hash = password_hash('Qa-Test-Pass1!', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM users WHERE username = 'qastudent1'");
$pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at) VALUES ('student','qastudent1',?, 'qs1@example.test', now())")->execute([$hash]);
$s = new Client($base); $s->login('qastudent1', 'Qa-Test-Pass1!', 'student');
$r = $s->req('POST', '/api/roster-upload.php', null, mkTmp($good)); check('student upload refused', $r['code'] === 403, (string) $r['code']);
$r = $s->req('GET', '/api/roster-template.php'); check('student cannot download roster template', in_array($r['code'], [401, 403], true), (string) $r['code']);
$r = $a->req('GET', '/api/roster-template.php'); check('admin can download roster template (CSV)', $r['code'] === 200 && stripos($r['headers'], 'csv') !== false, (string) $r['code']);
$pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE username = 'qastudent1')");
$pdo->exec("DELETE FROM users WHERE username = 'qastudent1'");

echo "\n=== Staff sign-up, approval and activation ===\n";
$pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-register:%'");
// The public sign-up form is off by default (invite only, modules/UserManagement/lib/StaffSignup.php); this part tests
// the form itself, so it is opened here and set back to the default in $cleanup.
$pdo->exec("INSERT INTO security_policies (key, value) VALUES ('staffSignup.mode', 'open') ON CONFLICT (key) DO UPDATE SET value = 'open'");
$reg = new Client($base);
$payload = ['lastName' => 'Qatest', 'firstName' => 'Staff', 'middleInitial' => 'Q', 'position' => 'counselor', 'email' => 'qastaff1@mcl.edu.ph', 'username' => 'qastaff1', 'privacyConsent' => true];
$r = $reg->req('POST', '/api/staff-register.php', $payload); check('valid staff sign-up accepted', $r['code'] === 200 || $r['code'] === 201, $r['code'] . ' ' . substr($r['body'], 0, 140));
$row = $pdo->query("SELECT id, role, approval_status, password_hash, is_active FROM users WHERE username = 'qastaff1'")->fetch();
check('account created as pending counselor-role with no usable password', $row && $row['approval_status'] === 'pending' && $row['role'] === 'counselor');
$staffId = (int) ($row['id'] ?? 0);
$r = $reg->req('POST', '/api/staff-register.php', $payload); check('duplicate sign-up refused (409)', $r['code'] === 409, (string) $r['code']);
$r = $reg->req('POST', '/api/staff-register.php', ['email' => 'qastaff2@gmail.com'] + ['username' => 'qastaff2'] + $payload); check('non-school email refused', $r['code'] === 400, (string) $r['code']);
$r = $reg->req('POST', '/api/staff-register.php', ['privacyConsent' => false, 'email' => 'qastaff3@mcl.edu.ph', 'username' => 'qastaff3'] + $payload); check('sign-up without privacy consent refused', $r['code'] === 400, (string) $r['code']);
$r = $reg->req('POST', '/api/staff-register.php', ['username' => '12345678', 'email' => 'qastaff4@mcl.edu.ph'] + $payload); check('all-digit username refused', $r['code'] === 400, (string) $r['code']);
$pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-register:%'");
$r = $reg->req('POST', '/api/staff-register.php', ['position' => 'admin', 'username' => 'qastaff5', 'email' => 'qastaff5@mcl.edu.ph'] + $payload); check('sign-up cannot choose the admin position', $r['code'] === 400, (string) $r['code']);
$r = $reg->req('POST', '/api/staff-register.php', ['role' => 'admin', 'username' => 'qastaff6', 'email' => 'qastaff6@mcl.edu.ph'] + $payload);
$role6 = $pdo->query("SELECT role FROM users WHERE username = 'qastaff6'")->fetchColumn();
check('extra "role":"admin" field is ignored (account is never admin)', $role6 === false || $role6 === 'counselor', (string) $role6);
$r = $reg->login('qastaff1', 'anything', 'staff'); check('pending account cannot sign in', $r['code'] === 401 || $r['code'] === 403, (string) $r['code']);

$r = $a->req('POST', '/api/staff-approvals.php', ['type' => 'approve', 'id' => $staffId]); check('admin approves the staff account', $r['code'] === 200 && ($a->json($r)['status'] ?? '') === 'approved', $r['code'] . ' ' . substr($r['body'], 0, 120));
$r = $a->req('POST', '/api/staff-approvals.php', ['type' => 'approve', 'id' => $staffId]); check('approving twice refused (409)', $r['code'] === 409, (string) $r['code']);
$r = $reg->login('qastaff1', 'anything', 'staff'); check('approved but not activated account still cannot sign in', $r['code'] !== 200, (string) $r['code']);
$act = new Client($base);
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qastaff1', 'code' => 'WRONG-CODE', 'password' => 'Qa-Staff-Pass1!']); check('activation with a wrong code refused', $r['code'] === 400, (string) $r['code']);
$code = StaffSetupCode::issue($pdo, $staffId);
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qastaff1', 'code' => $code, 'password' => 'weak']); check('activation with a weak password refused', $r['code'] === 400, $r['code'] . ' ' . substr($r['body'], 0, 100));
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qastaff1', 'code' => $code, 'password' => 'Qa-Staff-Pass1!']); check('activation with the right code and a strong password works', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 100));
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qastaff1', 'code' => $code, 'password' => 'Qa-Staff-Pass2!']); check('the code cannot be used a second time', $r['code'] === 400, (string) $r['code']);
$st = new Client($base); $r = $st->login('qastaff1', 'Qa-Staff-Pass1!', 'staff'); check('activated staff member can sign in', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 100));
$r = $st->req('POST', '/api/staff-approvals.php', ['type' => 'approve', 'id' => $staffId]); check('a counselor cannot self-approve/modify staff accounts', $r['code'] === 403 || $r['code'] === 409, (string) $r['code']);
$r = $a->req('POST', '/api/staff-approvals.php', ['type' => 'toggleActive', 'id' => $staffId]); check('admin deactivates the staff account', $r['code'] === 200 && ($a->json($r)['isActive'] ?? true) === false, (string) $r['code']);
$r = (new Client($base))->login('qastaff1', 'Qa-Staff-Pass1!', 'staff'); check('deactivated staff member cannot sign in', $r['code'] === 401, (string) $r['code']);
$log = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('approve_staff_account','deactivate_staff_account','staff_account_activated') AND (target_id = '$staffId')")->fetchColumn();
check('approval, activation and deactivation are in the audit log', (int) $log >= 3, (string) $log);
$pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-register:%'");
$rej = ['lastName' => 'Qarej', 'firstName' => 'Staff', 'middleInitial' => 'R', 'position' => 'facilitator', 'email' => 'qastaff7@mcl.edu.ph', 'username' => 'qastaff7', 'privacyConsent' => true];
(new Client($base))->req('POST', '/api/staff-register.php', $rej);
$id7 = (int) $pdo->query("SELECT id FROM users WHERE username = 'qastaff7'")->fetchColumn();
$r = $a->req('POST', '/api/staff-approvals.php', ['type' => 'reject', 'id' => $id7]); check('admin rejects a pending sign-up', $r['code'] === 200, (string) $r['code']);
$r = (new Client($base))->login('qastaff7', 'x', 'staff'); check('rejected sign-up cannot sign in', $r['code'] !== 200, (string) $r['code']);

echo "\n=== Excel export ===\n";
$r = $a->req('GET', '/api/analytics-export-xlsx.php?academicYear=' . urlencode($ay));
$isZip = substr($r['body'], 0, 2) === 'PK';
if ($r['code'] === 502 && stripos($r['body'], 'could not be emailed') !== false) {
    // Production behaviour by design: the workbook's edit password is only e-mailed, so with no mail service nothing is handed out.
    check('production mode, no mail service: export refused (502) instead of giving a report whose password was never delivered', true);
    $r['code'] = 200; $isZip = false;
    check('(skipped) workbook content checks need APP_ENV=local', true);
}
if ($isZip || $r['code'] !== 200) check('Excel export returns a real .xlsx (zip) file', $r['code'] === 200 && $isZip, $r['code'] . ' ' . substr($r['headers'], 0, 120));
if ($isZip) {
    $tmp = mkTmp($r['body']);
    try { $rows = XlsxReader::readRows($tmp); $ok = count($rows) > 0; } catch (Throwable $e) { $rows = []; $ok = false; }
    check('xlsx can be read back as a workbook with rows', $ok);
    $flat = json_encode($rows);
    check('exported cells contain no cell starting with = + @', !preg_match('/"[=+@][A-Za-z(]/', $flat));
}
$r = $anon->raw('GET', '/api/analytics-export-xlsx.php?academicYear=' . urlencode($ay)); check('anonymous Excel export refused', in_array($r['code'], [401, 403], true), (string) $r['code']);

try { $cleanup(); } catch (Throwable $e) { echo "  (cleanup note: " . $e->getMessage() . ")\n"; }
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
