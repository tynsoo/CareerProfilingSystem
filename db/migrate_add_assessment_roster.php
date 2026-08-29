<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

// Intentionally decoupled from users/students — this is the *expected* list
// of students for an assessment period (per db/schema.sql's convention of
// listing new tables there too), which may include students who haven't
// registered an account yet. Assessment Statistics joins it against real
// `assessments` rows by school_id to show expected-vs-completed.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS assessment_roster (
        id              SERIAL PRIMARY KEY,
        academic_year   VARCHAR(20) NOT NULL,
        school_id       VARCHAR(50) NOT NULL,
        name_enc        TEXT NOT NULL,
        strand          VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'HUMSS', 'GAS', 'TVL')),
        section         VARCHAR(20) NOT NULL,
        uploaded_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        uploaded_by     INT REFERENCES users(id),
        UNIQUE (academic_year, school_id)
    )"
);

echo "assessment_roster table created (or already present).\n";
