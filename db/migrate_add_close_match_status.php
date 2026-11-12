<?php
/**
 * Adds the "close" match status: the student's Preferred Course is one of their Alternative Courses (the next-highest
 * cosine similarity after the Best RIASEC Match). It is shown as "Close match" and is not a mismatch, so these students
 * are no longer counted as Mismatched or sent the "Please visit the Guidance Office" reminder.
 *
 * 1. Lets recommendations.match_status hold 'close'.
 * 2. Re-labels saved results that are 'mismatch' (preferred_not_matched) but whose Preferred Course is an Alternative
 *    Course, using the same grouping as api/recommendations.php. Nothing else in the result changes, and no CBF value
 *    is recomputed.
 *
 *   php db/migrate_add_close_match_status.php --dry-run    count only, change nothing
 *   php db/migrate_add_close_match_status.php
 *
 * Safe to run more than once.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/CBFData.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::get();

/** The Alternative Course ids of one saved result (same rule as modules/ContentBasedFiltering/api/recommendations.php). */
function alternativeIds(array $scores, ?string $cbfProgramIds): array
{
    $scores = array_map(fn($s) => $s + ['cosineFull' => (float) ($s['cosineExact'] ?? $s['cosine'])], $scores);
    usort($scores, fn($a, $b) => abs($a['cosineFull'] - $b['cosineFull']) < CBFEngine::TIE_EPSILON
        ? $a['programId'] <=> $b['programId']
        : $b['cosineFull'] <=> $a['cosineFull']);
    $bestIds = isset($scores[0]['dotProduct']) && $cbfProgramIds !== null
        ? CBFData::parseIntArray($cbfProgramIds)
        : array_map('intval', array_column(CBFEngine::selectCandidates($scores, 'cosineFull'), 'programId'));
    if (!$bestIds) {
        return [];
    }
    $rest = array_values(array_filter($scores, fn($s) => !in_array((int) $s['programId'], $bestIds, true)));
    return array_map('intval', array_column(CBFEngine::selectCandidates($rest, 'cosineFull'), 'programId'));
}

$pdo->beginTransaction();
if (!$dryRun) {
    $pdo->exec('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_match_status_check');
    $pdo->exec("ALTER TABLE recommendations ADD CONSTRAINT recommendations_match_status_check CHECK (match_status IN ('match', 'close', 'mismatch'))");
}

$rows = $pdo->query(
    "SELECT id, stated_program_id, scores, cbf_program_ids FROM recommendations
     WHERE match_status = 'mismatch' AND mismatch_reason = 'preferred_not_matched' AND stated_program_id IS NOT NULL"
)->fetchAll();
$update = $pdo->prepare("UPDATE recommendations SET match_status = 'close', mismatch_reason = NULL WHERE id = ?");
$changed = 0;
foreach ($rows as $r) {
    $scores = json_decode((string) $r['scores'], true) ?: [];
    if (in_array((int) $r['stated_program_id'], alternativeIds($scores, $r['cbf_program_ids']), true)) {
        $changed++;
        if (!$dryRun) {
            $update->execute([(int) $r['id']]);
        }
    }
}
$pdo->commit();

echo ($dryRun ? '[dry run] ' : '') . "Mismatch results checked: " . count($rows) . ". Re-labelled as close match: $changed.\n";
