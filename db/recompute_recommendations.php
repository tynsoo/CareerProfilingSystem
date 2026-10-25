<?php
/**
 * Recompute every student's recommendation with the CURRENT settings
 * (config/cbf.php). Results are saved only when a student submits the Career
 * Worksheet, so after changing the method, existing students keep showing
 * their old scores until this is run.
 *
 *   php db/recompute_recommendations.php            recompute everyone who has a worksheet
 *   php db/recompute_recommendations.php --dry-run  show what would change, save nothing
 *
 * Uses each student's latest RIASEC assessment and latest worksheet (stated
 * program and electives). A new `recommendations` row is added per student;
 * older rows stay as history.
 */

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/RecommendationPipeline.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::get();

if ($dryRun) {
    $students = $pdo->query(
        "SELECT s.user_id, s.school_id,
                (SELECT w.id FROM worksheets w WHERE w.student_id = s.user_id ORDER BY w.submitted_at DESC LIMIT 1) AS worksheet_id
         FROM students s JOIN assessments a ON a.student_id = s.user_id AND a.is_latest = TRUE
         ORDER BY s.school_id"
    )->fetchAll();
    $programs = CBFData::activePrograms($pdo);
    $titles = array_column($programs, 'title', 'id');
    $done = 0;
    foreach ($students as $st) {
        if ($st['worksheet_id'] === null) {
            continue; // no worksheet yet -> no recommendation to refresh
        }
        $profile = CBFData::studentProfile($pdo, (int) $st['user_id']);
        $r = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
        printf("%-15s %s%s  Best RIASEC Match: %s\n", $st['school_id'], $r['status'], $r['reason'] ? " ({$r['reason']})" : '',
            implode(', ', array_map(fn($id) => $titles[$id], $r['bestRiasecMatchIds'])) ?: '-');
        $done++;
    }
    echo "Dry run: $done students would be recomputed (nothing saved).\n";
    exit;
}

$result = CBFData::recomputeAll($pdo, function (string $schoolId, ?array $saved, ?string $error) {
    $label = sprintf('%-15s', $schoolId);
    if ($error !== null) {
        printf("%s FAILED: %s\n", $label, $error);
        return;
    }
    if ($saved === null) {
        printf("%s not saved: no active programs\n", $label);
        return;
    }
    printf("%s %s%s  Best RIASEC Match: %s\n", $label, $saved['status'], $saved['reason'] ? " ({$saved['reason']})" : '',
        implode(', ', $saved['bestRiasecMatchTitles']) ?: '-');
});
echo "{$result['recomputed']} students recomputed with the current CBF settings (config/cbf.php)."
    . ($result['failed'] ? " {$result['failed']} failed (see above)." : '') . "\n";
