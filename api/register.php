<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

// Only Mapúa MCL's own student email domain may self-register a student
// account — this is a public page, and without this check anyone on the
// internet could sign up. Real ownership of the address is then confirmed
// by the verification email sent below (see api/verify-email.php).
const STUDENT_EMAIL_DOMAIN = 'live.mcl.edu.ph';

// Fixed section codes per strand — the school offers exactly these 7
// sections, each belonging to exactly one strand. The registration page's
// Section dropdown is populated from this same list, cascading on the
// chosen Strand. Kept here as the server-side source of truth since the
// client-side dropdown can't be trusted alone.
const SECTIONS_BY_STRAND = [
    'STEM' => ['S1114', 'S1109'],
    'ABM' => ['A1101', 'A1102'],
    'ICT' => ['I1101', 'I1102'],
    'HUMSS' => ['H1102'],
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = readJsonBody();
$schoolId = trim((string) ($body['schoolId'] ?? ''));
$firstName = trim((string) ($body['firstName'] ?? ''));
$lastName = trim((string) ($body['lastName'] ?? ''));
$email = trim((string) ($body['email'] ?? ''));
$strand = (string) ($body['strand'] ?? '');
$gradeLevel = (string) ($body['gradeLevel'] ?? '');
$section = trim((string) ($body['section'] ?? ''));
$password = (string) ($body['password'] ?? '');
$privacyConsent = $body['privacyConsent'] ?? false;

if ($schoolId === '' || $firstName === '' || $lastName === '' || $email === '' || $section === '' || $password === '') {
    jsonResponse(['success' => false, 'error' => 'All fields are required.'], 400);
}
// Never trust the client-side checkbox's "required" attribute alone —
// a request built by hand (or a modified page) could omit it entirely.
if ($privacyConsent !== true) {
    jsonResponse(['success' => false, 'error' => 'You must agree to the Data Privacy Policy to create an account.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Enter a valid email address.'], 400);
}
if (!str_ends_with(strtolower($email), '@' . STUDENT_EMAIL_DOMAIN)) {
    jsonResponse(['success' => false, 'error' => 'Please register using your official @' . STUDENT_EMAIL_DOMAIN . ' student email address.'], 400);
}
if (!in_array($strand, ['STEM', 'ABM', 'ICT', 'HUMSS'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid strand.'], 400);
}
if (!in_array($gradeLevel, ['11', '12'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid grade level.'], 400);
}
if (!in_array($section, SECTIONS_BY_STRAND[$strand], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid section for the selected strand.'], 400);
}

$pdo = Database::get();

function passwordPolicy(PDO $pdo): array
{
    $rows = $pdo->query("SELECT key, value FROM security_policies WHERE key LIKE 'password.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'minLength' => (int) ($rows['password.minLength'] ?? 8),
        'requireUpper' => ($rows['password.requireUpper'] ?? 'true') === 'true',
        'requireLower' => ($rows['password.requireLower'] ?? 'true') === 'true',
        'requireNumber' => ($rows['password.requireNumber'] ?? 'true') === 'true',
        'requireSymbol' => ($rows['password.requireSymbol'] ?? 'true') === 'true',
    ];
}

$policy = passwordPolicy($pdo);
$errors = [];
if (strlen($password) < $policy['minLength']) {
    $errors[] = "Password must be at least {$policy['minLength']} characters.";
}
if ($policy['requireUpper'] && !preg_match('/[A-Z]/', $password)) {
    $errors[] = 'Password must contain an uppercase letter.';
}
if ($policy['requireLower'] && !preg_match('/[a-z]/', $password)) {
    $errors[] = 'Password must contain a lowercase letter.';
}
if ($policy['requireNumber'] && !preg_match('/[0-9]/', $password)) {
    $errors[] = 'Password must contain a number.';
}
if ($policy['requireSymbol'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
    $errors[] = 'Password must contain a symbol.';
}
if ($errors) {
    jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 400);
}

$existing = $pdo->prepare('SELECT 1 FROM students WHERE LOWER(school_id) = LOWER(?)');
$existing->execute([$schoolId]);
if ($existing->fetch()) {
    jsonResponse(['success' => false, 'error' => 'An account with this School ID already exists.'], 409);
}

$pdo->beginTransaction();
try {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare('INSERT INTO users (role, username, password_hash, email, is_active) VALUES (?, ?, ?, ?, TRUE) RETURNING id');
    $userStmt->execute(['student', $schoolId, $hash, $email]);
    $userId = (int) $userStmt->fetchColumn();

    $currentAy = $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
    $studentStmt = $pdo->prepare(
        'INSERT INTO students (user_id, school_id, first_name_enc, last_name_enc, strand, grade_level, section, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $studentStmt->execute([$userId, $schoolId, Crypto::enc($firstName), Crypto::enc($lastName), $strand, $gradeLevel, $section, $currentAy ?: null]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $pdo->prepare(
        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '48 hours')"
    )->execute([$userId, $tokenHash]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

AuditLogger::log($userId, 'student', 'register', 'user', (string) $userId, "New student account: $schoolId");

$verifyLink = rtrim((string) getenv('APP_URL'), '/') . '/verify-email.html?token=' . $rawToken;
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

$bodyHtml = EmailTemplate::render(
    'Verify your email to finish signing up',
    "<p style=\"margin:0 0 12px 0;\">Hi $safeFirstName,</p>"
        . '<p style="margin:0;">Thanks for creating a ProfilePath account. Confirm this is your email address to activate it — you won\'t be able to sign in until you do.</p>',
    'Verify Email Address',
    $verifyLink,
    'This link expires in 48 hours.'
);
$bodyText = "Hi $firstName,\n\nThanks for creating a ProfilePath account. Confirm this is your email address to activate it:\n$verifyLink\n\nThis link expires in 48 hours.";
$sent = Mailer::send($email, $firstName, 'Verify your ProfilePath email', $bodyHtml, $bodyText);

$response = ['success' => true, 'emailSent' => $sent];
if (!$sent) {
    // The student just proved they control this form submission (not the
    // inbox) — if delivery fails there's no other way for them to get
    // moving, so hand over the link the same way staff-accounts.php does
    // for admin-created accounts.
    $response['verifyLink'] = $verifyLink;
}
jsonResponse($response);
