<?php
/**
 * One-time data fix: api/assessment-submit.php used to store the RIASEC
 * "Investigative" dimension as "Investigate" in assessments.top_types /
 * worksheets.top_types (JSONB). The write path is now fixed, but rows
 * submitted before that fix still hold the truncated value. Idempotent —
 * safe to run more than once.
 */
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$fixed = 0;

foreach (['assessments', 'worksheets'] as $table) {
    $rows = $pdo->query("SELECT id, top_types FROM $table")->fetchAll();
    $update = $pdo->prepare("UPDATE $table SET top_types = ? WHERE id = ?");

    foreach ($rows as $row) {
        $types = json_decode($row['top_types'], true);
        if (!is_array($types)) {
            continue;
        }
        $changed = false;
        foreach ($types as &$t) {
            if ($t === 'Investigate') {
                $t = 'Investigative';
                $changed = true;
            }
        }
        unset($t);
        if ($changed) {
            $update->execute([json_encode($types), $row['id']]);
            $fixed++;
        }
    }
}

echo "Fixed $fixed row(s).\n";
