<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../StudentManagement/lib/Sections.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';

// Account Management: sections are assigned to each guidance counselor / facilitator by whoever holds the "Guidance Counselor"
// permission (Security Configuration). It is its own permission, separate from Staff Accounts (approving sign-ups): the
// Head of Guidance has it, nobody else by default. A staff member then sees only those sections' students
// (modules/UserManagement/lib/StaffScope.php); with nothing assigned they see everyone.
$user = Rbac::requireAccess('counselor', $_SERVER['REQUEST_METHOD'] === 'GET' ? 'limited' : 'full');
$pdo = Database::get();

/** Every section that can be assigned: the active sections list plus any section on this year's roster. */
function assignableSections(PDO $pdo): array
{
    $all = [];
    foreach (Sections::byStrand($pdo) as $strand => $codes) {
        foreach ($codes as $code) {
            $all[$strand . '|' . $code] = ['strand' => $strand, 'section' => $code];
        }
    }
    $stmt = $pdo->prepare('SELECT DISTINCT strand, section FROM assessment_roster WHERE academic_year = ?');
    $stmt->execute([AcademicYear::current()]);
    foreach ($stmt->fetchAll() as $r) {
        $all[$r['strand'] . '|' . $r['section']] = ['strand' => $r['strand'], 'section' => $r['section']];
    }
    ksort($all);
    return array_values($all);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $byUser = [];
    try {
        foreach ($pdo->query('SELECT user_id, strand, section FROM staff_sections ORDER BY strand, section')->fetchAll() as $r) {
            $byUser[(int) $r['user_id']][] = ['strand' => $r['strand'], 'section' => $r['section']];
        }
    } catch (Throwable $e) {
        // table not created yet: nothing assigned
    }
    jsonResponse(['assignments' => (object) $byUser, 'options' => assignableSections($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$userId = (int) ($body['userId'] ?? 0);
$sections = $body['sections'] ?? [];
if (!is_array($sections)) {
    jsonResponse(['success' => false, 'error' => 'Invalid sections.'], 400);
}

$exists = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'counselor'");
$exists->execute([$userId]);
if (!$exists->fetch()) {
    jsonResponse(['success' => false, 'error' => 'Staff account not found.'], 404);
}

$allowed = [];
foreach (assignableSections($pdo) as $s) {
    $allowed[$s['strand'] . '|' . $s['section']] = $s;
}
$clean = [];
foreach ($sections as $s) {
    $key = (string) ($s['strand'] ?? '') . '|' . (string) ($s['section'] ?? '');
    if (!isset($allowed[$key])) {
        jsonResponse(['success' => false, 'error' => 'One of the sections is not valid.'], 400);
    }
    $clean[$key] = $allowed[$key];
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM staff_sections WHERE user_id = ?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO staff_sections (user_id, strand, section) VALUES (?, ?, ?)');
    foreach ($clean as $s) {
        $insert->execute([$userId, $s['strand'], $s['section']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[staff-assignments] failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Could not save the sections. Has the staff_sections table been created?'], 500);
}

AuditLogger::log($user['id'], 'admin', 'assign_staff_sections', 'user', (string) $userId, count($clean) . ' section(s): ' . implode(', ', array_map(fn($s) => $s['strand'] . ' ' . $s['section'], $clean)));
jsonResponse(['success' => true, 'sections' => array_values($clean)]);
