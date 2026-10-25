<?php
/**
 * Test accounts for the ProfilePath Test Plan (Section 1). Made-up people only; never real records.
 *
 *   php db/seed_test_accounts.php               on a database whose name ends in _test
 *   php db/seed_test_accounts.php --test-copy   on a test copy with another name (asks you to type YES)
 *   php db/seed_test_accounts.php --remove      take them out again (add --test-copy as above)
 *
 * Creates, all with the password printed at the end:
 *   test.counselor, test.facilitator   staff, assigned to section STEM TEST-1
 *   test.student.a … test.student.e    assessment and Career Worksheet done, results computed:
 *       A clear profile (S, I, R; nurse)      B tied for 2nd (I = R; nurse)
 *       C every answer Neutral (accountant)   D every answer Dislike (civil engineer)
 *       E high I, R, C but chose nurse (Preferred Course differs from the Best RIASEC Match)
 *   test.student.f, test.student.g     nothing done yet, for the assessment test cases
 *   an exam schedule for TEST-1 today, access code TEST2026
 *
 * Answers are saved exactly as the assessment page saves them (question set, answers by question id,
 * scoring), and results come from the same recommendation pipeline (CBFData::saveRecommendation).
 * Nothing is emailed. The admin account is not touched.
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../modules/Encryption/lib/Crypto.php';
require_once __DIR__ . '/../modules/Authentication/lib/PasswordPolicy.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/AssessmentVersion.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/RiasecProfile.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/CareerMatcher.php';
require_once __DIR__ . '/../modules/CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$remove = in_array('--remove', $argv, true);
$testCopy = in_array('--test-copy', $argv, true);

// ---- only on a test database ----
$dbName = (string) envValue('DB_NAME');
if (!str_ends_with($dbName, '_test')) {
    if (!$testCopy) {
        fwrite(STDERR, "Refusing to run on database \"$dbName\": its name does not end in _test.\n"
            . "If this is a test copy with no real student records, run again with --test-copy.\n");
        exit(2);
    }
    echo "Database \"$dbName\" on " . envValue('DB_HOST') . ".\n"
        . 'This ' . ($remove ? 'removes' : 'adds') . " made-up test accounts. Use it only on a TEST copy, never on the live system with real students.\n"
        . 'Type YES to continue: ';
    if (trim((string) fgets(STDIN)) !== 'YES') {
        exit("Nothing changed.\n");
    }
}

$pdo = Database::get();
const PASSWORD = 'TestAccount-2026!';
const SECTION = 'TEST-1';
const STRAND = 'STEM';
const ACCESS_CODE = 'TEST2026';

// Five answers per type (1 = Dislike … 5 = Enjoy); repeated if the question bank has more per type.
$students = [
    'a' => ['Clear profile', 'nurse', ['S' => [5, 5, 5, 5, 5], 'I' => [4, 4, 4, 4, 4], 'R' => [3, 3, 3, 3, 3], 'C' => [3, 3, 2, 2, 2], 'A' => [2, 2, 2, 2, 2], 'E' => [2, 2, 2, 1, 1]]],
    'b' => ['Tied for 2nd', 'nurse', ['S' => [5, 5, 5, 5, 5], 'I' => [4, 4, 4, 4, 4], 'R' => [4, 4, 4, 4, 4], 'C' => [3, 3, 3, 3, 3], 'A' => [2, 2, 2, 2, 2], 'E' => [2, 2, 2, 1, 1]]],
    'c' => ['Flat (all Neutral)', 'accountant', array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], [3, 3, 3, 3, 3])],
    'd' => ['All Dislike', 'civil engineer', array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], [1, 1, 1, 1, 1])],
    'e' => ['Preferred differs', 'nurse', ['I' => [5, 5, 5, 5, 5], 'R' => [4, 4, 4, 4, 4], 'C' => [3, 3, 3, 3, 3], 'S' => [2, 2, 2, 2, 2], 'A' => [2, 2, 2, 1, 1], 'E' => [2, 2, 1, 1, 1]]],
    'f' => ['Not started', null, null],
    'g' => ['Not started', null, null],
];
$staff = ['counselor' => 'Test Counselor', 'facilitator' => 'Test Facilitator'];
$usernames = array_merge(array_map(fn($p) => "test.$p", array_keys($staff)), array_map(fn($k) => "test.student.$k", array_keys($students)));

// ---- remove ----
if ($remove) {
    foreach ($usernames as $u) {
        $id = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $id->execute([$u]);
        $id = $id->fetchColumn();
        if ($id === false) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach (['monitoring_flags', 'recommendations', 'saved_programs'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE student_id = ?")->execute([$id]);
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
            echo "  removed $u\n";
        } catch (PDOException $e) {
            // Audit log entries refer to the account; the log is sealed and is never edited, so keep the
            // account but switch it off and rename it, so a fresh set can be created with the same names.
            $pdo->rollBack();
            $tag = '.removed-' . date('YmdHis');
            $pdo->prepare('UPDATE users SET is_active = FALSE, username = username || ? WHERE id = ?')->execute([$tag, $id]);
            $pdo->prepare('UPDATE students SET school_id = school_id || ? WHERE user_id = ?')->execute([strtoupper($tag), $id]);
            echo "  deactivated and renamed $u (kept because the audit log refers to it)\n";
        }
    }
    $pdo->prepare('DELETE FROM exam_schedules WHERE section = ? AND access_code = ?')->execute([SECTION, ACCESS_CODE]);
    echo "Done.\n";
    exit;
}

// ---- add ----
$exists = $pdo->prepare('SELECT username FROM users WHERE username = ANY(?::text[])');
$exists->execute([CBFData::textArrayLiteral($usernames)]);
if ($found = $exists->fetchAll(PDO::FETCH_COLUMN)) {
    exit('Test accounts already exist (' . implode(', ', $found) . "). Run with --remove first to start fresh.\n");
}
if ($errors = PasswordPolicy::errors($pdo, PASSWORD)) {
    exit('The test password does not meet this system\'s password rules: ' . implode(' ', $errors) . "\n");
}
$questions = AssessmentVersion::activeQuestions($pdo);
$set = AssessmentVersion::setFor($pdo, $questions);
$programs = CareerMatcher::loadPrograms($pdo);
$titles = array_column(CBFData::activePrograms($pdo), 'title', 'id');
$ay = AcademicYear::current();
$hash = password_hash(PASSWORD, PASSWORD_BCRYPT);

$pdo->beginTransaction();
try {
    $sec = $pdo->prepare('SELECT 1 FROM sections WHERE strand = ? AND LOWER(code) = LOWER(?)');
    $sec->execute([STRAND, SECTION]);
    if (!$sec->fetch()) {
        $pdo->prepare('INSERT INTO sections (strand, code) VALUES (?, ?)')->execute([STRAND, SECTION]);
    }
    $newUser = $pdo->prepare(
        "INSERT INTO users (role, username, password_hash, email, email_verified_at, approval_status, full_name, staff_position)
         VALUES (?, ?, ?, ?, NOW(), 'approved', ?, ?) RETURNING id"
    );
    $staffIds = [];
    foreach ($staff as $position => $name) {
        $newUser->execute(['counselor', "test.$position", $hash, "test.$position@example.invalid", $name, $position]);
        $staffIds[$position] = (int) $newUser->fetchColumn();
        $pdo->prepare('INSERT INTO staff_sections (user_id, strand, section) VALUES (?, ?, ?)')->execute([$staffIds[$position], STRAND, SECTION]);
    }
    $pdo->prepare(
        "INSERT INTO exam_schedules (academic_year, exam_date, start_time, end_time, room, grade_level, strand, section, access_code, created_by)
         VALUES (?, ?, '00:00', '23:59', 'Test Room', '12', ?, ?, ?, ?)"
    )->execute([$ay, (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'), STRAND, SECTION, ACCESS_CODE, $staffIds['facilitator']]);

    $report = [];
    foreach ($students as $key => [$purpose, $career, $pattern]) {
        $username = "test.student.$key";
        $newUser->execute(['student', $username, $hash, "$username@example.invalid", null, null]);
        $id = (int) $newUser->fetchColumn();
        $pdo->prepare('INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, 'TEST-' . strtoupper($key), Crypto::enc('Student'), Crypto::enc(strtoupper($key)), STRAND, '12', SECTION, $ay]);
        if ($pattern === null) {
            $report[] = [$username, $purpose, '-', '-', '-', '-'];
            continue;
        }
        // The assessment, saved as assessment-submit.php saves it.
        $seen = [];
        $answers = array_map(function ($q) use ($pattern, &$seen) {
            $i = $seen[$q['dimension']] = ($seen[$q['dimension']] ?? -1) + 1;
            return ['questionId' => $q['id'], 'answer' => $pattern[$q['dimension']][$i % 5]];
        }, $questions);
        $scored = AssessmentVersion::score($questions, $answers);
        $scores = $scored['scores'];
        $topTypes = array_map(fn($c) => RiasecProfile::NAMES[$c], array_slice(RiasecProfile::order($scores), 0, 3));
        $a = $pdo->prepare(
            'INSERT INTO assessments (student_id, attempt_number, is_latest, score_r, score_i, score_a, score_s, score_e, score_c, top_types,
                                      question_set_id, answers_enc, scoring_version)
             VALUES (?, 1, TRUE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $a->execute([$id, $scores['R'], $scores['I'], $scores['A'], $scores['S'], $scores['E'], $scores['C'], json_encode($topTypes),
            $set['id'], Crypto::enc(json_encode($scored['answers'])), AssessmentVersion::SCORING_VERSION]);
        $assessmentId = (int) $a->fetchColumn();
        // The Career Worksheet, saved as worksheet-submit.php saves it, then the recommendation.
        $programId = CareerMatcher::resolve($career, $programs, $scores);
        $w = $pdo->prepare('INSERT INTO worksheets (student_id, attempt_number, stated_program_id, stated_career, electives, top_types)
                            VALUES (?, 1, ?, ?, ?, ?) RETURNING id');
        $w->execute([$id, $programId, $career, Careers::toLiteral([]), json_encode($topTypes)]);
        $saved = CBFData::saveRecommendation($pdo, $id, CBFData::studentProfile($pdo, $id, []), $programId, $assessmentId, (int) $w->fetchColumn());
        $profile = RiasecProfile::derive($scores);
        $report[] = [$username, $purpose,
            implode(' ', array_map(fn($c) => "$c{$scores[$c]}", ['R', 'I', 'A', 'S', 'E', 'C'])),
            $profile['code'] . ($profile['codeProvisional'] ? ' (provisional)' : ''),
            $programId !== null ? ($titles[$programId] ?? "program $programId") : 'not linked',
            implode(', ', $saved['bestRiasecMatchTitles'] ?? [])];
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    exit('Nothing was saved: ' . $e->getMessage() . "\n");
}

echo "Test accounts created (password for all: " . PASSWORD . ")\n\n";
foreach ($staff as $position => $name) {
    printf("  %-20s %s, section %s %s\n", "test.$position", $name, STRAND, SECTION);
}
echo "\n";
foreach ($report as [$u, $purpose, $scores, $code, $preferred, $best]) {
    printf("  %-16s %-19s %s\n", $u, $purpose, $scores === '-' ? 'no assessment yet' : "$scores  code $code");
    if ($scores !== '-') {
        printf("  %-16s %-19s Preferred: %s | Best RIASEC Match: %s\n", '', '', $preferred, $best);
    }
}
echo "\n  Exam schedule: section " . STRAND . ' ' . SECTION . ', today, access code ' . ACCESS_CODE . "\n";
echo "  Remove them later with: php db/seed_test_accounts.php --remove" . ($testCopy ? ' --test-copy' : '') . "\n";
