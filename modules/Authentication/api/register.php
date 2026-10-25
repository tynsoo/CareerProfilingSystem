<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../StudentManagement/lib/Sections.php';
require_once __DIR__ . '/../../StudentManagement/lib/StudentNumber.php';
require_once __DIR__ . '/../../../shared/lib/AcademicYear.php';
require_once __DIR__ . '/../lib/PasswordPolicy.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../../UserManagement/lib/Consent.php';

// A student registers with their Student Number, student email and a password. Who they are
// (name, strand, section) is not typed in: it comes from the class roster the Guidance Office
// uploaded, so only students on the roster can register and the Student Number is the same
// everywhere (roster, registration, login, records).
//
// Only Mapúa MCL's own student email domain may register, and when the roster has an email for
// this Student Number the registration must use that one, so nobody can claim another student's
// number with their own address. Real ownership of the address is then confirmed by the
// verification email sent below (see api/verify-email.php).
//
// After the format checks, every outcome gets the same answer (not on the roster, a different email, already
// registered, or a new account), so the form never tells a stranger whether a Student Number is on the roster,
// which email it has, or whether it is registered. Only the email on file learns more: a verification link, or
// "you already have an account". Why it failed is kept in the audit log (register_failed) for the Guidance Office.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// A public form that looks numbers up on the roster. The limit per address is loose (a whole school on one Wi-Fi
// shares one address; modules/AuditLog/lib/AuditLogger.php finds the real one behind Render's proxy); the tight limit is per Student
// Number, below.
$registerTooMany = ['success' => false, 'error' => 'Too many tries for now. Please wait a while and try again, or visit the Guidance Office for help.'];
if (RateLimiter::tooMany('register:' . (AuditLogger::clientIpFrom($_SERVER) ?? 'unknown'), 300, 60)) {
    jsonResponse($registerTooMany, 429);
}

$pdo = Database::get();

$body = readJsonBody();
$schoolId = trim((string) ($body['schoolId'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));
$privacyConsent = $body['privacyConsent'] ?? false;

if ($schoolId === '' || $email === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
// Never trust the client-side checkbox's "required" attribute alone —
// a request built by hand (or a modified page) could omit it entirely.
if ($privacyConsent !== true) {
    jsonResponse(['success' => false, 'error' => 'You must agree to the Data Privacy Policy to create an account.'], 400);
}
if (!StudentNumber::isValid($schoolId)) {
    jsonResponse(['success' => false, 'error' => StudentNumber::INVALID_MESSAGE], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
}
if (!str_ends_with($email, '@' . StudentNumber::EMAIL_DOMAIN)) {
    jsonResponse(['success' => false, 'error' => 'Please register using your official @' . StudentNumber::EMAIL_DOMAIN . ' student email address.'], 400);
}
// 5 tries an hour per Student Number, from anywhere (after the format checks, so typing mistakes do not use them up).
// Any number can hit it, so it says nothing about the roster.
if (RateLimiter::tooMany('register-num:' . $schoolId, 5, 60)) {
    jsonResponse($registerTooMany, 429);
}

// The one answer every outcome below gets (the page shows "Almost there! Check your school email.").
$sameAnswer = ['success' => true];

// Every answer after the format checks takes about the same time (at least 2 to 2.5 seconds, longer than creating
// an account and sending an email usually take), so the wait does not tell which outcome it was either. A very slow
// email service can still go past it; that is logged so it can be noticed.
function registerAnswer(array $answer): void
{
    if (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'] > 2.0) {
        error_log('[register] answer took longer than the 2 second floor');
    }
    $minimum = $_SERVER['REQUEST_TIME_FLOAT'] + random_int(2000, 2500) / 1000;
    $left = $minimum - microtime(true);
    if ($left > 0) {
        usleep((int) ($left * 1000000));
    }
    jsonResponse($answer);
}

// Nothing is sent: log why (detail is encrypted; admins only, api/audit-log.php) and give the same answer.
function registerQuietEnd(array $answer, string $schoolId, string $reason): void
{
    AuditLogger::log(null, null, 'register_failed', 'student', null, "Student Number $schoolId: $reason");
    registerAnswer($answer);
}

function registerVerificationEmail(string $firstName, string $verifyLink, bool $isNew): array
{
    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $intro = $isNew
        ? 'Thanks for creating a ProfilePath account. Confirm this is your email address to activate it — you won\'t be able to sign in until you do.'
        : 'Here is a new link to finish creating your ProfilePath account. Open it to choose your password.';
    $html = EmailTemplate::render(
        'Verify your email to finish signing up',
        "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>" . '<p style="margin:0;">' . $intro . '</p>',
        'Verify Email Address',
        $verifyLink,
        'This link expires in 48 hours.'
    );
    $text = "Hi $firstName,\n\n$intro\n$verifyLink\n\nThis link expires in 48 hours.";
    return [$html, $text];
}

// Must be on this academic year's class roster.
$currentAy = AcademicYear::current();
$rosterStmt = $pdo->prepare('SELECT name_enc, strand, section, email FROM assessment_roster WHERE academic_year = ? AND school_id = ?');
$rosterStmt->execute([$currentAy, $schoolId]);
$roster = $rosterStmt->fetch();
if (!$roster) {
    registerQuietEnd($sameAnswer, $schoolId, "not on the class roster for Academic Year $currentAy");
}
// No hint of the email on file: with the school's email pattern, even a partly hidden address helps a stranger.
if ($roster['email'] !== null && strtolower($roster['email']) !== $email) {
    registerQuietEnd($sameAnswer, $schoolId, 'the email typed is not the one on the class roster');
}

$gradeLevel = Sections::gradeLevel($roster['section']);
if ($gradeLevel === null) {
    registerQuietEnd($sameAnswer, $schoolId, 'the section on the class roster is not valid (fix the roster)');
}
// The roster stores "LASTNAME, FIRSTNAME MIDDLE".
try {
    $fullName = (string) Crypto::dec($roster['name_enc']);
} catch (Throwable $e) {
    error_log('[register] roster name could not be read: ' . $e->getMessage());
    $fullName = ''; // treated as an incomplete name below (same answer as every other outcome)
}
[$lastName, $firstName] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
if ($lastName === '' || $firstName === '') {
    registerQuietEnd($sameAnswer, $schoolId, 'the name on the class roster is incomplete (fix the roster)');
}
$strand = $roster['strand'];
$section = $roster['section'];

// Checked against `users` (the table the UNIQUE constraint actually lives
// on), not just `students` — a users row can exist without a matching
// students row (e.g. a profile deleted separately from its account), and
// checking students alone let that case fall through to the INSERT below,
// where it failed on the UNIQUE(username) constraint and surfaced only as
// a generic "Registration failed" 500.
$existing = $pdo->prepare(
    'SELECT u.id, u.role, u.username, u.email, u.email_verified_at, s.first_name_enc FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     WHERE LOWER(u.username) = LOWER(?) OR LOWER(u.email) = LOWER(?)
     ORDER BY (LOWER(u.username) = LOWER(?)) DESC LIMIT 1'
);
$existing->execute([$schoolId, $email, $schoolId]);
$account = $existing->fetch();
if ($account) {
    // Email only when this Student Number's own account was asked for with the email it registered with. Otherwise
    // (the email belongs to someone else's account, or the number was registered with another address) nobody is
    // emailed: a new link must never go to an address other than the one this form was filled in with.
    if ($account['role'] !== 'student' || strtolower((string) $account['username']) !== strtolower($schoolId)) {
        registerQuietEnd($sameAnswer, $schoolId, 'the email already belongs to another account');
    }
    if (strtolower((string) $account['email']) !== $email) {
        registerQuietEnd($sameAnswer, $schoolId, 'already registered with a different email (check with the student)');
    }
    // A few emails an hour at most, so this form cannot be used to flood a student's inbox. The limit is per account
    // and shared with "Resend it" on the sign-in page (api/resend-verification.php).
    if (RateLimiter::tooMany('verify-mail:' . $account['id'], 3, 60)) {
        registerQuietEnd($sameAnswer, $schoolId, 'already registered (no email: too many in the last hour)');
    }
    $accountName = $account['first_name_enc'] !== null ? (string) Crypto::dec($account['first_name_enc']) : $firstName;
    if ($account['email_verified_at'] !== null) {
        // Already registered and verified: tell the owner, and point to "Forgot password".
        $resetLink = rtrim((string) envValue('APP_URL'), '/') . '/student-forgot-password';
        $bodyHtml = EmailTemplate::render(
            'You already have a ProfilePath account',
            '<p style="margin:0 0 12px 0;">Hi ' . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p style="margin:0;">Someone tried to create a ProfilePath account with your Student Number, but you already have one. If it was you and you forgot your password, you can reset it. If it wasn\'t you, you can ignore this email.</p>',
            'Reset Password',
            $resetLink,
            'Your Student Number is your username.'
        );
        $bodyText = "Hi $accountName,\n\nSomeone tried to create a ProfilePath account with your Student Number, but you already have one. If it was you and you forgot your password, reset it here:\n$resetLink\n\nIf it wasn't you, you can ignore this email.";
        Mailer::send($account['email'], $accountName, 'You already have a ProfilePath account', $bodyHtml, $bodyText);
        AuditLogger::log(null, null, 'register_failed', 'student', null, "Student Number $schoolId: already registered (reminder emailed)");
        registerAnswer($sameAnswer);
    }
    // Registered but never verified: a fresh link to the email on file (older links stop working).
    $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$account['id']]);
    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
    )->execute([$account['id'], hash('sha256', $rawToken)]);
    $verifyLink = rtrim((string) envValue('APP_URL'), '/') . '/verify-email?token=' . $rawToken;
    [$bodyHtml, $bodyText] = registerVerificationEmail($accountName, $verifyLink, false);
    $sent = Mailer::send($account['email'], $accountName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);
    AuditLogger::log(null, null, 'register_failed', 'student', null, "Student Number $schoolId: registered but not verified (new link emailed)"
        . ($sent ? '' : ' (email could not be sent)'));
    registerAnswer($sameAnswer); // never the link itself, not even locally (it would activate the account without the inbox)
}

$pdo->beginTransaction();
try {
    // The password is NOT taken here. Anyone who knows a Student Number and its roster email could otherwise register first
    // with their own password, and the real student's later click on the verification link would activate it. The account
    // gets an unusable password; the person who opens the emailed link chooses the real one (api/verify-email.php).
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare('INSERT INTO users (role, username, password_hash, email, is_active) VALUES (?, ?, ?, ?, TRUE) RETURNING id');
    $userStmt->execute(['student', $schoolId, $hash, $email]);
    $userId = (int) $userStmt->fetchColumn();

    $studentStmt = $pdo->prepare(
        'INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $studentStmt->execute([$userId, $schoolId, Crypto::enc($firstName), Crypto::enc($lastName), $strand, $gradeLevel, $section, $currentAy ?: null]);

    // Receipt of the privacy consent checked above, saved with the account (modules/UserManagement/lib/Consent.php).
    Consent::record($pdo, $userId, 'registration');

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $pdo->prepare(
        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
    )->execute([$userId, $tokenHash]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[register] could not create the account: ' . $e->getMessage());
    registerQuietEnd($sameAnswer, $schoolId, 'the account could not be created (server error, try again)');
}

AuditLogger::log($userId, 'student', 'register', 'user', (string) $userId, "New student account: $schoolId");

$verifyLink = rtrim((string) envValue('APP_URL'), '/') . '/verify-email?token=' . $rawToken;
[$bodyHtml, $bodyText] = registerVerificationEmail($firstName, $verifyLink, true);
RateLimiter::tooMany('verify-mail:' . $userId, 3, 60); // the first email counts toward the shared limit too
$sent = Mailer::send($email, $firstName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

// No name, strand or section in the answer: it is the same answer a wrong Student Number gets.
$response = $sameAnswer;
// Local development only (no email service there). In production the link is never put in the response:
// anyone who knew a Student Number and its roster email could otherwise activate that account without the inbox.
if (!$sent && envValue('APP_ENV') === 'local') {
    // The student just proved they control this form submission (not the
    // inbox) — if delivery fails there's no other way for them to get
    // moving, so hand over the link directly (api/staff-register.php does
    // the same for staff sign-ups).
    $response['verifyLink'] = $verifyLink;
}
registerAnswer($response);
