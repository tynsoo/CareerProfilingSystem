<?php

require_once __DIR__ . '/_bootstrap.php';

Rbac::requireAccess('rac', 'full'); // audit visibility lives alongside the other security/system admin tools

$pdo = Database::get();

// Actions ending in _failed are the only "Failed" outcomes this app currently logs;
// everything else recorded is by definition a completed action.
const FAILED_SUFFIX = '_failed';
// An account lockout is a security event too, so it reads as Failed in the log.
const FAILED_SQL = "(al.action LIKE '%_failed' OR al.action IN ('login_lockout', 'login_shared_suspected'))";

$actionLabels = [
    'login' => 'Signed in',
    'login_failed' => 'Failed sign-in',
    'login_lockout' => 'Account locked',
    'login_shared_suspected' => 'Possible shared account',
    'logout' => 'Signed out',
    'register' => 'Registered new account',
    'submit_assessment' => 'Submitted RIASEC assessment',
    'update_question' => 'Updated assessment question',
    'update_rbac' => 'Access permissions changed',
    'update_policy' => 'Security setting changed',
    'update_settings' => 'Security setting changed',
    'create_faq' => 'Create FAQ',
    'update_faq' => 'Update FAQ',
    'delete_faq' => 'Delete FAQ',
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

/** Who did it: staff by username, a student by name (their username is their Student Number, which staff don't see). */
function actorLabel(array $r): string
{
    if (($r['actor_role'] ?? null) === 'student') {
        $name = trim(Crypto::dec($r['last_name_enc'] ?? '') . ', ' . Crypto::dec($r['first_name_enc'] ?? ''), ' ,');
        return $name !== '' ? $name : 'Student';
    }
    if (trim((string) ($r['full_name'] ?? '')) !== '') {
        return $r['full_name'];
    }
        if (!isset($r['username']) && ($r['action'] ?? '') === 'login_failed') {
        return 'Unregistered user';
    }
    return $r['username'] ?? 'System';
}

function roleLabel(?string $role, array $labels): string
{
    if ($role === null) {
        return '-';
    }
    return $labels[$role] ?? ucfirst($role);
}

function buildFilters(array $q, array $actionLabels, array $roleLabels): array
{
    $where = ['1=1'];
    $params = [];

    if (!empty($q['status']) && $q['status'] !== 'All') {
        $where[] = $q['status'] === 'Failed' ? FAILED_SQL : 'NOT ' . FAILED_SQL;
    }
    if (!empty($q['module']) && $q['module'] !== 'All') {
        $where[] = 'al.target_type = ?';
        $params[] = $q['module'];
    }
        if (!empty($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['from'])) {
        $where[] = 'al.created_at >= CAST(? AS date)';
        $params[] = $q['from'];
    }
    if (!empty($q['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['to'])) {
        $where[] = "al.created_at < CAST(? AS date) + INTERVAL '1 day'";
        $params[] = $q['to'];
    }    
        $terms = array_values(array_filter(
        array_map('trim', explode(',', (string) ($q['search'] ?? ''))),
        fn($t) => $t !== ''
    ));
    if ($terms) {
        $pdo = Database::get();
        $actions = $pdo->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        $targets = $pdo->query('SELECT DISTINCT target_type FROM audit_log WHERE target_type IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $students = $pdo->query('SELECT user_id, first_name_enc, last_name_enc FROM students')->fetchAll();
        // Same names the page shows in the Category column.
        $categoryLabels = ['user' => 'User Accounts', 'faq' => 'FAQ', 'security_policies' => 'Security Policies', 'security_rbac' => 'Access Permissions'];

        foreach ($terms as $t) {
            $has = fn(string $text): bool => mb_stripos($text, $t) !== false;
            $like = '%' . addcslashes($t, '\\%_') . '%';
            $or = [];

            // Staff name or username (student names are encrypted, handled below).
            $or[] = "(COALESCE(u.role, '') <> 'student' AND (u.username ILIKE ? OR u.full_name ILIKE ?))";
            array_push($params, $like, $like);

            // IP address.
            $or[] = 'al.ip_address::text ILIKE ?';
            $params[] = $like;

            // Student names: decrypt in PHP, then filter by user id (ids are integers).
            $ids = [];
            foreach ($students as $s) {
                $name = trim(Crypto::dec($s['last_name_enc']) . ', ' . Crypto::dec($s['first_name_enc']), ' ,');
                if ($has($name)) {
                    $ids[] = (int) $s['user_id'];
                }
            }
            if ($ids) {
                $or[] = 'al.actor_user_id IN (' . implode(',', $ids) . ')';
            }

            // Action, as shown on screen (for example "Create FAQ").
            $codes = array_values(array_filter($actions, fn($a) => $has(actionLabel($a, $actionLabels))));
            if ($codes) {
                $or[] = 'al.action IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
                array_push($params, ...$codes);
            }

            // Category, as shown on screen.
            $cats = array_values(array_filter($targets, fn($c) => $has($categoryLabels[$c] ?? ucwords(str_replace('_', ' ', $c)))));
            if ($cats) {
                $or[] = 'al.target_type IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
                array_push($params, ...$cats);
            }

            // Role, as shown on screen.
            $roles = array_keys(array_filter($roleLabels, fn($label) => $has($label)));
            if ($roles) {
                $or[] = 'al.actor_role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
                array_push($params, ...$roles);
            }

            // Rows with no user behind them.
            if ($has('Unregistered user')) {
                $or[] = "(al.actor_user_id IS NULL AND al.action = 'login_failed')";
            }
            if ($has('System')) {
                $or[] = "(al.actor_user_id IS NULL AND al.action <> 'login_failed')";
            }

            // Status.
            if ($has('Failed')) {
                $or[] = FAILED_SQL;
            }
            if ($has('Success')) {
                $or[] = 'NOT ' . FAILED_SQL;
            }

            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }
            return [implode(' AND ', $where), $params];
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    // CSV export of everything matching the current filters (no pagination limit).
    [$where, $params] = buildFilters($_GET, $actionLabels, $roleLabels);
    $sql = "SELECT al.created_at, u.username, u.full_name, al.actor_role, al.action, al.target_type, al.ip_address, st.first_name_enc, st.last_name_enc
            FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN students st ON st.user_id = al.actor_user_id
            WHERE $where ORDER BY al.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit-log-export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Action', 'Category', 'IP Address', 'Status'], escape: '\\');
    foreach ($stmt as $row) {
        $status = (str_ends_with($row['action'], FAILED_SUFFIX) || in_array($row['action'], ['login_lockout', 'login_shared_suspected'], true)) ? 'Failed' : 'Success';
        fputcsv($out, [
            (new DateTime($row['created_at']))->format('M j, Y g:i:s A'), actorLabel($row), roleLabel($row['actor_role'], $roleLabels),
            actionLabel($row['action'], $actionLabels), $row['target_type'] ?? '-', $row['ip_address'], $status,
        ], escape: '\\');
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// The page loads more rows on demand ("Load more"), so the size is caller-chosen (capped).
$pageSize = min(100, max(1, (int) ($_GET['pageSize'] ?? 10)));
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

[$where, $params] = buildFilters($_GET, $actionLabels, $roleLabels);

$countSql = "SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$sql = "SELECT al.id, al.created_at, u.username, u.full_name, al.actor_role, al.action, al.target_type, al.target_id, al.ip_address, st.first_name_enc, st.last_name_enc
        FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN students st ON st.user_id = al.actor_user_id
        WHERE $where
        ORDER BY al.created_at $sort
        LIMIT $pageSize OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = array_map(function ($r) use ($actionLabels, $roleLabels) {
    return [
        'id' => (int) $r['id'],
        'timestamp' => (new DateTime($r['created_at']))->format('M j, Y g:i:s A'),
        'user' => actorLabel($r),
        'role' => roleLabel($r['actor_role'], $roleLabels),
        'action' => actionLabel($r['action'], $actionLabels),
        'module' => $r['target_type'] ?? '-',
        'ip' => $r['ip_address'],
        'status' => (str_ends_with($r['action'], FAILED_SUFFIX) || in_array($r['action'], ['login_lockout', 'login_shared_suspected'], true)) ? 'Failed' : 'Success',
    ];
}, $stmt->fetchAll());

$modules = $pdo->query('SELECT DISTINCT target_type FROM audit_log WHERE target_type IS NOT NULL ORDER BY target_type')
    ->fetchAll(PDO::FETCH_COLUMN);
    $firstDate = $pdo->query('SELECT MIN(created_at)::date FROM audit_log')->fetchColumn() ?: null;

// Summary cards follow the same search/filters as the table, so they always describe the rows
// below it. Successful is Total minus Failed over that same set, so the two always add up to
// Total and the percentages stay meaningful. With no filters, Users Active keeps its usual
// "last 7 days" meaning; with filters, it counts the distinct users in the filtered rows.
$filtered = $where !== '1=1';
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) FILTER (WHERE " . FAILED_SQL . "), COUNT(DISTINCT al.actor_user_id)
     FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
     WHERE $where"
);
$summaryStmt->execute($params);
[$failedCount, $usersInResults] = array_map('intval', $summaryStmt->fetch(PDO::FETCH_NUM));
$successCount = $total - $failedCount;
if ($filtered) {
    $usersActive = $usersInResults;
    $overallTotal = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
} else {
    $usersActive = (int) $pdo->query(
        "SELECT COUNT(DISTINCT actor_user_id) FROM audit_log WHERE created_at >= NOW() - INTERVAL '7 days'"
    )->fetchColumn();
    $overallTotal = $total;
}

jsonResponse([
    'rows' => $rows,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'totalPages' => $totalPages,
    'modules' => $modules,
    'firstDate' => $firstDate,
    'summary' => [
        'total' => $total,
        'successful' => $successCount,
        'failed' => $failedCount,
        'usersActive' => $usersActive,
        'filtered' => $filtered,
        'overallTotal' => $overallTotal,
    ],
]);
