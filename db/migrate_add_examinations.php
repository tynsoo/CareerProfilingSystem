<?php
// Creates the tables backing Examination Scheduling & Rooms and the Retake
// Examination workflow, and registers the new 'examinations' RBAC module.
// Idempotent (IF NOT EXISTS / DO NOTHING), safe to re-run.
//
// Originally also created a Faculty Assignment feature (faculty +
// exam_schedule_faculty tables, a no-login Faculty Portal) — removed:
// in this school's actual process, Guidance Counselors are the ones
// assigned per room, not a separate faculty/proctor entity. See
// db/migrate_drop_faculty.php for the corresponding drop on databases
// that already ran the old version of this script.
//
// Note on retake_grants: RIASEC is an interest inventory, not a pass/fail
// exam, so there is no automatic "failed -> eligible for retake" trigger.
// Eligibility is staff-initiated only (see api/retake-grants.php) -- a row
// here with status='granted' and completed_attempt_number IS NULL is what
// api/assessment-submit.php checks before allowing attempt_number > 1.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_schedules (
        id              SERIAL PRIMARY KEY,
        academic_year   VARCHAR(20) NOT NULL,
        exam_date       DATE NOT NULL,
        start_time      TIME NOT NULL,
        end_time        TIME NOT NULL,
        room            VARCHAR(50) NOT NULL,
        grade_level     VARCHAR(2) CHECK (grade_level IN ('11', '12')),
        strand          VARCHAR(10) CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
        section         VARCHAR(20),
        access_code     VARCHAR(20) NOT NULL,
        notes_enc       TEXT,
        schedule_type   VARCHAR(10) NOT NULL DEFAULT 'initial' CHECK (schedule_type IN ('initial', 'retake')),
        created_by      INT REFERENCES users(id),
        created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS retake_grants (
        id                          SERIAL PRIMARY KEY,
        student_id                  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        original_attempt_number     INT NOT NULL,
        reason_enc                  TEXT NOT NULL,
        schedule_id                 INT REFERENCES exam_schedules(id),
        status                      VARCHAR(15) NOT NULL DEFAULT 'granted' CHECK (status IN ('granted', 'completed', 'revoked')),
        granted_by                  INT REFERENCES users(id),
        granted_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        completed_attempt_number    INT,
        completed_at                TIMESTAMPTZ
    )"
);

// Register the 'examinations' RBAC module. CGC staff (counselor) get 'full'
// here, unlike most modules which give counselors 'limited' -- Guidance
// Counselors are the ones actually assigned per room, so they need the
// same standing admin has to schedule/manage sessions and rooms.
$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
foreach (['admin' => 'full', 'counselor' => 'full', 'student' => 'none'] as $role => $level) {
    $rbacStmt->execute(['examinations', $role, $level]);
}

echo "exam_schedules + retake_grants tables created (or already present); RBAC rows seeded.\n";
