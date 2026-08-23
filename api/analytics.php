<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

// Optional ?strand=STEM filter — narrows every metric below to that strand's
// students, except strandDistribution (kept unfiltered so it stays useful as
// a population-wide breakdown regardless of which strand is selected).
$validStrands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'];
$strand = trim((string) ($_GET['strand'] ?? ''));
if (!in_array($strand, $validStrands, true)) {
    $strand = '';
}
$hasStrand = $strand !== '';

$totalStudents = (int) (function () use ($pdo, $hasStrand, $strand) {
    $sql = 'SELECT COUNT(*) FROM students' . ($hasStrand ? ' WHERE strand = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetchColumn();
})();

$assessedCount = (int) (function () use ($pdo, $hasStrand, $strand) {
    $sql = 'SELECT COUNT(*) FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE'
        . ($hasStrand ? ' AND s.strand = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetchColumn();
})();

$worksheetCount = (int) (function () use ($pdo, $hasStrand, $strand) {
    $sql = 'SELECT COUNT(DISTINCT w.student_id) FROM worksheets w
            JOIN students s ON s.user_id = w.student_id
            WHERE w.attempt_number = (SELECT MAX(attempt_number) FROM assessments a WHERE a.student_id = w.student_id)'
        . ($hasStrand ? ' AND s.strand = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetchColumn();
})();

$thresholdRow = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
$threshold = $thresholdRow !== false ? (float) $thresholdRow : 0.50;

$latestRecScores = (function () use ($pdo, $hasStrand, $strand) {
    $sql = 'SELECT DISTINCT ON (r.student_id) r.top_score FROM recommendations r'
        . ($hasStrand ? ' JOIN students s ON s.user_id = r.student_id WHERE s.strand = ?' : '')
        . ' ORDER BY r.student_id, r.computed_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
})();
$recCount = count($latestRecScores);
$confidentCount = count(array_filter($latestRecScores, fn($s) => (float) $s >= $threshold));

// Always the full population breakdown, regardless of the strand filter.
$strandRows = $pdo->query('SELECT strand, COUNT(*) AS cnt FROM students GROUP BY strand ORDER BY cnt DESC')->fetchAll();
$topStrand = $strandRows[0] ?? null;

$riasecRow = (function () use ($pdo, $hasStrand, $strand) {
    $sql = 'SELECT AVG(a.score_r) AS r, AVG(a.score_i) AS i, AVG(a.score_a) AS a, AVG(a.score_s) AS s, AVG(a.score_e) AS e, AVG(a.score_c) AS c
            FROM assessments a JOIN students s ON s.user_id = a.student_id WHERE a.is_latest = TRUE'
        . ($hasStrand ? ' AND s.strand = ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetch();
})();

$careerCounts = (function () use ($pdo, $hasStrand, $strand) {
    $sql = "SELECT top_program_id, COUNT(*) AS cnt FROM (
                SELECT DISTINCT ON (r.student_id) r.student_id, r.top_program_id
                FROM recommendations r"
        . ($hasStrand ? ' JOIN students s ON s.user_id = r.student_id WHERE s.strand = ?' : '')
        . " ORDER BY r.student_id, r.computed_at DESC
            ) latest GROUP BY top_program_id ORDER BY cnt DESC LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($hasStrand ? [$strand] : []);
    return $stmt->fetchAll();
})();

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
    'strand' => $strand,
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
