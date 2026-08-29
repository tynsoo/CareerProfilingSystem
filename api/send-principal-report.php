<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AnalyticsReport.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

// GET builds and returns the same fixed sections the report will contain,
// for an admin to preview before sending — it never touches Mailer.
// POST builds the identical sections and actually emails them. Both
// branches share buildSections() below so the preview can never drift
// from what actually gets sent.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Viewing or sending the Principal report is restricted to admins only —
// the same bar as every other Security Configuration write, rather than
// tied to the 'monitoring' RBAC module (which counselors also hold 'full' on).
$user = Auth::requireLogin();
if ($user['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Only administrators can view or send the Principal report.'], 403);
}
$pdo = Database::get();

$policyRow = fn(string $k) => (string) ($pdo->query('SELECT value FROM security_policies WHERE key = ' . $pdo->quote($k))->fetchColumn() ?: '');
$principalName = $policyRow('principal.name');
$principalEmail = $policyRow('principal.email');
$currentAy = $policyRow('academicYear.current');

if ($principalName === '' || $principalEmail === '') {
    jsonResponse(['success' => false, 'error' => 'Set the Principal\'s name and email in Security Configuration first.'], 400);
}
if ($currentAy === '') {
    jsonResponse(['success' => false, 'error' => 'Set the current Academic Year in Security Configuration first.'], 400);
}

/** @return array<int,array{title:string,rows:array<int,array{0:string,1:string}>}> */
function buildReportSections(array $data, string $currentAy): array
{
    $enrollmentRows = [
        ['Total Registered Students', (string) $data['totalStudents']],
        ['RIASEC Assessment Completion', $data['completion']['rate'] . '% (' . $data['completion']['count'] . ' of ' . $data['completion']['total'] . ')'],
        ['Career Worksheet Completion', $data['worksheet']['rate'] . '% (' . $data['worksheet']['count'] . ' of ' . $data['worksheet']['total'] . ')'],
        ['Recommendation Confidence Rate', $data['confidence']['rate'] . '% (' . $data['confidence']['count'] . ' of ' . $data['confidence']['total'] . ')'],
    ];

    $rosterRows = [
        ['Expected Students (Uploaded Roster)', (string) $data['assessmentStats']['expectedCount']],
        ['Completed Assessment', (string) $data['assessmentStats']['completedCount']],
        ['Sections in Roster', $data['assessmentStats']['expectedSections'] ? implode(', ', $data['assessmentStats']['expectedSections']) : '—'],
    ];

    $strandRows = [];
    foreach ($data['strandDistribution'] as $row) {
        $strandRows[] = [$row['strand'], (string) $row['count'] . ' student(s)'];
    }
    if (!$strandRows) {
        $strandRows[] = ['No students registered yet', '—'];
    }
    if ($data['topStrand']) {
        $strandRows[] = ['Top Strand', $data['topStrand']['strand'] . ' (' . $data['topStrand']['percent'] . '% of students)'];
    }

    $careerRows = [];
    $rank = 1;
    foreach ($data['topCareers'] as $c) {
        $careerRows[] = ['#' . $rank . ' ' . $c['title'], $c['count'] . ' student(s) (' . $c['percent'] . '%)'];
        $rank++;
    }
    if (!$careerRows) {
        $careerRows[] = ['No recommendations computed yet', '—'];
    }

    return [
        ['title' => 'Enrollment & Completion', 'rows' => $enrollmentRows],
        ['title' => 'Assessment Roster — AY ' . $currentAy, 'rows' => $rosterRows],
        ['title' => 'Students by Strand', 'rows' => $strandRows],
        ['title' => 'Top Recommended Careers (Institution-wide)', 'rows' => $careerRows],
    ];
}

// Whole-school figures, unfiltered — the Principal's report is always the
// institution-wide picture, not whatever strand/section an admin happened
// to have selected on the Analytics Dashboard.
$data = AnalyticsReport::compute($pdo, '', '');
$generatedAt = date('F j, Y \a\t g:i A');
$sections = buildReportSections($data, $currentAy);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse([
        'success' => true,
        'academicYear' => $currentAy,
        'generatedAt' => $generatedAt,
        'principalName' => $principalName,
        'principalEmail' => $principalEmail,
        'sections' => $sections,
    ]);
}

// POST from here — actually send.
$safeName = htmlspecialchars($principalName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::renderReport(
    'SHS Career Profiling Summary Report',
    'Academic Year ' . htmlspecialchars($currentAy, ENT_QUOTES, 'UTF-8') . ' &middot; Generated ' . $generatedAt,
    "<p style=\"margin:0;\">Dear $safeName,</p><p style=\"margin:12px 0 0 0;\">Below is a summary of student career-profiling activity generated automatically by ProfilePath, so figures are always drawn from the same live data shown on the Analytics Dashboard — no manual re-entry.</p>",
    $sections
);

$bodyText = "SHS Career Profiling Summary Report\nAcademic Year $currentAy — Generated $generatedAt\n\n"
    . "Dear $principalName,\n\nBelow is a summary of student career-profiling activity, generated automatically by ProfilePath.\n\n"
    . implode("\n\n", array_map(
        fn($s) => strtoupper($s['title']) . "\n" . implode("\n", array_map(fn($r) => "- {$r[0]}: {$r[1]}", $s['rows'])),
        $sections
    ));

$sent = Mailer::send($principalEmail, $principalName, 'ProfilePath Summary Report — AY ' . $currentAy, $bodyHtml, $bodyText);

if ($sent) {
    $nowIso = date('c');
    $stmt = $pdo->prepare(
        'INSERT INTO security_policies (key, value, updated_by) VALUES (?, ?, ?)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by'
    );
    $stmt->execute(['principal.lastSentAt', $nowIso, $user['id']]);

    AuditLogger::log($user['id'], $user['role'], 'send_principal_report', 'security_policies', 'principal', "Sent summary report to $principalName <$principalEmail> for AY $currentAy");
    jsonResponse(['success' => true, 'sentAt' => $nowIso]);
}

AuditLogger::log($user['id'], $user['role'], 'send_principal_report_failed', 'security_policies', 'principal', "Failed to send to $principalName <$principalEmail> for AY $currentAy");
jsonResponse(['success' => false, 'error' => 'The email could not be sent. Check the mail configuration and try again.'], 502);
