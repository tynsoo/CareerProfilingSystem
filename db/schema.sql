-- ProfilePath schema (PostgreSQL)
--
-- Encryption convention: columns suffixed _enc hold base64 ciphertext produced by
-- modules/Encryption/lib/Crypto.php (Modified AES-256-CBC with a key-dependent S-box). They are never
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
    -- Counselors sign up themselves and start 'pending' until an admin approves
    -- them (Account Management). Everyone else is always 'approved'.
    approval_status         VARCHAR(10) NOT NULL DEFAULT 'approved' CHECK (approval_status IN ('pending', 'approved', 'rejected')),
    full_name               VARCHAR(150),
    -- 'counselor' or 'facilitator', chosen at staff sign-up. Both are the same
    -- system role (role = 'counselor') with identical access; this is only the
    -- title shown next to the name. NULL (older accounts) reads as counselor.
    staff_position          VARCHAR(12) CHECK (staff_position IS NULL OR staff_position IN ('counselor', 'facilitator')),
    -- A Guidance Counselor the administrator made a co-admin (Account Management): shares the admin's day-to-day work,
    -- never the permission table, security settings or audit log retention (modules/Authentication/lib/Rbac.php).
    -- Never a Guidance Facilitator (DFD process 4.0), a student or the administrator (db/migrate_add_co_admin.php).
    is_co_admin             BOOLEAN NOT NULL DEFAULT FALSE,
    failed_login_attempts   INT NOT NULL DEFAULT 0,
    locked_until            TIMESTAMPTZ,
    notifications_read_at   TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT users_co_admin_counselor_only
        CHECK (NOT is_co_admin OR (role = 'counselor' AND staff_position IS DISTINCT FROM 'facilitator'))
);

-- Students self-register with a MMCL-issued email (see api/register.php's
-- domain check) but that only proves the string LOOKS right — this proves
-- they actually control the inbox before the account can log in. NULL =
-- not verified yet. Enforced for role='student'; counselors who sign up
-- themselves verify their email too and are then approved by an admin
-- (users.approval_status, api/staff-approvals.php).
CREATE TABLE email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Consent receipts (RA 10173): who agreed to the Terms of Use and Data Privacy Policy, which version,
-- how, and when (modules/UserManagement/lib/Consent.php). No IP address or device is stored.
CREATE TABLE consent_records (
    id              BIGSERIAL PRIMARY KEY,
    user_id         INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    policy_version  VARCHAR(20) NOT NULL,
    method          VARCHAR(30) NOT NULL CHECK (method IN ('registration', 'staff_signup')),
    consented_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_consent_records_user ON consent_records (user_id);

-- Backs modules/Authentication/lib/DbSessionHandler.php — PHP sessions stored here instead of local
-- disk, since Render's free web service tier wipes local disk on every
-- restart after idling.
CREATE TABLE sessions (
    id              VARCHAR(128) PRIMARY KEY,
    data            TEXT NOT NULL DEFAULT '',
    last_activity   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- whose sign-in this is (NULL before sign-in); modules/Authentication/lib/SessionEnd.php uses it to sign one person out
    user_id         INT,
    -- set when the session was ended for a reason (other_device, deactivated, password_changed)
    ended_reason    VARCHAR(30)
);
CREATE INDEX idx_sessions_user ON sessions (user_id);

CREATE TABLE colleges (
    id      SERIAL PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE,
    name    VARCHAR(255) NOT NULL
);

-- Admin-manageable section list, one strand each (see modules/StudentManagement/lib/Sections.php,
-- the server-side source of truth for every place a section is accepted:
-- registration, exam scheduling, staff section-corrections). Deactivated
-- rather than deleted when a section stops being offered, so a student
-- already registered under it keeps valid historical data — it just stops
-- being offered for new registrations/schedules.
CREATE TABLE sections (
    id          SERIAL PRIMARY KEY,
    strand      VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
    code        VARCHAR(20) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by  INT REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX idx_sections_strand_code ON sections (strand, LOWER(code));

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
    holland_code_enc    TEXT NOT NULL,      -- final (validator-revised) code; used by the CBF
    original_holland_code_enc TEXT,         -- code before validator revision; audit only
    description_enc     TEXT,
    -- SHS strands aligned with this program (CBF "strand" feature); '{}' = not specified.
    related_strands     TEXT[] NOT NULL DEFAULT '{}',
    -- Careers this program leads to, shown to students on Career Results and
    -- offered on the Career Worksheet. Plain reference data (like colleges),
    -- not student data, so it is not encrypted and can be loaded with SQL.
    careers             TEXT[] NOT NULL DEFAULT '{}',
    status              VARCHAR(10) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE assessment_questions (
    id                  SERIAL PRIMARY KEY,
    dimension           CHAR(1) NOT NULL CHECK (dimension IN ('R', 'I', 'A', 'S', 'E', 'C')),
    question_text_enc   TEXT NOT NULL,
    order_index         INT NOT NULL CHECK (order_index >= 1),
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
    -- NULL when the career the student typed couldn't be linked to a program
    -- (see modules/CareerProfiling/lib/CareerMatcher.php).
    stated_program_id   INT REFERENCES programs(id),
    stated_career       VARCHAR(120),
    electives           TEXT[] NOT NULL DEFAULT '{}',
    top_types           JSONB NOT NULL,
    submitted_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (student_id, attempt_number)
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
    source_worksheet_id     INT REFERENCES worksheets(id),
    -- Match/mismatch outcome (RecommendationPipeline::finalRecommendation); NULL on results saved before it existed.
    match_status            VARCHAR(10) CHECK (match_status IN ('match', 'mismatch')),
    mismatch_reason         VARCHAR(40),
    -- Pipeline stages (modules/ContentBasedFiltering/lib/RecommendationPipeline.php); NULL on results saved before they existed.
    cbf_program_ids         INT[],              -- Best RIASEC Match (CBF: highest cosine similarity)
    prediction_program_ids  INT[],              -- prediction model output; NULL while the model is not enabled
    final_program_ids       INT[],              -- final Best Match (CBF + prediction + worksheet); NULL until the prediction model exists
    model_version           VARCHAR(80)         -- imported WEKA model used
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
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- Tamper protection (modules/AuditLog/lib/AuditChain.php, db/migrate_add_audit_hash_chain.php): HMAC seal of this
    -- entry and of the entry before it, so a changed or removed entry breaks the chain.
    prev_hash       CHAR(64),
    row_hash        CHAR(64)
);

-- One row per feature and role (module = a key of Rbac::DEFAULTS in modules/Authentication/lib/Rbac.php, grouped by
-- sidebar page in Rbac::PAGES). Filled by db/seed_security_defaults.php and db/migrate_rbac_by_page.php.
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
    -- The student's school email from the roster file: announcements are emailed here, registered or not,
    -- and a registration for this Student Number must use it.
    email           VARCHAR(255),
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
    -- Set once its email batch has gone out (see api/announcements.php's
    -- emailAnnouncement()); NULL until then, so it's never sent twice.
    emailed_at      TIMESTAMPTZ,
    -- A draft is never shown to students or emailed until it is sent.
    status          VARCHAR(10) NOT NULL DEFAULT 'sent' CHECK (status IN ('draft', 'sent')),
    -- When "Remind Unread" last emailed the students who hadn't seen it.
    last_reminded_at TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Which student saw which announcement (shown on their Assessments page or
-- the full Notifications page); drives the "% read" figure on the staff page.
CREATE TABLE announcement_reads (
    announcement_id INT NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    read_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (announcement_id, student_id)
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
    name_enc            TEXT,
    subject_enc         TEXT,
    message_enc         TEXT,
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    status              VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    resolved_by         INT REFERENCES users(id),
    resolved_at         TIMESTAMPTZ
);

-- Generic sliding-window rate limiter (shared/lib/RateLimiter.php), reused by
-- forgot-password, RIASEC access-code verification, and any future
-- endpoint that needs simple abuse throttling.
CREATE TABLE rate_limit_hits (
    id          SERIAL PRIMARY KEY,
    rate_key    VARCHAR(255) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_rate_limit_hits_key_time ON rate_limit_hits(rate_key, created_at);

-- Email one-time codes (modules/Authentication/lib/TwoFactor.php) for the staff password-change
-- step-up verification, gated by the 'twoFactor' security policy toggle.
CREATE TABLE two_factor_codes (
    id          SERIAL PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash   VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    attempts    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_two_factor_codes_user_id ON two_factor_codes(user_id);

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
    schedule_type   VARCHAR(10) NOT NULL DEFAULT 'initial' CHECK (schedule_type IN ('initial', 'retake', 'makeup')),
    created_by      INT REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The students a make-up session (schedule_type 'makeup') is for: absent on the day, or needing another date. A make-up
-- row keeps grade_level, strand and section NULL and is matched only through this table, never by the group rule
-- (ExamSchedule::matchSql). Added by db/migrate_add_makeup_sessions.php.
CREATE TABLE exam_schedule_students (
    schedule_id     INT NOT NULL REFERENCES exam_schedules(id) ON DELETE CASCADE,
    student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    PRIMARY KEY (schedule_id, student_id)
);
CREATE INDEX idx_exam_schedule_students_student_id ON exam_schedule_students(student_id);

-- A student's RIASEC answers in progress, saved as they go (modules/CareerProfiling/lib/AssessmentProgress.php), and the
-- exam session that unlocked the assessment (NULL with is_retake for an approved retake). Lets a
-- student who was signed out continue, on any PC, without the code while the session is open.
-- answers_enc: JSON {set, answers}, encrypted (assessment data). Deleted on submit.
CREATE TABLE assessment_progress (
    student_id      INT PRIMARY KEY REFERENCES students(user_id) ON DELETE CASCADE,
    schedule_id     INT REFERENCES exam_schedules(id) ON DELETE SET NULL,
    is_retake       BOOLEAN NOT NULL DEFAULT FALSE,
    answers_enc     TEXT,
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

-- A student's request to retake the RIASEC assessment. A counselor or the Head of Guidance approves or declines it; an
-- approved request lets the student retake once (api/assessment-submit.php), and the earlier result stays on record as
-- an older attempt. Only recommendations built from the latest assessment count (see modules/CareerProfiling/lib/RetakeRequest.php).
CREATE TABLE retake_requests (
    id                          SERIAL PRIMARY KEY,
    student_id                  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    attempt_number              INT NOT NULL,
    reason_enc                  TEXT NOT NULL,
    status                      VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'declined', 'completed')),
    requested_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    decided_by                  INT REFERENCES users(id) ON DELETE SET NULL,
    decided_at                  TIMESTAMPTZ,
    decision_note_enc           TEXT,
    completed_attempt_number    INT,
    completed_at                TIMESTAMPTZ
);
-- A student has at most one request waiting or approved at a time.
CREATE UNIQUE INDEX idx_retake_requests_open ON retake_requests (student_id) WHERE status IN ('pending', 'approved');

-- A running, timestamped log of free-text notes a counselor/admin writes
-- about a student (session summaries, follow-up items) -- distinct from
-- the auto-derived Availed/Did Not Avail counseling status already shown
-- on student-profile.html, which only reflects whether an advising
-- request was ever submitted. Encrypted like help_requests.message_enc,
-- since counseling notes are exactly the kind of sensitive personal
-- disclosure the schema's encryption convention exists to protect.
CREATE TABLE counseling_notes (
    id              SERIAL PRIMARY KEY,
    student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    note_enc        TEXT NOT NULL,
    author_id       INT REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- A counselor's invitation to book a counseling session (Student Information, Invite). The student gets the CGC booking link by
-- email and as a notification (api/notifications.php shows these); the link is kept here as it was when the invite was sent.
CREATE TABLE counseling_invites (
    id          SERIAL PRIMARY KEY,
    student_id  INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    invited_by  INT REFERENCES users(id) ON DELETE SET NULL,
    booking_url VARCHAR(300) NOT NULL,
    email_sent  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_counseling_invites_student ON counseling_invites (student_id, id);

-- Notifications are computed live; this remembers the ones a student deleted
-- (item_key comes from api/notifications.php, e.g. 'ann:12').
CREATE TABLE notification_dismissals (
    user_id      INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    item_key     VARCHAR(100) NOT NULL,
    dismissed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, item_key)
);

-- Which sections each guidance counselor / facilitator handles (assigned by the administrator).
-- A staff member with none assigned sees every section.
CREATE TABLE staff_sections (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    strand  VARCHAR(10) NOT NULL CHECK (strand IN ('STEM', 'ABM', 'ICT', 'HUMSS')),
    section VARCHAR(20) NOT NULL,
    PRIMARY KEY (user_id, strand, section)
);

-- Help Center FAQs the Guidance Office can add, edit, reorder and delete (api/faqs.php).
-- audience 'student' shows on the student Help Center, 'staff' on the staff one.
CREATE TABLE faqs (
    id          SERIAL PRIMARY KEY,
    audience    VARCHAR(10) NOT NULL CHECK (audience IN ('student', 'staff')),
    question    VARCHAR(300) NOT NULL,
    answer      TEXT NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_by  INT REFERENCES users(id) ON DELETE SET NULL,
    updated_by  INT REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_faqs_audience_order ON faqs (audience, sort_order, id);

-- Announcement templates the Guidance Counselor types and keeps (api/announcement-templates.php), so a new
-- announcement can start from one.
CREATE TABLE announcement_templates (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    body        TEXT NOT NULL,
    created_by  INT REFERENCES users(id) ON DELETE SET NULL,
    updated_by  INT REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The emailed 6-digit codes behind "Forgot password" (api/password-reset.php). A correct code returns a one-time
-- reset token; both are stored hashed. Replaces the old emailed-link flow (password_reset_tokens, now unused).
CREATE TABLE password_reset_codes (
    id                SERIAL PRIMARY KEY,
    user_id           INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    portal            VARCHAR(10) NOT NULL CHECK (portal IN ('student', 'staff')),
    code_hash         VARCHAR(255) NOT NULL,
    attempts          INT NOT NULL DEFAULT 0,
    expires_at        TIMESTAMPTZ NOT NULL,
    verified_at       TIMESTAMPTZ,
    reset_token_hash  VARCHAR(255),
    reset_expires_at  TIMESTAMPTZ,
    used_at           TIMESTAMPTZ,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_password_reset_codes_user ON password_reset_codes (user_id, id);
CREATE INDEX idx_password_reset_codes_token ON password_reset_codes (reset_token_hash);
