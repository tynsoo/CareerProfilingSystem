<?php

/**
 * Traceability of the counseling process (panel revisions S11, S12, S13).
 *
 * The escalation process for a flagged student (a low cosine similarity; a RIASEC mismatch never raises a flag), as the
 * system enforces it:
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
 *      writes resolved_at and resolved_by, and the note is stored encrypted. The flag's active invitation is closed with
 *      it (OUTCOME_BOOKING; Cancelled when a staff invitation's flag is dismissed), so the student's status follows.
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

    /**
     * The Counseling Status shown on Student Information and the Student Profile, from the student's latest invitation.
     * "Invited" covers both a staff invitation and the student's own Avail Counseling click (the source is shown beside
     * it); it never means a session was booked. Did Not Avail is only ever set by staff (did not attend), never assumed.
     */
    public const STATUS_DISPLAY = [
        'invited' => 'Invited',
        'booked' => 'Booked',
        'attended' => 'Availed',
        'no_show' => 'Did Not Avail',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
    ];

    /** An invitation in one of these is still active: no second one is saved (Avail Counseling or Invite to counseling). */
    public const ACTIVE_STATUSES = ['invited', 'booked'];

    /**
     * The booking status an active invitation takes when its flag is closed with a counseling outcome, so the Student
     * Portal and Student Information stop showing it as Invited or Booked (and the student can ask again later).
     */
    public const OUTCOME_BOOKING = [
        'counseled_kept_choice' => 'attended',
        'counseled_changed_choice' => 'attended',
        'referred' => 'attended',
        'declined_counseling' => 'declined',
        'no_show' => 'no_show',
    ];

    /**
     * Closes the active invitation tied to a flag For Counseling when the flag is closed (api/monitoring-action.php),
     * in the caller's transaction. The invitation is the one the Counseling Queue shows for the flag (api/monitoring.php):
     * tied to the flag, or sent after it was raised without a flag. $status is the booking status it takes. With
     * $staffOnly, an invitation the student started with Avail Counseling stays open (a dismissed flag does not cancel
     * the student's own request). Returns [invite id, old status] when one was changed, else null.
     */
    public static function closeInviteForFlag(PDO $pdo, array $flag, string $status, int $userId, bool $staffOnly = false): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, status FROM counseling_invites
             WHERE student_id = ? AND status IN ('invited', 'booked')
               AND (flag_id = ? OR (flag_id IS NULL AND created_at >= COALESCE(?::timestamptz, ?::timestamptz)))"
            . ($staffOnly ? ' AND invited_by IS DISTINCT FROM student_id' : '') . '
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$flag['student_id'], $flag['id'], $flag['escalated_at'], $flag['created_at']]);
        $invite = $stmt->fetch();
        if (!$invite) {
            return null;
        }
        $pdo->prepare('UPDATE counseling_invites SET status = ?, status_updated_at = NOW(), status_updated_by = ? WHERE id = ?')
            ->execute([$status, $userId, $invite['id']]);
        return [(int) $invite['id'], (string) $invite['status']];
    }

    private static ?bool $ready = null;

    public static function outcomeLabel(?string $outcome): ?string
    {
        return $outcome === null ? null : (self::OUTCOMES[$outcome] ?? $outcome);
    }

    public static function bookingLabel(?string $status): string
    {
        return self::BOOKING_STATUSES[$status ?? 'invited'] ?? (string) $status;
    }

    /**
     * Each student's latest counseling invitation, by student id: id, status, when, session date, and who started it
     * (the student through Avail Counseling, or the staff member who sent it). Students with none are left out.
     *
     * @param int[]|null $studentIds null for every student
     * @return array<int,array{id:int,status:string,at:string,sessionDate:?string,selfRequested:bool,by:?string}>
     */
    public static function latestInvites(PDO $pdo, ?array $studentIds = null): array
    {
        self::ensureSchema($pdo);
        if ($studentIds !== null && !$studentIds) {
            return [];
        }
        $where = $studentIds === null ? '' : 'WHERE ci.student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
        $stmt = $pdo->prepare(
            "SELECT DISTINCT ON (ci.student_id) ci.student_id, ci.id, ci.status, ci.created_at, ci.session_date,
                    ci.invited_by = ci.student_id AS self_requested, u.role, u.full_name, u.username
             FROM counseling_invites ci LEFT JOIN users u ON u.id = ci.invited_by
             $where
             ORDER BY ci.student_id, ci.id DESC"
        );
        $stmt->execute($studentIds === null ? [] : array_map('intval', $studentIds));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $staffName = trim((string) $r['full_name']) !== '' ? $r['full_name'] : $r['username'];
            $out[(int) $r['student_id']] = [
                'id' => (int) $r['id'],
                'status' => (string) $r['status'],
                'at' => $r['created_at'],
                'sessionDate' => $r['session_date'],
                'selfRequested' => (bool) $r['self_requested'],
                'by' => $r['self_requested'] ? 'via Avail Counseling' : ($r['role'] !== null ? 'by ' . $staffName : null),
            ];
        }
        return $out;
    }

    /**
     * The Counseling Status label for one student: from the latest invitation, else "Availed" for a student who sent the
     * older "Request for Academic Advising" message, else '' (shown as "No request").
     */
    public static function displayStatus(?array $invite, bool $legacyAdvising): string
    {
        if ($invite !== null) {
            return self::STATUS_DISPLAY[$invite['status']] ?? (string) $invite['status'];
        }
        return $legacyAdvising ? 'Availed' : '';
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
