<?php
/**
 * Sets up an EMPTY database from scratch, in one go: the schema, every migration (oldest first),
 * the reference data (colleges, the 32 programs with their careers and descriptions, the
 * 30-question O*NET set, the default permissions) and the default admin account.
 *
 *   php db/setup_new_database.php
 *
 * Uses DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD and APP_AES_KEY from the environment
 * (or .env). APP_AES_KEY must be the same key the web service uses, or the encrypted program
 * names and questions cannot be read by the site.
 *
 * Refuses to run on a database that already has user accounts, so it cannot overwrite a live site.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../shared/lib/Database.php';

if (!envValue('APP_AES_KEY')) {
    echo "APP_AES_KEY is not set. Use the same key as the web service on Render. Stopped.\n";
    exit(1);
}

$pdo = Database::get();
echo 'Database: ' . envValue('DB_NAME') . ' on ' . envValue('DB_HOST') . "\n";
$hasUsersTable = $pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn();
if ($hasUsersTable && (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() > 0) {
    echo "This database already has user accounts, so it is not empty. Stopped (nothing was changed).\n";
    exit(1);
}

$steps = [
    'run_schema.php',
    // Every migration, in the order they were written. Each is safe to run on a new database.
    'migrate_add_avatar.php',
    'migrate_add_sessions_table.php',
    'migrate_add_email_verification.php',
    'migrate_academic_year.php',
    'migrate_add_section.php',
    'migrate_add_assessment_roster.php',
    'migrate_add_announcements.php',
    'migrate_add_examinations.php',
    'migrate_add_program_description.php',
    'migrate_drop_faculty.php',
    'migrate_update_strands.php',
    'migrate_add_counseling_notes.php',
    'migrate_add_notifications_read_at.php',
    'migrate_add_announcement_email.php',
    'migrate_add_notification_dismissals.php',
    'migrate_add_rate_limiting.php',
    'migrate_add_two_factor.php',
    'migrate_encrypt_help_requests.php',
    'migrate_add_sections_table.php',
    'migrate_add_program_related_strands.php',
    'migrate_add_sections_rbac.php',
    'migrate_add_program_careers.php',
    'migrate_add_staff_approval.php',
    'migrate_announcement_features.php',
    'migrate_add_recommendation_status.php',
    'migrate_add_staff_sections.php',
    'migrate_add_recommendation_sets.php',
    'migrate_add_faqs.php',
    'migrate_add_roster_email.php',
    'migrate_counselor_write_access.php',
    'migrate_add_announcement_templates.php',
    'migrate_add_password_reset_codes.php',
    'migrate_student_announcements_no_access.php',
    'migrate_question_bank_flexible.php',
    'migrate_add_counseling_invites.php',
    'migrate_add_facilitator_role.php',
    'migrate_counselor_career_access.php',
    'migrate_add_staff_accounts_rbac.php',
    'migrate_add_retake_requests.php',
    'migrate_add_assessment_progress.php',
    'migrate_add_consent_records.php',
    'migrate_add_session_user.php',
    'migrate_add_assessment_versions.php',
    // Reference data.
    'seed_colleges.php',
    'seed_programs.php',
    'backfill_program_careers.php',
    'backfill_program_descriptions.php',
    'migrate_final_holland_codes.php',
    'seed_questions.php',
    'seed_security_defaults.php',
    // Permission rows that depend on the defaults above (safe to run again).
    'migrate_add_sections_rbac.php',
    'migrate_counselor_write_access.php',
    'migrate_student_announcements_no_access.php',
    'migrate_add_facilitator_role.php',
    'migrate_counselor_career_access.php',
    'migrate_add_staff_accounts_rbac.php',
    // Last: the default admin account.
    'seed_admin.php',
    // The audit log's tamper seal over everything written so far.
    'migrate_add_audit_hash_chain.php',
];

foreach ($steps as $i => $step) {
    echo "\n[" . ($i + 1) . '/' . count($steps) . "] $step\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $step), $code);
    if ($code !== 0) {
        echo "\nFAILED at $step (exit code $code). Fix the problem above, then start again on a new empty database.\n";
        exit($code);
    }
}

echo "\nDone. Sign in at /staff-login as admin and change the password straight away.\n";
