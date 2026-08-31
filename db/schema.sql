-- ProfilePath schema (PostgreSQL)
--
-- Encryption convention: columns suffixed _enc hold base64 ciphertext produced by
-- lib/Crypto.php (Modified AES-256-CBC with a key-dependent S-box). They are never
-- used in WHERE/ORDER BY/JOIN — every such column has a plaintext sibling column
-- for anything that needs to be queried, sorted, or constrained.

CREATE TABLE users (
    id                      SERIAL PRIMARY KEY,
    role                    VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'counselor', 'student')),
    username                VARCHAR(100) NOT NULL UNIQUE,
    password_hash           VARCHAR(255) NOT NULL,
    email                   VARCHAR(255),
    email_verified_at       TIMESTAMPTZ,
    avatar_data_url         TEXT,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    failed_login_attempts   INT NOT NULL DEFAULT 0,
    locked_until            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Students self-register with a MMCL-issued email (see api/register.php's
-- domain check) but that only proves the string LOOKS right — this proves
-- they actually control the inbox before the account can log in. NULL =
-- not verified yet. Only enforced for role='student'; admin/counselor
-- accounts are staff-vetted through a different path (api/staff-accounts.php).
CREATE TABLE email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Backs lib/DbSessionHandler.php — PHP sessions stored here instead of local
-- disk, since Render's free web service tier wipes local disk on every
-- restart after idling.
CREATE TABLE sessions (
    id              VARCHAR(128) PRIMARY KEY,
    data            TEXT NOT NULL DEFAULT '',
    last_activity   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE colleges (
    id      SERIAL PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE,
    name    VARCHAR(255) NOT NULL
);

CREATE TABLE students (
    user_id         INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    school_id       VARCHAR(50) NOT NULL UNIQUE,
    first_name_enc  TEXT NOT NULL,
    last_name_enc   TEXT NOT NULL,
    strand          VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
    grade_level     VARCHAR(2) NOT NULL CHECK (grade_level IN ('11', '12')),
    section         VARCHAR(20) NOT NULL DEFAULT 'N/A',
    academic_year   VARCHAR(20),
    registered_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX idx_students_school_id_lower ON students (LOWER(school_id));

CREATE TABLE programs (
    id                  SERIAL PRIMARY KEY,
    college_id          INT NOT NULL REFERENCES colleges(id),
    title_enc           TEXT NOT NULL,
    holland_code_enc    TEXT NOT NULL,
    description_enc     TEXT,
    status              VARCHAR(10) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE assessment_questions (
    id                  SERIAL PRIMARY KEY,
    dimension           CHAR(1) NOT NULL CHECK (dimension IN ('R', 'I', 'A', 'S', 'E', 'C')),
    question_text_enc   TEXT NOT NULL,
    order_index         INT NOT NULL CHECK (order_index BETWEEN 1 AND 10),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by          INT REFERENCES users(id),
    UNIQUE (dimension, order_index)
);

CREATE TABLE assessments (
    id              SERIAL PRIMARY KEY,
    student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    attempt_number  INT NOT NULL,
    is_latest       BOOLEAN NOT NULL DEFAULT TRUE,
    score_r         INT NOT NULL CHECK (score_r BETWEEN 0 AND 50),
    score_i         INT NOT NULL CHECK (score_i BETWEEN 0 AND 50),
    score_a         INT NOT NULL CHECK (score_a BETWEEN 0 AND 50),
    score_s         INT NOT NULL CHECK (score_s BETWEEN 0 AND 50),
    score_e         INT NOT NULL CHECK (score_e BETWEEN 0 AND 50),
    score_c         INT NOT NULL CHECK (score_c BETWEEN 0 AND 50),
    top_types       JSONB NOT NULL,
    completed_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (student_id, attempt_number)
);

CREATE TABLE worksheets (
    id                  SERIAL PRIMARY KEY,
    student_id          INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    attempt_number      INT NOT NULL,
    stated_program_id   INT NOT NULL REFERENCES programs(id),
    electives           TEXT[] NOT NULL DEFAULT '{}',
    top_types           JSONB NOT NULL,
    submitted_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (student_id, attempt_number)
);

CREATE TABLE saved_programs (
    student_id  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    program_id  INT NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
    saved_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (student_id, program_id)
);

CREATE TABLE recommendations (
    id                      SERIAL PRIMARY KEY,
    student_id              INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    computed_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    stated_program_id       INT REFERENCES programs(id),
    scores                  JSONB NOT NULL,
    top_program_id          INT NOT NULL REFERENCES programs(id),
    top_score               NUMERIC(5, 4) NOT NULL,
    source_assessment_id    INT NOT NULL REFERENCES assessments(id),
    source_worksheet_id     INT REFERENCES worksheets(id)
);

CREATE TABLE monitoring_flags (
    id                  SERIAL PRIMARY KEY,
    student_id          INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    recommendation_id   INT REFERENCES recommendations(id),
    reason              VARCHAR(30) NOT NULL CHECK (reason IN ('low_confidence', 'manual_escalation', 'other')),
    status              VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'escalated', 'dismissed')),
    counselor_id        INT REFERENCES users(id),
    note                TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at         TIMESTAMPTZ
);

CREATE TABLE audit_log (
    id              BIGSERIAL PRIMARY KEY,
    actor_user_id   INT REFERENCES users(id),
    actor_role      VARCHAR(20),
    action          VARCHAR(100) NOT NULL,
    target_type     VARCHAR(50),
    target_id       VARCHAR(50),
    detail_enc      TEXT,
    ip_address      INET,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE security_rbac (
    module          VARCHAR(50) NOT NULL,
    role            VARCHAR(20) NOT NULL,
    access_level    VARCHAR(10) NOT NULL CHECK (access_level IN ('full', 'limited', 'none')),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by      INT REFERENCES users(id),
    PRIMARY KEY (module, role)
);

CREATE TABLE security_policies (
    key         VARCHAR(100) PRIMARY KEY,
    value       TEXT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by  INT REFERENCES users(id)
);

CREATE TABLE password_reset_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The *expected* list of students for an assessment period, uploaded by an
-- admin (CSV) ahead of time. Intentionally decoupled from users/students —
-- a roster entry may name a student who hasn't registered an account yet.
-- Assessment Statistics joins it against real `assessments` rows by
-- school_id to show expected-vs-completed counts.
CREATE TABLE assessment_roster (
    id              SERIAL PRIMARY KEY,
    academic_year   VARCHAR(20) NOT NULL,
    school_id       VARCHAR(50) NOT NULL,
    name_enc        TEXT NOT NULL,
    strand          VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
    section         VARCHAR(20) NOT NULL,
    uploaded_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    uploaded_by     INT REFERENCES users(id),
    UNIQUE (academic_year, school_id)
);

CREATE TABLE announcements (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    body_enc        TEXT NOT NULL,
    created_by      INT REFERENCES users(id),
    target_type     VARCHAR(10) NOT NULL CHECK (target_type IN ('all', 'specific')),
    publish_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Only populated when announcements.target_type = 'specific'.
CREATE TABLE announcement_recipients (
    announcement_id INT NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    PRIMARY KEY (announcement_id, student_id)
);

CREATE TABLE help_requests (
    id                  SERIAL PRIMARY KEY,
    student_id          INT REFERENCES students(user_id) ON DELETE SET NULL,
    school_id_snapshot  VARCHAR(50),
    name                VARCHAR(255),
    subject             VARCHAR(255),
    message_enc         TEXT,
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    status              VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    resolved_by         INT REFERENCES users(id),
    resolved_at         TIMESTAMPTZ
);

-- A schedule = one exam session (date/time/room) targeting whichever
-- grade level/strand/section it names (NULL on any of those three = "all"
-- for that dimension), rather than a per-student assignment — matches how
-- assessment_roster/analytics already group students by strand/section.
-- access_code follows the same plaintext + hash_equals pattern as
-- security_policies['assessment.accessCode']; it does not replace that
-- global code as the actual assessment-start gate.
CREATE TABLE exam_schedules (
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
);

-- Staff-initiated retake authorization (RIASEC has no pass/fail score, so
-- there's no automatic trigger here — see db/migrate_add_examinations.php
-- comment). A 'granted' row with completed_attempt_number IS NULL is what
-- api/assessment-submit.php checks before allowing attempt_number > 1.
CREATE TABLE retake_grants (
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
);
