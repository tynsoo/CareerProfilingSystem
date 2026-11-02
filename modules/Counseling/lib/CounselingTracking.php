<?php

/**
 * Traceability of the counseling process (panel revisions S11, S12, S13).
 *
 * The escalation process for a flagged student (a RIASEC mismatch or a low similarity), as the system enforces it:
 *
 *   1. Needs Review (status 'pending'). Raised by the system (CBFData::saveRecommendation). Anyone with Full on
 *      "Flagged student monitoring" reviews it and either
 *        - approves it ('approved', outcome 'no_counseling_needed'): the result stands, no session is needed,
 *        - dismisses it ('dismissed', outcome 'not_valid'): a false flag; it stays in History, never deleted, or
 *        - escalates it ('escalated'): it moves to the For Counseling tab.
 *   2. For Counseling (status 'escalated'). The Guidance Counselor or the Administrator (never a Guidance Facilitator,
 *      DFD process 4.0; the same rule as sending invitations) invites the student to book through the CGC booking
 *      link and records what happened with the booking (BOOKING_STATUSES), since the external booking system cannot
 *      report back on its own.
 *   3. Resolved. The Guidance Counselor or the Administrator closes the flag with one of COUNSELING_OUTCOMES. Closing
 *      writes resolved_at and resolved_by, and the note is stored encrypted.
 *
 * Every step is also written to the Audit Log. Approved and dismissed flags are kept and listed on the History tab
 * (monitoring.php tab=history) with when, by whom and why.
 *
 * The columns are added by ensureSchema(), on the first request that needs them (the same way the co-admin column
 * is added, Rbac::hasCoAdminColumn), so the live site needs no manual step. db/migrate_add_counseling_tracking.php
 * does the same by hand.
 */
class CounselingTracking
{
    /** How a flag ended. Only these are stored in monitoring_flags.outcome. */
    public const OUTCOMES = [
        // From Needs Review
        'no_counseling_needed' => 'Reviewed, no counseling needed',
        'not_valid' => 'Dismissed, not a valid flag',
        // From For Counseling (COUNSELING_OUTCOMES)
        'counseled_kept_choice' => 'Counseled, student kept their chosen program',
        'counseled_changed_choice' => 'Counseled, student changed their chosen program',
        'referred' => 'Referred for further counseling or to another office',
        'declined_counseling' => 'Student declined counseling',
        'no_show' => 'Student did not book or did not attend',
        // Older rows and retakes
        'replaced_by_retake' => 'Replaced by a retake',
    ];

    /** What may close a flag that is For Counseling. */
    public const COUNSELING_OUTCOMES = ['counseled_kept_choice', 'counseled_changed_choice', 'referred', 'declined_counseling', 'no_show'];

    /**
     * The booking state of one counseling invitation. The CGC books sessions in its own system, which cannot report
     * back, so the Guidance Counselor or the Administrator records here what they learn (api/counseling-invite.php
     * type status). Each change is written to the Audit Log.
     */
    public const BOOKING_STATUSES = [
        'invited' => 'Invited, not booked yet',
        'booked' => 'Booked a session',
        'attended' => 'Attended the session',
        'no_show' => 'Did not attend',
        'declined' => 'Declined the invitation',
        'cancelled' => 'Invitation cancelled',
    ];

    private static ?bool $ready = null;

    public static function outcomeLabel(?string $outcome): ?string
    {
        return $outcome === null ? null : (self::OUTCOMES[$outcome] ?? $outcome);
    }

    public static function bookingLabel(?string $status): string
    {
        return self::BOOKING_STATUSES[$status ?? 'invited'] ?? (string) $status;
    }

    /** Adds the tracking columns if they are missing. Safe to call on every request; it checks once per request. */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ready === true) {
            return;
        }
        $have = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE (table_name = 'monitoring_flags' AND column_name IN ('resolved_by', 'outcome', 'resolution_note_enc', 'escalated_at', 'escalated_by'))
                OR (table_name = 'counseling_invites' AND column_name IN ('flag_id', 'status', 'status_updated_at', 'status_updated_by', 'session_date'))"
        )->fetchColumn();
        if ((int) $have < 10) {
            self::migrate($pdo);
        }
        self::$ready = true;
    }

    public static function migrate(PDO $pdo): void
    {
        // One at a time, so two first requests at once do not both try.
        $pdo->query("SELECT pg_advisory_lock(hashtext('counseling-tracking-schema'))");
        try {
            $pdo->exec('ALTER TABLE monitoring_flags ADD COLUMN IF NOT EXISTS resolved_by INT REFERENCES users(id) ON DELETE SET NULL');
            $pdo->exec('ALTER TABLE monitoring_flags ADD COLUMN IF NOT EXISTS outcome VARCHAR(30)');
            $pdo->exec('ALTER TABLE monitoring_flags ADD COLUMN IF NOT EXISTS resolution_note_enc TEXT');
            $pdo->exec('ALTER TABLE monitoring_flags ADD COLUMN IF NOT EXISTS escalated_at TIMESTAMPTZ');
            $pdo->exec('ALTER TABLE monitoring_flags ADD COLUMN IF NOT EXISTS escalated_by INT REFERENCES users(id) ON DELETE SET NULL');
            $pdo->exec('ALTER TABLE counseling_invites ADD COLUMN IF NOT EXISTS flag_id INT REFERENCES monitoring_flags(id) ON DELETE SET NULL');
            $pdo->exec("ALTER TABLE counseling_invites ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'invited'");
            $pdo->exec('ALTER TABLE counseling_invites ADD COLUMN IF NOT EXISTS status_updated_at TIMESTAMPTZ');
            $pdo->exec('ALTER TABLE counseling_invites ADD COLUMN IF NOT EXISTS status_updated_by INT REFERENCES users(id) ON DELETE SET NULL');
            $pdo->exec('ALTER TABLE counseling_invites ADD COLUMN IF NOT EXISTS session_date DATE');
            if (!$pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'counseling_invites_status_check'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE counseling_invites ADD CONSTRAINT counseling_invites_status_check
                            CHECK (status IN ('" . implode("', '", array_keys(self::BOOKING_STATUSES)) . "'))");
            }
            // Older rows: who escalated was kept in counselor_id; a retake's automatic dismissal said so in note.
            $pdo->exec("UPDATE monitoring_flags SET escalated_by = counselor_id WHERE escalated_by IS NULL AND counselor_id IS NOT NULL");
            $pdo->exec("UPDATE monitoring_flags SET outcome = 'replaced_by_retake' WHERE outcome IS NULL AND status = 'dismissed' AND note = 'Replaced by a retake'");
        } finally {
            $pdo->query("SELECT pg_advisory_unlock(hashtext('counseling-tracking-schema'))");
        }
    }
}
