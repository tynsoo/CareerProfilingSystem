<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();

$assessedCount = (int) $pdo->query('SELECT COUNT(*) FROM assessments WHERE is_latest = TRUE')->fetchColumn();
$worksheetCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT student_id) FROM worksheets w
     WHERE w.attempt_number = (SELECT MAX(attempt_number) FROM assessments a WHERE a.student_id = w.student_id)'
)->fetchColumn();

$thresholdRow = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
$threshold = $thresholdRow !== false ? (float) $thresholdRow : 0.50;

$latestRecScores = $pdo->query(
    'SELECT DISTINCT ON (student_id) top_score FROM recommendations ORDER BY student_id, computed_at DESC'
)->fetchAll(PDO::FETCH_COLUMN);
$recCount = count($latestRecScores);
$confidentCount = count(array_filter($latestRecScores, fn($s) => (float) $s >= $threshold));

$strandRows = $pdo->query('SELECT strand, COUNT(*) AS cnt FROM students GROUP BY strand ORDER BY cnt DESC')->fetchAll();
$topStrand = $strandRows[0] ?? null;

$riasecRow = $pdo->query(
    'SELECT AVG(score_r) AS r, AVG(score_i) AS i, AVG(score_a) AS a, AVG(score_s) AS s, AVG(score_e) AS e, AVG(score_c) AS c
     FROM assessments WHERE is_latest = TRUE'
)->fetch();

$careerCounts = $pdo->query(
    "SELECT top_program_id, COUNT(*) AS cnt FROM (
        SELECT DISTINCT ON (student_id) student_id, top_program_id
        FROM recommendations ORDER BY student_id, computed_at DESC
     ) latest GROUP BY top_program_id ORDER BY cnt DESC LIMIT 5"
)->fetchAll();

$programIds = array_map(fn($r) => (int) $r['top_program_id'], $careerCounts);
$titles = [];
if ($programIds) {
    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title_enc FROM programs WHERE id IN ($placeholders)");
    $stmt->execute($programIds);
    foreach ($stmt->fetchAll() as $r) {
        $titles[(int) $r['id']] = Crypto::dec($r['title_enc']);
    }
}
$topCareers = array_map(fn($r) => [
    'title' => $titles[(int) $r['top_program_id']] ?? '—',
    'count' => (int) $r['cnt'],
    'percent' => $recCount > 0 ? round(((int) $r['cnt'] / $recCount) * 100, 1) : 0,
], $careerCounts);

function pct(int $n, int $total): float
{
    return $total > 0 ? round(($n / $total) * 100, 1) : 0.0;
}

jsonResponse([
    'totalStudents' => $totalStudents,
    'completion' => ['rate' => pct($assessedCount, $totalStudents), 'count' => $assessedCount, 'total' => $totalStudents],
    'worksheet' => ['rate' => pct($worksheetCount, $assessedCount), 'count' => $worksheetCount, 'total' => $assessedCount],
    'confidence' => ['rate' => pct($confidentCount, $recCount), 'count' => $confidentCount, 'total' => $recCount],
    'topStrand' => $topStrand ? [
        'strand' => $topStrand['strand'],
        'count' => (int) $topStrand['cnt'],
        'percent' => pct((int) $topStrand['cnt'], $totalStudents),
    ] : null,
    'strandDistribution' => array_map(fn($r) => ['strand' => $r['strand'], 'count' => (int) $r['cnt']], $strandRows),
    'riasecAverages' => $riasecRow ? [
        'R' => round((float) $riasecRow['r'], 1), 'I' => round((float) $riasecRow['i'], 1), 'A' => round((float) $riasecRow['a'], 1),
        'S' => round((float) $riasecRow['s'], 1), 'E' => round((float) $riasecRow['e'], 1), 'C' => round((float) $riasecRow['c'], 1),
    ] : ['R' => 0, 'I' => 0, 'A' => 0, 'S' => 0, 'E' => 0, 'C' => 0],
    'topCareers' => $topCareers,
]);
