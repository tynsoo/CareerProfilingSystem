<?php
// Changing a program's Holland code on the Career Dataset page: the audit log records old -> new,
// every saved recommendation is recomputed with the new code, and invalid or unauthorised edits change nothing.
//   DB_NAME=x_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=x_test php tests/program_code_http_test.php http://127.0.0.1:8099
// Uses the seeded test-database admin, a synthetic program and the test database's students only;
// refuses to run unless DB_NAME ends in _test. Needs at least one student with a Career Worksheet.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/AuditLog/lib/AuditChange.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) { fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n"); exit(2); }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$pass = 0; $fail = 0;
function check(string $l, bool $c, string $detail = ''): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . ($c || $detail === '' ? '' : "  [$detail]") . "\n"; }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'pc'); }
    function req(string $method, string $path, $body = null): array {
        if ($this->csrf === null) { $this->csrf = $this->raw('GET', '/api/session.php')['json']['csrfToken'] ?? null; }
        return $this->raw($method, $path, $body, true);
    }
    function raw(string $method, string $path, $body = null, bool $csrf = false): array {
        $ch = curl_init($this->base . $path);
        $h = ['Content-Type: application/json'];
        if ($csrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $r = (string) curl_exec($ch);
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($r, true) ?? []];
    }
    function login(string $u, string $p, string $portal = 'staff'): int { $this->csrf = null; $c = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal])['code']; $this->csrf = null; return $c; }
}

$title = 'QA Code Change Program';
$findProgram = function () use ($pdo, $title): ?int {
    foreach ($pdo->query('SELECT id, title_enc FROM programs')->fetchAll() as $r) {
        if (Crypto::dec($r['title_enc']) === $title) { return (int) $r['id']; }
    }
    return null;
};
$recCount = fn() => (int) $pdo->query('SELECT COUNT(*) FROM recommendations')->fetchColumn();
$withWorksheet = (int) $pdo->query(
    'SELECT COUNT(*) FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     WHERE EXISTS (SELECT 1 FROM worksheets w WHERE w.student_id = s.user_id)'
)->fetchColumn();
$lastAudit = function (string $action, int $programId) use ($pdo): ?string {
    $st = $pdo->prepare("SELECT detail_enc FROM audit_log WHERE action = ? AND target_type = 'program' AND target_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$action, (string) $programId]);
    $enc = $st->fetchColumn();
    return $enc ? Crypto::dec($enc) : null;
};

// ---- synthetic program (letters A, E, C: never the best match for the test students' S/I/R profiles) ----
if ($old = $findProgram()) { $pdo->prepare("UPDATE programs SET status = 'Inactive' WHERE id = ?")->execute([$old]); }
$collegeId = (int) $pdo->query('SELECT MIN(id) FROM colleges')->fetchColumn();
$ins = $pdo->prepare("INSERT INTO programs (college_id, title_enc, holland_code_enc, description_enc, careers, status) VALUES (?, ?, ?, NULL, '{}', 'Active') RETURNING id");
$ins->execute([$collegeId, Crypto::enc($title), Crypto::enc('AEC')]);
$pid = (int) $ins->fetchColumn();
$payload = fn(string $code, string $desc = '') => ['id' => $pid, 'title' => $title, 'collegeId' => $collegeId, 'hollandCode' => $code,
    'status' => 'Active', 'description' => $desc, 'careers' => ['Synthetic Career']];

echo "=== Access ===\n";
$anon = new Client($base);
check('not signed in -> refused', in_array($anon->req('PUT', '/api/programs.php', $payload('ECA'))['code'], [401, 403], true));
$a = new Client($base);
check('admin login', $a->login('admin', 'ChangeMe123!') === 200);
check("test data has students with a worksheet ($withWorksheet)", $withWorksheet > 0);

echo "\n=== Who may edit: admin and Guidance Counselors yes, Facilitators view only ===\n";
$staffAccount = function (string $username, string $position) use ($pdo): void {
    $hash = password_hash('QaPc-Pass-2026!', PASSWORD_BCRYPT);
    $st = $pdo->prepare("UPDATE users SET password_hash = ?, is_active = TRUE, failed_login_attempts = 0, locked_until = NULL WHERE username = ?");
    $st->execute([$hash, $username]);
    if ($st->rowCount() === 0) {
        $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position)
                       VALUES ('counselor', ?, ?, ?, NOW(), 'approved', ?, ?)")
            ->execute([$username, $hash, "$username@example.invalid", 'QA ' . ucfirst($position), $position]);
    }
};
$staffAccount('qa_pc_counselor', 'counselor');
$staffAccount('qa_pc_facilitator', 'facilitator');
$canEdit = fn(Client $c) => $c->req('GET', '/api/programs.php?all=1')['json']['canEdit'] ?? null;
check('admin: page shows the edit controls (canEdit)', $canEdit($a) === true);
$co = new Client($base);
check('Guidance Counselor login', $co->login('qa_pc_counselor', 'QaPc-Pass-2026!') === 200);
check('Guidance Counselor: canEdit', $canEdit($co) === true);
$r = $co->req('PUT', '/api/programs.php', $payload('AEC', 'Edited by a counselor'));
check('Guidance Counselor can save a program edit', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
$fa = new Client($base);
check('Facilitator login', $fa->login('qa_pc_facilitator', 'QaPc-Pass-2026!') === 200);
check('Facilitator: can view the list but canEdit is false', $canEdit($fa) === false);
$r = $fa->req('PUT', '/api/programs.php', $payload('ECA', 'Edited by a facilitator'));
check('Facilitator cannot edit a program (403)', $r['code'] === 403, (string) $r['code']);
$r = $fa->req('POST', '/api/programs.php', ['type' => 'toggleActive', 'id' => $pid]);
check('Facilitator cannot deactivate a program (403)', $r['code'] === 403, (string) $r['code']);
$st = $pdo->prepare('SELECT holland_code_enc FROM programs WHERE id = ?');
$st->execute([$pid]);
check('the facilitator\'s attempt changed nothing (code still AEC)', Crypto::dec((string) $st->fetchColumn()) === 'AEC');

echo "\n=== Edit without a code change: audit records what changed, nothing recomputed ===\n";
$before = $recCount();
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description'));
check('saved', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
check('reported as no code change', ($r['json']['hollandCodeChanged'] ?? null) === false);
check('no recommendations recomputed', $recCount() === $before);
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$fields = array_column($detail['changes'] ?? [], 'field');
check('audit: "Description" listed as changed, Holland code not', in_array('Description', $fields, true)
    && !in_array('Holland code', $fields, true), json_encode($fields));

echo "\n=== Related SHS strands: optional, saved, returned, audited, never recomputed ===\n";
$strandsOf = function () use ($a, $pid): ?array {
    foreach ($a->req('GET', '/api/programs.php?all=1')['json']['programs'] ?? [] as $p) {
        if ((int) $p['id'] === $pid) { return $p['relatedStrands'] ?? null; }
    }
    return null;
};
check('starts with no strands', $strandsOf() === []);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => ['ict', 'STEM']]);
check('saving STEM and ICT accepted', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
check('returned as saved (allowed list order)', $strandsOf() === ['STEM', 'ICT'], json_encode($strandsOf()));
check('no recommendations recomputed for a strand change', $recCount() === $before);
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$st = array_values(array_filter($detail['changes'] ?? [], fn($c) => $c['field'] === 'Related SHS strands'));
check('audit: "Related SHS strands" before (none) and after STEM, ICT', isset($st[0]) && $st[0]['after'] === 'STEM, ICT', json_encode($st));
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description'));
check('an edit that does not send strands keeps them', $r['code'] === 200 && $strandsOf() === ['STEM', 'ICT']);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => ['STEM', 'XYZ']]);
check('unknown strand XYZ -> 400, nothing changed', $r['code'] === 400 && $strandsOf() === ['STEM', 'ICT'], (string) $r['code']);
$r = $a->req('PUT', '/api/programs.php', $payload('AEC', 'Synthetic description') + ['relatedStrands' => []]);
check('clearing all strands saved', $r['code'] === 200 && $strandsOf() === []);

echo "\n=== Invalid codes are refused and change nothing ===\n";
foreach (['RRC' => 'repeated letter', 'RIX' => 'letter outside RIASEC', 'RI' => 'two letters'] as $code => $why) {
    $r = $a->req('PUT', '/api/programs.php', $payload($code));
    check("$code ($why) -> 400", $r['code'] === 400, (string) $r['code']);
}
check('no recommendations recomputed by refused edits', $recCount() === $before);

echo "\n=== Code change: audit old -> new, every saved recommendation recomputed ===\n";
$r = $a->req('PUT', '/api/programs.php', $payload('ECA', 'Synthetic description'));
$j = $r['json'];
check('saved', $r['code'] === 200 && ($j['success'] ?? false), json_encode($j));
check('reported: AEC -> ECA', ($j['hollandCodeChanged'] ?? null) === true && ($j['previousHollandCode'] ?? null) === 'AEC' && ($j['hollandCode'] ?? null) === 'ECA');
check("every student with a worksheet recomputed ($withWorksheet), none failed",
    ($j['recomputed'] ?? null) === $withWorksheet && ($j['recomputeFailed'] ?? null) === 0, json_encode($j));
check('one new recommendation row per student (older rows kept)', $recCount() === $before + $withWorksheet);
$latest = $pdo->query('SELECT DISTINCT ON (student_id) scores FROM recommendations ORDER BY student_id, computed_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN);
$codes = [];
foreach ($latest as $json) {
    foreach (json_decode($json, true) ?: [] as $sc) {
        if ((int) $sc['programId'] === $pid) { $codes[] = $sc['hollandCode']; }
    }
}
check('every latest result uses the new code ECA', count($codes) === $withWorksheet && array_unique($codes) === ['ECA'], json_encode(array_unique($codes)));
$detail = AuditChange::parse((string) $lastAudit('update_program', $pid));
$hc = array_values(array_filter($detail['changes'] ?? [], fn($c) => $c['field'] === 'Holland code'));
check('audit: "Holland code: AEC -> ECA"', isset($hc[0]) && $hc[0]['before'] === 'AEC' && $hc[0]['after'] === 'ECA', json_encode($hc));
$rc = (string) $lastAudit('recompute_recommendations', $pid);
check('audit: recompute entry names the change and the count', str_contains($rc, 'AEC -> ECA') && str_contains($rc, "$withWorksheet students"), $rc);

echo "\n=== Clean up ===\n";
$pdo->prepare("UPDATE programs SET status = 'Inactive' WHERE id = ?")->execute([$pid]);
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
$res = CBFData::recomputeAll($pdo);   // latest results no longer include the synthetic program
check('results recomputed without the synthetic program', $res['failed'] === 0);
try {
    $pdo->prepare('DELETE FROM programs WHERE id = ?')->execute([$pid]);
    echo "  synthetic program deleted\n";
} catch (PDOException $e) {
    echo "  synthetic program kept as Inactive (referenced by saved history)\n";
}

echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
