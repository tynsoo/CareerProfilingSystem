<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';
require_once __DIR__ . '/../../Counseling/lib/CounselingTracking.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// View Only (limited) can look; acting on a flag needs Full (api/monitoring-action.php).
$user = Rbac::requireAccess('monitoring', 'limited');
$pdo = Database::get();
CounselingTracking::ensureSchema($pdo);

$tab = $_GET['tab'] ?? 'needs-review';
// The Dismissed tab is now part of History (filter Dismissed there); older links still open History.
if ($tab === 'dismissed') {
    $tab = 'history';
}
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
    return match ($status) {
        'mismatch' => 'Mismatch',
        'match' => 'Match',
        'close' => 'Close match',
        default => 'Not compared yet',
    };
}

$threshold = MonitoringLevel::get($pdo);

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

/**
 * Flag rows of one status ('pending' = Needs Review, 'escalated' = Counseling Queue, 'history' = approved and dismissed).
 * For Counseling rows also carry who escalated it and the newest counseling invitation with its booking status
 * (panel revision S11). History rows (never deleted) carry when, by whom and how it was closed.
 * Needs Review lists only students of the current school year (the same rule as Student Information,
 * AcademicYear::studentCondition), lowest similarity first; the Counseling Queue and History keep every year, so no
 * student in counseling and no closed flag drops out of sight.
 */
function buildFlagRows(PDO $pdo, string $status): array
{
    $isHistory = $status === 'history';
    [$yearSql, $yearParams] = $status === 'pending' ? AcademicYear::studentCondition('s', AcademicYear::current()) : ['TRUE', []];
    $sql = "SELECT mf.id AS flag_id, mf.reason, mf.status, mf.created_at, mf.note,
                   mf.escalated_at, esc.full_name AS escalated_by_name,
                   mf.resolved_at, res.full_name AS resolved_by_name, res.username AS resolved_by_username, res.role AS resolved_by_role,
                   COALESCE(mf.outcome, CASE WHEN mf.note = 'Replaced by a retake' THEN 'replaced_by_retake' END) AS outcome,
                   mf.resolution_note_enc,
                   ci.id AS invite_id, ci.status AS invite_status, ci.created_at AS invited_at, ci.session_date, ci.status_updated_at, ci.self_requested,
                   s.user_id, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score, r.cbf_program_ids, r.match_status
            FROM monitoring_flags mf
            JOIN students s ON s.user_id = mf.student_id
            LEFT JOIN recommendations r ON r.id = mf.recommendation_id
            LEFT JOIN users esc ON esc.id = mf.escalated_by
            LEFT JOIN users res ON res.id = mf.resolved_by
            LEFT JOIN LATERAL (
                SELECT id, status, created_at, session_date, status_updated_at, invited_by = student_id AS self_requested FROM counseling_invites
                WHERE student_id = mf.student_id AND (flag_id = mf.id OR (flag_id IS NULL AND created_at >= COALESCE(mf.escalated_at, mf.created_at)))
                ORDER BY id DESC LIMIT 1
            ) ci ON TRUE
            WHERE " . ($isHistory ? "mf.status IN ('approved', 'dismissed')" : 'mf.status = ?') . " AND $yearSql
            ORDER BY " . ($isHistory ? 'mf.resolved_at DESC NULLS LAST, mf.id DESC'
                : ($status === 'pending' ? 'r.top_score ASC NULLS LAST, mf.created_at DESC, mf.id DESC' : 'COALESCE(mf.escalated_at, mf.created_at) DESC, mf.id DESC'));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($isHistory ? $yearParams : array_merge([$status], $yearParams));
    $rows = $stmt->fetchAll();

    $titles = programTitles($pdo, array_merge([], ...array_map('topMatchIds', $rows)));

    $reasonLabels = [
        'low_confidence' => 'Highest similarity below the monitoring level',
        'manual_escalation' => 'Escalated for counselor review',
        'other' => 'Flagged for manual review',
    ];

    $result = array_map(function ($r) use ($titles, $reasonLabels) {
        $student = decryptStudent($r);
        $topScore = $r['top_score'] !== null ? (float) $r['top_score'] : null;
        return $student + [
            'flagId' => (int) $r['flag_id'],
            'career' => topMatchesLabel(topMatchIds($r), $titles, $r['top_program_id'] !== null ? 'Recompute needed' : 'No match yet'),
            'match' => statusLabel($r['match_status']),
            'reason' => $reasonLabels[$r['reason']] ?? $r['reason'],
            'topScore' => $topScore,
            'createdAt' => $r['created_at'],
            'status' => $r['status'],
            'escalatedAt' => $r['escalated_at'],
            'escalatedBy' => $r['escalated_by_name'],
            'invite' => $r['invite_id'] === null ? null : [
                'id' => (int) $r['invite_id'],
                'status' => $r['invite_status'],
                'statusLabel' => CounselingTracking::bookingLabel($r['invite_status']),
                'invitedAt' => $r['invited_at'],
                // Saved by the student's Avail Counseling click rather than sent by staff.
                'selfRequested' => (bool) $r['self_requested'],
                'sessionDate' => $r['session_date'],
                'updatedAt' => $r['status_updated_at'],
            ],
            'resolvedAt' => $r['resolved_at'],
            'resolvedBy' => $r['resolved_at'] === null ? null
                : (trim((string) $r['resolved_by_name']) !== '' ? $r['resolved_by_name']
                    : ($r['resolved_by_username'] ?? ($r['outcome'] === 'replaced_by_retake' ? 'Automatically (retake)' : null))),
            'outcome' => $r['outcome'],
            // History filter: how the flag was closed.
            'closedAs' => $r['status'] === 'dismissed' ? ($r['outcome'] === 'replaced_by_retake' ? 'retake' : 'dismissed')
                : (in_array($r['outcome'], CounselingTracking::COUNSELING_OUTCOMES, true) ? 'counseling' : 'reviewed'),
            'outcomeLabel' => CounselingTracking::outcomeLabel($r['outcome'])
                ?? ($r['status'] === 'dismissed' ? 'Dismissed' : ($r['status'] === 'approved' ? 'Approved' : null)),
            'resolutionNote' => $r['resolution_note_enc'] !== null ? Crypto::dec($r['resolution_note_enc']) : null,
        ];
    }, $rows);

    return $result;
}

/**
 * History rows. A flag dismissed before resolved_by was kept on the flag has who dismissed it only in the Audit Log (the
 * latest monitoring_dismiss entry, api/monitoring-action.php); it is filled from there. A retake dismisses open flags by
 * itself (api/assessment-submit.php, no audit entry). An entry removed by the audit log retention leaves only the date.
 */
function buildHistoryRows(PDO $pdo): array
{
    $rows = buildFlagRows($pdo, 'history');
    $ids = array_map(fn($r) => (string) $r['flagId'], array_filter($rows, fn($r) => $r['resolvedBy'] === null && $r['status'] === 'dismissed'));
    $who = [];
    if ($ids) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT ON (al.target_id) al.target_id, u.role, u.full_name, u.username, u.staff_position
             FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
             WHERE al.action = 'monitoring_dismiss' AND al.target_type = 'monitoring_flag'
               AND al.target_id = ANY(?::text[])
             ORDER BY al.target_id, al.id DESC"
        );
        $stmt->execute(['{' . implode(',', $ids) . '}']); // one array parameter: the ids are whole numbers, any count
        foreach ($stmt->fetchAll() as $a) {
            if ($a['role'] !== null) {
                $who[$a['target_id']] = trim((string) $a['full_name']) !== '' ? $a['full_name'] : $a['username'];
            }
        }
    }
    return array_map(fn($r) => ['resolvedBy' => $r['resolvedBy'] ?? $who[(string) $r['flagId']] ?? null] + $r, $rows);
}

function buildCompletedRows(PDO $pdo): array
{
    [$yearSql, $yearParams] = AcademicYear::studentCondition('s', AcademicYear::current());
    $sql = "SELECT a.student_id, a.completed_at, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc,
                   r.top_program_id, r.top_score, r.cbf_program_ids, r.match_status
            FROM assessments a
            JOIN students s ON s.user_id = a.student_id
            LEFT JOIN recommendations r ON r.student_id = a.student_id
                AND r.id = (SELECT id FROM recommendations WHERE student_id = a.student_id AND source_assessment_id = a.id ORDER BY computed_at DESC LIMIT 1)
            WHERE a.is_latest = TRUE AND a.completed_at::date = CURRENT_DATE AND $yearSql
            ORDER BY a.completed_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($yearParams);
    $rows = $stmt->fetchAll();
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
    [$yearSql, $yearParams] = AcademicYear::studentCondition('s', AcademicYear::current());
    $sql = "SELECT s.user_id, s.school_id, s.strand, s.section, s.first_name_enc, s.last_name_enc, s.registered_at
            FROM students s
            WHERE NOT EXISTS (SELECT 1 FROM assessments a WHERE a.student_id = s.user_id AND a.is_latest = TRUE) AND $yearSql
            ORDER BY s.registered_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($yearParams);
    $rows = $stmt->fetchAll();

    return array_map(fn($r) => decryptStudent($r) + [
        'assigned' => (new DateTime($r['registered_at']))->format('Y-m-d'),
        'status' => 'Not Started',
    ], $rows);
}

$allNeedsReview = buildFlagRows($pdo, 'pending');
$allCounseling = buildFlagRows($pdo, 'escalated');
$allHistory = $tab === 'history' ? buildHistoryRows($pdo) : [];
$historyCount = (int) $pdo->query("SELECT COUNT(*) FROM monitoring_flags WHERE status IN ('approved', 'dismissed')")->fetchColumn();
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
    'history' => $allHistory,
];

[$filteredRows] = applySearchAndLimit($rowsByTab[$tab] ?? [], $search, $limit);

jsonResponse([
    'tab' => $tab,
    'threshold' => $threshold,
    'rows' => $filteredRows,
    'summary' => [
        'needsReview' => count($allNeedsReview),
        'completedToday' => count($allCompleted),
        'pending' => count($allPending),
        'counseling' => count($allCounseling),
        'history' => $historyCount,
    ],
    // Who may close a flag For Counseling or record a booking status (CounselingTracking): never a Guidance Facilitator.
    'canCounsel' => Rbac::atLeast(Rbac::accessLevel('counselingStatus', Rbac::roleKey($user)), 'full') && !Rbac::isFacilitator($user),
    'outcomes' => array_map(fn($k) => ['value' => $k, 'label' => CounselingTracking::OUTCOMES[$k]], CounselingTracking::COUNSELING_OUTCOMES),
    'bookingStatuses' => array_map(fn($k, $v) => ['value' => $k, 'label' => $v], array_keys(CounselingTracking::BOOKING_STATUSES), CounselingTracking::BOOKING_STATUSES),
]);
