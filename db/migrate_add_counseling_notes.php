<?php
// Adds counseling_notes -- a timestamped free-text log a counselor/admin
// writes about a student (session summaries, follow-up items), distinct
// from the auto-derived Availed/Did Not Avail counseling status already
// shown on student-profile.html. See db/schema.sql's table comment for
// the encryption rationale.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS counseling_notes (
        id              SERIAL PRIMARY KEY,
        student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        note_enc        TEXT NOT NULL,
        author_id       INT REFERENCES users(id),
        created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);

// Register the 'counselingNotes' RBAC module. Distinct from the existing
// (never-wired-up) 'counselor' module, which is about managing counselor
// staff accounts, not counseling records -- reusing it here would conflate
// two unrelated permissions.
$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
foreach (['admin' => 'full', 'counselor' => 'full', 'student' => 'none'] as $role => $level) {
    $rbacStmt->execute(['counselingNotes', $role, $level]);
}

echo "Created counseling_notes table and registered 'counselingNotes' RBAC module.\n";
