<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS academic_year VARCHAR(20)");

// Seed the two new settings only if they don't already exist, so re-running
// this script (or running it after an admin has already changed them) never
// clobbers a real value.
$existing = $pdo->query(
    "SELECT key FROM security_policies WHERE key IN ('academicYear.current', 'assessment.accessCode')"
)->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('academicYear.current', $existing, true)) {
    $now = (int) date('n') >= 6 ? (int) date('Y') : (int) date('Y') - 1; // school year starts ~June in PH
    $defaultAy = $now . '-' . ($now + 1);
    $pdo->prepare("INSERT INTO security_policies (key, value) VALUES ('academicYear.current', ?)")
        ->execute([$defaultAy]);
    echo "Seeded academicYear.current = $defaultAy\n";
}

if (!in_array('assessment.accessCode', $existing, true)) {
    $code = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars, e.g. "A3F9C1"
    $pdo->prepare("INSERT INTO security_policies (key, value) VALUES ('assessment.accessCode', ?)")
        ->execute([$code]);
    echo "Seeded assessment.accessCode = $code\n";
}

// Existing students (registered before this feature existed) have no AY on
// record — backfill them to the current AY rather than leaving it NULL, so
// AY-scoped reports/exports don't silently drop pre-existing students.
$currentAy = $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
$pdo->prepare("UPDATE students SET academic_year = ? WHERE academic_year IS NULL")->execute([$currentAy]);

echo "students.academic_year column added (or already present); backfilled to '$currentAy'.\n";
echo "Done.\n";
