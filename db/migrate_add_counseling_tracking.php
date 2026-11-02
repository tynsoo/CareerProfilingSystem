<?php
// Counseling traceability (panel revisions S11, S12, S13; modules/Counseling/lib/CounselingTracking.php):
//   monitoring_flags:   resolved_by, outcome, resolution_note_enc (encrypted), escalated_at, escalated_by
//   counseling_invites: flag_id, status (invited, booked, attended, no_show, declined, cancelled), status_updated_at,
//                       status_updated_by, session_date
// Safe to run twice. The app also does this by itself on the first request that needs the columns, so the live site
// needs no manual run; this script is for a new database or to do it by hand.

require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Counseling/lib/CounselingTracking.php';

CounselingTracking::migrate(Database::get());
echo "Counseling tracking columns added (or already present).\n";
