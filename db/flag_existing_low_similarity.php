<?php
// One-time pass for students assessed before the level of the low-similarity flag was set (db/migrate_monitoring_level.php).
// A flag is otherwise raised only when a result is saved (CBFData::saveRecommendation), so these students would wait for
// a retake. For each student, the newest recommendation of the latest assessment is checked: below the level
// (MonitoringLevel::get) and no low-similarity flag already open (Pending or Escalated) -> one Pending flag, the same row
// saveRecommendation writes. A career mismatch is not looked at. Nothing else changes, and no e-mail is sent.
//
//   php db/flag_existing_low_similarity.php            dry run: lists what would be flagged, writes nothing
//   php db/flag_existing_low_similarity.php --apply    writes the flags (only on a database whose name ends in _test)
//   php db/flag_existing_low_similarity.php --apply --live   the live database: run only with the team's approval
// Safe to run twice: a student with an open flag is skipped.

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Monitoring/lib/MonitoringLevel.php';

$opts = getopt('', ['apply', 'live']);
$apply = isset($opts['apply']);
$dbName = (string) envValue('DB_NAME');
if ($apply && !str_ends_with($dbName, '_test') && !isset($opts['live'])) {
    fwrite(STDERR, "Refusing to write to $dbName: add --live to write outside a _test database.\n");
    exit(2);
}

$pdo = Database::get();
$level = MonitoringLevel::get($pdo);
$rows = $pdo->query(
    "SELECT DISTINCT ON (r.student_id) r.student_id, r.id AS recommendation_id, r.top_score
     FROM recommendations r
     JOIN assessments a ON a.id = r.source_assessment_id AND a.is_latest = TRUE
     WHERE r.top_score IS NOT NULL
     ORDER BY r.student_id, r.computed_at DESC, r.id DESC"
)->fetchAll();
$open = $pdo->prepare("SELECT 1 FROM monitoring_flags WHERE student_id = ? AND reason = 'low_confidence' AND status IN ('pending', 'escalated')");
$insert = $pdo->prepare("INSERT INTO monitoring_flags (student_id, recommendation_id, reason, status) VALUES (?, ?, 'low_confidence', 'pending')");

$below = 0; $skipped = 0; $flagged = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as $r) {
        if ((float) $r['top_score'] >= $level) {
            continue;
        }
        $below++;
        $open->execute([$r['student_id']]);
        if ($open->fetchColumn()) {
            $skipped++;
            continue;
        }
        if ($apply) {
            $insert->execute([$r['student_id'], $r['recommendation_id']]);
        }
        $flagged++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed, nothing written: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Database $dbName, level $level" . ($apply ? '' : ' (dry run: nothing written)') . "\n";
echo 'Students with a result: ' . count($rows) . ", below the level: $below, already flagged: $skipped, "
    . ($apply ? 'flagged now' : 'would be flagged') . ": $flagged\n";
