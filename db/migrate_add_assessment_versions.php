<?php
// Reproducible assessments: immutable question-set snapshots, and per attempt the set it was
// scored against, the answers by question id (encrypted) and the scoring version.
// Attempts saved before this have NULL in the new columns ("legacy"); nothing is backfilled.
// Safe to re-run.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS assessment_question_sets (
        id              SERIAL PRIMARY KEY,
        fingerprint     CHAR(64) NOT NULL UNIQUE,   -- SHA-256 of the snapshot (ids, types, exact wording, order)
        question_count  INT NOT NULL,
        items_per_type  INT NOT NULL,
        scoring_version VARCHAR(40) NOT NULL,
        snapshot_enc    TEXT NOT NULL,              -- the snapshot itself, encrypted like the questions
        created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )'
);
$pdo->exec('ALTER TABLE assessments ADD COLUMN IF NOT EXISTS question_set_id INT REFERENCES assessment_question_sets(id)');
$pdo->exec('ALTER TABLE assessments ADD COLUMN IF NOT EXISTS answers_enc TEXT');
$pdo->exec('ALTER TABLE assessments ADD COLUMN IF NOT EXISTS scoring_version VARCHAR(40)');
$legacy = (int) $pdo->query('SELECT COUNT(*) FROM assessments WHERE question_set_id IS NULL')->fetchColumn();
echo "assessment_question_sets ready; assessments.question_set_id, answers_enc, scoring_version added (or already present).\n";
echo "$legacy existing attempt(s) have no recorded question set or answers (legacy; left as they are).\n";
