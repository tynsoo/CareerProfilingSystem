<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/AuditChain.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

// "Check log" on the Audit Log page (administrators only): checks every entry's seal and link
// (modules/AuditLog/lib/AuditChain.php) and records the check and its result in the log. A POST, because it writes that
// entry; the CSRF check in _bootstrap.php applies.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Rbac::requireAccess('rac', 'full');
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Only administrators can check the audit log.'], 403);
    }
    $pdo = Database::get();
    if (!AuditChain::enabled($pdo)) {
        jsonResponse(['success' => false, 'error' => 'Tamper protection is not set up yet. Ask the system maintainer to run the database update.'], 409);
    }
    // Reads the whole log, so a few checks an hour are plenty. Refusals are not logged (as with exports).
    if (RateLimiter::tooMany('audit-verify:' . (int) $user['id'], 10, 60)) {
        jsonResponse(['success' => false, 'error' => 'Too many checks in the last hour. Please try again later.'], 429);
    }

    try {
        $result = AuditChain::verify($pdo);
    } catch (Throwable $e) {
        error_log('[audit-verify] ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'The check could not finish. Please try again.'], 500);
    }

    $count = number_format($result['checked']) . ' ' . ($result['checked'] === 1 ? 'entry' : 'entries');
    if ($result['intact']) {
        $message = "Log is intact. $count checked.";
        AuditLogger::log($user['id'], $user['role'], 'verify_audit_log', 'audit_log', null, "Intact: $count checked.");
    } else {
        $why = match ($result['problem']) {
            'start' => 'the starting point of the log was changed, so entries at the start may have been removed',
            'changed' => 'it was changed after it was saved',
            'removed' => 'an entry before it was removed, or the order was changed',
            default => 'it has no seal (it was not written by the app)',
        };
        $message = "Problem found at entry #{$result['brokenId']}: $why.";
        AuditLogger::log($user['id'], $user['role'], 'verify_audit_log_failed', 'audit_log', (string) $result['brokenId'],
            "Problem at entry #{$result['brokenId']}: $why. $count checked before it.");
    }
    jsonResponse(['success' => true, 'intact' => $result['intact'], 'message' => $message]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
