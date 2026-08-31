<?php
// Removes the Faculty Assignment feature — a no-login "Faculty" entity
// assigned to exam sessions with an encrypted per-session access code —
// which was built and then rolled back: in this school's actual process,
// Guidance Counselors are the ones assigned per room, not a separate
// faculty/proctor entity. exam_schedules and retake_grants are untouched
// and stay in place (see db/migrate_add_examinations.php, now updated to
// no longer create these two tables on fresh installs).
//
// Idempotent (IF EXISTS), safe to re-run. exam_schedule_faculty is
// dropped first since it holds the FK to faculty.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec('DROP TABLE IF EXISTS exam_schedule_faculty');
$pdo->exec('DROP TABLE IF EXISTS faculty');

echo "faculty + exam_schedule_faculty tables dropped (or already absent).\n";
