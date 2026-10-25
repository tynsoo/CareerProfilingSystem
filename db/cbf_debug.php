<?php
/**
 * Command-line view of the CBF calculation for one student: student vector, every
 * course's vector, dot product, magnitudes and cosine similarity, and the sources.
 *
 *   php db/cbf_debug.php <school_id>            readable report
 *   php db/cbf_debug.php <school_id> --csv      all courses as CSV (paste into Excel/Word)
 *   php db/cbf_debug.php <school_id> --json     everything as JSON
 *
 * Read-only: recomputes from the student's current profile and the Active
 * program dataset; nothing is written to the database.
 */

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/RecommendationPipeline.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$schoolId = $argv[1] ?? null;
$format = $argv[2] ?? '';
if ($schoolId === null) {
    fwrite(STDERR, "Usage: php db/cbf_debug.php <school_id> [--csv|--json]\n");
    exit(1);
}

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT user_id FROM students WHERE LOWER(school_id) = LOWER(?)');
$stmt->execute([$schoolId]);
$studentId = (int) $stmt->fetchColumn();
$profile = $studentId > 0 ? CBFData::studentProfile($pdo, $studentId) : null;
if ($profile === null) {
    fwrite(STDERR, "No student with school ID $schoolId.\n");
    exit(1);
}

$programs = CBFData::activePrograms($pdo);
$titles = array_column($programs, 'title', 'id');
$named = fn(array $ids) => $ids ? implode(', ', array_map(fn($id) => $titles[$id], $ids)) : 'none';
$run = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
$cbf = $run['cbf'];

if ($format === '--json') {
    echo json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}
if ($cbf['status'] !== 'available') {
    fwrite(STDERR, "CBF not computed: {$cbf['reason']}\n");
    exit(1);
}

// Which of the program's 1st, 2nd and 3rd letters match the student's 1st, 2nd and 3rd interests,
// e.g. "1 2 -" (used only to order courses with the same cosine similarity).
$letters = fn(array $m) => implode(' ', array_map(fn($i) => $m[$i] ? (string) ($i + 1) : '-', array_keys($m)));

if ($format === '--csv') {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['Course', 'RIASEC', 'Course Vector [R,I,A,S,E,C]', 'Dot Product', '||S||', '||C||', 'Cosine Similarity', '1-2-3 Letter Match', 'Result']);
    foreach ($cbf['results'] as $r) {
        fputcsv($out, [$r['title'], $r['hollandCode'], '[' . implode(', ', $r['courseVector']) . ']', $r['dotProduct'],
            $cbf['studentMagnitude'], $r['courseMagnitude'], $r['cosine'], $letters($r['letterOrder']),
            in_array($r['id'], $cbf['candidateIds'], true) ? 'Best RIASEC Match' : (in_array($r['id'], $cbf['alternatives'], true) ? 'Alternative' : '')]);
    }
    exit;
}

echo "CBF calculation for $schoolId   cos(S, C) = (S · C) / (||S|| x ||C||)\n\n";
echo 'Student RIASEC totals : ' . implode('  ', array_map(fn($d) => "$d={$cbf['studentScores'][$d]}", CBFEngine::DIMENSIONS)) . "\n";
echo 'Student vector S      : [' . implode(', ', $cbf['studentVector']) . "]  (mean item score = total / 10, order R,I,A,S,E,C)\n";
echo "||S||                 : {$cbf['studentMagnitude']}\n";
echo "Equal cosine values are listed by the 1-2-3 letter match (1st, then 2nd, then 3rd), then program order.\n\n";
printf("%-62s %-6s %-20s %8s %7s %8s %-7s\n", 'Course', 'RIASEC', 'Course vector C', 'S·C', '||C||', 'Cosine', '1-2-3');
foreach ($cbf['results'] as $r) {
    printf("%-62s %-6s %-20s %8s %7s %8.4f %-7s%s\n", substr($r['title'], 0, 62), $r['hollandCode'], '[' . implode(',', $r['courseVector']) . ']',
        $r['dotProduct'], $r['courseMagnitude'], $r['cosine'], $letters($r['letterOrder']), in_array($r['id'], $cbf['candidateIds'], true) ? '  <- Best RIASEC Match'
            : (in_array($r['id'], $cbf['alternatives'], true) ? '  <- Alternative' : ''));
}
foreach ($cbf['excluded'] as $x) {
    echo "EXCLUDED: {$x['title']} ({$x['hollandCode']}): {$x['reason']}\n";
}
echo "\nRecommendation sources\n";
echo '  Best RIASEC Match (CBF) : ' . $named($run['bestRiasecMatchIds']) . "\n";
echo '  Alternatives (CBF)      : ' . $named($run['alternativeIds']) . "\n";
echo '  Preferred Course        : ' . ($run['worksheet']['status'] === 'available' ? $named($run['worksheet']['courseIds']) : 'no worksheet result')
    . ($run['preferred'] ? ($run['preferred'][0]['inBestRiasecMatch'] ? ' (also a Best RIASEC Match)' : ' (differs from the Best RIASEC Match)') : '') . "\n";
echo "  Prediction model        : {$run['prediction']['reason']}\n";
echo "  Final Best Match        : not computed (needs the prediction model)\n";
