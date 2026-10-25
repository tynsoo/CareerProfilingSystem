<?php
// Black-box tests: password reset, email verification, student registration, session timeout.
//   APP_ENV=production DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/auth_flows_http_test.php http://127.0.0.1:8099
// Emails are never sent (no mail key); the test inserts the codes/tokens an email would have carried.
// Run the server with APP_ENV=production to check that no debug code or link is handed out.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../modules/Authentication/lib/PasswordReset.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$ay = AcademicYear::current();
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
    function sessionId(): ?string { return preg_match('/PHPSESSID\s+(\S+)/', (string) file_get_contents($this->jar), $m) ? $m[1] : null; }
}

$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT);
$mkStudentUser = function (string $name, string $email, bool $verified = true) use ($pdo, $hash) {
    $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE username = ?)")->execute([$name]);
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$name]);
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at) VALUES ('student', ?, ?, ?, ?) RETURNING id");
    $s->execute([$name, $hash('Old-Pass-1234!'), $email, $verified ? date('c') : null]);
    return (int) $s->fetchColumn();
};
$giveCode = function (int $uid, string $code, string $portal = 'student', string $expires = "NOW() + INTERVAL '10 minutes'") use ($pdo) {
    $pdo->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$uid]);
    $pdo->prepare("INSERT INTO password_reset_codes (user_id, portal, code_hash, expires_at) VALUES (?, ?, ?, $expires)")->execute([$uid, $portal, PasswordReset::hashCode($uid, $code)]);
};
$clearLimits = fn() => $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'pwreset%' OR rate_key LIKE 'resend-verification:%' OR rate_key LIKE 'register%' OR rate_key LIKE 'verify-mail:%'");
$cleanup = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE 'qaflow%' OR username LIKE '99991111%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['password_reset_codes', 'email_verification_tokens'] as $t) { $pdo->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$id]); }
        $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    $pdo->exec("DELETE FROM assessment_roster WHERE school_id LIKE '99991111%'");
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'pwreset%' OR rate_key LIKE 'resend-verification:%'");
};
$cleanup();
$env = $pdo->query("SELECT 1")->fetchColumn(); // connection check
$prod = false; $probe = new Client($base);

echo "=== Password reset (student portal) ===\n";
$uid = $mkStudentUser('qaflow1', 'qaflow1@live.mcl.edu.ph');
$p = new Client($base); $clearLimits();
$real = $p->req('POST', '/api/password-reset.php', ['action' => 'request', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph']);
$none = $p->req('POST', '/api/password-reset.php', ['action' => 'request', 'portal' => 'student', 'email' => 'nobody-here@live.mcl.edu.ph']);
$rj = $p->json($real); $nj = $p->json($none);
check('request for a real and an unknown email both succeed', $real['code'] === 200 && $none['code'] === 200, $real['code'] . '/' . $none['code']);
check('both responses have the same fields except a local-only debug code', array_diff(array_keys($rj), ['debugCode']) === array_diff(array_keys($nj), ['debugCode']));
echo "  INFO: debugCode " . (isset($rj['debugCode']) ? 'IS' : 'is NOT') . " returned for a real account (server APP_ENV decides; must be absent in production)\n";
$prodMode = !isset($rj['debugCode']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'request', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph']);
check('second immediate request hits the 60 s cooldown (429)', $r['code'] === 429, (string) $r['code']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'request', 'portal' => 'student', 'email' => 'not-an-email']); check('invalid email rejected', $r['code'] === 400, (string) $r['code']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'request', 'portal' => 'hacker', 'email' => 'qaflow1@live.mcl.edu.ph']); check('unknown portal rejected', $r['code'] === 400, (string) $r['code']);

$clearLimits(); $giveCode($uid, '123456');
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '12345']); check('short code rejected', $r['code'] === 400);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '000000']); $j = $p->json($r);
check('wrong code: 400 with attempts left = 4', $r['code'] === 400 && ($j['attemptsLeft'] ?? 0) === 4, $r['body']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'staff', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '123456']); check('student code does not work on the staff portal', $r['code'] === 400, (string) $r['code']);
for ($i = 0; $i < 4; $i++) { $r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '00000' . $i]); }
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '123456']); $j = $p->json($r);
check('after 5 wrong tries even the correct code is refused (locked)', $r['code'] === 400 && ($j['reason'] ?? '') === 'locked', $r['body']);

$clearLimits(); $giveCode($uid, '222222', 'student', "NOW() - INTERVAL '1 minute'");
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '222222']); $j = $p->json($r);
check('expired code refused', $r['code'] === 400 && ($j['reason'] ?? '') === 'expired', $r['body']);

$clearLimits(); $giveCode($uid, '654321');
$old = new Client($base); $old->login('qaflow1', 'Old-Pass-1234!', 'student');
check('old password signs in before the reset', $old->req('GET', '/api/my-account.php')['code'] === 200);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '654321']); $j = $p->json($r);
check('correct code returns a reset token', $r['code'] === 200 && preg_match('/^[0-9a-f]{64}$/', $j['resetToken'] ?? '') === 1, $r['body']);
$token = $j['resetToken'] ?? '';
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => $token, 'password' => 'weak']); check('weak new password refused (policy)', $r['code'] === 400 && ($p->json($r)['reason'] ?? '') === 'policy', $r['body']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'staff', 'resetToken' => $token, 'password' => 'New-Pass-5678!']); check('token from the student portal is useless on the staff portal', $r['code'] === 400, (string) $r['code']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => $token, 'password' => 'Old-Pass-1234!']); check('reusing the current password is refused', $r['code'] === 400 && ($p->json($r)['reason'] ?? '') === 'reuse', $r['body']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => $token, 'password' => 'New-Pass-5678!']); check('valid reset succeeds', $r['code'] === 200, $r['body']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => $token, 'password' => 'Another-Pass-9!']); check('the token cannot be used twice', $r['code'] === 400, (string) $r['code']);
check('a session open before the reset is signed out', $old->req('GET', '/api/my-account.php')['code'] === 401);
$r = (new Client($base))->login('qaflow1', 'Old-Pass-1234!', 'student'); check('old password no longer works', $r['code'] === 401, (string) $r['code']);
$r = (new Client($base))->login('qaflow1', 'New-Pass-5678!', 'student'); check('new password works', $r['code'] === 200, (string) $r['code']);
$stored = $pdo->query("SELECT password_hash FROM users WHERE username = 'qaflow1'")->fetchColumn();
check('password stored as bcrypt, not plaintext', str_starts_with($stored, '$2y$') && stripos($stored, 'New-Pass') === false);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => str_repeat('a', 64), 'password' => 'New-Pass-5678!']); check('guessed token refused', $r['code'] === 400, (string) $r['code']);
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => "' OR 1=1 --", 'password' => 'New-Pass-5678!']); check('SQL text as token refused cleanly', $r['code'] === 400 && stripos($r['body'], 'SQLSTATE') === false);

echo "\n=== Password reset: lockout cleared, unverified account ===\n";
$pdo->exec("UPDATE users SET failed_login_attempts = 3, locked_until = NOW() + INTERVAL '10 minutes' WHERE username = 'qaflow1'");
$clearLimits(); $giveCode($uid, '111222');
$j = $p->json($p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow1@live.mcl.edu.ph', 'code' => '111222']));
$p->req('POST', '/api/password-reset.php', ['action' => 'reset', 'portal' => 'student', 'resetToken' => $j['resetToken'] ?? '', 'password' => 'Third-Pass-4321!']);
check('a successful reset also clears a lockout', (new Client($base))->login('qaflow1', 'Third-Pass-4321!', 'student')['code'] === 200);
$uid2 = $mkStudentUser('qaflow2', 'qaflow2@live.mcl.edu.ph', false); $clearLimits(); $giveCode($uid2, '333444');
$r = $p->req('POST', '/api/password-reset.php', ['action' => 'verify', 'portal' => 'student', 'email' => 'qaflow2@live.mcl.edu.ph', 'code' => '333444']);
check('an account whose email is not verified cannot use password reset', $r['code'] === 400, $r['body']);

echo "\n=== Student registration and email verification ===\n";
$clearLimits();
$num = '999911110001';
$pdo->prepare("INSERT INTO assessment_roster (academic_year, school_id, name_enc, strand, section, email) VALUES (?,?,?,?,?,?) ON CONFLICT (academic_year, school_id) DO NOTHING")
    ->execute([$ay, $num, Crypto::enc('QAFLOW, TESTER T'), 'STEM', 'S1194', 'qaflowreg@live.mcl.edu.ph']);
$reg = new Client($base);
$good = ['schoolId' => $num, 'email' => 'qaflowreg@live.mcl.edu.ph', 'privacyConsent' => true];
$attackerPw = 'Attacker-Pass-1!'; $ownerPw = 'Owner-Pass-5678!';
// After the format checks every outcome gets the same answer (no roster or registration status, no email hint).
$sameAnswer = fn(array $r) => $r['code'] === 200 && (json_decode($r['body'], true) ?? []) === ['success' => true];
$r = $reg->req('POST', '/api/register.php', ['schoolId' => '999911119999'] + $good); check('Student Number not on the roster: same answer as a real registration (200)', $sameAnswer($r), $r['code'] . ' ' . substr($r['body'], 0, 90));
$r = $reg->req('POST', '/api/register.php', ['email' => 'someoneelse@live.mcl.edu.ph'] + $good); check('email different from the roster email: same answer, no hint of the email on file', $sameAnswer($r) && strpos($r['body'], '@') === false, $r['code'] . ' ' . substr($r['body'], 0, 90));
check('no account was made for the wrong email', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = '$num'")->fetchColumn() === 0);
$r = $reg->req('POST', '/api/register.php', ['privacyConsent' => false] + $good); check('missing privacy consent refused', $r['code'] === 400, (string) $r['code']);
$r = $reg->req('POST', '/api/register.php', ['schoolId' => "1' OR '1'='1"] + $good); check('SQL text as Student Number refused cleanly', $r['code'] >= 400 && $r['code'] < 500 && stripos($r['body'], 'SQLSTATE') === false, (string) $r['code']);
$r = $reg->req('POST', '/api/register.php', ['password' => $attackerPw] + $good); $j = $reg->json($r);
check('valid registration succeeds (a password sent along is ignored)', $r['code'] === 200 && ($j['success'] ?? false) === true, $r['code'] . ' ' . substr($r['body'], 0, 120));
check('the answer holds no name, strand or section from the roster', !isset($j['name']) && !isset($j['strand']) && !isset($j['section']), substr($r['body'], 0, 120));
echo "  INFO: registration response " . (isset($j['verifyLink']) ? 'INCLUDES' : 'does not include') . " the verification link when the email could not be sent\n";
check('the verification link is NOT handed out in the HTTP response when email is not delivered (production)', !isset($j['verifyLink']) || !$prodMode, 'verifyLink present');
$regUser = $pdo->query("SELECT id, email_verified_at, password_hash FROM users WHERE username = '$num'")->fetch();
check('new student account starts unverified', $regUser && $regUser['email_verified_at'] === null);
check('the password typed at registration was NOT stored (nobody can sign in with it)', !password_verify($attackerPw, (string) $regUser['password_hash']));
$fn = $pdo->query("SELECT first_name_enc FROM students WHERE school_id = '$num'")->fetchColumn();
check('student name copied from the roster and stored encrypted', $fn && Crypto::dec($fn) === 'TESTER T');
$r = $reg->req('POST', '/api/register.php', $good); check('registering the same Student Number again: same answer, no second account', $sameAnswer($r) && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = '$num'")->fetchColumn() === 1, $r['code'] . ' ' . substr($r['body'], 0, 90));
$r = (new Client($base))->login($num, $attackerPw, 'student'); check('unverified student cannot sign in (401, same answer as a wrong password)', $r['code'] === 401 && (json_decode($r['body'], true) ?? []) === ['success' => false, 'error' => 'Invalid username or password.'], $r['code'] . ' ' . substr($r['body'], 0, 90));

$mkToken = function (int $uid, string $raw, string $expires = "NOW() + INTERVAL '48 hours'") use ($pdo) {
    $pdo->prepare("INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, $expires)")->execute([$uid, hash('sha256', $raw)]);
};
$uid3 = (int) $regUser['id'];
$ver = new Client($base);
$r = $ver->req('POST', '/api/verify-email.php', ['token' => '']); check('empty verification token refused', $r['code'] === 400);
$r = $ver->req('POST', '/api/verify-email.php', ['token' => str_repeat('0', 64)]); check('made-up verification token refused', $r['code'] === 400);
$r = $ver->req('GET', '/api/verify-email.php?token=x'); check('verification by plain GET is not allowed (prefetch-safe)', $r['code'] === 405, (string) $r['code']);
$mkToken($uid3, 'expiredtoken-qa', "NOW() - INTERVAL '1 minute'");
$r = $ver->req('POST', '/api/verify-email.php', ['token' => 'expiredtoken-qa']); check('expired verification token refused', $r['code'] === 400, (string) $r['code']);
$r = $ver->req('POST', '/api/resend-verification.php', ['username' => $num]); $j1 = $ver->json($r);
$r2 = $ver->req('POST', '/api/resend-verification.php', ['username' => 'no-such-account']); $j2 = $ver->json($r2);
check('resend answers the same for a real and an unknown account', $r['code'] === 200 && $r2['code'] === 200 && ($j1['message'] ?? 1) === ($j2['message'] ?? 2));
$mkToken($uid3, 'goodtoken-qa');
$r = $ver->req('POST', '/api/verify-email.php', ['token' => 'goodtoken-qa']); check('a student link without a new password does not activate the account (needsPassword)', $r['code'] === 400 && ($ver->json($r)['needsPassword'] ?? false) === true, $r['body']);
$r = $ver->req('POST', '/api/verify-email.php', ['token' => 'goodtoken-qa', 'password' => 'weak']); check('a weak password at verification is refused and the link stays usable', $r['code'] === 400 && ($ver->json($r)['needsPassword'] ?? false) === true, $r['body']);
check('account still unverified after the refused attempts', $pdo->query("SELECT email_verified_at FROM users WHERE username = '$num'")->fetchColumn() === null);
$r = $ver->req('POST', '/api/verify-email.php', ['token' => 'goodtoken-qa', 'password' => $ownerPw]); check('valid token plus a strong password activates the account', $r['code'] === 200, $r['body']);
$r = $ver->req('POST', '/api/verify-email.php', ['token' => 'goodtoken-qa', 'password' => 'Second-Pass-9!']); check('verification token is single use', $r['code'] === 400, (string) $r['code']);
$r = (new Client($base))->login($num, $attackerPw, 'student'); check('TAKEOVER CHECK: the password typed at registration does not work after the owner verifies', $r['code'] === 401, (string) $r['code']);
$r = (new Client($base))->login($num, $ownerPw, 'student'); check('the password chosen when verifying works', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 90));
$hs = $pdo->query("SELECT password_hash FROM users WHERE username = '$num'")->fetchColumn(); check('chosen password stored as bcrypt', str_starts_with($hs, '$2y$') && stripos($hs, 'Owner-Pass') === false);

echo "\n=== Session timeout ===\n";
$orig = $pdo->query("SELECT value FROM security_policies WHERE key = 'timeoutMinutes'")->fetchColumn();
$origEn = $pdo->query("SELECT value FROM security_policies WHERE key = 'sessionTimeoutEnabled'")->fetchColumn();
$setPol = fn(string $k, string $v) => $pdo->prepare("INSERT INTO security_policies (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([$k, $v]);
$backdate = function (Client $c, int $secondsAgo) use ($pdo) {
    $sid = $c->sessionId(); if (!$sid) { return false; }
    $data = $pdo->prepare("SELECT data FROM sessions WHERE id = ?"); $data->execute([$sid]); $d = (string) $data->fetchColumn();
    $new = preg_replace('/lastActivity\|i:\d+;/', 'lastActivity|i:' . (time() - $secondsAgo) . ';', $d, 1, $n);
    $pdo->prepare("UPDATE sessions SET data = ? WHERE id = ?")->execute([$new, $sid]);
    return $n === 1;
};
$setPol('sessionTimeoutEnabled', 'true'); $setPol('timeoutMinutes', '5');
$s = new Client($base); $s->login($num, $ownerPw, 'student');
check('fresh session is valid', $s->req('GET', '/api/my-account.php')['code'] === 200);
check('(test setup) last-activity time moved back 4 minutes', $backdate($s, 240));
check('idle 4 minutes with a 5 minute limit: still signed in', $s->req('GET', '/api/my-account.php')['code'] === 200);
check('(test setup) last-activity time moved back 6 minutes', $backdate($s, 360));
check('idle 6 minutes with a 5 minute limit: signed out (401)', $s->req('GET', '/api/my-account.php')['code'] === 401);
check('and the session stays dead afterwards', $s->req('GET', '/api/my-account.php')['code'] === 401);
$s2 = new Client($base); $s2->login($num, $ownerPw, 'student'); $backdate($s2, 3600); $setPol('sessionTimeoutEnabled', 'false');
check('with the timeout switched off an old session is still valid', $s2->req('GET', '/api/my-account.php')['code'] === 200);
$setPol('sessionTimeoutEnabled', $origEn === false ? 'true' : (string) $origEn); $setPol('timeoutMinutes', $orig === false ? '30' : (string) $orig);
echo "  INFO: the Security Configuration API clamps the timeout to 5-240 minutes (modules/SecurityConfiguration/api/security-config.php)\n";

$cleanup();
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
