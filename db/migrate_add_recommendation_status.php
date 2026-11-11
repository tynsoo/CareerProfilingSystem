<?php
// Adds recommendations.match_status ('match' / 'close' / 'mismatch') and mismatch_reason,
// set by RecommendationPipeline::finalRecommendation() whenever a recommendation is saved. Existing rows
// stay NULL until db/recompute_recommendations.php is run. Safe to run more than once.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS match_status VARCHAR(10)");
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS mismatch_reason VARCHAR(40)");
$pdo->exec("ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_match_status_check");
$pdo->exec("ALTER TABLE recommendations ADD CONSTRAINT recommendations_match_status_check CHECK (match_status IN ('match', 'close', 'mismatch'))");

echo "recommendations.match_status and mismatch_reason added (or already present).\n";
