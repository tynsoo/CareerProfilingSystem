<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';
require_once __DIR__ . '/../lib/LoginDevice.php';
require_once __DIR__ . '/../lib/SessionEnd.php';
require_once __DIR__ . '/../lib/LoginLock.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
// Students and guidance staff have separate sign-in pages; each says which one it is.
$portal = (string) ($body['portal'] ?? '');

if ($username === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'Username and password are required.'], 400);
}
// What the audit log keeps of the typed name: target_id holds 50 characters, and a longer value
// would make the log insert fail (a server error instead of "Invalid username or password").
$typed = mb_substr($username, 0, 50);

$pdo = Database::get();

// A bcrypt hash of a random throwaway text. Unknown usernames are checked against it, so they take
// as long to answer as real accounts and the timing does not tell which accounts exist.
const TIMING_DUMMY_HASH = '$2y$12$fm2fIi2HLx99eEzo4LSkLOGfj2W2JdC2COBwb3NQ7UBJ8H.aJlISy';
const LOCKED_MESSAGE = 'Account temporarily locked due to repeated failed attempts. ';

function lockoutPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'lockout.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'enabled' => ($rows['lockout.enabled'] ?? 'true') === 'true',
        'maxAttempts' => (int) ($rows['lockout.maxAttempts'] ?? 5),
        'lockoutMinutes' => (int) ($rows['lockout.lockoutMinutes'] ?? 15),
    ];
}

// No real username or email is this long; it is treated like an unknown one.
$user = false;
if (mb_strlen($username) <= 150) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
}
// Staff sign in with their email (they have no username to remember); students keep using their Student Number.
if (!$user && mb_strlen($username) <= 150 && strpos($username, '@') !== false) {
    $byEmail = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND role IN ('admin', 'counselor') ORDER BY id LIMIT 1");
    $byEmail->execute([$username]);
    $user = $byEmail->fetch();
}

$genericError = ['success' => false, 'error' => 'Invalid username or password.'];

$policy = lockoutPolicy($pdo);

// One address trying many accounts (the per-account lock cannot see that): at most 500 failed sign-ins per 15
// minutes from one real visitor address (modules/AuditLog/lib/AuditLogger.php). Loose on purpose: 800+ students on the school Wi-Fi
// share one address, and an exam-day rush with typos must stay far below it; the per-account lock is the first line.
// Only checked passwords that fail count (RateLimiter::hit on each failure below): a click while an account is
// locked checks no password, so it does not count, and a success never takes one back, so a working account cannot
// be used to earn more guesses. The answer is the same for every username, so it says nothing about which accounts
// exist. The block is written to the audit log once per 15 minutes per address.
$loginIpKey = 'login-ip:' . (AuditLogger::clientIpFrom($_SERVER) ?? 'unknown');
if (RateLimiter::isLimited($loginIpKey, 500, 15)) {
    if (!RateLimiter::tooMany('logged-' . $loginIpKey, 1, 15)) {
        AuditLogger::log(null, null, 'login_failed', 'user', $typed, 'Too many failed sign-ins from this address');
    }
    jsonResponse(['success' => false, 'error' => 'Too many sign-in attempts from this network. Please wait a few minutes and try again.'], 429);
}

if (!$user || !$user['is_active']) {
    password_verify($password, TIMING_DUMMY_HASH);
    // Unknown names get the same soft lock as real accounts (modules/Authentication/lib/LoginLock.php), so the "locked"
    // answer does not prove an account exists.
    if ($policy['enabled']) {
        $nameLock = LoginLock::forName($typed);
        LoginLock::oneAtATime($pdo, $nameLock);
        $until = LoginLock::lockedUntil($pdo, $nameLock, $policy['lockoutMinutes']);
        if ($until !== null) {
            AuditLogger::log(null, null, 'login_failed', 'user', $typed, 'Unknown or inactive username (locked)');
            jsonResponse(['success' => false, 'error' => LOCKED_MESSAGE . LoginLock::waitText($until)], 423);
        }
        LoginLock::recordFailure($pdo, $nameLock, $policy['maxAttempts'], $policy['lockoutMinutes']);
    }
    AuditLogger::log(null, null, 'login_failed', 'user', $typed, 'Unknown or inactive username');
    RateLimiter::hit($loginIpKey);
    jsonResponse($genericError, 401);
}

if ($policy['enabled']) {
    // Attempts on one account wait in line, then read the lock fresh: a burst of parallel wrong
    // passwords cannot all slip past it (modules/Authentication/lib/LoginLock.php).
    LoginLock::oneAtATime($pdo, LoginLock::forAccount((int) $user['id']));
    $fresh = $pdo->prepare('SELECT locked_until FROM users WHERE id = ?');
    $fresh->execute([$user['id']]);
    $user['locked_until'] = $fresh->fetchColumn() ?: null;
}
if ($policy['enabled'] && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
    password_verify($password, TIMING_DUMMY_HASH);   // same time as a locked unknown name
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Account locked');
    jsonResponse(['success' => false, 'error' => LOCKED_MESSAGE . LoginLock::waitText((int) strtotime($user['locked_until']))], 423);
}

// A student account has no usable password until its email link is opened (api/verify-email.php). It gets the same
// answer as a wrong password, so a Student Number alone does not tell a stranger that it was registered; the student
// sign-in page always shows "Didn't get the verification email? Resend it". The password is still checked (and the
// result ignored) so this answer takes as long as a real one. It also gets the same soft lock as an unknown name
// (modules/Authentication/lib/LoginLock.php), so after a few tries it says "locked" exactly when an unknown number would.
if ($user['role'] === 'student' && $user['email_verified_at'] === null) {
    password_verify($password, $user['password_hash']);
    if ($policy['enabled']) {
        $nameLock = LoginLock::forName($typed);
        LoginLock::oneAtATime($pdo, $nameLock);
        $until = LoginLock::lockedUntil($pdo, $nameLock, $policy['lockoutMinutes']);
        if ($until !== null) {
            AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Email not verified (locked)');
            jsonResponse(['success' => false, 'error' => LOCKED_MESSAGE . LoginLock::waitText($until)], 423);
        }
        LoginLock::recordFailure($pdo, $nameLock, $policy['maxAttempts'], $policy['lockoutMinutes']);
    }
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Email not verified');
    RateLimiter::hit($loginIpKey);
    jsonResponse($genericError, 401);
}

if (!password_verify($password, $user['password_hash'])) {
    // Soft, growing lock (modules/Authentication/lib/LoginLock.php): wrong tries count for 15 minutes; the lock is 1, then 5,
    // then the admin's lockout length. (failed_login_attempts is no longer counted up.)
    $lockMinutes = $policy['enabled']
        ? LoginLock::recordFailure($pdo, LoginLock::forAccount((int) $user['id']), $policy['maxAttempts'], $policy['lockoutMinutes'])
        : null;
    if ($lockMinutes !== null) {
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NOW() + make_interval(mins => ?) WHERE id = ?')
            ->execute([$lockMinutes, $user['id']]);
        // One entry per lockout (not per wrong password): what the Security Overview's Account Lockouts counts.
        AuditLogger::log((int) $user['id'], $user['role'], 'login_lockout', 'user', $typed,
            "Locked for $lockMinutes minute" . ($lockMinutes === 1 ? '' : 's') . " after {$policy['maxAttempts']} failed attempts");
    }

    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Incorrect password');
    RateLimiter::hit($loginIpKey);
    jsonResponse($genericError, 401);
}

// Checked after the password, so the wrong page reveals nothing to someone who doesn't know it.
if ($portal === 'staff' && $user['role'] === 'student') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Student tried the staff sign in');
    jsonResponse(['success' => false, 'error' => 'This is the staff sign in. Students, please use the student sign in page.'], 403);
}
if ($portal === 'student' && $user['role'] !== 'student') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff tried the student sign in');
    jsonResponse(['success' => false, 'error' => 'This sign in is for students only.'], 403);
}

// Staff who signed up themselves wait for an admin: they confirm their email
// first, then stay 'pending' until approved in Account Management. (Checked
// only after the password is right, so the status never leaks to a stranger.)
if ($user['role'] === 'counselor' && $user['approval_status'] === 'pending') {
    if ($user['email_verified_at'] === null) {
        AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff email not verified');
        jsonResponse([
            'success' => false,
            'error' => 'Please verify your email first — check your inbox for the verification link. An administrator then needs to approve your account.',
            'needsVerification' => true,
        ], 403);
    }
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff account awaiting approval');
    jsonResponse(['success' => false, 'error' => 'Your account is waiting for an administrator to approve it. You will be able to sign in once it is approved.'], 403);
}
if ($user['role'] === 'counselor' && $user['approval_status'] === 'rejected') {
    AuditLogger::log((int) $user['id'], $user['role'], 'login_failed', 'user', $typed, 'Staff sign-up was rejected');
    jsonResponse(['success' => false, 'error' => 'Your sign-up was not approved. Please contact your system administrator.'], 403);
}

// Success — reset lockout state.
$reset = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
$reset->execute([$user['id']]);
LoginLock::clear($pdo, LoginLock::forAccount((int) $user['id']));

$sessionData = [
    'id' => (int) $user['id'],
    'role' => $user['role'],
    'username' => $user['username'],
    'avatarUrl' => $user['avatar_data_url'],
];
// What staff see beside their name: their own name, not a login name.
if ($user['role'] !== 'student') {
    $sessionData['displayName'] = trim((string) ($user['full_name'] ?? '')) !== '' ? $user['full_name'] : $user['username'];
}
// Guidance counselors and facilitators share one role and one set of
// permissions; the position is just the title shown beside their name.
if ($user['role'] === 'counselor') {
    $sessionData['positionLabel'] = StaffPosition::label($user['staff_position'] ?? null);
}

$redirect = 'admin-dashboard';
if ($user['role'] === 'student') {
    $studentStmt = $pdo->prepare('SELECT school_id, first_name_enc, last_name_enc, strand, grade_level, section, registered_at FROM students WHERE user_id = ?');
    $studentStmt->execute([$user['id']]);
    $student = $studentStmt->fetch();
    if ($student) {
        $sessionData['schoolId'] = $student['school_id'];
        $sessionData['firstName'] = Crypto::dec($student['first_name_enc']);
        $sessionData['lastName'] = Crypto::dec($student['last_name_enc']);
        $sessionData['strand'] = $student['strand'];
        $sessionData['gradeLevel'] = $student['grade_level'];
        $sessionData['section'] = $student['section'];
        $sessionData['registeredAt'] = $student['registered_at'];
    }
    $redirect = 'assessment';
}

Auth::login($sessionData);
// One device at a time for students: the newest sign-in wins, and the older device is told why on its sign-in page.
if ($user['role'] === 'student') {
    try {
        $endedOthers = SessionEnd::endFor($pdo, (int) $user['id'], 'other_device', session_id());
        if ($endedOthers > 0) {
            AuditLogger::log((int) $user['id'], 'student', 'signed_out_other_device', 'user', $user['username'], "Signed out on $endedOthers other device(s) (one device at a time)");
        }
    } catch (Throwable $e) {
        error_log('[login] could not sign out other devices: ' . $e->getMessage()); // the sign-in itself still works
    }
}
// Where this login came from. If a student signs in from another IP or device while an earlier login
// is still recent, it is flagged as a possible shared account (nobody is blocked).
// Same address the audit log stores (the visitor's, not Render's proxy), so the two can be compared.
$loginIp = AuditLogger::clientIpFrom($_SERVER);
$loginDevice = LoginDevice::describe((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($user['role'] === 'student') {
    try {
        $conflict = LoginDevice::findConflict($pdo, (int) $user['id'], $loginIp, $loginDevice);
        if ($conflict !== null) {
            AuditLogger::log((int) $user['id'], 'student', 'login_shared_suspected', 'user', $user['username'], "Signed in from {$loginIp} ({$loginDevice}) while a login from {$conflict['ip']} ({$conflict['device']}) was still recent");
        }
    } catch (Throwable $e) {
        error_log('[login-device] check failed: ' . $e->getMessage());
    }
}
AuditLogger::log((int) $user['id'], $user['role'], 'login', 'user', $user['username'], 'Successful login — ' . $loginDevice);

jsonResponse(['success' => true, 'role' => $user['role'], 'redirect' => $redirect, 'user' => $sessionData]);
