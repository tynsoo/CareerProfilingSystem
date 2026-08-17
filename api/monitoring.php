<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

$tab = $_GET['tab'] ?? 'needs-review';
$search = trim((string) ($_GET['search'] ?? ''));
$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : null;

function decryptStudent(array $row): array
{
    return [
        'userId' => (int) $row['user_id'],
        'schoolId' => $row['school_id'],
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
    ];
}

/** Batch-decrypt program titles for a set of program ids. */
function programTitles(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, title_enc FROM programs WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $titles = [];
    foreach ($stmt->fetchAll() as $r) {
        $titles[(int) $r['id']] = Crypto::dec($r['title_enc']);
    }
    return $titles;
}

function priorityFor(string $reason, ?float $topScore, float $threshold): string
{
    if ($reason === 'manual_escalation') {
        return 'High';
    }
    if ($topScore === null) {
        return 'Medium';
    }
    if ($topScore < $threshold / 2) {
        return 'High';
    }
    if ($topScore < $threshold) {
        return 'Medium';
    }
    return 'Low';
}

$thresholdRow = $pdo->query("SELECT value FROM security_policies WHERE key = 'monitoring.lowConfidenceThreshold'")->fetchColumn();
$threshold = $thresholdRow !== false ? (float) $thresholdRow : 0.50;

/** @return array{0: array, 1: array} [rows filtered by search+limit, all rows unfiltered] */
function applySearchAndLimit(array $rows, ?string $search, ?int $limit): array
{
    $filtered = $rows;
    if ($search) {
        $needle = mb_strtolower($search);
        $filtered = array_values(array_filter($filtered, fn($r) => str_contains(mb_strtolower($r['name']), $needle)));
    }
    if ($limit !== null) {
        $filtered = array_slice($filtered, 0, $limit);
    }
    return [$filtered, $rows];
}

function buildFlagRows(PDO $pdo, string $status, float $threshold): array
{
    $sql = "SELECT mf.id AS flag_id, mf.reason, mf.status, mf.created_at, mf.note,
                   s.user_id, s.school_id, s.strand, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score
            FROM monitoring_flags mf
            JOIN students s ON s.user_id = mf.student_id
            LEFT JOIN recommendations r ON r.id = mf.recommendation_id
            WHERE mf.status = ?
            ORDER BY mf.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status]);
    $rows = $stmt->fetchAll();

    $titles = programTitles($pdo, array_map(fn($r) => $r['top_program_id'] !== null ? (int) $r['top_program_id'] : null, $rows));

    $reasonLabels = [
        'low_confidence' => 'Below minimum confidence threshold',
        'manual_escalation' => 'Escalated for counselor review',
        'other' => 'Flagged for manual review',
    ];

    $result = array_map(function ($r) use ($titles, $reasonLabels, $threshold) {
        $student = decryptStudent($r);
        $topScore = $r['top_score'] !== null ? (float) $r['top_score'] : null;
        return $student + [
            'flagId' => (int) $r['flag_id'],
            'career' => $r['top_program_id'] !== null ? ($titles[(int) $r['top_program_id']] ?? '—') : '—',
            'match' => $topScore !== null ? (int) round($topScore * 100) . '%' : '—',
            'reason' => $reasonLabels[$r['reason']] ?? $r['reason'],
            'priority' => priorityFor($r['reason'], $topScore, $threshold),
            'note' => $r['note'],
            'createdAt' => $r['created_at'],
        ];
    }, $rows);

    return $result;
}

function buildCompletedRows(PDO $pdo): array
{
    $sql = "SELECT a.student_id, a.completed_at, s.school_id, s.strand, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score
            FROM assessments a
            JOIN students s ON s.user_id = a.student_id
            LEFT JOIN recommendations r ON r.student_id = a.student_id
                AND r.id = (SELECT id FROM recommendations WHERE student_id = a.student_id ORDER BY computed_at DESC LIMIT 1)
            WHERE a.is_latest = TRUE AND a.completed_at::date = CURRENT_DATE
            ORDER BY a.completed_at DESC";
    $rows = $pdo->query($sql)->fetchAll();
    $titles = programTitles($pdo, array_map(fn($r) => $r['top_program_id'] !== null ? (int) $r['top_program_id'] : null, $rows));

    $result = array_map(function ($r) use ($titles) {
        $student = decryptStudent(['user_id' => $r['student_id']] + $r);
        $topScore = $r['top_score'] !== null ? (float) $r['top_score'] : null;
        return $student + [
            'career' => $r['top_program_id'] !== null ? ($titles[(int) $r['top_program_id']] ?? '—') : 'No worksheet yet',
            'match' => $topScore !== null ? (int) round($topScore * 100) . '%' : '—',
            'time' => (new DateTime($r['completed_at']))->format('g:i A'),
        ];
    }, $rows);

    return $result;
}

function buildPendingRows(PDO $pdo): array
{
    $sql = "SELECT s.user_id, s.school_id, s.strand, s.first_name_enc, s.last_name_enc, s.registered_at
            FROM students s
            WHERE NOT EXISTS (SELECT 1 FROM assessments a WHERE a.student_id = s.user_id AND a.is_latest = TRUE)
            ORDER BY s.registered_at DESC";
    $rows = $pdo->query($sql)->fetchAll();

    return array_map(fn($r) => decryptStudent($r) + [
        'assigned' => (new DateTime($r['registered_at']))->format('Y-m-d'),
        'status' => 'Not Started',
    ], $rows);
}

$allNeedsReview = buildFlagRows($pdo, 'pending', $threshold);
$allCounseling = buildFlagRows($pdo, 'escalated', $threshold);
$allCompleted = buildCompletedRows($pdo);
$allPending = buildPendingRows($pdo);

$rowsByTab = [
    'needs-review' => $allNeedsReview,
    'completed' => $allCompleted,
    'pending' => $allPending,
    'counseling' => $allCounseling,
];

[$filteredRows] = applySearchAndLimit($rowsByTab[$tab] ?? [], $search, $limit);

jsonResponse([
    'rows' => $filteredRows,
    'summary' => [
        'needsReview' => count($allNeedsReview),
        'completedToday' => count($allCompleted),
        'pending' => count($allPending),
        'counseling' => count($allCounseling),
    ],
]);
