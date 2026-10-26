<?php
// Moves the level of the low-similarity monitoring flag (security_policies monitoring.lowConfidenceThreshold) from the
// old 0.50 to MonitoringLevel::DEFAULT (0.767). No valid assessment can score below 0.5844, so 0.50 never flagged anyone.
// Only the old value (or a missing row) is changed: a level the Administrator chose in Security Configuration stays.
// Students already assessed are not flagged by this script; see db/flag_existing_low_similarity.php. Safe to run twice.
//   php db/migrate_monitoring_level.php

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Monitoring/lib/MonitoringLevel.php';

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
$stmt->execute([MonitoringLevel::KEY]);
$before = $stmt->fetchColumn();

if ($before === false || (is_numeric($before) && (float) $before === 0.50)) {
    $pdo->prepare(
        'INSERT INTO security_policies (key, value) VALUES (?, ?)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()'
    )->execute([MonitoringLevel::KEY, (string) MonitoringLevel::DEFAULT]);
    echo 'Monitoring level: ' . ($before === false ? 'missing' : $before) . ' -> ' . MonitoringLevel::DEFAULT . "\n";
} else {
    echo "Monitoring level left as it is: $before\n";
}
