<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/Faq.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChange.php';

// The Help Center FAQs. Anyone signed in can read the list meant for them (students read the student FAQs;
// staff read both, so they can see what students are told). The administrator and Guidance Counselors add,
// edit, reorder and delete them; a Guidance Facilitator, like everywhere else, can only look.
$user = Auth::requireLogin();
$pdo = Database::get();
$isStudent = $user['role'] === 'student';
$canEdit = !$isStudent && !Rbac::isFacilitator($user);

function faqRow(array $r): array
{
    return ['id' => (int) $r['id'], 'audience' => $r['audience'], 'question' => $r['question'], 'answer' => $r['answer'], 'sortOrder' => (int) $r['sort_order']];
}

/** Renumbers an audience's FAQs 1..n in their current order, so moves and deletes never leave gaps or ties. */
function renumber(PDO $pdo, string $audience): void
{
    $ids = $pdo->prepare('SELECT id FROM faqs WHERE audience = ? ORDER BY sort_order, id');
    $ids->execute([$audience]);
    $set = $pdo->prepare('UPDATE faqs SET sort_order = ? WHERE id = ?');
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
        $set->execute([$i + 1, $id]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $audience = (string) ($_GET['audience'] ?? ($isStudent ? 'student' : 'staff'));
    if (!in_array($audience, Faq::AUDIENCES, true) || ($isStudent && $audience !== 'student')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
    $stmt = $pdo->prepare('SELECT id, audience, question, answer, sort_order FROM faqs WHERE audience = ? ORDER BY sort_order, id');
    $stmt->execute([$audience]);
    jsonResponse(['audience' => $audience, 'canEdit' => $canEdit, 'faqs' => array_map('faqRow', $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}
if (!$canEdit) {
    jsonResponse(['success' => false, 'error' => $isStudent ? 'Forbidden' : 'Guidance Facilitators have view-only access to this.'], 403);
}

$body = readJsonBody();
$type = (string) ($body['type'] ?? '');

if ($type === 'create') {
    $audience = (string) ($body['audience'] ?? '');
    $question = Faq::clean((string) ($body['question'] ?? ''), false);
    $answer = Faq::clean((string) ($body['answer'] ?? ''), true);
    if (!in_array($audience, Faq::AUDIENCES, true)) {
        jsonResponse(['success' => false, 'error' => 'Choose whether this FAQ is for students or for staff.'], 400);
    }
    $problem = Faq::validate($question, $answer);
    if ($problem !== null) {
        jsonResponse(['success' => false, 'error' => $problem], 400);
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM faqs WHERE audience = ?');
    $count->execute([$audience]);
    if ((int) $count->fetchColumn() >= Faq::PER_AUDIENCE_MAX) {
        jsonResponse(['success' => false, 'error' => 'There are already ' . Faq::PER_AUDIENCE_MAX . ' FAQs for this audience. Delete one before adding another.'], 409);
    }
    $insert = $pdo->prepare(
        'INSERT INTO faqs (audience, question, answer, sort_order, created_by, updated_by)
         VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faqs WHERE audience = ?), ?, ?) RETURNING id'
    );
    $insert->execute([$audience, $question, $answer, $audience, $user['id'], $user['id']]);
    $id = (int) $insert->fetchColumn();
    AuditLogger::log($user['id'], $user['role'], 'create_faq', 'faq', (string) $id, "$audience: $question");
    jsonResponse(['success' => true, 'id' => $id]);
}

$id = (int) ($body['id'] ?? 0);
$find = $pdo->prepare('SELECT id, audience, question, answer FROM faqs WHERE id = ?');
$find->execute([$id]);
$faq = $find->fetch();
if (!$faq) {
    jsonResponse(['success' => false, 'error' => 'That FAQ no longer exists. Refresh the page.'], 404);
}

if ($type === 'update') {
    $question = Faq::clean((string) ($body['question'] ?? ''), false);
    $answer = Faq::clean((string) ($body['answer'] ?? ''), true);
    $problem = Faq::validate($question, $answer);
    if ($problem !== null) {
        jsonResponse(['success' => false, 'error' => $problem], 400);
    }
    $pdo->prepare('UPDATE faqs SET question = ?, answer = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$question, $answer, $user['id'], $id]);
    // Before -> after for the audit log (only what changed is kept).
    AuditLogger::log($user['id'], $user['role'], 'update_faq', 'faq', (string) $id, AuditChange::detail(
        $faq['audience'] === 'staff' ? 'FAQ for staff' : 'FAQ for students',
        ['question' => $faq['question'], 'answer' => $faq['answer']],
        ['question' => $question, 'answer' => $answer],
        ['question' => 'Question', 'answer' => 'Answer']
    ));
    jsonResponse(['success' => true]);
}

if ($type === 'delete') {
    $pdo->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
    renumber($pdo, $faq['audience']);
    AuditLogger::log($user['id'], $user['role'], 'delete_faq', 'faq', (string) $id, "{$faq['audience']}: {$faq['question']}");
    jsonResponse(['success' => true]);
}

if ($type === 'move') {
    $direction = (string) ($body['direction'] ?? '');
    if (!in_array($direction, ['up', 'down'], true)) {
        jsonResponse(['success' => false, 'error' => 'Choose up or down.'], 400);
    }
    $pdo->beginTransaction();
    try {
        renumber($pdo, $faq['audience']); // so every item has its own place before two are swapped
        $order = $pdo->prepare('SELECT id FROM faqs WHERE audience = ? ORDER BY sort_order, id');
        $order->execute([$faq['audience']]);
        $ids = array_map('intval', $order->fetchAll(PDO::FETCH_COLUMN));
        $pos = array_search($id, $ids, true);
        $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && isset($ids[$swapWith])) {
            [$ids[$pos], $ids[$swapWith]] = [$ids[$swapWith], $ids[$pos]];
            $set = $pdo->prepare('UPDATE faqs SET sort_order = ? WHERE id = ?');
            foreach ($ids as $i => $fid) {
                $set->execute([$i + 1, $fid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[faqs] move failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Could not move that FAQ. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
