<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/StudentNumber.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../lib/RosterCsv.php';
require_once __DIR__ . '/../../../shared/lib/XlsxReader.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Rbac::requireRosterEditor();
$pdo = Database::get();

if (!isset($_FILES['roster'])) {
    jsonResponse(['success' => false, 'error' => 'No file was uploaded.'], 400);
}
// PHP itself already rejects anything over upload_max_filesize/post_max_size
// before this script runs, and reports it the same way as "no file" — give
// the real reason when that's what happened, rather than the generic message.
if (in_array($_FILES['roster']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    jsonResponse(['success' => false, 'error' => 'File is too large. Roster files should be under 2MB.'], 400);
}
if ($_FILES['roster']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'error' => 'No file was uploaded.'], 400);
}

// A roster is at most a few thousand rows of plain text — anything past a
// couple MB is either the wrong file or someone testing what happens with
// a huge upload. Reject early rather than let fgetcsv() churn through it.
// (PHP's own upload_max_filesize above already catches most of this —
// this is the backstop for a deployment with a higher ini limit.)
const MAX_ROSTER_BYTES = 2 * 1024 * 1024; // 2MB
if ($_FILES['roster']['size'] > MAX_ROSTER_BYTES) {
    jsonResponse(['success' => false, 'error' => 'File is too large. Roster files should be under 2MB.'], 400);
}

$originalName = (string) ($_FILES['roster']['name'] ?? '');
$extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'xlsx'], true)) {
    jsonResponse(['success' => false, 'error' => 'Please upload a .csv or .xlsx file.'], 400);
}

$currentAy = AcademicYear::current();

// One file is one section (Strand: / Section: at the top, then the students),
// saved either as CSV or straight from Excel as .xlsx.
if ($extension === 'xlsx') {
    try {
        $parsed = RosterCsv::parseLines(XlsxReader::readRows($_FILES['roster']['tmp_name']));
    } catch (RuntimeException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
} else {
    $handle = fopen($_FILES['roster']['tmp_name'], 'r');
    if ($handle === false) {
        jsonResponse(['success' => false, 'error' => 'Could not read the uploaded file.'], 400);
    }
    $parsed = RosterCsv::parse($handle);
    fclose($handle);
}

if ($parsed['error'] !== null) {
    jsonResponse(['success' => false, 'error' => $parsed['error']], 400);
}
if ($parsed['details']) {
    jsonResponse(['success' => false, 'error' => 'The file had ' . count($parsed['details']) . ' invalid row(s).', 'details' => array_slice($parsed['details'], 0, 20)], 400);
}
if (!$parsed['rows']) {
    jsonResponse(['success' => false, 'error' => 'No students found in the file.'], 400);
}
$strand = $parsed['strand'];
$section = $parsed['section'];

// A section an administrator has deactivated is not being offered: reactivate it first.
$inactive = $pdo->prepare('SELECT 1 FROM sections WHERE strand = ? AND LOWER(code) = LOWER(?) AND is_active = FALSE');
$inactive->execute([$strand, $section]);
if ($inactive->fetchColumn()) {
    jsonResponse(['success' => false, 'error' => "Section $section ($strand) is deactivated. Reactivate it under Sections first, then upload its roster."], 409);
}

/**
 * What saving this file would change on this academic year's roster (read only). Used for the preview and again
 * inside the save transaction, so the preview and the result always count the same way.
 * moved: [number, name from the file, section it is in now]; removed: Student Numbers in this section now but not
 * in the file (only the number: their names are not needed to check the list against the file).
 */
function rosterChanges(PDO $pdo, string $ay, string $strand, string $section, array $rows): array
{
    $existing = $pdo->prepare('SELECT school_id, strand, section FROM assessment_roster WHERE academic_year = ?');
    $existing->execute([$ay]);
    $before = [];
    foreach ($existing->fetchAll() as $e) {
        $before[$e['school_id']] = $e['strand'] . ' ' . $e['section'];
    }
    $here = $strand . ' ' . $section;
    $moved = [];   // listed under another section until now
    $added = 0;
    foreach ($rows as $r) {
        if (!isset($before[$r[0]])) {
            $added++;
        } elseif ($before[$r[0]] !== $here) {
            $moved[] = [$r[0], $r[1], $before[$r[0]]];
        }
    }
    $removed = [];  // in this section before, not in this file
    foreach ($before as $number => $where) {
        if ($where === $here && !isset($rows[$number])) {
            $removed[] = (string) $number;
        }
    }
    return ['added' => $added, 'moved' => $moved, 'removed' => $removed];
}

// Preview (panel revision S8): the page first sends the file with preview=1 and shows what would change; nothing is
// saved. The person then confirms, and the page sends the same file again to save it. The preview names Student
// Numbers and sections, so it is written to the Audit Log (preview_roster), and a counselor limited to some sections
// (StaffScope) gets the lists only for sections they handle: elsewhere just the counts and "another section".
if (($_POST['preview'] ?? '') === '1') {
    $changes = rosterChanges($pdo, $currentAy, $strand, $section, $parsed['rows']);
    $known = $pdo->prepare('SELECT 1 FROM sections WHERE strand = ? AND LOWER(code) = LOWER(?)');
    $known->execute([$strand, $section]);
    $scope = StaffScope::forUser($pdo, $user);
    $seesHere = StaffScope::allows($scope, $strand, $section);
    $fromShown = function (string $where) use ($scope): string {
        [$fromStrand, $fromSection] = array_pad(explode(' ', $where, 2), 2, '');
        return StaffScope::allows($scope, $fromStrand, $fromSection) ? $where : 'another section';
    };
    $listMax = 50; // a long list is cut; the totals are always complete
    AuditLogger::log($user['id'], $user['role'], 'preview_roster', 'assessment_roster', $currentAy,
        'Preview only, nothing saved: ' . count($parsed['rows']) . " student(s) in $strand $section for AY $currentAy ("
        . $changes['added'] . ' new, ' . count($changes['moved']) . ' would move here, ' . count($changes['removed']) . ' would come off)');
    jsonResponse([
        'success' => true,
        'preview' => true,
        'count' => count($parsed['rows']),
        'academicYear' => $currentAy,
        'strand' => $strand,
        'section' => $section,
        'newSection' => !$known->fetchColumn(),
        'added' => $changes['added'],
        'moved' => count($changes['moved']),
        'removed' => count($changes['removed']),
        'movedList' => array_map(fn($m) => ['number' => $m[0], 'name' => $m[1], 'from' => $fromShown($m[2])], array_slice($changes['moved'], 0, $listMax)),
        'removedList' => $seesHere ? array_slice($changes['removed'], 0, $listMax) : [],
        'withoutEmail' => count(array_filter($parsed['rows'], fn($r) => $r[2] === null)),
    ]);
}

$pdo->beginTransaction();
try {
    // What is already on the roster this academic year, so the result can say what changed.
    $changes = rosterChanges($pdo, $currentAy, $strand, $section, $parsed['rows']);
    $added = $changes['added'];
    $moved = array_map(fn($m) => $m[2], $changes['moved']);
    $removed = count($changes['removed']);

    // A re-upload replaces just this section for the current AY, so the other
    // sections' rosters are left alone.
    $deleted = $pdo->prepare('DELETE FROM assessment_roster WHERE academic_year = ? AND strand = ? AND section = ?');
    $deleted->execute([$currentAy, $strand, $section]);

    // A student already listed under another section this AY is moved here.
    $insert = $pdo->prepare(
        'INSERT INTO assessment_roster (academic_year, school_id, name_enc, strand, section, email, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (academic_year, school_id) DO UPDATE
            SET name_enc = EXCLUDED.name_enc, strand = EXCLUDED.strand, section = EXCLUDED.section, email = EXCLUDED.email,
                uploaded_by = EXCLUDED.uploaded_by, uploaded_at = NOW()'
    );
    foreach ($parsed['rows'] as $r) {
        $insert->execute([$currentAy, $r[0], Crypto::enc($r[1]), $strand, $section, $r[2], $user['id']]);
    }

    // The section list (exam schedules, filters, reports) follows the roster: a section that is
    // on a roster but not in the list yet is added.
    $pdo->prepare('INSERT INTO sections (strand, code, created_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING')
        ->execute([$strand, $section, $user['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[roster-upload] failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Failed to save the roster. Please try again.'], 500);
}

$count = count($parsed['rows']);
$noEmail = count(array_filter($parsed['rows'], fn($r) => $r[2] === null));
$movedFrom = array_count_values($moved);
AuditLogger::log($user['id'], $user['role'], 'upload_roster', 'assessment_roster', $currentAy, "$count student(s) in $strand $section for AY $currentAy ($added new, " . count($moved) . " moved here, $removed removed)");

jsonResponse([
    'success' => true,
    'count' => $count,
    'academicYear' => $currentAy,
    'strand' => $strand,
    'section' => $section,
    'added' => $added,
    'removed' => $removed,
    'moved' => count($moved),
    'movedFrom' => $movedFrom,
    'withoutEmail' => $noEmail,
]);
