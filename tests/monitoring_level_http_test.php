<?php
// The level of the low-similarity flag through the running site: Monitoring lists a student below the level and not a
// student with only a career mismatch; who may see Monitoring and who may change the level in Security Configuration
// (the Administrator only, refused below the lowest possible score, written to the Audit Log); and the one-time
// db/flag_existing_low_similarity.php (dry run, --apply, run twice, refused outside a _test database).
//   DB_NAME=profilepath_test php -S 127.0.0.1:8099 router.php
//   DB_NAME=profilepath_test php tests/monitoring_level_http_test.php http://127.0.0.1:8099
// Seeds synthetic users (prefix "qa_mlh_") and removes only those it created; the level is put back to what it was.
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../modules/Monitoring/lib/MonitoringLevel.php';
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
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $r, 'json' => json_decode($r, true)];
    }
    function login(string $u, string $p, string $portal): int { $this->csrf = null; $r = $this->req('POST', '/api/login.php', ['username' => $u, 'password' => $p, 'portal' => $portal]); $this->csrf = null; return $r['code']; }
}
function runScript(array $args, array $env = []): array {
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/../db/flag_existing_low_similarity.php'], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return ['code' => proc_close($p), 'out' => trim($out)];
}

$stored = function () use ($pdo) {
    $s = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
    $s->execute([MonitoringLevel::KEY]);
    return $s->fetchColumn();
};
$levelBefore = $stored();

// ---- seed -------------------------------------------------------------
$pw = 'Qa-Test-Pass1!'; $hash = password_hash($pw, PASSWORD_BCRYPT);
$ids = [];
$mkUser = function (string $role, string $name, ?string $pos = null) use ($pdo, $hash, &$ids) {
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$name]);
    $s = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position) VALUES (?,?,?,?,?,'approved',?,?) RETURNING id");
    $s->execute([$role, $name, $hash, "$name@example.test", date('c'), $name, $pos]);
    return $ids[] = (int) $s->fetchColumn();
};
$mkStudent = function (string $name, string $last) use ($pdo, $mkUser) {
    $id = $mkUser('student', $name);
    $pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section) VALUES (?,?,?,?,'STEM','11','QA-M')")
        ->execute([$id, $name, Crypto::enc('Qa'), Crypto::enc($last)]);
    return $id;
};
$save = function (int $sid, array $s, ?int $stated = null) use ($pdo): array {
    $pdo->prepare('UPDATE assessments SET is_latest = FALSE WHERE student_id = ?')->execute([$sid]);
    $a = $pdo->prepare("INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types, completed_at)
                        VALUES (?, (SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM assessments WHERE student_id = ?), TRUE,?,?,?,?,?,?,'[]',NOW()) RETURNING id");
    $a->execute([$sid, $sid, $s['R'], $s['I'], $s['A'], $s['S'], $s['E'], $s['C']]);
    return CBFData::saveRecommendation($pdo, $sid, CBFData::studentProfile($pdo, $sid, []), $stated, (int) $a->fetchColumn(), null);
};
$openFlags = function (int $sid) use ($pdo): array {
    $q = $pdo->prepare("SELECT id, status, recommendation_id FROM monitoring_flags WHERE student_id = ? AND reason = 'low_confidence' AND status IN ('pending','escalated')");
    $q->execute([$sid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $pdo->prepare('UPDATE security_policies SET value = ? WHERE key = ?')->execute(['0.793', MonitoringLevel::KEY]);
    $stuLow = $mkStudent('qa_mlh_low', 'LowSimilarity');
    $stuMis = $mkStudent('qa_mlh_mis', 'MismatchOnly');
    $stuOld = $mkStudent('qa_mlh_old', 'AssessedBefore');
    $adm = $mkUser('admin', 'qa_mlh_adm');
    $cou = $mkUser('counselor', 'qa_mlh_cou', 'counselor');
    $coa = $mkUser('counselor', 'qa_mlh_coa', 'counselor');
    $pdo->prepare('UPDATE users SET is_co_admin = TRUE WHERE id = ?')->execute([$coa]);
    $mkUser('counselor', 'qa_mlh_fac', 'facilitator');

    $low = ['R' => 12, 'I' => 38, 'A' => 30, 'S' => 20, 'E' => 12, 'C' => 24];        // 0.7920
    $strongIrc = ['R' => 40, 'I' => 50, 'A' => 10, 'S' => 10, 'E' => 10, 'C' => 30];
    $rLow = $save($stuLow, $low);
    $first = $save($stuMis, $strongIrc);
    $programs = CBFData::activePrograms($pdo);
    $other = (int) array_values(array_filter($programs, fn($p) => !in_array($p['title'], $first['bestRiasecMatchTitles'], true)))[0]['id'];
    $rMis = $save($stuMis, $strongIrc, $other);
    $rOld = $save($stuOld, $low);
    // A student assessed before the level was set: the result is below it, but no flag was raised at the time.
    $pdo->prepare("DELETE FROM monitoring_flags WHERE student_id = ?")->execute([$stuOld]);

    echo "=== Monitoring shows the low similarity, not the mismatch ===\n";
    check('setup: the low student scores 0.7920 and the mismatch student is a mismatch above the level',
        abs($rLow['topScore'] - 0.792) < 0.00005 && $rMis['status'] === 'mismatch' && $rMis['topScore'] >= 0.793, json_encode([$rLow['topScore'], $rMis['status'], $rMis['topScore']]));
    $c = new Client($base);
    check('counselor signs in', $c->login('qa_mlh_cou', $pw, 'staff') === 200);
    $m = $c->req('GET', '/api/monitoring.php?tab=needs-review')['json'] ?? [];
    $names = array_column($m['rows'] ?? [], 'name');
    check('Monitoring reports the level 0.793', abs(($m['threshold'] ?? 0) - 0.793) < 1e-9, json_encode($m['threshold'] ?? null));
    check('the student below the level is in Needs Review', in_array('LowSimilarity, Qa', $names, true), json_encode($names));
    $row = array_values(array_filter($m['rows'] ?? [], fn($r) => $r['name'] === 'LowSimilarity, Qa'))[0] ?? [];
    check('...with the reason "Highest similarity below the monitoring level", priority Medium and the score', ($row['reason'] ?? '') === 'Highest similarity below the monitoring level'
        && ($row['priority'] ?? '') === 'Medium' && abs(($row['topScore'] ?? 0) - 0.792) < 0.00005, json_encode($row));
    check('the student with only a career mismatch is not listed', !in_array('MismatchOnly, Qa', $names, true));
    check('the summary count equals the rows of Needs Review', ($m['summary']['needsReview'] ?? -1) === count($m['rows'] ?? []));

    echo "\n=== who can see Monitoring and change the level ===\n";
    $clients = [];
    foreach (['stu' => ['qa_mlh_low', 'student'], 'fac' => ['qa_mlh_fac', 'staff'], 'cou' => ['qa_mlh_cou', 'staff'], 'coa' => ['qa_mlh_coa', 'staff'], 'adm' => ['qa_mlh_adm', 'staff']] as $k => [$u, $portal]) {
        $clients[$k] = new Client($base);
        $code = $clients[$k]->login($u, $pw, $portal);
        check("$u signs in", $code === 200, (string) $code);
    }
    $code = $clients['stu']->req('GET', '/api/monitoring.php')['code'];
    check('a student cannot open Monitoring', in_array($code, [401, 403], true), (string) $code);
    foreach (['fac', 'cou', 'coa', 'adm'] as $k) {
        $code = $clients[$k]->req('GET', '/api/monitoring.php')['code'];
        check("$k sees Monitoring", $code === 200, (string) $code);
    }
    $code = $clients['fac']->req('POST', '/api/monitoring-action.php', ['flagId' => 0, 'action' => 'dismiss'])['code'];
    check('a facilitator (View Only) cannot act on a flag', $code === 403, (string) $code);
    $code = $clients['cou']->req('POST', '/api/monitoring-action.php', ['flagId' => 0, 'action' => 'dismiss'])['code'];
    check('a counselor gets past the permission check (400 for flag 0)', $code === 400, (string) $code);

    $sc = $clients['adm']->req('GET', '/api/security-config.php')['json'] ?? [];
    check('Security Configuration shows the Administrator the level 0.793, the lowest score and the default',
        ($sc['policies']['flagging']['level'] ?? null) == 0.793 && ($sc['policies']['flagging']['floor'] ?? null) == 0.5844 && ($sc['policies']['flagging']['default'] ?? null) == 0.793, json_encode($sc['policies']['flagging'] ?? null));
    foreach (['cou', 'coa', 'fac'] as $k) {
        $j = $clients[$k]->req('GET', '/api/security-config.php')['json'] ?? [];
        check("$k is not sent the security policies", !isset($j['policies']), json_encode(array_keys($j)));
        $r = $clients[$k]->req('POST', '/api/security-config.php', ['type' => 'policy', 'key' => 'flagging', 'level' => '0.900']);
        check("$k cannot change the level", $r['code'] === 403 && $stored() === '0.793', $r['code'] . ' ' . var_export($stored(), true));
    }
    $code = $clients['stu']->req('POST', '/api/security-config.php', ['type' => 'policy', 'key' => 'flagging', 'level' => '0.900'])['code'];
    check('a student cannot change the level', in_array($code, [401, 403], true) && $stored() === '0.793', (string) $code);
    foreach (['0.50', '0.584', '1.2', '0.7931', 'abc', ''] as $bad) {
        $r = $clients['adm']->req('POST', '/api/security-config.php', ['type' => 'policy', 'key' => 'flagging', 'level' => $bad]);
        check("the Administrator's '$bad' is refused and nothing is saved", $r['code'] === 400 && $stored() === '0.793', $r['code'] . ' ' . substr($r['body'], 0, 120));
    }
    $auditBefore = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
    $r = $clients['adm']->req('POST', '/api/security-config.php', ['type' => 'policy', 'key' => 'flagging', 'level' => '0.800']);
    check('the Administrator sets 0.800', $r['code'] === 200 && ($r['json']['policies']['flagging']['level'] ?? null) == 0.8 && $stored() === '0.800', $r['code'] . ' ' . var_export($stored(), true));
    $a = $pdo->prepare("SELECT actor_user_id, action, target_type, target_id, detail_enc FROM audit_log WHERE id > ? AND action = 'update_policy' ORDER BY id DESC LIMIT 1");
    $a->execute([$auditBefore]);
    $entry = $a->fetch();
    $detail = $entry ? json_decode((string) Crypto::dec($entry['detail_enc']), true) : null;
    check('the change is in the Audit Log with who, the old and the new level', $entry && (int) $entry['actor_user_id'] === $adm && $entry['target_id'] === 'flagging'
        && ($detail['changes'][0]['before'] ?? '') === '0.793' && ($detail['changes'][0]['after'] ?? '') === '0.8', json_encode([$entry['target_id'] ?? null, $detail]));
    $m = $clients['cou']->req('GET', '/api/monitoring.php')['json'] ?? [];
    check('Monitoring now reports 0.800', abs(($m['threshold'] ?? 0) - 0.8) < 1e-9, json_encode($m['threshold'] ?? null));
    $r = $clients['adm']->req('POST', '/api/security-config.php', ['type' => 'policy', 'key' => 'flagging', 'level' => '0.793']);
    check('the Administrator puts 0.793 back', $r['code'] === 200 && $stored() === '0.793');

    echo "\n=== db/flag_existing_low_similarity.php ===\n";
    check('setup: the student assessed before has a result below the level and no flag', $rOld['topScore'] < 0.793 && $openFlags($stuOld) === []);
    $r = runScript([]);
    check('the dry run lists the student and writes nothing', $r['code'] === 0 && str_contains($r['out'], 'dry run') && $openFlags($stuOld) === [], $r['out']);
    $r = runScript(['--apply']);
    $f = $openFlags($stuOld);
    check('--apply raises one Pending flag on the latest result', $r['code'] === 0 && count($f) === 1 && $f[0]['status'] === 'pending'
        && (int) $f[0]['recommendation_id'] === $rOld['recommendationId'], $r['out'] . ' ' . json_encode($f));
    check('the students already flagged and above the level are untouched', count($openFlags($stuLow)) === 1 && $openFlags($stuMis) === []);
    $r = runScript(['--apply']);
    check('running it again adds no flag', $r['code'] === 0 && count($openFlags($stuOld)) === 1 && str_contains($r['out'], 'flagged now: 0'), $r['out']);
    $r = runScript(['--apply'], ['DB_NAME' => 'profilepath']);
    check('--apply on a database not ending in _test is refused before connecting', $r['code'] === 2 && str_contains($r['out'], 'Refusing'), $r['code'] . ' ' . $r['out']);
} finally {
    foreach ($ids as $id) {
        foreach (['UPDATE security_policies SET updated_by = NULL WHERE updated_by = ?', 'DELETE FROM monitoring_flags WHERE student_id = ?', 'DELETE FROM recommendations WHERE student_id = ?', 'DELETE FROM assessments WHERE student_id = ?',
                  'DELETE FROM audit_log WHERE actor_user_id = ?', 'DELETE FROM sessions WHERE user_id = ?', 'DELETE FROM students WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $pdo->prepare($sql)->execute([$id]); } catch (PDOException $e) { echo "  cleanup note: " . $e->getMessage() . "\n"; }
        }
    }
    if ($levelBefore !== false) {
        $pdo->prepare('UPDATE security_policies SET value = ? WHERE key = ?')->execute([$levelBefore, MonitoringLevel::KEY]);
    }
}
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'qa_mlh_%'")->fetchColumn();
check('the synthetic users are removed', $left === 0);
echo "\n=== Summary: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
