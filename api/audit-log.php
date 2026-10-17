<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/AuditLogPdf.php';

$user = Rbac::requireAccess('rac', 'full'); // audit visibility lives alongside the other security/system admin tools

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
    'export_audit_log' => 'Exported audit log',
    'export_audit_log_failed' => 'Audit log export failed',
];

$roleLabels = [
    'admin' => 'Administrator',
    'counselor' => 'Guidance Counselor',
    'student' => 'Student',
];

// Category names as the page shows them (CATEGORY_LABEL in audit-log.html), for search and the PDF.
$categoryLabels = ['user' => 'User Accounts', 'faq' => 'FAQ', 'security_policies' => 'Security Policies', 'security_rbac' => 'Access Permissions'];

// The most activities one PDF may hold (about 175 pages). A bigger export has to be narrowed with the
// filters first, which keeps the request quick and stops one click from copying the whole log.
const MAX_EXPORT_ROWS = 5000;

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

function categoryLabel(?string $type, array $labels): string
{
    if ($type === null || $type === '') {
        return '-';
    }
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * The filters of an export in words, for the PDF header and the audit entry. Reads each filter the
 * same way buildFilters() applies it, so even a hand-made link prints what the rows really match.
 */
function exportFilterText(array $q, array $categoryLabels): string
{
    $date = fn(string $key): string => (is_string($q[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q[$key])) ? $q[$key] : '';
    $from = $date('from');
    $to = $date('to');
    $dates = ($from === '' && $to === '') ? 'Any time' : ($from !== '' ? $from : 'start') . ' to ' . ($to !== '' ? $to : 'today');
    // As in buildFilters(): empty or "All" adds no condition, "Failed" keeps failures, anything else successes.
    $s = $q['status'] ?? '';
    $status = (empty($s) || $s === 'All') ? 'All' : ($s === 'Failed' ? 'Failed' : 'Success');
    // Only a real category code is printed (target_type is VARCHAR(50)); anything else matches no rows.
    $m = $q['module'] ?? '';
    $category = (empty($m) || $m === 'All') ? 'All'
        : ((is_string($m) && preg_match('/^[a-z_]{1,50}$/', $m)) ? categoryLabel($m, $categoryLabels) : 'Unknown (no matches)');
    $search = is_string($q['search'] ?? null) ? trim($q['search']) : '';
    if (mb_strlen($search) > 60) {
        $search = mb_substr($search, 0, 60) . '...';
    }
    return "Dates: $dates | Status: $status | Category: $category | Search: " . ($search === '' ? 'none' : '"' . $search . '"');
}

function buildFilters(array $q, array $actionLabels, array $roleLabels, array $categoryLabels): array
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
            $cats = array_values(array_filter($targets, fn($c) => $has(categoryLabel($c, $categoryLabels))));
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
    // PDF of everything matching the current filters (up to MAX_EXPORT_ROWS). Every export is rate
    // limited and written to the audit log with a reference code that is also printed on each page,
    // so a printed or forwarded copy can be traced back to who exported it and when.

    // The page downloads with fetch(), so its requests are same-origin. A link or redirect from another
    // site (which still carries the session cookie under SameSite=Lax) is refused before it can use up
    // an export or write a row in this person's name. Browsers that send no Sec-Fetch-Site pass.
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
        jsonResponse(['error' => 'Start the export from the Audit Log page.'], 403);
    }
    // Refusals by the rate limit are not logged: each refused click would add a row, and the
    // exports before it are already in the log.
    if (RateLimiter::tooMany('audit-export:' . (int) $user['id'], 10, 60)) {
        jsonResponse(['error' => 'Too many exports in the last hour. Please try again later.'], 429);
    }

    $filters = exportFilterText($_GET, $categoryLabels);
    [$where, $params] = buildFilters($_GET, $actionLabels, $roleLabels, $categoryLabels);
    $sql = "SELECT al.created_at, u.username, u.full_name, al.actor_role, al.action, al.target_type, al.ip_address, st.first_name_enc, st.last_name_enc
            FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN students st ON st.user_id = al.actor_user_id
            WHERE $where ORDER BY al.created_at DESC LIMIT " . (MAX_EXPORT_ROWS + 1);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $found = $stmt->fetchAll();
    if (count($found) > MAX_EXPORT_ROWS) {
        // An attempt at a bulk copy of the log is worth a trace even though it was refused.
        AuditLogger::log($user['id'], $user['role'], 'export_audit_log_failed', 'audit_log', null, 'Refused: more than ' . MAX_EXPORT_ROWS . ' activities. ' . $filters);
        jsonResponse(['error' => 'Too many activities to export (more than ' . number_format(MAX_EXPORT_ROWS) . '). Narrow the filters and try again.'], 422);
    }

    // 8 random hex characters, so two exports on the same day practically never share a code.
    $reference = 'EXP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $position = $user['role'] === 'counselor' ? ($user['positionLabel'] ?? $roleLabels['counselor']) : roleLabel($user['role'], $roleLabels);
    try {
        // Inside the try: actorLabel() decrypts student names, and Crypto::dec() throws on bad data.
        $rows = [];
        foreach ($found as $row) {
            $rows[] = [
                (new DateTime($row['created_at']))->format('M j, Y g:i:s A'),
                actorLabel($row),
                roleLabel($row['actor_role'], $roleLabels),
                actionLabel($row['action'], $actionLabels),
                categoryLabel($row['target_type'], $categoryLabels),
                (string) ($row['ip_address'] ?? '-'),
                (str_ends_with($row['action'], FAILED_SUFFIX) || in_array($row['action'], ['login_lockout', 'login_shared_suspected'], true)) ? 'Failed' : 'Success',
            ];
        }
        $pdfBytes = AuditLogPdf::build($rows, [
            'exportedBy' => ($user['displayName'] ?? $user['username']) . " ($position)",
            'exportedAt' => (new DateTime())->format('M j, Y g:i A'),
            'reference' => $reference,
            'filters' => $filters,
        ]);
    } catch (Throwable $e) {
        error_log('[audit-export] ' . $e->getMessage());
        AuditLogger::log($user['id'], $user['role'], 'export_audit_log_failed', 'audit_log', $reference, 'PDF could not be built');
        jsonResponse(['error' => 'The PDF could not be made. Please try again.'], 500);
    }
    // Filters stay in the encrypted detail (a search can contain a name); the reference goes in target_id.
    AuditLogger::log($user['id'], $user['role'], 'export_audit_log', 'audit_log', $reference, 'PDF, ' . count($rows) . ' activities. ' . $filters);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '-' . $reference . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: no-store');
    header('X-Export-Reference: ' . $reference);
    echo $pdfBytes;
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// The page loads more rows on demand ("Load more"), so the size is caller-chosen (capped).
$pageSize = min(100, max(1, (int) ($_GET['pageSize'] ?? 10)));
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

[$where, $params] = buildFilters($_GET, $actionLabels, $roleLabels, $categoryLabels);

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
