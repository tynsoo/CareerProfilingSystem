<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../Authentication/lib/LoginLock.php';
require_once __DIR__ . '/../../CareerProfiling/lib/QuestionBank.php';
require_once __DIR__ . '/../../CareerProfiling/lib/RiasecInterpretation.php';
require_once __DIR__ . '/../lib/Sections.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/Mismatch.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../Authentication/lib/SessionEnd.php';
require_once __DIR__ . '/../../CareerProfiling/lib/AssessmentProgress.php';
require_once __DIR__ . '/../lib/StudentListPdf.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

$user = Rbac::requireRole('admin', 'counselor');
$pdo = Database::get();
// Sections this staff member handles (null = everyone): see modules/UserManagement/lib/StaffScope.php.
$scope = StaffScope::forUser($pdo, $user);

// What this person may do here (the permission table, Security Configuration): the summary cards and charts
// (dashboard), the student list and profiles (studentInfo), account actions (studentAccounts), results on the profile
// (assessmentResults) and downloads with student names (exportResults, Full).
$levels = Rbac::levelsFor(Rbac::roleKey($user));
$may = fn(string $module, string $min = 'limited'): bool => Rbac::atLeast($levels[$module], $min);
$refuse = fn() => jsonResponse(['success' => false, 'error' => 'Insufficient permissions for this action.'], 403);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    if (in_array($body['type'] ?? '', ['toggleActive', 'unlock'], true) && !$may('studentAccounts', 'full')) {
        $refuse();
    }
    if (($body['type'] ?? '') === 'updateSection' && !$may('studentInfo', 'full')) {
        $refuse();
    }
    if (($body['type'] ?? '') === 'toggleActive') {
        $targetId = (int) ($body['userId'] ?? 0);
        $stmt = $pdo->prepare("SELECT u.id, u.is_active, s.strand, s.section FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'student'");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row || !StaffScope::allows($scope, $row['strand'], $row['section'])) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $newState = !$row['is_active'];
        // PDOStatement::execute() stringifies a bound PHP bool — false
        // becomes '' (not '0'), which Postgres's boolean type rejects
        // outright ("invalid input syntax for type boolean"). Bind an int
        // instead; Postgres accepts 0/1 for boolean.
        $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $targetId]);
        if (!$newState) {
            SessionEnd::endFor($pdo, $targetId, 'deactivated'); // signed out right away, not only at the next sign-in
        }
        // login.php already rejects any user (any role) with is_active =
        // false, so deactivating here immediately blocks sign-in -- no
        // separate enforcement needed.
        AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_student_account' : 'deactivate_student_account', 'user', (string) $targetId);
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }
    // Admin only: delete a student's answers (assessment, worksheet and the recommendation built from
    // them) so they can take the assessment again, for the rare case guidance allows a retake.
    // Unlock a student locked out by wrong passwords (for example a classmate typing their Student
    // Number on exam day), so they can sign in right away. Same staff and sections as Activate/Deactivate.
    if (($body['type'] ?? '') === 'unlock') {
        $targetId = (int) ($body['userId'] ?? 0);
        $stmt = $pdo->prepare("SELECT u.id, s.strand, s.section FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'student'");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row || !StaffScope::allows($scope, $row['strand'], $row['section'])) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?')->execute([$targetId]);
        LoginLock::clear($pdo, LoginLock::forAccount($targetId));   // the next lock is short again
        AuditLogger::log($user['id'], $user['role'], 'unlock_account', 'user', (string) $targetId, 'Unlocked after failed sign-in attempts');
        jsonResponse(['success' => true]);
    }

    if (($body['type'] ?? '') === 'resetAssessment') {
        if (!Rbac::isAdminOrCoAdmin($user)) {
            jsonResponse(['success' => false, 'error' => 'Only an administrator can reset a student\'s assessment.'], 403);
        }
        $targetId = (int) ($body['userId'] ?? 0);
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student'");
        $exists->execute([$targetId]);
        if (!$exists->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        $pdo->beginTransaction();
        try {
            foreach (['monitoring_flags', 'recommendations', 'worksheets', 'assessments'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE student_id = ?")->execute([$targetId]);
            }
            AssessmentProgress::clear($pdo, $targetId);   // answers saved from an unfinished attempt
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[reset-assessment] failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not reset the assessment. Please try again.'], 500);
        }
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key IN (?, ?)')->execute(['access-code:' . $targetId, 'result-email:' . $targetId]);
        AuditLogger::log($user['id'], $user['role'], 'reset_student_assessment', 'user', (string) $targetId, 'Previous answers deleted so the student can retake it');
        jsonResponse(['success' => true]);
    }
    // Correct a student's section after registration. Only sections that belong
    // to the student's own strand are accepted (same allow-list as registration).
    // Exam gating and notifications read students.section on every request, so
    // the change applies immediately.
    if (($body['type'] ?? '') === 'updateSection') {
        $targetId = (int) ($body['userId'] ?? 0);
        $section = trim((string) ($body['section'] ?? ''));
        $stmt = $pdo->prepare('SELECT strand, section FROM students WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $student = $stmt->fetch();
        if (!$student || !StaffScope::allows($scope, $student['strand'], $student['section'])) {
            jsonResponse(['success' => false, 'error' => 'Student account not found.'], 404);
        }
        if (!Sections::isValid($pdo, $student['strand'], $section)) {
            jsonResponse(['success' => false, 'error' => 'Invalid section for this student\'s strand.'], 400);
        }
        if ($student['section'] !== $section) {
            $pdo->prepare('UPDATE students SET section = ? WHERE user_id = ?')->execute([$section, $targetId]);
            AuditLogger::log($user['id'], $user['role'], 'update_student_section', 'user', (string) $targetId, "Section: {$student['section']} -> $section");
        }
        jsonResponse(['success' => true, 'section' => $section]);
    }
    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Single-student lookup mode: student-profile.html loads a real record by the
// student's internal user id (never the Student Number, so it doesn't end up in the web
// address or browser history) instead of everything being smuggled through
// URL query params.
$idLookup = (int) ($_GET['id'] ?? 0);
if ($idLookup > 0) {
    if (!$may('studentInfo')) {
        $refuse();
    }
    $seesResults = $may('assessmentResults');
    $seesAccounts = $may('studentAccounts'); // No Access on Student account management: no active or locked state is sent
    $stmt = $pdo->prepare(
        'SELECT s.user_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section,
                s.academic_year, s.registered_at, u.is_active, u.locked_until, a.top_types, a.completed_at, a.score_r, a.score_i, a.score_a,
                a.score_s, a.score_e, a.score_c
         FROM students s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
         WHERE s.user_id = ?'
    );
    $stmt->execute([$idLookup]);
    $row = $stmt->fetch();
    if (!$row || !StaffScope::allows($scope, $row['strand'], $row['section'])) {
        jsonResponse(['error' => 'Student not found'], 404);
    }
    $hasAssessment = $row['completed_at'] !== null;
    // subject is encrypted (subject_enc), so it can't be matched with a
    // plain SQL WHERE clause — decrypt and compare in PHP instead. Cheap
    // here since it's scoped to one student's own requests.
    $counseledStmt = $pdo->prepare('SELECT subject_enc FROM help_requests WHERE student_id = ?');
    $counseledStmt->execute([(int) $row['user_id']]);
    $counseled = false;
    foreach ($counseledStmt->fetchAll(PDO::FETCH_COLUMN) as $subjectEnc) {
        if (Crypto::dec($subjectEnc) === 'Request for Academic Advising') {
            $counseled = true;
            break;
        }
    }

    $openStmt = $pdo->prepare("SELECT 1 FROM help_requests WHERE student_id = ? AND status = 'open' LIMIT 1");
    $openStmt->execute([(int) $row['user_id']]);
    $openRequest = (bool) $openStmt->fetchColumn();

    // Full attempt history (not just is_latest), for the "Assessment
    // Attempts" section. Attempts made before retakes were removed still show.
    $attemptsStmt = $pdo->prepare(
        'SELECT id, attempt_number, top_types, completed_at, score_r, score_i, score_a, score_s, score_e, score_c
         FROM assessments WHERE student_id = ? ORDER BY attempt_number DESC'
    );
    $attemptsStmt->execute([(int) $row['user_id']]);

    $scoresOf = fn(array $a) => [
        'R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
        'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c'],
    ];
    // The staff profile names the interest areas and marks ties but never shows a score (Chapter 4, Table 7 and Figure 23).
    $withoutScores = function (?array $i): ?array {
        if ($i === null) {
            return null;
        }
        $i['summary'] = preg_replace(['/ \(\d+(?:, \d+)*(?: each)?\)/', '/scored \d+\./'], ['', 'scored the same.'], $i['summary']);
        unset($i['scores'], $i['lines']);
        $i['ranked'] = array_map(function ($r) {
            unset($r['score']);
            return $r;
        }, $i['ranked']);
        return $i;
    };
    $attempts = !$seesResults ? [] : array_map(fn($a) => [
        'attemptNumber' => (int) $a['attempt_number'],
        'completedAt' => $a['completed_at'],
        'riasec' => implode(', ', json_decode($a['top_types'], true)),
        'riasecLabel' => RiasecInterpretation::codeLabel(RiasecInterpretation::interpret($scoresOf($a))),
    ], $attemptsStmt->fetchAll());

    jsonResponse(['student' => [
        'userId' => (int) $row['user_id'],
        'firstName' => Crypto::dec($row['first_name_enc']),
        'lastName' => Crypto::dec($row['last_name_enc']),
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
        'gradeLevel' => $row['grade_level'],
        'section' => $row['section'],
        'allowedSections' => (function () use ($pdo, $row) {
            $sections = Sections::byStrand($pdo)[$row['strand']] ?? [];
            // Keep the student's current section selectable even if it's
            // since been deactivated — otherwise the dropdown silently
            // wouldn't offer their own existing value.
            if ($row['section'] && !in_array($row['section'], $sections, true)) {
                $sections[] = $row['section'];
            }
            return $sections;
        })(),
        'academicYear' => $row['academic_year'],
        'isActive' => $seesAccounts ? (bool) $row['is_active'] : null,
        // Set while the account is locked by wrong passwords (the profile then offers Unlock).
        'lockedUntil' => $seesAccounts && $row['locked_until'] !== null && strtotime($row['locked_until']) > time() ? $row['locked_until'] : null,
        'status' => $hasAssessment ? 'Completed' : 'Pending',
        // Without Assessment results the profile shows whether the student finished, but no RIASEC code, scores or attempts.
        'seesResults' => $seesResults,
        'riasec' => $hasAssessment && $seesResults ? implode(', ', json_decode($row['top_types'], true)) : '',
        // What the scores establish, ties included (modules/CareerProfiling/lib/RiasecInterpretation.php), without the scores.
        'interpretation' => $hasAssessment && $seesResults ? $withoutScores(RiasecInterpretation::interpret($scoresOf($row))) : null,
        'counseling' => $hasAssessment ? ($counseled ? 'Availed' : 'Did Not Avail') : '',
        'openRequest' => $openRequest,
        'registeredAt' => $row['registered_at'],
        'attempts' => $attempts,
    ]]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// ?all=1 (used by the Announcements student-picker) returns everyone
// matching the filters in one response instead of paginating.
// ?pageSize= is the Rows per page choice on Student Information (25, 50 or 100); anything else keeps 8.
$askedPageSize = (int) ($_GET['pageSize'] ?? 0);
$pageSize = isset($_GET['all']) ? PHP_INT_MAX : (in_array($askedPageSize, [25, 50, 100], true) ? $askedPageSize : 8);
$search = trim((string) ($_GET['search'] ?? ''));
$strandFilter = (string) ($_GET['strand'] ?? '');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$counselingFilter = (string) ($_GET['counseling'] ?? '');
$registrationFilter = (string) ($_GET['registration'] ?? ''); // registered | notRegistered
$accountFilter = (string) ($_GET['account'] ?? '');           // active | inactive (a registered student's sign-in)
$assessmentFilter = (string) ($_GET['assessment'] ?? '');     // assessed | notAssessed
$asCsv = ($_GET['format'] ?? '') === 'csv';
$asPdf = ($_GET['format'] ?? '') === 'pdf';

// The Announcements student picker (?all=1) needs the list to choose who gets an announcement, so Full on Announcements
// opens it too. Everything else needs the cards (dashboard) or the list (studentInfo); downloads with names need Full on
// Export student results.
$isPicker = isset($_GET['all']) && $may('announcements', 'full');
$seesList = $may('studentInfo') || $isPicker;
$seesCards = $may('dashboard');
if (!$seesList && !$seesCards) {
    $refuse();
}
if (!$seesList) {
    $search = ''; // no names are decrypted for someone who only sees the cards
}
if (($asCsv || $asPdf) && (!$may('exportResults', 'full') || !$may('studentInfo'))) {
    $refuse();
}
$exportsResults = $may('assessmentResults'); // the downloads leave out the RIASEC code without Assessment results
// Without Student account management (No Access), a student's account state (active, deactivated, locked) is not sent,
// shown in a download, or usable as a filter.
$seesAccounts = $may('studentAccounts');
if (!$seesAccounts) {
    $accountFilter = '';
}

$rows = $pdo->query(
    'SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.strand, s.grade_level, s.section, s.registered_at, s.academic_year,
            u.is_active, u.email, (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS locked, a.top_types, a.completed_at,
            a.score_r, a.score_i, a.score_a, a.score_s, a.score_e, a.score_c
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
     ORDER BY s.registered_at DESC, s.user_id DESC' // the id breaks ties, so a row never moves between pages
)->fetchAll();

// A student "availed" counseling if they've submitted a Schedule Advising request
// from Help Center (results.html links there with a fixed subject line). This is
// distinct from monitoring escalation, which is a counselor-initiated review of a
// low-confidence recommendation, not the student asking for advising themselves.
//
// subject is now encrypted (subject_enc), so it can't be matched with a plain
// SQL WHERE clause anymore — encryption produces different ciphertext every
// time, even for the same input, so this has to decrypt and compare in PHP.
$counseledIds = [];
$firstAdvisingAt = []; // student_id => timestamp of their earliest advising request (for the monthly trend)
$helpRequestRows = $pdo->query(
    'SELECT student_id, subject_enc, sent_at FROM help_requests WHERE student_id IS NOT NULL'
)->fetchAll();
foreach ($helpRequestRows as $hr) {
    if (Crypto::dec($hr['subject_enc']) === 'Request for Academic Advising') {
        $sid = (int) $hr['student_id'];
        $counseledIds[$sid] = true;
        $sentTs = strtotime((string) $hr['sent_at']);
        if (!isset($firstAdvisingAt[$sid]) || $sentTs < $firstAdvisingAt[$sid]) {
            $firstAdvisingAt[$sid] = $sentTs;
        }
    }
}

$allRegisteredSchoolIds = array_column($rows, 'school_id'); // before scoping: a student registered elsewhere is not 'Not Registered'
// ?academicYear= picks the school year (the current one by default): the students registered in that year or on that
// year's class roster, and the roster students who have not registered yet. Same rule as Analytics.
$viewAy = AcademicYear::resolve($pdo, (string) ($_GET['academicYear'] ?? ''));
$viewRoster = AcademicYear::rosterIds($pdo, $viewAy);
$rows = array_values(array_filter($rows, fn($r) => AcademicYear::studentBelongs($r['academic_year'], (string) $r['school_id'], $viewAy, $viewRoster)));
$rows = array_values(array_filter($rows, fn($r) => StaffScope::allows($scope, $r['strand'], $r['section'])));
$mismatchIds = Mismatch::studentIds($pdo); // finished students whose Preferred Course isn't a Best RIASEC Match
// Students with a recommendation for their latest assessment (assessment + worksheet done), the same rule as
// Mismatch::studentIds: they are either matched or mismatched. An assessment without a worksheet is neither yet.
$recommendedIds = array_flip(array_map('intval', $pdo->query(
    'SELECT DISTINCT student_id FROM recommendations WHERE source_assessment_id IN (SELECT id FROM assessments WHERE is_latest = TRUE)'
)->fetchAll(PDO::FETCH_COLUMN)));

$students = array_map(function ($r) use ($counseledIds, $mismatchIds, $recommendedIds) {
    $hasAssessment = $r['completed_at'] !== null;
    $status = $hasAssessment ? 'Completed' : 'Pending';
    $topTypes = $hasAssessment ? json_decode($r['top_types'], true) : [];
    $interpretation = $hasAssessment ? RiasecInterpretation::interpret([
        'R' => $r['score_r'], 'I' => $r['score_i'], 'A' => $r['score_a'], 'S' => $r['score_s'], 'E' => $r['score_e'], 'C' => $r['score_c'],
    ]) : null;
    $counseling = $hasAssessment ? (isset($counseledIds[(int) $r['user_id']]) ? 'Availed' : 'Did Not Avail') : '';

    return [
        'userId' => (int) $r['user_id'],
        // The name stays encrypted until a row is really shown, searched or downloaded ($withName below):
        // decrypting every student on every filter click was the slow part of this endpoint.
        '_lastEnc' => $r['last_name_enc'],
        '_firstEnc' => $r['first_name_enc'],
        'strand' => $r['strand'],
        'gradeLevel' => $r['grade_level'],
        'section' => $r['section'],
        'isActive' => (bool) $r['is_active'],
        'locked' => (bool) $r['locked'],   // locked by wrong passwords: the list shows Locked and Unlock
        'status' => $status,
        'riasec' => implode(', ', $topTypes),
        // Equal scores: no unique code. riasecLabel says so ("CER or CEI (tied)"); riasec keeps the stored order.
        'riasecTied' => $interpretation !== null && $interpretation['status'] !== 'distinct',
        'riasecLabel' => $interpretation !== null ? RiasecInterpretation::codeLabel($interpretation) : '',
        '_riasecShort' => $interpretation !== null ? RiasecInterpretation::shortLabel($interpretation) : '',
        '_riasecTie' => $interpretation !== null ? RiasecInterpretation::tieLabel($interpretation) : '',
        'counseling' => $counseling,
        'mismatched' => $hasAssessment && isset($mismatchIds[(int) $r['user_id']]),
        // For the Course Match chart only (counted per section below, never sent per student).
        'matched' => $hasAssessment && isset($recommendedIds[(int) $r['user_id']]) && !isset($mismatchIds[(int) $r['user_id']]),
        'registeredAt' => $r['registered_at'],
        'assessmentDate' => $r['completed_at'],
        '_email' => $r['email'], // only for the CSV download; never sent in the list JSON
        '_schoolId' => $r['school_id'], // only for the PDF download
    ];
}, $rows);

// Students on the current Academic Year's uploaded roster who haven't
// registered an account yet — shown here (userId: null, status "Not
// Registered", no assessment/counseling data) so the overview reflects a
// roster upload, not just who happened to sign up. A roster row whose
// school_id already matches a registered student is skipped entirely;
// the real account's live data always wins over the one-time CSV snapshot.
$currentAy = $viewAy;
if ($currentAy !== '') {
    $registeredSchoolIds = array_flip(array_map('strval', $allRegisteredSchoolIds)); // a lookup, not a scan per roster row
    $rosterStmt = $pdo->prepare('SELECT id, school_id, name_enc, strand, section, email FROM assessment_roster WHERE academic_year = ? ORDER BY id');
    $rosterStmt->execute([$currentAy]);
    foreach ($rosterStmt->fetchAll() as $rr) {
        if (isset($registeredSchoolIds[(string) $rr['school_id']]) || !StaffScope::allows($scope, $rr['strand'], $rr['section'])) {
            continue;
        }
        $students[] = [
            'userId' => null,
            '_nameEnc' => $rr['name_enc'],
            'strand' => $rr['strand'],
            'gradeLevel' => null,
            'section' => $rr['section'],
            'isActive' => null,
            'status' => 'Not Registered',
            'riasec' => '',
            'riasecTied' => false,
            'riasecLabel' => '',
            '_riasecShort' => '',
            '_riasecTie' => '',
            'counseling' => '',
            'mismatched' => false,
            'matched' => false,
            'registeredAt' => null,
            'assessmentDate' => null,
            'rosterId' => (int) $rr['id'],
            'hasEmail' => trim((string) $rr['email']) !== '',
            '_email' => $rr['email'],
            '_schoolId' => $rr['school_id'],
        ];
    }
}

$totalStudents = count($students);
$completedCount = count(array_filter($students, fn($s) => $s['status'] === 'Completed'));
$pendingCount = $totalStudents - $completedCount;
$counselingCount = count(array_filter($students, fn($s) => $s['counseling'] === 'Availed'));
$mismatchedCount = count(array_filter($students, fn($s) => $s['mismatched']));

// Each summary card's number split by strand, for the breakdown a card opens on the dashboard. Counted the
// same way as the cards (every student in view, before the table's filters), so each split adds up to its card.
$byStrand = [];
foreach (array_unique([...Sections::STRANDS, ...array_column($students, 'strand')]) as $strandName) {
    $byStrand[$strandName] = ['strand' => $strandName, 'total' => 0, 'completed' => 0, 'pending' => 0, 'mismatched' => 0];
}
foreach ($students as $s) {
    $byStrand[$s['strand']]['total']++;
    $byStrand[$s['strand']][$s['status'] === 'Completed' ? 'completed' : 'pending']++;
    if ($s['mismatched']) {
        $byStrand[$s['strand']]['mismatched']++;
    }
}

// A summary per section for the dashboard: how many students each section has and where they stand.
$sectionSummary = [];
foreach ($students as $s) {
    $key = $s['strand'] . '|' . $s['section'];
    if (!isset($sectionSummary[$key])) {
        // Counts only: no page reads names per section, so they are not sent (fewer names leave the server).
        $sectionSummary[$key] = [
            'strand' => $s['strand'], 'section' => $s['section'], 'total' => 0, 'completed' => 0, 'pending' => 0, 'notRegistered' => 0, 'mismatched' => 0, 'matched' => 0,
        ];
    }
    $sectionSummary[$key]['total']++;
    if ($s['status'] === 'Completed') {
        $sectionSummary[$key]['completed']++;
    } elseif ($s['status'] === 'Not Registered') {
        $sectionSummary[$key]['notRegistered']++;
    } else {
        $sectionSummary[$key]['pending']++;
    }
    if ($s['mismatched']) {
        $sectionSummary[$key]['mismatched']++;
    }
    if ($s['matched']) {
        $sectionSummary[$key]['matched']++;
    }
}
ksort($sectionSummary);
foreach ($sectionSummary as &$sec) {
    $sec['registered'] = $sec['total'] - $sec['notRegistered'];
    // Assessed out of everyone expected in the section (registered or on the roster).
    $sec['completionRate'] = $sec['total'] > 0 ? round($sec['completed'] / $sec['total'] * 100, 1) : 0.0;
}
unset($sec);

// Decrypts a row's name the first time it is needed (shown, searched or downloaded) and drops the ciphertext.
$withName = function (array $s): array {
    if (!array_key_exists('name', $s)) {
        $s['name'] = isset($s['_nameEnc'])
            ? Crypto::dec($s['_nameEnc'])
            : Crypto::dec($s['_lastEnc']) . ', ' . Crypto::dec($s['_firstEnc']);
    }
    unset($s['_nameEnc'], $s['_lastEnc'], $s['_firstEnc']);
    return $s;
};

// Filters on plain columns first; the name search runs last, so only the rows still left get decrypted.
$filtered = $students;
if ($strandFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['strand'] === $strandFilter));
}
if ($sectionFilter !== '') {
    $needleSection = mb_strtolower($sectionFilter);
    $filtered = array_values(array_filter($filtered, fn($s) => mb_strtolower($s['section']) === $needleSection));
}
if ($statusFilter === 'Mismatched') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['mismatched']));
} elseif ($statusFilter === 'Matched') {
    // Course Match "Matched" (an assessment result): only with Assessment results access, otherwise nobody matches.
    $filtered = $may('assessmentResults') ? array_values(array_filter($filtered, fn($s) => $s['matched'])) : [];
} elseif ($statusFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === $statusFilter));
}
if ($counselingFilter !== '') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['counseling'] === $counselingFilter));
}
if ($accountFilter === 'active') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['isActive'] === true));
} elseif ($accountFilter === 'inactive') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['isActive'] === false));
}
if ($registrationFilter === 'registered') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] !== 'Not Registered'));
} elseif ($registrationFilter === 'notRegistered') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === 'Not Registered'));
}
if ($assessmentFilter === 'assessed') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] === 'Completed'));
} elseif ($assessmentFilter === 'notAssessed') {
    $filtered = array_values(array_filter($filtered, fn($s) => $s['status'] !== 'Completed')); // pending or not registered
}
if ($search !== '') {
    $needle = mb_strtolower($search);
    $filtered = array_values(array_filter(array_map($withName, $filtered), fn($s) => str_contains(mb_strtolower($s['name']), $needle)));
}

if (($asCsv || $asPdf) && !$exportsResults) {
    $filtered = array_map(fn($s) => ['riasec' => '', 'riasecTied' => false, 'riasecLabel' => '', '_riasecShort' => '', '_riasecTie' => ''] + $s, $filtered); // no RIASEC code in the file
}

// A downloadable copy of the list as filtered (every row, not just one page), for following up with
// students who haven't registered or haven't taken the assessment.
if ($asCsv) {
    $filtered = array_map($withName, $filtered);
    usort($filtered, fn($a, $b) => [$a['strand'], $a['section'], $a['name']] <=> [$b['strand'], $b['section'], $b['name']]);
    // A cell that starts with = + - or @ would be run as a formula by Excel.
    $safe = fn($v) => is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false ? "'" . $v : (string) $v;
    $parts = array_filter([
        $registrationFilter === 'notRegistered' ? 'not-registered' : ($registrationFilter === 'registered' ? 'registered' : ''),
        $assessmentFilter === 'notAssessed' ? 'not-assessed' : ($assessmentFilter === 'assessed' ? 'assessed' : ''),
        $strandFilter, $sectionFilter,
    ]);
    $fileName = 'students-' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts)) ?: 'all') . '-' . date('Y-m-d') . '.csv';
    AuditLogger::log($user['id'], $user['role'], 'export_student_list', 'student', null, count($filtered) . " row(s): $fileName");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with Ñ and accents correctly
    fputcsv($out, ['Name', 'Strand', 'Section', 'Registered', 'Assessed', 'Assessment Date', 'Holland Code (Top 3, ranked)', 'Top-3 Ties', 'Email'], escape: '\\');
    foreach ($filtered as $s) {
        fputcsv($out, [
            $safe($s['name']), $s['strand'], $s['section'],
            $s['status'] === 'Not Registered' ? 'No' : 'Yes',
            $s['status'] === 'Completed' ? 'Yes' : 'No',
            $s['assessmentDate'] ? date('Y-m-d', strtotime((string) $s['assessmentDate'])) : '',
            QuestionBank::hollandCode(array_values(array_filter(explode(', ', (string) ($s['riasec'] ?? ''))))),
            // "Unique", "Tied: CER or CEI" or "All six equal": equal scores have no unique ranked code.
            (string) ($s['_riasecTie'] ?? ''),
            $safe((string) ($s['_email'] ?? '')),
        ], escape: '\\');
    }
    fclose($out);
    exit;
}

// The list as a PDF, with every row matching the filters (not just one page) and the filters printed on it.
// Same safeguards as the audit log export: started from the page only, rate limited, and recorded in the
// audit log with a reference code that is also printed on every page.
if ($asPdf) {
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
        jsonResponse(['error' => 'Start the download from the Student Information page.'], 403);
    }
    if (RateLimiter::tooMany('student-list-pdf:' . (int) $user['id'], 20, 60)) {
        jsonResponse(['error' => 'Too many downloads in the last hour. Please try again later.'], 429);
    }
    $filtered = array_map($withName, $filtered);
    usort($filtered, fn($a, $b) => [$a['strand'], $a['section'], $a['name']] <=> [$b['strand'], $b['section'], $b['name']]);
    $labels = [
        'status' => ['Completed' => 'Completed', 'Pending' => 'Pending', 'Mismatched' => 'Mismatched', 'Matched' => 'Matched', 'Not Registered' => 'Not registered'],
        'registration' => ['registered' => 'Registered', 'notRegistered' => 'Not registered'],
        'account' => ['active' => 'Active', 'inactive' => 'Deactivated'],
        'assessment' => ['assessed' => 'Yes', 'notAssessed' => 'No'],
        'counseling' => ['Availed' => 'Availed', 'Did Not Avail' => 'Did not avail'],
    ];
    $applied = array_filter([
        $search !== '' ? 'Search "' . $search . '"' : '',
        $strandFilter !== '' ? 'Strand ' . $strandFilter : '',
        $sectionFilter !== '' ? 'Section ' . $sectionFilter : '',
        $registrationFilter !== '' ? 'Registration: ' . ($labels['registration'][$registrationFilter] ?? $registrationFilter) : '',
        $accountFilter !== '' ? 'Account: ' . ($labels['account'][$accountFilter] ?? $accountFilter) : '',
        $statusFilter !== '' ? 'Assessment status: ' . ($labels['status'][$statusFilter] ?? $statusFilter) : '',
        $assessmentFilter !== '' ? 'Assessment taken: ' . ($labels['assessment'][$assessmentFilter] ?? $assessmentFilter) : '',
        $counselingFilter !== '' ? 'Counseling: ' . ($labels['counseling'][$counselingFilter] ?? $counselingFilter) : '',
    ]);
    $filters = $applied ? implode('; ', $applied) : 'None (all students)';
    $reference = 'STU-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $count = fn(callable $test) => number_format(count(array_filter($filtered, $test)));
    $counts = 'In this list: ' . $count(fn($s) => $s['status'] === 'Completed') . ' completed, '
        . $count(fn($s) => $s['status'] === 'Pending') . ' pending, '
        . $count(fn($s) => $s['status'] === 'Not Registered') . ' not registered, '
        . $count(fn($s) => $s['mismatched']) . ' mismatched.'
        . (array_filter($filtered, fn($s) => !empty($s['riasecTied'])) ? ' "Tie" / "All equal": equal scores, so there is no unique Top-3 code.' : '');
    try {
        // Inside the try: the student names were decrypted above, but nothing here should break the page with a raw error.
        $rows = array_map(fn($s) => [
            $s['name'],
            (string) ($s['_schoolId'] ?? ''),
            (string) $s['strand'],
            (string) $s['section'],
            !$seesAccounts ? '-' : ($s['userId'] === null ? 'No account' : (!$s['isActive'] ? 'Deactivated' : (!empty($s['locked']) ? 'Locked' : 'Active'))),
            // The same words as the page: Completed, Pending, Not registered (a mismatched result is always a completed one).
            $s['mismatched'] ? 'Mismatched' : ($s['status'] === 'Not Registered' ? 'Not registered' : $s['status']),
            $s['assessmentDate'] ? date('M j, Y', strtotime((string) $s['assessmentDate'])) : '-',
            ($s['_riasecShort'] ?? '') !== '' ? $s['_riasecShort'] : (QuestionBank::hollandCode(array_values(array_filter(explode(', ', (string) ($s['riasec'] ?? ''))))) ?: '-'),
            $s['counseling'] !== '' ? $s['counseling'] : 'No request',
        ], $filtered);
        $position = $user['role'] === 'counselor' ? ($user['positionLabel'] ?? 'Guidance Counselor') : 'Administrator';
        $pdfBytes = StudentListPdf::build($rows, [
            'exportedBy' => ($user['displayName'] ?? $user['username']) . " ($position)",
            'exportedAt' => (new DateTime())->format('M j, Y g:i A'),
            'reference' => $reference,
            'schoolYear' => $viewAy !== '' ? $viewAy : 'not set',
            'filters' => $filters,
            'counts' => $counts,
        ]);
    } catch (Throwable $e) {
        error_log('[student-list-pdf] ' . $e->getMessage());
        AuditLogger::log($user['id'], $user['role'], 'export_student_list_pdf_failed', 'student', $reference, 'PDF could not be built');
        jsonResponse(['error' => 'The PDF could not be made. Please try again.'], 500);
    }
    // The filters stay in the encrypted detail (a search can contain a name); the reference goes in target_id.
    AuditLogger::log($user['id'], $user['role'], 'export_student_list_pdf', 'student', $reference, 'PDF, ' . count($rows) . ' students. Filters: ' . $filters);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="students-' . date('Y-m-d') . '-' . $reference . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: no-store');
    header('X-Export-Reference: ' . $reference);
    echo $pdfBytes;
    exit;
}

// Only the cards: no student rows (and no names) leave the server.
if (!$seesList) {
    $filtered = [];
}
$total = count($filtered);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$seesResults = $may('assessmentResults');
// A registered student's account email is shown to staff (it is their sign-in); a roster email is never sent to the page.
$pageRows = array_map(function ($s) use ($withName, $seesResults, $seesAccounts) {
    $s = $withName($s); // only the rows on this page are decrypted (all of them for ?all=1)
    $s['email'] = $s['userId'] !== null ? $s['_email'] : null;
    if (!$seesResults) {
        $s['riasec'] = ''; // the RIASEC code is an assessment result
        $s['riasecTied'] = false;
        $s['riasecLabel'] = '';
    }
    unset($s['_riasecShort'], $s['_riasecTie']);
    if (!$seesAccounts) {
        $s['isActive'] = null; // the account state belongs to Student account management
        unset($s['locked']);
    }
    unset($s['_email'], $s['_schoolId'], $s['matched']); // matched: only as a count per section
    return $s;
}, array_slice($filtered, $offset, $pageSize));
// The Announcements picker without Student information gets only what it needs to choose recipients.
if (!$may('studentInfo')) {
    $pageRows = array_map(fn($s) => ['userId' => $s['userId'], 'name' => $s['name'], 'strand' => $s['strand'], 'section' => $s['section']], $pageRows);
}

jsonResponse([
    'students' => $pageRows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'startIndex' => $total > 0 ? $offset + 1 : 0,
    // What this person may see here, so the page shows only the parts that are theirs.
    'seesList' => $seesList,
    'seesCards' => $seesCards,
    // Matched (Course Match chart) is an assessment result: only with Assessment results access.
    'sections' => !$seesCards ? [] : ($seesResults ? array_values($sectionSummary)
        : array_map(function ($sec) { unset($sec['matched']); return $sec; }, array_values($sectionSummary))),
    'academicYear' => $viewAy,
    'academicYears' => AcademicYear::available($pdo),
    'isPastYear' => AcademicYear::isPast($viewAy),
    'summary' => !$seesCards ? null : [
        'totalStudents' => $totalStudents,
        'completedCount' => $completedCount,
        'pendingCount' => $pendingCount,
        'counselingCount' => $counselingCount,
        'mismatchedCount' => $mismatchedCount,
        'byStrand' => array_values($byStrand),
    ],
]);
