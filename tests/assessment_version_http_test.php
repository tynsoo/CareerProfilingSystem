<?php
/**
 * Versioned assessment submission over HTTP, against a running server that uses a *_test database:
 * answers by question id, rejection of old / changed / incomplete submissions, storage that
 * reproduces the score, the one-attempt rule, and legacy (pre-versioning) attempts.
 *
 *   DB_NAME=x_test php -S 127.0.0.1:8099 router.php
 *   DB_NAME=x_test php tests/assessment_version_http_test.php http://127.0.0.1:8099
 *
 * Creates a SYNTHETIC student ("qa_av_student", group section QAAV) and a same-day exam schedule
 * for that group only, and removes both at the end. Sends no email.
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/AssessmentVersion.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/RiasecProfile.php';
if (!str_ends_with((string) envValue('DB_NAME'), '_test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end in _test\n");
    exit(2);
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pdo = Database::get();
$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
$jar = tempnam(sys_get_temp_dir(), 'av');
$csrf = null;
function req(string $method, string $path, $body = null): array
{
    global $base, $jar, $csrf;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($csrf) {
        $h[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = (string) curl_exec($ch);
    return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($text, true) ?? []];
}
$refreshCsrf = function () use (&$csrf) {
    $csrf = null;
    $csrf = req('GET', '/api/session.php')['json']['csrfToken'] ?? null;
};

// ---- synthetic student and exam schedule ----
$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE username = 'qa_av_student')");
    $pdo->exec("DELETE FROM monitoring_flags WHERE student_id IN (SELECT id FROM users WHERE username = 'qa_av_student')");
    $pdo->exec("DELETE FROM recommendations WHERE student_id IN (SELECT id FROM users WHERE username = 'qa_av_student')");
    $pdo->exec("DELETE FROM users WHERE username = 'qa_av_student'");
    $pdo->exec("DELETE FROM exam_schedules WHERE section = 'QAAV'");
    $pdo->exec("DELETE FROM rate_limit_hits WHERE rate_key LIKE '%qa_av_student%'");
};
$cleanup();
$setsBefore = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM assessment_question_sets')->fetchColumn();
$u = $pdo->prepare("INSERT INTO users (role, username, password_hash, email, email_verified_at) VALUES ('student', 'qa_av_student', ?, 'qa_av_student@example.invalid', NOW()) RETURNING id");
$u->execute([password_hash('QaAv-Pass-2026!', PASSWORD_BCRYPT)]);
$uid = (int) $u->fetchColumn();
$pdo->prepare("INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, 'QA-AV-0001', ?, ?, 'STEM', '12', 'QAAV', 'QA-TEST')")
    ->execute([$uid, Crypto::enc('Synthetic'), Crypto::enc('Student')]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$pdo->prepare("INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code) VALUES ('QA-TEST', ?, '00:00', '23:59', 'QA', '12', 'STEM', 'QAAV', 'QAAV2026')")
    ->execute([$today]);

$refreshCsrf();
$r = req('POST', '/api/login.php', ['username' => 'qa_av_student', 'password' => 'QaAv-Pass-2026!', 'portal' => 'student']);
check('synthetic student signs in', $r['code'] === 200, (string) $r['code']);
$refreshCsrf();
$r = req('POST', '/api/verify-access-code.php', ['code' => 'QAAV2026']);
check('access code unlocks the assessment', $r['code'] === 200, json_encode($r['json']));

echo "\n=== The question set handed to the student ===\n";
$q = req('GET', '/api/assessment-questions.php')['json'];
$setId = $q['questionSetId'] ?? null;
check('questions come with an integer question-set id', is_int($setId) && count($q['questions'] ?? []) === 30, json_encode(array_keys($q)));
check('same questions -> the same set id on a second request', req('GET', '/api/assessment-questions.php')['json']['questionSetId'] === $setId);
$values = ['R' => 4, 'I' => 5, 'A' => 1, 'S' => 2, 'E' => 3, 'C' => 4];
$answers = array_map(fn($x) => ['questionId' => $x['id'], 'answer' => $values[$x['dimension']]], $q['questions']);

echo "\n=== Submissions that must be refused ===\n";
$r = req('POST', '/api/assessment-submit.php', ['answers' => array_fill(0, 30, 3)]);
check('old page: positional answers without a set id -> 409', $r['code'] === 409, (string) $r['code']);
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId + 1000, 'answers' => $answers]);
check('a set id other than the one presented -> 409', $r['code'] === 409, (string) $r['code']);
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => array_slice($answers, 1)]);
check('one answer missing -> 400', $r['code'] === 400, (string) $r['code']);
$bad = $answers;
$bad[0]['answer'] = 6;
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => $bad]);
check('answer outside 1-5 -> 400', $r['code'] === 400, (string) $r['code']);
$bad = $answers;
$bad[0]['questionId'] = 999999;
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => $bad]);
check('answer to a question not in the set -> 400', $r['code'] === 400, (string) $r['code']);

// Staff edit a question's wording while the student is answering (done directly, then undone).
$edit = $pdo->query("SELECT id, question_text_enc FROM assessment_questions WHERE is_active ORDER BY id LIMIT 1")->fetch();
$pdo->prepare('UPDATE assessment_questions SET question_text_enc = ? WHERE id = ?')->execute([Crypto::enc(Crypto::dec($edit['question_text_enc']) . ' (QA edit)'), $edit['id']]);
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => $answers]);
check('wording edited after the questions were shown -> 409 (not scored against a different set)', $r['code'] === 409, (string) $r['code']);
$newSet = req('GET', '/api/assessment-questions.php')['json']['questionSetId'] ?? null;
check('the edited wording is a new, different set', is_int($newSet) && $newSet !== $setId);
$pdo->prepare('UPDATE assessment_questions SET question_text_enc = ? WHERE id = ?')->execute([$edit['question_text_enc'], $edit['id']]);
check('wording restored -> the original set id again (sets are immutable)', req('GET', '/api/assessment-questions.php')['json']['questionSetId'] === $setId);
check('no attempt was saved by any refused submission', (int) $pdo->query("SELECT COUNT(*) FROM assessments WHERE student_id = $uid")->fetchColumn() === 0);

echo "\n=== A valid submission is stored reproducibly ===\n";
$shuffled = $answers;
shuffle($shuffled);
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => $shuffled]);
check('valid answers (in any order) -> saved', $r['code'] === 200 && ($r['json']['success'] ?? false), json_encode($r['json']));
check('stored scores = raw x 2 (R40 I50 A10 S20 E30 C40)', ($r['json']['scores'] ?? null) === ['R' => 40, 'I' => 50, 'A' => 10, 'S' => 20, 'E' => 30, 'C' => 40]);
check('top types from the shared ranking (I, R, C: R and C tie at 40, R first by convention)', ($r['json']['topTypes'] ?? null) === ['Investigative', 'Realistic', 'Conventional']);
$row = $pdo->query("SELECT id, question_set_id, answers_enc, scoring_version FROM assessments WHERE student_id = $uid")->fetch();
check('attempt stores its question set and scoring version', (int) $row['question_set_id'] === $setId && $row['scoring_version'] === AssessmentVersion::SCORING_VERSION);
$stored = json_decode(Crypto::dec($row['answers_enc']), true);
check('answers stored encrypted, by question id, in the order shown', is_array($stored) && array_column($stored, 'questionId') === array_column($q['questions'], 'id') && !str_contains($row['answers_enc'], 'questionId'));
$rep = AssessmentVersion::reproduce($pdo, (int) $row['id']);
check('the attempt can be re-scored from storage and matches', $rep['status'] === 'versioned' && $rep['matchesStored'] === true);
$st = req('GET', '/api/assessment-status.php')['json'];
check('assessment status returns the derived profile (code IRC, provisional: R and C tie)', ($st['riasecProfile']['code'] ?? null) === 'IRC' && ($st['riasecProfile']['codeProvisional'] ?? null) === true);

echo "\n=== Existing permissions preserved ===\n";
$refreshCsrf();
req('POST', '/api/verify-access-code.php', ['code' => 'QAAV2026']);
$r = req('POST', '/api/assessment-submit.php', ['questionSetId' => $setId, 'answers' => $answers]);
check('second attempt without an approved retake -> refused', in_array($r['code'], [403, 409], true) && (int) $pdo->query("SELECT COUNT(*) FROM assessments WHERE student_id = $uid")->fetchColumn() === 1, (string) $r['code']);

echo "\n=== Legacy attempts (saved before versioning) ===\n";
$legacy = $pdo->query('SELECT id, score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE question_set_id IS NULL LIMIT 1')->fetch();
if ($legacy) {
    check('legacy attempt is reported as legacy, nothing invented', AssessmentVersion::reproduce($pdo, (int) $legacy['id']) === ['status' => 'legacy']);
    $lp = RiasecProfile::derive(['R' => $legacy['score_r'], 'I' => $legacy['score_i'], 'A' => $legacy['score_a'], 'S' => $legacy['score_s'], 'E' => $legacy['score_e'], 'C' => $legacy['score_c']]);
    check('legacy attempt still gets a ranked profile from its stored scores', $lp['valid'] === true && strlen($lp['code']) === 3);
} else {
    echo "  (no legacy attempt in this database; skipped)\n";
}

$cleanup();
$pdo->prepare('DELETE FROM assessment_question_sets s WHERE s.id > ? AND NOT EXISTS (SELECT 1 FROM assessments a WHERE a.question_set_id = s.id)')->execute([$setsBefore]);
echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
