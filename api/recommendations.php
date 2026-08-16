<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$requestedStudentId = isset($_GET['studentId']) ? (int) $_GET['studentId'] : null;
if ($requestedStudentId !== null && $requestedStudentId !== (int) $user['id']) {
    Rbac::requireAccess('recommendations', 'full');
    $studentId = $requestedStudentId;
} else {
    if ($user['role'] !== 'student') {
        jsonResponse(['error' => 'studentId is required for non-student accounts.'], 400);
    }
    $studentId = (int) $user['id'];
}

$stmt = $pdo->prepare(
    'SELECT id, computed_at, stated_program_id, scores, top_program_id, top_score, source_worksheet_id
     FROM recommendations WHERE student_id = ? ORDER BY computed_at DESC LIMIT 1'
);
$stmt->execute([$studentId]);
$row = $stmt->fetch();

if (!$row) {
    jsonResponse(['hasRecommendation' => false]);
}

function parsePgTextArray(?string $raw): array
{
    if ($raw === null || $raw === '{}') {
        return [];
    }
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m);
    return array_map(fn($s) => str_replace(['\\"', '\\\\'], ['"', '\\'], $s), $m[1]);
}

$electives = [];
if ($row['source_worksheet_id'] !== null) {
    $wsStmt = $pdo->prepare('SELECT electives FROM worksheets WHERE id = ?');
    $wsStmt->execute([(int) $row['source_worksheet_id']]);
    $wsRow = $wsStmt->fetch();
    if ($wsRow) {
        $electives = parsePgTextArray($wsRow['electives']);
    }
}

$scores = json_decode($row['scores'], true);
usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
$top3Scores = array_slice($scores, 0, 3);

$statedProgramId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;
$top3Ids = array_column($top3Scores, 'programId');
$statedInTop3 = $statedProgramId !== null && in_array($statedProgramId, $top3Ids, true);

$statedOutsideTop3Score = null;
if ($statedProgramId !== null && !$statedInTop3) {
    foreach ($scores as $s) {
        if ($s['programId'] === $statedProgramId) {
            $statedOutsideTop3Score = $s;
            break;
        }
    }
}

$neededIds = $top3Ids;
if ($statedProgramId !== null) {
    $neededIds[] = $statedProgramId;
}
$neededIds = array_values(array_unique($neededIds));

$programs = [];
if ($neededIds) {
    $placeholders = implode(',', array_fill(0, count($neededIds), '?'));
    $programStmt = $pdo->prepare(
        "SELECT p.id, p.title_enc, p.holland_code_enc, c.code AS college_code, c.name AS college_name
         FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.id IN ($placeholders)"
    );
    $programStmt->execute($neededIds);
    foreach ($programStmt->fetchAll() as $r) {
        $programs[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'title' => Crypto::dec($r['title_enc']),
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
        ];
    }
}

function enrichEntry(array $scoreEntry, array $programs): ?array
{
    $program = $programs[$scoreEntry['programId']] ?? null;
    if ($program === null) {
        return null;
    }
    return $program + [
        'cosine' => (float) $scoreEntry['cosine'],
        'score' => (float) $scoreEntry['score'],
        'matchPercent' => (int) round($scoreEntry['score'] * 100),
    ];
}

$top3 = array_values(array_filter(array_map(fn($s) => enrichEntry($s, $programs), $top3Scores)));

$statedProgramScore = null;
foreach ($scores as $s) {
    if ($s['programId'] === $statedProgramId) {
        $statedProgramScore = $s;
        break;
    }
}

jsonResponse([
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    'statedProgramId' => $statedProgramId,
    'statedProgram' => $statedProgramScore !== null ? enrichEntry($statedProgramScore, $programs) : null,
    'electives' => $electives,
    'topProgramId' => (int) $row['top_program_id'],
    'topScore' => (float) $row['top_score'],
    'top3' => $top3,
    'statedOutsideTop3' => $statedOutsideTop3Score !== null ? enrichEntry($statedOutsideTop3Score, $programs) : null,
]);
