# ProfilePath

A web-based career profiling system for senior high school students, built around a RIASEC
interest assessment, content-based career recommendations, and counselor/admin tooling for
academic monitoring, security, and reporting.

- **Backend:** PHP 8.4 (built-in server, no framework)
- **Database:** PostgreSQL 17
- **Frontend:** Static HTML/CSS/JS (no build step)
- **Deployment:** [Render](https://render.com) via Docker (see `render.yaml`, `Dockerfile`)

## Roles

- **Student** — registers with an MMCL email, completes the RIASEC assessment, views career
  recommendations, worksheets, and program info.
- **Counselor** — manages student rosters, counseling requests/notes, announcements, and
  monitoring.
- **Admin** — everything a counselor can do, plus staff account management, security/RBAC
  configuration, analytics, and audit logs.

## Project layout

```
api/        JSON API endpoints (one file per action, e.g. api/login.php)
lib/        Shared PHP classes (Database, Auth, Crypto, CBFEngine, Rbac, Mailer, ...)
db/         Schema, migrations, and seed scripts
tests/      Standalone PHP test scripts (no test framework/DB dependency for most)
images/     Static assets, grouped by page/section
*.html      One static page per route (served without the .html extension, see router.php)
config/     .env loader
```

## Prerequisites

- PHP 8.4+ with the `pdo_pgsql` extension
- PostgreSQL 17 (running locally, or a connection string to a remote instance)

## Local setup

1. **Copy the environment file and fill it in:**

   ```bash
   cp .env.example .env
   ```

   - `DB_*` — your local Postgres connection details.
   - `APP_AES_KEY` — generate with `php -r "echo base64_encode(random_bytes(32));"`.
   - `BREVO_API_KEY`, `SMTP_FROM_EMAIL` — only needed if you want real emails to send
     (registration/verification/password-reset). Leave blank for local dev; in `APP_ENV=local`,
     unsent emails are logged instead of failing silently (see `api/forgot-password.php`).

2. **Create the database and apply the schema:**

   ```bash
   php db/create_database.php
   php db/run_schema.php
   ```

3. **Run the migrations** (each is idempotent/`IF NOT EXISTS`-guarded; run in this order):

   ```bash
   php db/migrate_add_sessions_table.php
   php db/migrate_add_email_verification.php
   php db/migrate_add_avatar.php
   php db/migrate_academic_year.php
   php db/migrate_add_section.php
   php db/migrate_add_announcements.php
   php db/migrate_add_program_description.php
   php db/migrate_drop_faculty.php
   php db/migrate_update_strands.php
   php db/migrate_add_assessment_roster.php
   php db/migrate_add_examinations.php
   php db/migrate_add_counseling_notes.php
   ```

4. **Seed reference data and a default admin account:**

   ```bash
   php db/seed_colleges.php
   php db/seed_programs.php
   php db/seed_questions.php
   php db/seed_security_defaults.php
   php db/seed_admin.php
   ```

   This creates `admin` / `ChangeMe123!` — change the password before any real deployment.

5. **Run the app:**

   ```bash
   php -S localhost:8000 router.php
   ```

   Visit `http://localhost:8000`.

## Tests

```bash
php tests/aes_roundtrip_test.php
php tests/cbf_test.php
php tests/rbac_audit_test.php
```

The first two are self-contained (no database needed). `rbac_audit_test.php` needs a database
connection matching `.env`.

## Deployment

`render.yaml` provisions a free Postgres instance and a Docker web service on Render. Push to
the connected branch, or use Render's Blueprint deploy from this repo. Secrets marked
`sync: false` (`APP_URL`, `APP_AES_KEY`, `BREVO_API_KEY`, `SMTP_FROM_EMAIL`) must be set manually
in the Render dashboard after the first deploy.

## Security notes

- Encrypted columns (suffixed `_enc`) use a Modified AES-256-CBC cipher with a key-dependent
  S-box (`lib/Crypto.php`, `lib/ModifiedAES256.php`) and are never used in `WHERE`/`ORDER BY`/
  `JOIN` — see the header comment in `db/schema.sql` for the convention.
- Module-level access is enforced via `lib/Rbac.php`; see `security-configuration.html` and
  `api/security-config.php` for the admin-facing config UI.
- All sensitive/state-changing actions are recorded via `lib/AuditLogger.php` and viewable on
  `audit-log.html`.
