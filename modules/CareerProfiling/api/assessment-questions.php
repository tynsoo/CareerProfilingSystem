<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/QuestionBank.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChange.php';
require_once __DIR__ . '/../lib/AssessmentVersion.php';

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];

/** Active questions per type right now. */
function activeCounts(PDO $pdo): array
{
    $rows = $pdo->query('SELECT dimension, COUNT(*) FROM assessment_questions WHERE is_active = TRUE GROUP BY dimension')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
}

/** How a question is named in the audit log: 'Enterprising question: "I would like to ..."'. */
function questionForLog(string $dimension, string $text): string
{
    return (QuestionBank::NAMES[$dimension] ?? $dimension) . ' question: "' . $text . '"';
}

function readText(array $body): string
{
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') {
        jsonResponse(['success' => false, 'error' => 'Question text cannot be empty.'], 400);
    }
    if (mb_strlen($text) > QuestionBank::MAX_TEXT_LENGTH) {
        jsonResponse(['success' => false, 'error' => 'Question text must be ' . QuestionBank::MAX_TEXT_LENGTH . ' characters or fewer.'], 400);
    }
    return $text;
}

if ($method === 'GET') {
    $user = Auth::requireLogin();
    $isStaff = $user['role'] !== 'student';
    $includeInactive = $isStaff && isset($_GET['all']); // the staff question-bank page passes ?all=1
    $balance = QuestionBank::balance(activeCounts($pdo));

    // Students only get a question set that is ready: the same number of active questions for every type.
    if (!$isStaff && !$balance['ok']) {
        jsonResponse(['questions' => [], 'unavailable' => true, 'error' => 'The assessment is being updated by the Guidance Office. Please check back soon.'], 409);
    }

    // Students get the immutable version (question set) of exactly these questions; the submission
    // is scored only against this set (modules/CareerProfiling/api/assessment-submit.php, modules/CareerProfiling/lib/AssessmentVersion.php).
    if (!$isStaff) {
        try {
            $questions = AssessmentVersion::activeQuestions($pdo);
            $set = AssessmentVersion::setFor($pdo, $questions);
        } catch (PDOException $e) {
            error_log('[assessment-questions] ' . $e->getMessage() . ' (run php db/migrate_add_assessment_versions.php)');
            jsonResponse(['questions' => [], 'unavailable' => true, 'error' => 'The assessment is being updated by the Guidance Office. Please check back soon.'], 503);
        }
        $_SESSION['assessmentQuestionSetId'] = $set['id'];
        jsonResponse(['questions' => array_map(fn($q) => $q + ['isActive' => true], $questions), 'questionSetId' => $set['id']]);
    }

    $sql = 'SELECT id, dimension, order_index, question_text_enc, is_active FROM assessment_questions';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = TRUE';
    }
    $sql .= " ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index, id";
    $rows = $pdo->query($sql)->fetchAll();

    $questions = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'dimension' => $r['dimension'],
        'orderIndex' => (int) $r['order_index'],
        'text' => Crypto::dec($r['question_text_enc']),
        'isActive' => (bool) $r['is_active'],
    ], $rows);

    $out = ['questions' => $questions];
    if ($isStaff) {
        $out['balance'] = $balance;
    }
    jsonResponse($out);
}

if ($method === 'PUT') {
    Rbac::requireAccess('rac', 'full');

    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing question id.'], 400);
    }

    $existing = $pdo->prepare('SELECT * FROM assessment_questions WHERE id = ?');
    $existing->execute([$id]);
    $question = $existing->fetch();
    if (!$question) {
        jsonResponse(['success' => false, 'error' => 'Question not found.'], 404);
    }

    $text = array_key_exists('text', $body) ? readText($body) : Crypto::dec($question['question_text_enc']);
    $isActive = array_key_exists('isActive', $body) ? (bool) $body['isActive'] : $question['is_active'];

    $update = $pdo->prepare('UPDATE assessment_questions SET question_text_enc = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
    // Bind an int, not a PHP bool: execute() sends false as '', which Postgres's boolean type rejects,
    // so hiding a question (isActive false) used to fail.
    $update->execute([Crypto::enc($text), (int) $isActive, $id]);

    $user = Auth::currentUser();
    // Before -> after for the audit log (only what changed is kept).
    try {
        $oldText = Crypto::dec($question['question_text_enc']);
    } catch (Throwable $e) {
        $oldText = '(text could not be read)';
    }
    AuditLogger::log($user['id'], $user['role'], 'update_question', 'assessment_question', (string) $id, AuditChange::detail(
        (QuestionBank::NAMES[$question['dimension']] ?? $question['dimension']) . ' question',
        ['text' => $oldText, 'active' => (bool) $question['is_active']],
        ['text' => $text, 'active' => (bool) $isActive],
        ['text' => 'Question', 'active' => 'Shown in the assessment'],
        ['active']
    ));

    jsonResponse(['success' => true, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

if ($method === 'POST') {
    $user = Rbac::requireAccess('rac', 'full');
    $body = readJsonBody();
    $action = (string) ($body['action'] ?? 'add');

    // Put the 30-question O*NET Mini Interest Profiler in place: every other question is hidden (kept, not deleted).
    if ($action === 'loadOnet') {
        $existing = $pdo->query('SELECT id, dimension, question_text_enc FROM assessment_questions')->fetchAll();
        $byText = [];
        foreach ($existing as $r) {
            $byText[$r['dimension'] . '|' . mb_strtolower(trim(Crypto::dec($r['question_text_enc'])))] = (int) $r['id'];
        }

        $added = 0;
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE assessment_questions SET is_active = FALSE, updated_at = NOW() WHERE is_active = TRUE');
            $reactivate = $pdo->prepare('UPDATE assessment_questions SET is_active = TRUE, updated_at = NOW() WHERE id = ?');
            $nextOrder = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) + 1 FROM assessment_questions WHERE dimension = ?');
            $insert = $pdo->prepare('INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active, created_by) VALUES (?, ?, ?, TRUE, ?)');
            // Re-use the existing question when the wording is already there; otherwise add it after the last one of that type.
            foreach (QuestionBank::ONET_MINI_IP as $dimension => $texts) {
                foreach ($texts as $text) {
                    $key = $dimension . '|' . mb_strtolower($text);
                    if (isset($byText[$key])) {
                        $reactivate->execute([$byText[$key]]);
                        continue;
                    }
                    $nextOrder->execute([$dimension]);
                    $insert->execute([$dimension, Crypto::enc($text), (int) $nextOrder->fetchColumn(), $user['id']]);
                    $added++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not load the question set. The database may need the latest update first.'], 500);
        }

        AuditLogger::log($user['id'], $user['role'], 'load_question_set', 'assessment_question', null, 'O*NET Mini Interest Profiler (30 questions)');
        jsonResponse(['success' => true, 'added' => $added, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
    }

    // Add one question to a type.
    $dimension = (string) ($body['dimension'] ?? '');
    if (!in_array($dimension, QuestionBank::DIMENSIONS, true)) {
        jsonResponse(['success' => false, 'error' => 'Choose a valid type (R, I, A, S, E or C).'], 400);
    }
    $text = readText($body);
    $isActive = !array_key_exists('isActive', $body) || (bool) $body['isActive'];

    $next = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) + 1 FROM assessment_questions WHERE dimension = ?');
    $next->execute([$dimension]);
    try {
        $insert = $pdo->prepare('INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active, created_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $insert->execute([$dimension, Crypto::enc($text), (int) $next->fetchColumn(), (int) $isActive, $user['id']]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Could not add the question. The database may need the latest update first.'], 500);
    }
    $newId = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], $user['role'], 'add_question', 'assessment_question', (string) $newId, questionForLog($dimension, $text));
    jsonResponse(['success' => true, 'id' => $newId, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

if ($method === 'DELETE') {
    $user = Rbac::requireAccess('rac', 'full');
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing question id.'], 400);
    }

    $stmt = $pdo->prepare('DELETE FROM assessment_questions WHERE id = ? RETURNING dimension, order_index, question_text_enc');
    $stmt->execute([$id]);
    $gone = $stmt->fetch();
    if (!$gone) {
        jsonResponse(['success' => false, 'error' => 'Question not found.'], 404);
    }

    // The question is gone after this, so the log keeps its text (a deleted question cannot be looked up later).
    try {
        $goneText = Crypto::dec($gone['question_text_enc']);
    } catch (Throwable $e) {
        $goneText = '(text could not be read)';
    }
    AuditLogger::log($user['id'], $user['role'], 'delete_question', 'assessment_question', (string) $id, questionForLog($gone['dimension'], $goneText));
    jsonResponse(['success' => true, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
