<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../Authentication/lib/LoginLock.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/StaffPosition.php';
require_once __DIR__ . '/../../Authentication/lib/StaffSetupCode.php';
require_once __DIR__ . '/../../Authentication/lib/SessionEnd.php';
require_once __DIR__ . '/../lib/StaffName.php';
require_once __DIR__ . '/../lib/StaffEmail.php';
require_once __DIR__ . '/../lib/StaffSignup.php';

// Account Management's staff section: guidance counselors sign up on their own
// (api/staff-register.php) and an administrator approves, rejects or
// deactivates them here. The administrator always can; guidance staff can when the administrator gave them "Staff Accounts"
// access in Edit Roles (View Only to see the list, Full to act). Sections are assigned in api/staff-assignments.php (admin only).
// Co-admins (users.is_co_admin): only the administrator makes or removes one (type setCoAdmin), and only the administrator
// acts on a co-admin's account, so a co-admin or a counselor with Staff Accounts cannot change another co-admin.
$user = Rbac::requireStaffAccounts($_SERVER['REQUEST_METHOD'] === 'GET' ? 'limited' : 'full');
$pdo = Database::get();
$isMainAdmin = $user['role'] === 'admin';
$coAdminCol = Rbac::hasCoAdminColumn() ? 'is_co_admin' : 'FALSE AS is_co_admin'; // until db/migrate_add_co_admin.php runs

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        "SELECT id, username, full_name, staff_position, email, email_verified_at, is_active, approval_status, created_at, $coAdminCol,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS locked
         FROM users WHERE role = 'counselor'
         ORDER BY (approval_status = 'pending') DESC, created_at DESC"
    )->fetchAll();

    jsonResponse(['accounts' => array_map(fn($r) => [
        'id' => (int) $r['id'],
        // Accounts made before staff gave their name fall back to their old login name; auto-generated ones show nothing.
        'fullName' => trim((string) $r['full_name']) !== '' ? $r['full_name'] : (str_starts_with($r['username'], 'staff-') ? '' : $r['username']),
        'position' => StaffPosition::label($r['staff_position'] ?? null),
        'email' => $r['email'],
        'emailVerified' => $r['email_verified_at'] !== null,
        'activated' => $r['email_verified_at'] !== null,
        'username' => $r['username'],
        'isActive' => (bool) $r['is_active'],
        'locked' => (bool) $r['locked'],   // locked by wrong passwords: an administrator can Unlock
        'status' => $r['approval_status'],
        'createdAt' => $r['created_at'],
        'isCoAdmin' => (bool) $r['is_co_admin'],
    ], $rows),
        // Make co-admin / Remove co-admin: the administrator only, once the column exists.
        'canManageCoAdmins' => $isMainAdmin && Rbac::hasCoAdminColumn(),
        'coAdminMax' => Rbac::CO_ADMIN_MAX,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$type = (string) ($body['type'] ?? '');
$id = (int) ($body['id'] ?? 0);

// Invite staff (panel revision S1): the administrator or a co-admin pre-registers a Guidance Counselor or Facilitator.
// The inviter picks the position (the person cannot choose their own role), the email must be a school email, and the
// account is approved at once but has no usable password: the person gets the temporary access code by email and
// creates their own password (api/staff-activate.php). Until then they cannot sign in or open any page.
if ($type === 'invite') {
    if (!Rbac::isAdminOrCoAdmin($user)) {
        jsonResponse(['success' => false, 'error' => 'Only the administrator or a co-admin can invite staff.'], 403);
    }
    $fullName = StaffName::compose((string) ($body['lastName'] ?? ''), (string) ($body['firstName'] ?? ''), (string) ($body['middleInitial'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $position = (string) ($body['position'] ?? '');
    if ($fullName === null || !StaffName::isValid($fullName) || mb_strlen($fullName) > 150) {
        jsonResponse(['success' => false, 'error' => 'Enter the last name and first name using letters only, and a single letter for the middle initial.'], 400);
    }
    if (!StaffPosition::isValid($position)) {
        jsonResponse(['success' => false, 'error' => 'Choose Guidance Counselor or Guidance Facilitator.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
    }
    $emailCheck = StaffEmail::check($email);
    if (!$emailCheck['ok']) {
        jsonResponse(['success' => false, 'error' => $emailCheck['error']], 400);
    }
    $taken = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)');
    $taken->execute([$email]);
    if ($taken->fetchColumn()) {
        jsonResponse(['success' => false, 'error' => 'An account with this email already exists.'], 409);
    }
    $username = StaffSignup::usernameFor($pdo, $email);
    $unusable = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT); // nobody can sign in with this
    $insert = $pdo->prepare(
        "INSERT INTO users (role, username, password_hash, email, full_name, staff_position, is_active, approval_status)
         VALUES ('counselor', ?, ?, ?, ?, ?, TRUE, 'approved') RETURNING id"
    );
    $insert->execute([$username, $unusable, $email, $fullName, $position]);
    $newId = (int) $insert->fetchColumn();
    AuditLogger::log($user['id'], $user['role'], 'invite_staff_account', 'user', (string) $newId,
        $fullName . ' (' . StaffPosition::label($position) . ', ' . $email . ')');
    $sent = sendSetupCode($pdo, ['id' => $newId, 'email' => $email, 'username' => $username], $fullName);
    jsonResponse(['success' => true, 'id' => $newId, 'username' => $username, 'emailSent' => $sent]);
}

$stmt = $pdo->prepare("SELECT id, username, full_name, staff_position, email, email_verified_at, is_active, approval_status, $coAdminCol FROM users WHERE id = ? AND role = 'counselor'");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) {
    jsonResponse(['success' => false, 'error' => 'Account not found.'], 404);
}
$displayName = $target['full_name'] ?: $target['username'];
if ($id === (int) $user['id'] && $type === 'toggleActive') {
    jsonResponse(['success' => false, 'error' => 'You cannot deactivate your own account.'], 409);
}
// A co-admin's account (and who is a co-admin) is the administrator's to change, nobody else's.
if (!$isMainAdmin && ($target['is_co_admin'] || $type === 'setCoAdmin')) {
    jsonResponse(['success' => false, 'error' => 'Only the administrator can change co-admins.'], 403);
}

function notifyStaff(array $target, string $displayName, string $subject, string $heading, string $message): void
{
    if (!$target['email']) {
        return;
    }
    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $link = rtrim((string) getenv('APP_URL'), '/') . '/staff-login';
    $html = EmailTemplate::render($heading, "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p><p style=\"margin:0;\">$message</p>", 'Go to Sign In', $link, '');
    Mailer::send($target['email'], $displayName, $subject, $html, "Hi $displayName,\n\n" . strip_tags($message) . "\n\n$link");
}

// Emails the temporary access code. The approver never sees a password or the code itself.
function sendSetupCode(PDO $pdo, array $target, string $displayName): bool
{
    if (!$target['email']) {
        return false;
    }
    $code = StaffSetupCode::issue($pdo, (int) $target['id']);
    [$html, $text] = StaffSetupCode::emailBody($displayName, (string) $target['username'], $code);
    return Mailer::send($target['email'], $displayName, 'Your ProfilePath account is approved', $html, $text);
}

if ($type === 'approve') {
    if ($target['approval_status'] === 'approved') {
        jsonResponse(['success' => false, 'error' => 'This account is already approved.'], 409);
    }
    if (!$target['email']) {
        jsonResponse(['success' => false, 'error' => 'This account has no email address to send the access code to.'], 409);
    }
    // Approved, but the person can't sign in until they enter the emailed code and create a
    // password (api/staff-activate.php). Entering the code is what proves the inbox is theirs.
    $pdo->prepare("UPDATE users SET approval_status = 'approved', is_active = TRUE, email_verified_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], $user['role'], 'approve_staff_account', 'user', (string) $id, $displayName);
    $sent = sendSetupCode($pdo, $target, $displayName);
    jsonResponse(['success' => true, 'status' => 'approved', 'emailSent' => $sent]);
}

if ($type === 'resendCode') {
    if ($target['approval_status'] !== 'approved' || $target['email_verified_at'] !== null) {
        jsonResponse(['success' => false, 'error' => 'A new code can only be sent to an approved account that has not been activated yet.'], 409);
    }
    $sent = sendSetupCode($pdo, $target, $displayName);
    AuditLogger::log($user['id'], $user['role'], 'resend_staff_setup_code', 'user', (string) $id, $displayName);
    jsonResponse(['success' => true, 'emailSent' => $sent]);
}

if ($type === 'reject') {
    if ($target['approval_status'] !== 'pending') {
        jsonResponse(['success' => false, 'error' => 'Only a pending sign-up can be rejected.'], 409);
    }
    $pdo->prepare("UPDATE users SET approval_status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$id]);
    AuditLogger::log($user['id'], $user['role'], 'reject_staff_account', 'user', (string) $id, $displayName);
    notifyStaff($target, $displayName, 'Your ProfilePath sign-up was not approved', 'Sign-up not approved', 'An administrator reviewed your staff sign-up and did not approve it. Please contact your system administrator if you think this is a mistake.');
    jsonResponse(['success' => true, 'status' => 'rejected']);
}

// Unlock a staff account locked by wrong passwords. Only the administrator or a co-admin does this, even when
// guidance staff were given Staff Accounts access (a co-admin's account: the administrator only, checked above).
if ($type === 'unlock') {
    if (!Rbac::isAdminOrCoAdmin($user)) {
        jsonResponse(['success' => false, 'error' => 'Only an administrator can unlock a staff account.'], 403);
    }
    $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);
    LoginLock::clear($pdo, LoginLock::forAccount($id));   // the next lock is short again
    $who = trim((string) ($target['full_name'] ?? '')) !== '' ? $target['full_name'] : $target['username'];
    AuditLogger::log($user['id'], $user['role'], 'unlock_account', 'user', (string) $id,
        'Unlocked ' . $who . ' (' . StaffPosition::label($target['staff_position'] ?? null) . ') after failed sign-in attempts');
    jsonResponse(['success' => true]);
}

if ($type === 'toggleActive') {
    if ($target['approval_status'] !== 'approved') {
        jsonResponse(['success' => false, 'error' => 'Approve this account first.'], 409);
    }
    $newState = !$target['is_active'];
    // Bind an int, not a PHP bool: execute() stringifies false to '', which
    // Postgres's boolean type rejects.
    $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $newState, $id]);
    // A deactivated co-admin is no longer one: turning the account back on later does not bring the admin work back.
    // Cleared in the database, not from the row read above, so a co-admin made at the same moment is cleared too.
    $endsCoAdmin = false;
    if (!$newState && Rbac::hasCoAdminColumn()) {
        $clear = $pdo->prepare('UPDATE users SET is_co_admin = FALSE WHERE id = ? AND is_co_admin RETURNING id');
        $clear->execute([$id]);
        $endsCoAdmin = $clear->fetchColumn() !== false;
    }
    if (!$newState) {
        SessionEnd::endFor($pdo, $id, 'deactivated'); // signed out right away, not only at the next sign-in
    }
    // Who it was, as it stood at the time (the audit log shows this in the row details).
    $who = trim((string) ($target['full_name'] ?? '')) !== '' ? $target['full_name'] : $target['username'];
    AuditLogger::log($user['id'], $user['role'], $newState ? 'activate_staff_account' : 'deactivate_staff_account', 'user', (string) $id,
        $who . ' (' . StaffPosition::label($target['staff_position'] ?? null) . ')' . ($endsCoAdmin ? '; no longer a co-admin' : ''));
    jsonResponse(['success' => true, 'isActive' => $newState]);
}

// Make a Guidance Counselor a co-admin, or remove it: the administrator only (checked above). Never a Guidance Facilitator
// (DFD process 4.0), never a pending, rejected, deactivated or not yet activated account, at most Rbac::CO_ADMIN_MAX.
if ($type === 'setCoAdmin') {
    if (!Rbac::hasCoAdminColumn()) {
        jsonResponse(['success' => false, 'error' => 'Co-admins are not set up yet. Ask the system maintainer to run the database update.'], 409);
    }
    if (!isset($body['on']) || !is_bool($body['on'])) {
        jsonResponse(['success' => false, 'error' => 'Missing on (true or false).'], 400);
    }
    $on = $body['on'];
    $who = trim((string) ($target['full_name'] ?? '')) !== '' ? $target['full_name'] : $target['username'];
    if ($on === (bool) $target['is_co_admin']) {
        jsonResponse(['success' => false, 'error' => $on ? "$who is already a co-admin." : "$who is not a co-admin."], 409);
    }
    if ($on) {
        if (($target['staff_position'] ?? null) === 'facilitator') {
            jsonResponse(['success' => false, 'error' => 'A Guidance Facilitator cannot be a co-admin.'], 400);
        }
        if ($target['approval_status'] !== 'approved' || !$target['is_active'] || $target['email_verified_at'] === null) {
            jsonResponse(['success' => false, 'error' => 'Only an approved, active and activated account can be a co-admin.'], 409);
        }
    }
    $pdo->beginTransaction();
    // One change at a time, so two clicks at once cannot pass the limit together.
    $pdo->query("SELECT pg_advisory_xact_lock(hashtext('co-admin'))");
    if ($on) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'counselor' AND is_co_admin")->fetchColumn();
        if ($count >= Rbac::CO_ADMIN_MAX) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'There are already ' . Rbac::CO_ADMIN_MAX . ' co-admins. Remove one first.'], 409);
        }
    }
    // Granting checks the account again inside the lock: it may have been deactivated since it was read above.
    $update = $pdo->prepare($on
        ? "UPDATE users SET is_co_admin = TRUE, updated_at = NOW() WHERE id = ? AND is_active AND approval_status = 'approved' AND email_verified_at IS NOT NULL"
        : 'UPDATE users SET is_co_admin = FALSE, updated_at = NOW() WHERE id = ?');
    $update->execute([$id]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'This account changed meanwhile. Reload the page and try again.'], 409);
    }
    AuditLogger::log($user['id'], $user['role'], $on ? 'grant_co_admin' : 'revoke_co_admin', 'user', (string) $id,
        $who . ' (' . StaffPosition::label($target['staff_position'] ?? null) . ')');
    $pdo->commit();
    Rbac::forgetStaffRows();
    if ($on) {
        notifyStaff($target, $displayName, 'You are now a ProfilePath co-admin', 'You are now a co-admin',
            'The administrator made you a co-admin. You can now manage staff accounts, reset assessments and read the Audit Log. The permission table and security settings stay with the administrator.');
    } else {
        notifyStaff($target, $displayName, 'You are no longer a ProfilePath co-admin', 'Co-admin removed',
            'The administrator removed your co-admin access. Your Guidance Counselor access is the same as before.');
    }
    jsonResponse(['success' => true, 'isCoAdmin' => $on]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
