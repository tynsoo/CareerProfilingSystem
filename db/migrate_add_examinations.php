<?php
// Creates the 4 tables backing Examination Scheduling & Rooms, Faculty
// Assignment, and the Retake Examination workflow, and registers the new
// 'examinations' RBAC module. Idempotent (IF NOT EXISTS / DO NOTHING), safe
// to re-run.
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
        strand          VARCHAR(10) CHECK (strand IN ('STEM', 'ABM', 'HUMSS', 'GAS', 'TVL')),
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
    "CREATE TABLE IF NOT EXISTS faculty (
        id              SERIAL PRIMARY KEY,
        faculty_code    VARCHAR(50) NOT NULL UNIQUE,
        first_name_enc  TEXT NOT NULL,
        last_name_enc   TEXT NOT NULL,
        email           VARCHAR(255) NOT NULL UNIQUE,
        is_active       BOOLEAN NOT NULL DEFAULT TRUE,
        created_by      INT REFERENCES users(id),
        created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_faculty_code_lower ON faculty (LOWER(faculty_code))");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_faculty_email_lower ON faculty (LOWER(email))");

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_schedule_faculty (
        schedule_id     INT NOT NULL REFERENCES exam_schedules(id) ON DELETE CASCADE,
        faculty_id      INT NOT NULL REFERENCES faculty(id) ON DELETE CASCADE,
        access_code_enc TEXT NOT NULL,
        assigned_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        assigned_by     INT REFERENCES users(id),
        PRIMARY KEY (schedule_id, faculty_id)
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
// here, unlike most modules which give counselors 'limited' -- the spec
// explicitly calls for CGC staff to see and assign schedules/rooms/faculty,
// the same standing admin has.
$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
foreach (['admin' => 'full', 'counselor' => 'full', 'student' => 'none'] as $role => $level) {
    $rbacStmt->execute(['examinations', $role, $level]);
}

echo "exam_schedules + faculty + exam_schedule_faculty + retake_grants tables created (or already present); RBAC rows seeded.\n";
