<?php
/**
 * Distribution of the highest cosine similarity (the CBF "top score") among the students'
 * latest results, to set the Monitoring low-confidence threshold from data instead of
 * guessing (security_policies key monitoring.lowConfidenceThreshold).
 *
 *   php db/cbf_score_distribution.php
 *
 * Why this matters: with binary course vectors and RIASEC scores of 10-50, the highest
 * cosine a student can get is never below about 0.55 (a perfectly "split" profile such as
 * A=50, C=50, every other type 10, because no program's code contains both A and C).
 * The seeded threshold of 0.50 is below that, so it can never flag anyone. A threshold is
 * only useful inside the range the scores actually take; a common choice is a low
 * percentile (e.g. the 10th) of this distribution, agreed with the Guidance Office.
 * Reads only; changes nothing.
 */

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$pdo = Database::get();
$programs = CBFData::activePrograms($pdo);
$rows = $pdo->query('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE is_latest = TRUE')->fetchAll();
$top = [];
foreach ($rows as $r) {
    $scores = ['R' => $r['score_r'], 'I' => $r['score_i'], 'A' => $r['score_a'], 'S' => $r['score_s'], 'E' => $r['score_e'], 'C' => $r['score_c']];
    try {
        $cbf = CBFEngine::compute($scores, $programs);
    } catch (InvalidArgumentException $e) {
        continue;
    }
    if ($cbf['results']) {
        $top[] = (float) $cbf['results'][0]['cosine'];
    }
}
$threshold = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
printf("Students with a valid latest result: %d\n", count($top));
printf("Current threshold (monitoring.lowConfidenceThreshold): %s\n", $threshold === false ? '(not set; code default 0.50)' : $threshold);
if (!$top) {
    exit("No results yet.\n");
}
sort($top);
$pct = function (float $p) use ($top): float {
    $i = ($p / 100) * (count($top) - 1);
    $lo = (int) floor($i);
    $hi = (int) ceil($i);
    return $top[$lo] + ($top[$hi] - $top[$lo]) * ($i - $lo);
};
printf("Highest cosine per student: min %.4f, max %.4f\n", $top[0], end($top));
foreach ([5, 10, 25, 50, 75, 90] as $p) {
    printf("  %2dth percentile: %.4f\n", $p, $pct($p));
}
$below = $threshold === false ? null : count(array_filter($top, fn($v) => $v < (float) $threshold));
if ($below !== null) {
    printf("Students below the current threshold: %d (%.1f%%)\n", $below, 100 * $below / count($top));
}
