<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS announcements (
        id              SERIAL PRIMARY KEY,
        title           VARCHAR(255) NOT NULL,
        body_enc        TEXT NOT NULL,
        created_by      INT REFERENCES users(id),
        target_type     VARCHAR(10) NOT NULL CHECK (target_type IN ('all', 'specific')),
        publish_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS announcement_recipients (
        announcement_id INT NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
        student_id      INT NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
        PRIMARY KEY (announcement_id, student_id)
    )"
);

$rbacStmt = $pdo->prepare(
    'INSERT INTO security_rbac (module, role, access_level) VALUES (?, ?, ?)
     ON CONFLICT (module, role) DO NOTHING'
);
foreach (['admin' => 'full', 'counselor' => 'limited', 'student' => 'limited'] as $role => $level) {
    $rbacStmt->execute(['announcements', $role, $level]);
}

echo "announcements + announcement_recipients tables created (or already present); RBAC rows seeded.\n";
