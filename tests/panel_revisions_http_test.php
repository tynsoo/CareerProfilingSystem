<?php
// Black-box tests for the panel revisions: staff sign-up and invitations (S1), no access before approval and activation
// (S2), pasted access codes (S3), counseling booking status (S11), the escalation process with outcomes (S12),
// resolved at / resolved by and the History of closed flags (S13), logged refusals (S14), the shuffled question order (S17), and the new FAQ entries (S18).
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/panel_revisions_http_test.php http://127.0.0.1:8099
// Emails are never sent (no mail key); the test issues the codes an email would have carried.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/Authentication/lib/StaffSetupCode.php';
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

$hash = fn(string $p) => password_hash($p, PASSWORD_BCRYPT);
$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'qarev%' OR username LIKE 'qarev%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM counseling_invites WHERE student_id = ? OR invited_by = ?')->execute([$id, $id]);
        $pdo->prepare('UPDATE monitoring_flags SET resolved_by = NULL WHERE resolved_by = ?')->execute([$id]);
        $pdo->prepare('UPDATE monitoring_flags SET escalated_by = NULL, counselor_id = NULL WHERE escalated_by = ? OR counselor_id = ?')->execute([$id, $id]);
        foreach (['monitoring_flags' => 'student_id', 'students' => 'user_id', 'email_verification_tokens' => 'user_id', 'consent_records' => 'user_id', 'audit_log' => 'actor_user_id'] as $t => $col) {
            $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE 'staff-%' OR rate_key LIKE 'counseling-invite:%' OR rate_key LIKE 'login-ip:%' OR rate_key LIKE 'denied-log:%'");
    $pdo->exec("DELETE FROM security_policies WHERE key = 'staffSignup.mode'");
};
$cleanup();
CounselingTracking::ensureSchema($pdo);
$mkStaff = function (string $username, string $position, string $password = 'Staff-Pass-1234!') use ($pdo, $hash): int {
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, staff_position, is_active, approval_status)
                        VALUES ('counselor', ?, ?, ?, NOW(), ?, ?, TRUE, 'approved') RETURNING id");
    $s->execute([$username, $hash($password), "$username@mcl.edu.ph", "Qa, $username", $position]);
    return (int) $s->fetchColumn();
};

$admin = new Client($base);
check('admin signs in', $admin->login('admin', 'ChangeMe123!', 'staff')['code'] === 200);

echo "=== S1: staff sign-up is invite only by default ===\n";
$anon = new Client($base);
$r = $anon->json($anon->raw('GET', '/api/staff-register.php'));
check('the sign-up page is told sign-up is closed', ($r['open'] ?? null) === false && !empty($r['message']));
$signup = ['lastName' => 'Rev', 'firstName' => 'Qa', 'middleInitial' => '', 'position' => 'counselor', 'email' => 'qarevself@mcl.edu.ph', 'username' => 'qarevself', 'privacyConsent' => true];
$r = $anon->req('POST', '/api/staff-register.php', $signup);
check('a sign-up is refused while invite only (403)', $r['code'] === 403 && ($anon->json($r)['closed'] ?? false) === true, (string) $r['code']);
check('no account was created', !$pdo->query("SELECT 1 FROM users WHERE email = 'qarevself@mcl.edu.ph'")->fetchColumn());

$r = $admin->req('POST', '/api/security-config.php', ['type' => 'staffSignup', 'mode' => 'open']);
check('the administrator can open sign-up', $r['code'] === 200 && ($admin->json($r)['settings']['staffSignup'] ?? '') === 'open', $r['body']);
check('the change is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'update_settings' AND target_id = 'staffSignup.mode'")->fetchColumn());
$r = $anon->req('POST', '/api/staff-register.php', $signup);
check('with open sign-up the form works', $r['code'] === 200, $r['body']);
$row = $pdo->query("SELECT approval_status FROM users WHERE email = 'qarevself@mcl.edu.ph'")->fetch();
check('the account starts pending', ($row['approval_status'] ?? '') === 'pending');
$admin->req('POST', '/api/security-config.php', ['type' => 'staffSignup', 'mode' => 'invite']);

echo "\n=== S1: invite staff (administrator or co-admin) ===\n";
$r = $admin->req('POST', '/api/staff-approvals.php', ['type' => 'invite', 'lastName' => 'Rev', 'firstName' => 'Invited', 'middleInitial' => 'Q', 'email' => 'qarevinvite@gmail.com', 'position' => 'counselor']);
check('a personal email is refused', $r['code'] === 400);
$r = $admin->req('POST', '/api/staff-approvals.php', ['type' => 'invite', 'lastName' => 'Rev', 'firstName' => 'Invited', 'middleInitial' => 'Q', 'email' => 'qarevinvite@mcl.edu.ph', 'position' => 'admin']);
check('the position can only be counselor or facilitator', $r['code'] === 400);
$r = $admin->req('POST', '/api/staff-approvals.php', ['type' => 'invite', 'lastName' => 'Rev', 'firstName' => 'Invited', 'middleInitial' => 'Q', 'email' => 'qarevinvite@mcl.edu.ph', 'position' => 'facilitator']);
$inv = $admin->json($r);
check('the administrator invites a staff member', $r['code'] === 200 && !empty($inv['id']), $r['body']);
$invitedId = (int) ($inv['id'] ?? 0);
$row = $pdo->query("SELECT approval_status, staff_position, email_verified_at FROM users WHERE id = $invitedId")->fetch();
check('the invited account is approved, with the chosen position, and not activated', $row && $row['approval_status'] === 'approved' && $row['staff_position'] === 'facilitator' && $row['email_verified_at'] === null);
check('the invitation is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'invite_staff_account' AND target_id = '$invitedId'")->fetchColumn());
$r = $admin->req('POST', '/api/staff-approvals.php', ['type' => 'invite', 'lastName' => 'Rev', 'firstName' => 'Again', 'middleInitial' => '', 'email' => 'qarevinvite@mcl.edu.ph', 'position' => 'counselor']);
check('the same email cannot be invited twice', $r['code'] === 409);

$counselorId = $mkStaff('qarevcoun', 'counselor');
$coun = new Client($base);
check('a Guidance Counselor signs in', $coun->login('qarevcoun', 'Staff-Pass-1234!', 'staff')['code'] === 200);
$r = $coun->req('POST', '/api/staff-approvals.php', ['type' => 'invite', 'lastName' => 'Rev', 'firstName' => 'Nope', 'middleInitial' => '', 'email' => 'qarevnope@mcl.edu.ph', 'position' => 'counselor']);
check('a counselor who is not a co-admin cannot invite', $r['code'] === 403, (string) $r['code']);

echo "\n=== S2 and S3: activation with a pasted code, then sign-in ===\n";
$code = StaffSetupCode::issue($pdo, $invitedId);
$act = new Client($base);
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qarevinvite@mcl.edu.ph', 'code' => $code, 'password' => 'Invited-Pass-1234!']);
check('activation without the privacy consent is refused', $r['code'] === 400 && str_contains($r['body'], 'Privacy'), $r['body']);
$pasted = "\u{00A0} " . substr($code, 0, 4) . '-' . substr($code, 4) . " \r\n";
$r = $act->req('POST', '/api/staff-activate.php', ['identifier' => 'qarevinvite@mcl.edu.ph', 'code' => $pasted, 'password' => 'Invited-Pass-1234!', 'privacyConsent' => true]);
check('a code pasted with spaces, a dash and a line break is accepted', $r['code'] === 200, $r['body']);
check('the consent receipt is saved at activation', (bool) $pdo->query("SELECT 1 FROM consent_records WHERE user_id = $invitedId")->fetchColumn());
$fac = new Client($base);
check('the activated facilitator signs in', $fac->login('qarevinvite@mcl.edu.ph', 'Invited-Pass-1234!', 'staff')['code'] === 200);

$r = $coun->raw('GET', '/api/session.php');
check('the counselor is signed in before the change', !empty($coun->json($r)['user']));
$pdo->exec("UPDATE users SET approval_status = 'pending' WHERE id = $counselorId");
$r = $coun->raw('GET', '/api/monitoring.php');
check('an account set back to pending loses its open session at once (401)', $r['code'] === 401, (string) $r['code']);
$r = $coun->raw('GET', '/admin-dashboard');
check('and the dashboard sends it to the sign-in page', $r['code'] === 302 && str_contains($r['headers'], 'staff-login'), (string) $r['code']);
$pdo->exec("UPDATE users SET approval_status = 'approved', email_verified_at = NULL WHERE id = $counselorId");
$r = (new Client($base))->login('qarevcoun', 'Staff-Pass-1234!', 'staff');
check('an approved account that was not activated cannot sign in', $r['code'] === 403 && str_contains($r['body'], 'not activated'), $r['body']);
$pdo->exec("UPDATE users SET email_verified_at = NOW() WHERE id = $counselorId");
check('the counselor signs in again once activated', $coun->login('qarevcoun', 'Staff-Pass-1234!', 'staff')['code'] === 200);

echo "\n=== S11, S12, S13: escalation, booking status, outcome, History ===\n";
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status) VALUES ('student', 'qarevstud', 'x', 'qarevstud@live.mcl.edu.ph', NOW(), 'approved') RETURNING id");
$s->execute();
$studentId = (int) $s->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, '99007777', ?, ?, 'STEM', '12', 'QA-R')")
    ->execute([$studentId, Crypto::enc('Qa'), Crypto::enc('Revision')]);
$mkFlag = function () use ($pdo, $studentId): int {
    $f = $pdo->prepare("INSERT INTO monitoring_flags (student_id, reason, status) VALUES (?, 'manual_escalation', 'pending') RETURNING id");
    $f->execute([$studentId]);
    return (int) $f->fetchColumn();
};
$flagId = $mkFlag();
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'dismiss']);
check('dismissing needs a note (kept in History)', $r['code'] === 400, (string) $r['code']);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'escalate']);
check('the counselor escalates the flag', $r['code'] === 200, $r['body']);
$row = $pdo->query("SELECT escalated_at, escalated_by FROM monitoring_flags WHERE id = $flagId")->fetch();
check('escalated at and by are recorded', $row['escalated_at'] !== null && (int) $row['escalated_by'] === $counselorId);

$r = $fac->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'resolve', 'outcome' => 'counseled_kept_choice']);
check('a facilitator cannot close a flag For Counseling', $r['code'] === 403, (string) $r['code']);
$r = $fac->req('POST', '/api/counseling-invite.php', ['studentId' => $studentId]);
check('a facilitator cannot send a counseling invitation', $r['code'] === 403);
check('the refused request is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'access_denied' AND actor_user_id = $invitedId")->fetchColumn());

$admin->req('POST', '/api/security-config.php', ['type' => 'bookingLink', 'url' => 'https://example.com/cgc-booking']);
$r = $coun->req('POST', '/api/counseling-invite.php', ['studentId' => $studentId, 'flagId' => $flagId]);
$inviteId = (int) ($coun->json($r)['inviteId'] ?? 0);
check('the counselor invites the student from the flag', $r['code'] === 200 && $inviteId > 0, $r['body']);
$list = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=counseling'));
$mine = array_values(array_filter($list['rows'] ?? [], fn($x) => $x['flagId'] === $flagId))[0] ?? null;
check('the Counseling Queue shows the invitation as not booked yet', ($mine['invite']['status'] ?? '') === 'invited', json_encode($mine['invite'] ?? null));
$r = $coun->req('POST', '/api/counseling-invite.php', ['type' => 'status', 'inviteId' => $inviteId, 'status' => 'booked', 'sessionDate' => '2026-11-20']);
check('the counselor records that the student booked', $r['code'] === 200, $r['body']);
$r = $coun->req('POST', '/api/counseling-invite.php', ['type' => 'status', 'inviteId' => $inviteId, 'status' => 'done']);
check('an unknown booking status is refused', $r['code'] === 400);
$list = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=counseling'));
$mine = array_values(array_filter($list['rows'] ?? [], fn($x) => $x['flagId'] === $flagId))[0] ?? null;
check('the queue shows Booked with the session date', ($mine['invite']['status'] ?? '') === 'booked' && ($mine['invite']['sessionDate'] ?? '') === '2026-11-20');
check('the booking change is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'update_counseling_status' AND target_id = '$studentId'")->fetchColumn());

$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'approve']);
check('a flag For Counseling is not simply approved', $r['code'] === 409);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'resolve', 'outcome' => 'nonsense']);
check('closing needs a known outcome', $r['code'] === 400);
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flagId, 'action' => 'resolve', 'outcome' => 'counseled_changed_choice', 'note' => 'Talked it through; moving to BS Psychology.']);
check('the counselor closes it with a counseling outcome', $r['code'] === 200, $r['body']);
$row = $pdo->query("SELECT status, outcome, resolved_at, resolved_by, resolution_note_enc FROM monitoring_flags WHERE id = $flagId")->fetch();
check('resolved at, resolved by and the outcome are stored', $row['resolved_at'] !== null && (int) $row['resolved_by'] === $counselorId && $row['outcome'] === 'counseled_changed_choice');
check('the note is stored encrypted', $row['resolution_note_enc'] !== null && !str_contains($row['resolution_note_enc'], 'Psychology'));
check('closing it with a counseling outcome marks the booked invitation Attended (shown as Availed)',
    $pdo->query("SELECT status FROM counseling_invites WHERE id = $inviteId")->fetchColumn() === 'attended'
    && ($coun->json($r)['invite']['status'] ?? '') === 'attended', $r['body']);
check('the invitation change is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'update_counseling_status' AND target_id = '$studentId' AND id = (SELECT MAX(id) FROM audit_log WHERE action = 'update_counseling_status' AND target_id = '$studentId')")->fetchColumn());

$flag2 = $mkFlag();
$r = $coun->req('POST', '/api/monitoring-action.php', ['flagId' => $flag2, 'action' => 'dismiss', 'note' => 'Duplicate after a retake.']);
check('a flag in Needs Review is dismissed with a note', $r['code'] === 200, $r['body']);
check('the dismissed flag is kept, not deleted', (bool) $pdo->query("SELECT 1 FROM monitoring_flags WHERE id = $flag2 AND status = 'dismissed'")->fetchColumn());
$hist = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=history'));
$byId = [];
foreach ($hist['rows'] ?? [] as $x) { $byId[$x['flagId']] = $x; }
check('History lists both closed flags', isset($byId[$flagId], $byId[$flag2]));
check('History shows when, by whom, the outcome and the note', ($byId[$flagId]['resolvedBy'] ?? '') === 'Qa, qarevcoun'
    && !empty($byId[$flagId]['resolvedAt'])
    && ($byId[$flagId]['outcomeLabel'] ?? '') === CounselingTracking::OUTCOMES['counseled_changed_choice']
    && ($byId[$flag2]['resolutionNote'] ?? '') === 'Duplicate after a retake.');
check('the History count is sent', ($hist['summary']['history'] ?? 0) >= 2);
// The Dismissed tab is now History filtered on Dismissed: each row says how it was closed, and old links still work.
check('History says how each flag was closed', ($byId[$flagId]['closedAs'] ?? '') === 'counseling' && ($byId[$flag2]['closedAs'] ?? '') === 'dismissed', json_encode([$byId[$flagId]['closedAs'] ?? null, $byId[$flag2]['closedAs'] ?? null]));
check('History names who dismissed it', ($byId[$flag2]['resolvedBy'] ?? '') === 'Qa, qarevcoun', json_encode($byId[$flag2] ?? null));
$dis = $coun->json($coun->raw('GET', '/api/monitoring.php?tab=dismissed'));
check('an old Dismissed link answers with History', ($dis['tab'] ?? '') === 'history' && count($dis['rows'] ?? []) === count($hist['rows'] ?? []));
check('no separate Dismissed count is sent any more', !isset($dis['summary']['dismissed']) && isset($dis['summary']['history']));

echo "\n=== S14: refused security changes are logged ===\n";
$r = $coun->req('POST', '/api/security-config.php', ['type' => 'settings', 'timeoutMinutes' => 60]);
check('a counselor cannot change the session timeout', $r['code'] === 403);
check('the attempt is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'security_config_denied' AND actor_user_id = $counselorId")->fetchColumn());
$r = $coun->req('GET', '/api/audit-log.php');
check('a counselor who is not a co-admin cannot open the Audit Log (hidden from them)', $r['code'] === 403, (string) $r['code']);
check('that refused request is in the Audit Log too', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'access_denied' AND target_id = 'audit-log.php' AND actor_user_id = $counselorId")->fetchColumn());
$r = $coun->raw('GET', '/audit-log');
check('typing the Audit Log address sends a counselor elsewhere', $r['code'] === 302 && str_contains($r['headers'], 'denied=1'), (string) $r['code']);
check('and that attempt is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'access_denied' AND target_id = 'page:audit-log' AND actor_user_id = $counselorId")->fetchColumn());

echo "\n=== S17: shuffled question order, same scoring ===\n";
$s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status) VALUES ('student', 'qarevshuf', ?, 'qarevshuf@live.mcl.edu.ph', NOW(), 'approved') RETURNING id");
$s->execute([$hash('Qa-Shuffle-1234!')]);
$shufId = (int) $s->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?, 'qarevshuf', ?, ?, 'STEM', '12', 'QA-R')")
    ->execute([$shufId, Crypto::enc('Qa'), Crypto::enc('Shuffle')]);
$stu = new Client($base);
check('a student signs in', $stu->login('qarevshuf', 'Qa-Shuffle-1234!', 'student')['code'] === 200);
$plain = $stu->json($stu->raw('GET', '/api/assessment-questions.php'));
check('by default the questions come grouped by type, with no shuffled order', !empty($plain['questions']) && !isset($plain['displayOrder']), json_encode(array_keys($plain)));
$r = $stu->req('POST', '/api/assessment-questions.php', ['action' => 'setShuffle', 'on' => true]);
check('a student cannot change the question order', $r['code'] === 403, (string) $r['code']);
$r = $admin->req('POST', '/api/assessment-questions.php', ['action' => 'setShuffle', 'on' => true]);
check('the administrator turns on "Shuffled for each student"', $r['code'] === 200 && ($admin->json($r)['shuffle'] ?? null) === true, $r['body']);
check('the change is in the Audit Log', (bool) $pdo->query("SELECT 1 FROM audit_log WHERE action = 'update_question_order' AND target_id = 'assessment.shuffleQuestions'")->fetchColumn());
check('the Question Bank page reads the setting', ($admin->json($admin->raw('GET', '/api/assessment-questions.php?all=1'))['shuffle'] ?? null) === true);
$sh = $stu->json($stu->raw('GET', '/api/assessment-questions.php'));
$order = $sh['displayOrder'] ?? [];
$sorted = $order; sort($sorted);
check('the student gets an order that shows every question once', count($order) === count($sh['questions'] ?? []) && $sorted === range(0, count($order) - 1), json_encode($order));
check('the questions themselves stay in the standard order (answers are scored in that order)', array_column($sh['questions'] ?? [], 'id') === array_column($plain['questions'], 'id'));
check('the same student gets the same order again', ($stu->json($stu->raw('GET', '/api/assessment-questions.php'))['displayOrder'] ?? null) === $order);
$admin->req('POST', '/api/assessment-questions.php', ['action' => 'setShuffle', 'on' => false]);
check('turned off, the questions are grouped by type again', !isset($stu->json($stu->raw('GET', '/api/assessment-questions.php'))['displayOrder']));
$pdo->exec("DELETE FROM security_policies WHERE key = 'assessment.shuffleQuestions'");

echo "\n=== S18: FAQ entries on assessment scheduling and the booking link ===\n";
require_once __DIR__ . '/../modules/Counseling/lib/Faq.php';
$pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([Faq::PANEL_ENTRIES_KEY]);
$panelQs = [];
foreach (Faq::panelEntries() as $aud => $items) { foreach ($items as [$q]) { $panelQs[$aud][] = $q; } }
$pdo->exec("DELETE FROM faqs WHERE question IN ('" . implode("','", array_map(fn($q) => str_replace("'", "''", $q), array_merge(...array_values($panelQs)))) . "')");
$staffFaqs = array_column($admin->json($admin->raw('GET', '/api/faqs.php?audience=staff'))['faqs'] ?? [], 'question');
check('the staff Help Center has the new entries', !array_diff($panelQs['staff'], $staffFaqs), json_encode($staffFaqs));
check('they come after the FAQs that were already there', array_slice($staffFaqs, -count($panelQs['staff'])) === $panelQs['staff']);
$studentFaqs = array_column($stu->json($stu->raw('GET', '/api/faqs.php'))['faqs'] ?? [], 'question');
check('the student Help Center has the new entries', !array_diff($panelQs['student'], $studentFaqs), json_encode($studentFaqs));
check('reading again adds nothing twice', count(array_column($admin->json($admin->raw('GET', '/api/faqs.php?audience=staff'))['faqs'] ?? [], 'question')) === count($staffFaqs));
$oneId = (int) $pdo->query("SELECT id FROM faqs WHERE audience = 'student' AND question = 'What if I miss my assessment session?'")->fetchColumn();
$r = $admin->req('POST', '/api/faqs.php', ['type' => 'delete', 'id' => $oneId]);
$after = array_column($stu->json($stu->raw('GET', '/api/faqs.php'))['faqs'] ?? [], 'question');
check('an entry staff delete stays deleted', $r['code'] === 200 && !in_array('What if I miss my assessment session?', $after, true), $r['body']);
$pdo->exec("DELETE FROM faqs WHERE question IN ('" . implode("','", array_map(fn($q) => str_replace("'", "''", $q), array_merge(...array_values($panelQs)))) . "')");
$pdo->prepare('DELETE FROM security_policies WHERE key = ?')->execute([Faq::PANEL_ENTRIES_KEY]);

$cleanup();
$pdo->exec("DELETE FROM security_policies WHERE key = 'booking.url'");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
