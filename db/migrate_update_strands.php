<?php
// Replaces the strand list (STEM, ABM, HUMSS, GAS, TVL) with the school's
// actual offered strands (STEM, ABM, ICT, HUMSS) across every table that
// constrains it: students, assessment_roster, exam_schedules.
//
// Safe to run even with existing rows whose strand is GAS/TVL (none exist
// on Render as of this migration — confirmed by direct query before writing
// this script) since it drops and recreates each CHECK constraint rather
// than altering data. If a future run ever hits existing GAS/TVL rows, this
// will fail loudly (constraint violation) rather than silently corrupt data
// — fix that data by hand first.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

function replaceStrandCheck(PDO $pdo, string $table, string $column, bool $nullable): void
{
    // Postgres auto-names an inline CHECK constraint "<table>_<column>_check"
    // unless one was given explicitly (none were here).
    $constraintName = "{$table}_{$column}_check";
    $pdo->exec("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraintName}");
    $pdo->exec(
        "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} " .
        "CHECK ({$column} IN ('STEM', 'ABM', 'ICT', 'HUMSS'))"
    );
    echo "Updated {$table}.{$column} CHECK constraint.\n";
}

replaceStrandCheck($pdo, 'students', 'strand', false);
replaceStrandCheck($pdo, 'assessment_roster', 'strand', false);
replaceStrandCheck($pdo, 'exam_schedules', 'strand', true);

echo "Done.\n";
