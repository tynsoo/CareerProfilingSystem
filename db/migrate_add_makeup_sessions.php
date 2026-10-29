<?php
// Make-up assessment sessions: a session for chosen students (absent on the day, or needing another date) instead of
// a grade/strand/section group. Adds exam_schedule_students (which students a make-up session is for) and allows
// schedule_type 'makeup' on exam_schedules. A make-up row keeps grade_level, strand and section NULL; it is matched
// to students only through exam_schedule_students (ExamSchedule::matchSql), never by the group rule.
// Safe to run twice.
//   php db/migrate_add_makeup_sessions.php

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();

$pdo->beginTransaction();
$pdo->exec('ALTER TABLE exam_schedules DROP CONSTRAINT IF EXISTS exam_schedules_schedule_type_check');
$pdo->exec(
    "ALTER TABLE exam_schedules ADD CONSTRAINT exam_schedules_schedule_type_check
     CHECK (schedule_type IN ('initial', 'retake', 'makeup'))"
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS exam_schedule_students (
        schedule_id     INT NOT NULL REFERENCES exam_schedules(id) ON DELETE CASCADE,
        student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        PRIMARY KEY (schedule_id, student_id)
    )'
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_exam_schedule_students_student_id ON exam_schedule_students(student_id)');
$pdo->commit();

echo "Make-up sessions: exam_schedule_students ready, schedule_type allows 'makeup'.\n";
