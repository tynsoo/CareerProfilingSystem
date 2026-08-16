<?php

require_once __DIR__ . '/_bootstrap.php';

Rbac::requireAccess('rac', 'full'); // audit visibility lives alongside the other security/system admin tools

$pdo = Database::get();

// Actions ending in _failed are the only "Failed" outcomes this app currently logs;
// everything else recorded is by definition a completed action.
const FAILED_SUFFIX = '_failed';

$actionLabels = [
    'login' => 'Logged in',
    'login_failed' => 'Failed login attempt',
    'logout' => 'Logged out',
    'register' => 'Registered new account',
    'submit_assessment' => 'Submitted RIASEC assessment',
    'update_question' => 'Updated assessment question',
];

$roleLabels = [
    'admin' => 'Administrator',
    'counselor' => 'Guidance Counselor',
    'student' => 'Student',
];

function actionLabel(string $action, array $labels): string
{
    return $labels[$action] ?? ucwords(str_replace('_', ' ', $action));
}

function roleLabel(?string $role, array $labels): string
{
    if ($role === null) {
        return '-';
    }
    return $labels[$role] ?? ucfirst($role);
}

function buildFilters(array $q): array
{
    $where = ['1=1'];
    $params = [];

    if (!empty($q['status']) && $q['status'] !== 'All') {
        $where[] = $q['status'] === 'Failed' ? "al.action LIKE '%_failed'" : "al.action NOT LIKE '%_failed'";
    }
    if (!empty($q['module']) && $q['module'] !== 'All') {
        $where[] = 'al.target_type = ?';
        $params[] = $q['module'];
    }
    if (!empty($q['search'])) {
        $where[] = "(u.username ILIKE ? OR al.action ILIKE ? OR al.target_type ILIKE ?)";
        $like = '%' . $q['search'] . '%';
        array_push($params, $like, $like, $like);
    }
    return [implode(' AND ', $where), $params];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    // CSV export of everything matching the current filters (no pagination limit).
    [$where, $params] = buildFilters($_GET);
    $sql = "SELECT al.created_at, u.username, al.actor_role, al.action, al.target_type, al.ip_address
            FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
            WHERE $where ORDER BY al.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit-log-export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Action', 'Module', 'IP Address', 'Status'], escape: '\\');
    foreach ($stmt as $row) {
        $status = str_ends_with($row['action'], FAILED_SUFFIX) ? 'Failed' : 'Success';
        fputcsv($out, [
            (new DateTime($row['created_at']))->format('M j, Y g:i:s A'), $row['username'] ?? 'System', roleLabel($row['actor_role'], $roleLabels),
            actionLabel($row['action'], $actionLabels), $row['target_type'] ?? '-', $row['ip_address'], $status,
        ], escape: '\\');
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 10;
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

[$where, $params] = buildFilters($_GET);

$countSql = "SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$sql = "SELECT al.id, al.created_at, u.username, al.actor_role, al.action, al.target_type, al.target_id, al.ip_address
        FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
        WHERE $where
        ORDER BY al.created_at $sort
        LIMIT $pageSize OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = array_map(function ($r) use ($actionLabels, $roleLabels) {
    return [
        'id' => (int) $r['id'],
        'timestamp' => (new DateTime($r['created_at']))->format('M j, Y g:i:s A'),
        'user' => $r['username'] ?? 'System',
        'role' => roleLabel($r['actor_role'], $roleLabels),
        'action' => actionLabel($r['action'], $actionLabels),
        'module' => $r['target_type'] ?? '-',
        'ip' => $r['ip_address'],
        'status' => str_ends_with($r['action'], FAILED_SUFFIX) ? 'Failed' : 'Success',
    ];
}, $stmt->fetchAll());

$modules = $pdo->query('SELECT DISTINCT target_type FROM audit_log WHERE target_type IS NOT NULL ORDER BY target_type')
    ->fetchAll(PDO::FETCH_COLUMN);

// Summary cards reflect the whole audit log, independent of the table's current
// search/filter state below — otherwise a narrow filter makes "successful"/"failed"
// exceed the visible "total" and the percentages stop making sense.
$globalTotal = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$successCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action NOT LIKE '%_failed'")->fetchColumn();
$failedCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%_failed'")->fetchColumn();
$usersActive = (int) $pdo->query(
    "SELECT COUNT(DISTINCT actor_user_id) FROM audit_log WHERE created_at >= NOW() - INTERVAL '7 days'"
)->fetchColumn();

jsonResponse([
    'rows' => $rows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'modules' => $modules,
    'summary' => [
        'total' => $globalTotal,
        'successful' => $successCount,
        'failed' => $failedCount,
        'usersActive' => $usersActive,
    ],
]);
