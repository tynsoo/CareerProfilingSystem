<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/LoginLock.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../lib/PasswordReset.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/SessionEnd.php';
require_once __DIR__ . '/../../../shared/lib/CodeInput.php';

// Forgot password, for students and for guidance staff, each from their own sign-in page:
//   request  { portal, email }            emails a 6-digit code (valid 10 minutes). Also used for "Resend code".
//   verify   { portal, email, code }      a correct code returns a one-time reset token
//   reset    { portal, token, password }  sets the new password
// GET ?policy=1 returns the password rules so the page can show the same checklist the server enforces.
//
// Nothing here says whether an email has an account: request always answers the same way, and a wrong code
// for an email with no account looks exactly like a wrong code for one that has an account.

$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['policy'])) {
        jsonResponse(PasswordPolicy::rules($pdo));
    }
    jsonResponse(['error' => 'Not found'], 404);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$action = (string) ($body['action'] ?? '');
$portal = (string) ($body['portal'] ?? '');
if (!in_array($portal, PasswordReset::PORTALS, true)) {
    jsonResponse(['success' => false, 'error' => 'Something went wrong. Please go back to the sign-in page and try again.'], 400);
}
// The real visitor address (behind Render's proxy too; modules/AuditLog/lib/AuditLogger.php), so one visitor cannot use up the limit for everyone.
$ip = AuditLogger::clientIpFrom($_SERVER) ?? 'unknown';
// The per-address limits are loose: a whole school on one Wi-Fi shares one address. The per-email limits below
// are what protect each account.
$loginUrl = rtrim((string) getenv('APP_URL'), '/') . ($portal === 'staff' ? '/staff-login' : '/student-login');

/**
 * The account a student or staff sign-in page may recover. Staff must be approved and activated. Accounts made
 * before the access-code activation existed (the administrator, and counselors with no staff position) already have
 * a working password and were never marked verified, so they count as activated too; a newer account that has not
 * used its access code yet still has to do that first.
 */
function findAccount(PDO $pdo, string $portal, string $email): ?array
{
    $sql = $portal === 'staff'
        ? "SELECT id, role, username, email, full_name, password_hash FROM users
           WHERE LOWER(email) = ? AND role IN ('admin', 'counselor') AND is_active = TRUE
             AND approval_status = 'approved'
             AND (email_verified_at IS NOT NULL OR role = 'admin' OR staff_position IS NULL)
           ORDER BY id LIMIT 1"
        : "SELECT id, role, username, email, NULL AS full_name, password_hash FROM users
           WHERE LOWER(email) = ? AND role = 'student' AND is_active = TRUE AND email_verified_at IS NOT NULL ORDER BY id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function secondsSinceLastHit(PDO $pdo, string $key): ?int
{
    $stmt = $pdo->prepare('SELECT EXTRACT(EPOCH FROM (NOW() - MAX(created_at))) FROM rate_limit_hits WHERE rate_key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

function fail(string $reason, string $message, int $status = 400, array $extra = []): never
{
    jsonResponse(['success' => false, 'reason' => $reason, 'error' => $message] + $extra, $status);
}

function readEmail(array $body): string
{
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        fail('invalid', 'Enter a valid email address.');
    }
    return $email;
}

// ------------------------------------------------------------------ request a code (and "Resend code")

if ($action === 'request') {
    $email = readEmail($body);

    // These limits are keyed by what was typed, so they apply the same to an address with no account.
    if (RateLimiter::tooMany("pwreset-ip:$ip", 60, 60)) {
        fail('limit', 'Too many requests. Please try again in a few minutes.', 429, ['retryAfter' => 600]);
    }
    if (RateLimiter::tooMany("pwreset-cd:$portal:$email", 1, 1)) {
        $wait = max(1, PasswordReset::RESEND_SECONDS - (secondsSinceLastHit($pdo, "pwreset-cd:$portal:$email") ?? 0));
        fail('cooldown', "Please wait $wait seconds before asking for another code.", 429, ['retryAfter' => $wait]);
    }
    if (RateLimiter::tooMany("pwreset-hr:$portal:$email", PasswordReset::MAX_SENDS_PER_HOUR, 60)) {
        fail('limit', 'Too many codes were requested for this address. Please try again in an hour.', 429, ['retryAfter' => 3600]);
    }

    $account = findAccount($pdo, $portal, $email);
    $response = [
        'success' => true,
        'maskedEmail' => PasswordReset::maskEmail($email),
        'expiresInSeconds' => PasswordReset::CODE_MINUTES * 60,
        'resendInSeconds' => PasswordReset::RESEND_SECONDS,
    ];

    if ($account === null) {
        // A fresh start for the pretend wrong-code counter below, and a delay like the one sending a real email takes.
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute(["pwreset-fake:$portal:$email"]);
        usleep(random_int(350000, 900000));
        jsonResponse($response);
    }

    $userId = (int) $account['id'];
    $code = PasswordReset::generateCode();
    $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    $pdo->prepare(
        "INSERT INTO password_reset_codes (user_id, portal, code_hash, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL '" . PasswordReset::CODE_MINUTES . " minutes')"
    )->execute([$userId, $portal, PasswordReset::hashCode($userId, $code)]);

    $name = trim((string) ($account['full_name'] ?? '')) !== '' ? $account['full_name'] : ($portal === 'student' ? 'there' : $account['username']);
    $safeName = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
    $html = EmailTemplate::renderCode(
        'Your verification code',
        "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p><p style=\"margin:0;\">Use this code to reset your ProfilePath password. If you didn't ask for it, you can ignore this email and your password will stay the same.</p>",
        $code,
        'This code expires in ' . PasswordReset::CODE_MINUTES . ' minutes and can be used once. Never share it with anyone.'
    );
    $text = "Hi $name,\n\nYour ProfilePath verification code is: $code\n\nIt expires in " . PasswordReset::CODE_MINUTES . " minutes and can be used once. If you didn't ask for it, ignore this email. Never share this code with anyone.";
    $sent = Mailer::send($account['email'], (string) $name, 'Your ProfilePath verification code', $html, $text);

    AuditLogger::log($userId, $account['role'], 'request_password_reset', 'user', (string) $userId, $sent ? 'Verification code emailed' : 'Verification code could not be emailed');
    if (!$sent && getenv('APP_ENV') === 'local') {
        $response['debugCode'] = $code; // local development has no email service; never sent in production
    }
    jsonResponse($response);
}

// ------------------------------------------------------------------ check the code

if ($action === 'verify') {
    $email = readEmail($body);
    $code = CodeInput::digits((string) ($body['code'] ?? '')); // a space or line break pasted along is dropped
    if (!PasswordReset::isCode($code)) {
        fail('invalid', 'Enter all 6 digits.');
    }
    if (RateLimiter::tooMany("pwreset-vip:$ip", 150, 60)) {
        fail('limit', 'Too many attempts. Please try again in a few minutes.', 429, ['retryAfter' => 600]);
    }

    $expiredMsg = 'This code has expired. Reset codes last ' . PasswordReset::CODE_MINUTES . ' minutes. Request a new one.';
    $lockedMsg = 'Too many wrong attempts. Request a new code.';
    $wrongMsg = fn(int $left) => "That code isn't correct. $left " . ($left === 1 ? 'attempt' : 'attempts') . ' left.';

    $account = findAccount($pdo, $portal, $email);
    if ($account === null) {
        // Behave exactly as a real account would: a code that was "sent" lasts 10 minutes and allows 5 wrong tries.
        $age = secondsSinceLastHit($pdo, "pwreset-cd:$portal:$email");
        if ($age === null || $age > PasswordReset::CODE_MINUTES * 60) {
            fail('expired', $expiredMsg);
        }
        $fakeKey = "pwreset-fake:$portal:$email";
        if (RateLimiter::tooMany($fakeKey, PasswordReset::MAX_WRONG_TRIES, PasswordReset::CODE_MINUTES)) {
            fail('locked', $lockedMsg);
        }
        $used = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ?');
        $used->execute([$fakeKey]);
        $left = PasswordReset::MAX_WRONG_TRIES - (int) $used->fetchColumn();
        $left > 0 ? fail('wrong', $wrongMsg($left), 400, ['attemptsLeft' => $left]) : fail('locked', $lockedMsg);
    }

    $userId = (int) $account['id'];
    $stmt = $pdo->prepare(
        'SELECT id, code_hash, attempts FROM password_reset_codes
         WHERE user_id = ? AND portal = ? AND used_at IS NULL AND verified_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $portal]);
    $row = $stmt->fetch();
    if (!$row) {
        // A code that was cancelled by too many wrong tries still reads as locked until it would have expired.
        $last = $pdo->prepare("SELECT attempts FROM password_reset_codes WHERE user_id = ? AND portal = ? AND created_at > NOW() - INTERVAL '" . PasswordReset::CODE_MINUTES . " minutes' ORDER BY id DESC LIMIT 1");
        $last->execute([$userId, $portal]);
        $attempts = $last->fetchColumn();
        fail($attempts !== false && (int) $attempts >= PasswordReset::MAX_WRONG_TRIES ? 'locked' : 'expired', $attempts !== false && (int) $attempts >= PasswordReset::MAX_WRONG_TRIES ? $lockedMsg : $expiredMsg);
    }
    if ((int) $row['attempts'] >= PasswordReset::MAX_WRONG_TRIES) {
        fail('locked', $lockedMsg);
    }

    if (!hash_equals($row['code_hash'], PasswordReset::hashCode($userId, $code))) {
        $pdo->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        $left = PasswordReset::MAX_WRONG_TRIES - ((int) $row['attempts'] + 1);
        AuditLogger::log($userId, $account['role'], 'password_reset_code_failed', 'user', (string) $userId, 'Wrong verification code');
        if ($left <= 0) {
            $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
            fail('locked', $lockedMsg);
        }
        fail('wrong', $wrongMsg($left), 400, ['attemptsLeft' => $left]);
    }

    $token = PasswordReset::newToken();
    $pdo->prepare(
        "UPDATE password_reset_codes SET verified_at = NOW(), reset_token_hash = ?, reset_expires_at = NOW() + INTERVAL '" . PasswordReset::TOKEN_MINUTES . " minutes' WHERE id = ?"
    )->execute([PasswordReset::hashToken($token), $row['id']]);
    jsonResponse(['success' => true, 'resetToken' => $token, 'expiresInSeconds' => PasswordReset::TOKEN_MINUTES * 60]);
}

// ------------------------------------------------------------------ set the new password

if ($action === 'reset') {
    $token = (string) ($body['resetToken'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $sessionOver = 'This reset session has expired. Please start again.';
    if (!preg_match('/^[0-9a-f]{64}$/', $token) || $password === '') {
        fail('expired', $sessionOver);
    }
    if (RateLimiter::tooMany("pwreset-rip:$ip", 100, 60)) {
        fail('limit', 'Too many attempts. Please try again in a few minutes.', 429, ['retryAfter' => 600]);
    }

    $stmt = $pdo->prepare(
        "SELECT c.id AS code_id, u.id, u.role, u.email, u.password_hash, u.full_name, u.username FROM password_reset_codes c
         JOIN users u ON u.id = c.user_id
         WHERE c.reset_token_hash = ? AND c.portal = ? AND c.verified_at IS NOT NULL AND c.used_at IS NULL AND c.reset_expires_at > NOW()
           AND u.is_active = TRUE"
    );
    $stmt->execute([PasswordReset::hashToken($token), $portal]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('expired', $sessionOver);
    }

    $problems = PasswordPolicy::errors($pdo, $password);
    if ($problems) {
        fail('policy', implode(' ', $problems));
    }
    if (password_verify($password, $row['password_hash'])) {
        fail('reuse', "Choose a password you haven't used before.");
    }

    $userId = (int) $row['id'];
    $pdo->beginTransaction();
    try {
        // A correct code also proves the person controls this inbox.
        $pdo->prepare('UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL, email_verified_at = COALESCE(email_verified_at, NOW()), updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $userId]);
        LoginLock::clear($pdo, LoginLock::forAccount((int) $userId));
        $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        // Whoever was signed in with the old password is signed out (modules/Authentication/lib/SessionEnd.php).
        SessionEnd::endFor($pdo, $userId, 'password_changed');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[password-reset] reset failed: ' . $e->getMessage());
        fail('error', 'We could not reset your password. Please try again.', 500);
    }

    AuditLogger::log($userId, $row['role'], 'reset_password', 'user', (string) $userId, 'Password reset with an emailed code');

    // Tell the owner, in case it wasn't them.
    $name = trim((string) ($row['full_name'] ?? '')) !== '' ? $row['full_name'] : 'there';
    $safeName = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
    $html = EmailTemplate::render(
        'Your password was changed',
        "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p><p style=\"margin:0;\">The password for your ProfilePath account was just changed. If this was you, there is nothing more to do. If it wasn't you, contact the Guidance Office right away.</p>",
        'Go to Sign In',
        $loginUrl,
        ''
    );
    Mailer::send($row['email'], (string) $name, 'Your ProfilePath password was changed', $html, "Hi $name,\n\nThe password for your ProfilePath account was just changed. If this wasn't you, contact the Guidance Office right away.\n\n$loginUrl");

    jsonResponse(['success' => true]);
}

fail('invalid', 'Unknown action.');
