<?php
// Guidance Counselors add and edit programs and their RIASEC codes (Career Dataset, "Career Profile Input");
// Guidance Facilitators keep view-only access. The administrator keeps Full access and can still change
// any of it in Security Configuration. Safe to re-run.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();
$counselor = $pdo->exec(
    "UPDATE security_rbac SET access_level = 'full', updated_at = NOW()
     WHERE module = 'career' AND role = 'counselor' AND access_level <> 'full'"
);
$facilitator = $pdo->exec(
    "INSERT INTO security_rbac (module, role, access_level) VALUES ('career', 'facilitator', 'limited')
     ON CONFLICT (module, role) DO UPDATE SET access_level = 'limited', updated_at = NOW()
     WHERE security_rbac.access_level = 'full'"
);

echo "Career Dataset: Guidance Counselors Full ($counselor row(s) changed), Facilitators View Only ($facilitator row(s) changed).\n";
