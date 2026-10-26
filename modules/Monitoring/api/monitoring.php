<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// View Only (limited) can look; acting on a flag needs Full (api/monitoring-action.php).
$user = Rbac::requireAccess('monitoring', 'limited');
$pdo = Database::get();

$tab = $_GET['tab'] ?? 'needs-review';
$search = trim((string) ($_GET['search'] ?? ''));
$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : null;

function decryptStudent(array $row): array
{
    return [
        'userId' => (int) $row['user_id'],
        'name' => Crypto::dec($row['last_name_enc']) . ', ' . Crypto::dec($row['first_name_enc']),
        'strand' => $row['strand'],
        'section' => $row['section'] ?? null,
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

/** Best RIASEC Match ids of a recommendation row (CBF: cbf_program_ids; none for older rows). */
function topMatchIds(array $r): array
{
    return CBFData::parseIntArray($r['cbf_program_ids'] ?? null) ?? [];
}

/** Best RIASEC Match titles for a staff table cell ("BS IT, BS CS (+3 more)"). */
function topMatchesLabel(array $ids, array $titles, string $empty): string
{
    if (!$ids) {
        return $empty;
    }
    $names = array_map(fn($id) => $titles[$id] ?? 'Removed program', $ids);
    $shown = array_slice($names, 0, 2);
    return implode(', ', $shown) . (count($names) > 2 ? ' (+' . (count($names) - 2) . ' more)' : '');
}

function statusLabel(?string $status): string
{
    return $status === 'mismatch' ? 'Mismatch' : ($status === 'match' ? 'Match' : 'Not compared yet');
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
    $sql = "SELECT mf.id AS flag_id, mf.reason, mf.status, mf.created_at,
                   s.user_id, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score, r.cbf_program_ids, r.match_status
            FROM monitoring_flags mf
            JOIN students s ON s.user_id = mf.student_id
            LEFT JOIN recommendations r ON r.id = mf.recommendation_id
            WHERE mf.status = ?
            ORDER BY mf.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status]);
    $rows = $stmt->fetchAll();

    $titles = programTitles($pdo, array_merge([], ...array_map('topMatchIds', $rows)));

    $reasonLabels = [
        'low_confidence' => 'Highest similarity below the monitoring level',
        'manual_escalation' => 'Escalated for counselor review',
        'other' => 'Flagged for manual review',
    ];

    $result = array_map(function ($r) use ($titles, $reasonLabels, $threshold) {
        $student = decryptStudent($r);
        $topScore = $r['top_score'] !== null ? (float) $r['top_score'] : null;
        return $student + [
            'flagId' => (int) $r['flag_id'],
            'career' => topMatchesLabel(topMatchIds($r), $titles, $r['top_program_id'] !== null ? 'Recompute needed' : 'No match yet'),
            'match' => statusLabel($r['match_status']),
            'reason' => $reasonLabels[$r['reason']] ?? $r['reason'],
            'priority' => priorityFor($r['reason'], $topScore, $threshold),
            'topScore' => $topScore,
            'createdAt' => $r['created_at'],
        ];
    }, $rows);

    return $result;
}

function buildCompletedRows(PDO $pdo): array
{
    $sql = "SELECT a.student_id, a.completed_at, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score, r.cbf_program_ids, r.match_status
            FROM assessments a
            JOIN students s ON s.user_id = a.student_id
            LEFT JOIN recommendations r ON r.student_id = a.student_id
                AND r.id = (SELECT id FROM recommendations WHERE student_id = a.student_id AND source_assessment_id = a.id ORDER BY computed_at DESC LIMIT 1)
            WHERE a.is_latest = TRUE AND a.completed_at::date = CURRENT_DATE
            ORDER BY a.completed_at DESC";
    $rows = $pdo->query($sql)->fetchAll();
    $titles = programTitles($pdo, array_merge([], ...array_map('topMatchIds', $rows)));

    $result = array_map(function ($r) use ($titles) {
        $student = decryptStudent(['user_id' => $r['student_id']] + $r);
        return $student + [
            'career' => topMatchesLabel(topMatchIds($r), $titles, $r['top_program_id'] !== null ? 'Recompute needed' : 'No worksheet yet'),
            'match' => statusLabel($r['match_status']),
            'time' => (new DateTime($r['completed_at']))->format('g:i A'),
        ];
    }, $rows);

    return $result;
}

function buildPendingRows(PDO $pdo): array
{
    $sql = "SELECT s.user_id, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc, s.registered_at
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

// A staff member sees only the sections assigned to them (everyone if none are; modules/UserManagement/lib/StaffScope.php).
// Flags are the exception: guidance staff review them as a team, so every flag shows to everyone
// with Monitoring access.
$scope = StaffScope::forUser($pdo, $user);
$inScope = fn(array $rows) => array_values(array_filter($rows, fn($r) => StaffScope::allows($scope, $r['strand'] ?? null, $r['section'] ?? null)));
$allCompleted = $inScope($allCompleted);
$allPending = $inScope($allPending);

$rowsByTab = [
    'needs-review' => $allNeedsReview,
    'completed' => $allCompleted,
    'pending' => $allPending,
    'counseling' => $allCounseling,
];

[$filteredRows] = applySearchAndLimit($rowsByTab[$tab] ?? [], $search, $limit);

jsonResponse([
    'threshold' => $threshold,
    'rows' => $filteredRows,
    'summary' => [
        'needsReview' => count($allNeedsReview),
        'completedToday' => count($allCompleted),
        'pending' => count($allPending),
        'counseling' => count($allCounseling),
    ],
]);
