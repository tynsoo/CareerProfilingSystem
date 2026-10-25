<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/AuditRetention.php';

// The Audit Log page calls this when it opens. With "Delete older logs automatically" on, entries older
// than the retention period are deleted, at most once a day (modules/AuditLog/lib/AuditRetention.php). The person only
// starts the check; the setting decides what goes. A POST, so the CSRF check in _bootstrap.php applies.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('rac', 'full');
    $name = trim((string) ($user['displayName'] ?? '')) !== '' ? $user['displayName'] : 'user #' . (int) $user['id'];
    jsonResponse(['success' => true, 'deleted' => AuditRetention::runIfDue(Database::get(), $name)]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
