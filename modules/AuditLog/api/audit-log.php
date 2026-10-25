<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/../lib/AuditLogPdf.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffPosition.php';
require_once __DIR__ . '/../../CareerProfiling/lib/QuestionBank.php';
require_once __DIR__ . '/../lib/AuditChange.php';
require_once __DIR__ . '/../lib/AuditRetention.php';

$user = Rbac::requireAccess('rac', 'full'); // audit visibility lives alongside the other security/system admin tools
// Full IP addresses are for the administrator (security incidents); other staff see them masked (maskIp()).
$seeFullIp = $user['role'] === 'admin';
$showIp = fn(?string $ip): ?string => $seeFullIp ? $ip : maskIp($ip);

$pdo = Database::get();

// Actions ending in _failed are the only "Failed" outcomes this app currently logs;
// everything else recorded is by definition a completed action.
const FAILED_SUFFIX = '_failed';
// Security events read as Failed too: a lockout, a possible shared account, and the refusals below.
// One list for the SQL (FAILED_SQL) and the PHP rule (statusOf), so they cannot drift apart.
const FAILED_ACTIONS = ['login_lockout', 'login_shared_suspected', 'access_code_throttled', 'assessment_resubmit_denied'];
define('FAILED_SQL', "(al.action LIKE '%_failed' OR al.action IN ('" . implode("', '", FAILED_ACTIONS) . "'))");
// Refusals by the app's own rules are shown as "Blocked": an account lockout, too many exam code
// attempts, a second try at a finished assessment. They stay failures in FAILED_SQL, so the Status
// filter and the summary cards still count them as Failed.
const BLOCKED_ACTIONS = ['login_lockout', 'access_code_throttled', 'assessment_resubmit_denied'];
// Who did it, in 5 groups: for the summary card breakdowns and the exact role filter. Fixed SQL.
// Counselors and facilitators share actor_role 'counselor'; staff_position tells them apart.
const ROLE_BUCKET_SQL = "(CASE WHEN al.actor_user_id IS NULL THEN 'none'"
    . " WHEN al.actor_role = 'counselor' AND u.staff_position = 'facilitator' THEN 'facilitator'"
    . " ELSE COALESCE(al.actor_role, 'none') END)";
const ROLE_BUCKETS = [
    'admin' => 'Administrator', 'counselor' => 'Guidance Counselor', 'facilitator' => 'Guidance Facilitator',
    'student' => 'Student', 'none' => 'No account',
];
// Details panel: on these the target is a sign-in name. A failed sign-in stores whatever was typed in
// the username or email box (sometimes a password typed in the wrong box), and a student's username is
// their Student Number, so the panel only shows the first 2 characters, or nothing when no account
// matched (then the text is most likely a mistyped password).
const SIGN_IN_ACTIONS = ['login', 'login_failed', 'login_lockout', 'login_shared_suspected', 'signed_out_other_device', 'logout', 'staff_activation_failed'];
// Details panel: these details are sensitive student data (a help request's subject, RIASEC results,
// a school id, a worksheet score, a counselor's retake decision note, staff notes on a monitoring
// flag). The other pages show them only with the right module access and inside StaffScope, so here
// the panel says they are hidden and points to the student's record instead.
const HIDDEN_DETAIL_ACTIONS = [
    'submit_help_request', 'submit_assessment', 'register', 'submit_worksheet',
    'approve_retake', 'decline_retake', 'monitoring_approve', 'monitoring_escalate', 'monitoring_dismiss',
];
// Staff actions on a student's account, logged with target_type 'user' and the student's user id
// (api/students.php). Like target_type 'student', only admins see which student and the detail.
// register_failed (api/register.php) is here too: its detail holds the Student Number that was typed.
const STUDENT_ACCOUNT_ACTIONS = ['activate_student_account', 'deactivate_student_account', 'reset_student_assessment', 'update_student_section', 'register_failed'];

// A written name for every action the app logs (past tense, plain words). An action missing here is
// shown by its code with the underscores turned into spaces.
$actionLabels = [
    // Signing in and accounts
    'login' => 'Signed in',
    'login_failed' => 'Failed sign-in',
    'login_lockout' => 'Account locked',
    'login_shared_suspected' => 'Possible shared account',
    'signed_out_other_device' => 'Signed out on another device',
    'logout' => 'Signed out',
    'register' => 'Registered new account',
    'register_failed' => 'Registration not completed',
    'verify_email' => 'Verified email',
    'request_password_reset' => 'Requested password reset',
    'reset_password' => 'Reset password',
    'password_reset_code_failed' => 'Password reset code failed',
    'change_password' => 'Changed password',
    'change_password_pending' => 'Started password change',
    'change_password_code_resent' => 'Resent password change code',
    'change_password_code_send_failed' => 'Password change code not sent',
    'change_password_failed' => 'Password change failed',
    'update_avatar' => 'Changed profile photo',
    'remove_avatar' => 'Removed profile photo',
    // Staff accounts
    'staff_signup' => 'Staff signed up',
    'create_staff_account' => 'Created staff account',   // older entries only
    'approve_staff_account' => 'Approved staff account',
    'reject_staff_account' => 'Rejected staff account',
    'resend_staff_setup_code' => 'Resent staff setup code',
    'staff_account_activated' => 'Staff account set up',
    'staff_activation_failed' => 'Staff account setup failed',
    'activate_staff_account' => 'Activated staff account',
    'deactivate_staff_account' => 'Deactivated staff account',
    'assign_staff_sections' => 'Assigned staff sections',
    // Students, sections and programs
    'activate_student_account' => 'Activated student account',
    'deactivate_student_account' => 'Deactivated student account',
    'update_student_section' => 'Changed student section',
    'reset_student_assessment' => 'Reset student assessment',
    'unlock_account' => 'Unlocked account',
    'export_student_list' => 'Exported student list',
    'export_student_roster' => 'Exported student roster',
    'upload_roster' => 'Uploaded class roster',
    'create_section' => 'Added section',
    'delete_section' => 'Deleted section',
    'activate_section' => 'Activated section',
    'deactivate_section' => 'Deactivated section',
    'create_program' => 'Added program',
    'update_program' => 'Edited program',
    'activate_program' => 'Activated program',
    'deactivate_program' => 'Deactivated program',
    'recompute_recommendations' => 'Recomputed recommendations',
    // Assessment
    'submit_assessment' => 'Submitted RIASEC assessment',
    'assessment_resubmit_denied' => 'Tried to resubmit assessment',
    'submit_worksheet' => 'Submitted worksheet',
    'request_retake' => 'Requested assessment retake',
    'approve_retake' => 'Approved retake request',
    'decline_retake' => 'Declined retake request',
    'retake_started' => 'Started assessment retake',
    'access_code_verified' => 'Entered exam access code',
    'access_code_failed' => 'Wrong exam access code',
    'access_code_throttled' => 'Too many exam code attempts',
    'add_question' => 'Added question',
    'update_question' => 'Edited question',
    'delete_question' => 'Deleted question',
    'load_question_set' => 'Loaded question set',
    'create_exam_schedule' => 'Added exam schedule',
    'delete_exam_schedule' => 'Deleted exam schedule',
    'regenerate_exam_schedule_code' => 'Made new exam access code',
    // Announcements, FAQs and student support
    'create_announcement' => 'Posted announcement',
    'draft_announcement' => 'Saved announcement draft',
    'update_announcement' => 'Edited announcement',
    'delete_announcement' => 'Deleted announcement',
    'remind_announcement' => 'Sent announcement reminder',
    'announcement_emailed' => 'Emailed announcement',
    'create_announcement_template' => 'Added announcement template',
    'update_announcement_template' => 'Edited announcement template',
    'delete_announcement_template' => 'Deleted announcement template',
    'create_faq' => 'Added FAQ',
    'update_faq' => 'Edited FAQ',
    'delete_faq' => 'Deleted FAQ',
    'submit_help_request' => 'Sent help request',
    'resolve_help_request' => 'Resolved help request',
    'add_counseling_note' => 'Added counseling note',
    'invite_to_counseling' => 'Invited to counseling',
    'send_counseling_link' => 'Sent counseling booking link to self',
    'send_counseling_link_failed' => 'Counseling booking link not sent',
    'invite_unregistered_students' => 'Invited unregistered students',
    'send_student_reminders' => 'Sent student reminders',
    'monitoring_approve' => 'Approved monitoring flag',
    'monitoring_escalate' => 'Escalated monitoring flag',
    'monitoring_dismiss' => 'Dismissed monitoring flag',
    'view_cbf_debug' => 'Viewed recommendation details',
    // Reports and exports
    'export_analytics_xlsx' => 'Exported analytics report',
    'export_analytics_xlsx_failed' => 'Analytics export failed',
    'export_analytics_pdf' => 'Exported analytics report (PDF)',
    'export_analytics_pdf_failed' => 'Analytics PDF export failed',
    'send_analytics_report' => 'Sent analytics report',
    'send_analytics_report_failed' => 'Analytics report not sent',
    'export_audit_log' => 'Exported audit log',
    'export_audit_log_failed' => 'Audit log export failed',
    'delete_old_audit_logs' => 'Deleted old audit logs',
    'delete_old_audit_logs_failed' => 'Old audit log cleanup failed',
    'verify_audit_log' => 'Checked the audit log',
    'verify_audit_log_failed' => 'Audit log check found a problem',
    // Security settings
    'update_rbac' => 'Changed access permissions',
    'update_policy' => 'Changed security setting',
    'update_settings' => 'Changed security setting',
    'update_office_hours' => 'Changed office hours',
    'update_booking_link' => 'Changed booking link',
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

/** "Blocked" (a refusal by a security rule), "Failed" (the same rule as FAILED_SQL) or "Success". */
function statusOf(string $action): string
{
    if (in_array($action, BLOCKED_ACTIONS, true)) {
        return 'Blocked';
    }
    return (str_ends_with($action, FAILED_SUFFIX) || in_array($action, FAILED_ACTIONS, true)) ? 'Failed' : 'Success';
}

/** Who did it: staff by username, a student by name (their username is their Student Number, which staff don't see). */
function actorLabel(array $r): string
{
    if (($r['actor_role'] ?? null) === 'student') {
        // A student whose account was later removed has no name to read: show "Student" instead of failing.
        if (empty($r['last_name_enc']) && empty($r['first_name_enc'])) {
            return 'Student';
        }
        $name = trim(Crypto::dec($r['last_name_enc'] ?? '') . ', ' . Crypto::dec($r['first_name_enc'] ?? ''), ' ,');
        return $name !== '' ? $name : 'Student';
    }
    if (trim((string) ($r['full_name'] ?? '')) !== '') {
        return $r['full_name'];
    }
    if (!isset($r['username']) && in_array($r['action'] ?? '', ['login_failed', 'register_failed'], true)) {
        return 'Unregistered user';
    }
    return $r['username'] ?? 'System';
}

/**
 * Counselors and facilitators share actor_role 'counselor'; users.staff_position tells them apart.
 * It is the person's current position (the log did not store it at the time).
 */
function roleLabel(?string $role, array $labels, ?string $position = null): string
{
    if ($role === null) {
        return '-';
    }
    if ($role === 'counselor') {
        return StaffPosition::label($position);
    }
    return $labels[$role] ?? ucfirst($role);
}

/** "Su***": enough to recognize a sign-in attempt without showing what was typed. */
function maskTyped(string $text): string
{
    return mb_strlen($text) <= 2 ? '***' : mb_substr($text, 0, 2) . '***';
}

/**
 * IP addresses are personal information (RA 10173). They are kept in full for security, but only the administrator
 * sees them in full; other staff see the network part only: "112.198.xx.xx", or "2001:db8:xxxx::" for IPv6.
 */
function maskIp(?string $ip): ?string
{
    if ($ip === null || $ip === '') {
        return $ip;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.xx.xx';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Expanded first, so short forms like "fe80::1" still give their first two groups ("fe80:0:xxxx::").
        $groups = str_split(bin2hex(inet_pton($ip)), 4);
        return (ltrim($groups[0], '0') ?: '0') . ':' . (ltrim($groups[1], '0') ?: '0') . ':xxxx::';
    }
    return 'xx.xx.xx.xx';
}

/** The same masking for addresses written inside a detail text (for example "Signed in from 112.198.45.201"). */
function maskIpsInText(string $text): string
{
    $text = preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', fn($m) => maskIp($m[0]) ?? $m[0], $text);
    // Any run of hex digits and colons with 2 or more colons, including short forms ("::1", "2001:db8::").
    return preg_replace_callback('/(?<![0-9a-f:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![0-9a-f:])/i',
        fn($m) => filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? maskIp($m[0]) : $m[0], $text);
}

/**
 * Plain words for the details. Older security setting entries saved the raw request as JSON, like
 * {"type":"policy","key":"lockout","maxAttempts":3}; that becomes "Account lockout" plus one line per
 * setting ("Failed attempts before lockout: 3"). Any other text is returned as it is.
 */
function readableDetail(string $detail): string
{
    // Sign-ins save "Successful login", a long dash (U+2014), then the device, for example "Chrome on
    // Windows". LoginDevice::findConflict() reads that stored form, so only the shown text changes.
    if (preg_match('/^Successful login\s+\x{2014}\s+(.+)$/u', $detail, $m)) {
        return 'Device: ' . $m[1];
    }
    // Exports save "PDF, 38 activities. Dates: Any time | Status: All | ...": one fact per line.
    if (preg_match('/^(PDF, \d+ activit(?:y|ies)|Refused: [^.]+)\. (Dates: .+)$/', $detail, $m)) {
        return implode("\n", array_merge([str_replace('PDF, ', 'PDF with ', $m[1])], explode(' | ', $m[2])));
    }
    $data = json_decode($detail, true);
    if (!is_array($data) || $data === [] || array_is_list($data)) {
        return $detail;
    }
    $labels = [
        'enabled' => 'Status', 'minLength' => 'Minimum password length', 'requireUpper' => 'Needs an uppercase letter',
        'requireLower' => 'Needs a lowercase letter', 'requireNumber' => 'Needs a number', 'requireSymbol' => 'Needs a symbol',
        'maxAttempts' => 'Failed attempts before lockout', 'lockoutMinutes' => 'Lockout length', 'retentionDays' => 'Log retention',
        'twoFactor' => 'Two-step sign-in', 'sessionTimeoutEnabled' => 'Session timeout', 'timeoutMinutes' => 'Timeout length',
    ];
    $units = ['lockoutMinutes' => 'minute', 'timeoutMinutes' => 'minute', 'retentionDays' => 'day'];
    $switches = ['enabled', 'twoFactor', 'sessionTimeoutEnabled'];   // On/Off; other yes/no settings say Yes/No
    $headings = ['password' => 'Password policy', 'lockout' => 'Account lockout', 'monitoring' => 'Login monitoring'];

    $lines = [];
    if (($data['type'] ?? '') === 'policy' && isset($headings[$data['key'] ?? ''])) {
        $lines[] = $headings[$data['key']];
    } elseif (($data['type'] ?? '') === 'settings') {
        $lines[] = 'Sign-in and session settings';
    }
    foreach ($data as $key => $value) {
        if ($key === 'type' || $key === 'key') {
            continue;
        }
        $label = $labels[$key] ?? ucfirst(strtolower(preg_replace('/(?<!^)([A-Z])/', ' $1', (string) $key)));
        if (is_bool($value)) {
            $text = in_array($key, $switches, true) ? ($value ? 'On' : 'Off') : ($value ? 'Yes' : 'No');
        } elseif (isset($units[$key]) && is_numeric($value)) {
            $text = $value . ' ' . $units[$key] . ((int) $value === 1 ? '' : 's');
        } else {
            $text = is_scalar($value) ? (string) $value : json_encode($value);
        }
        $lines[] = "$label: $text";
    }
    return implode("\n", $lines);
}

/**
 * The target in plain words, as [label, text], or [null, null] when there is nothing useful to show.
 * Accounts are shown by name (a student's name only to admins, or when it is the student's own row);
 * numbers and setting keys like "lockout" or "rac:counselor" are left out, since the details say
 * what the activity was about and a bare id means nothing to a person.
 */
function readableTarget(PDO $pdo, array $r, string $target, bool $viewerIsAdmin): array
{
    $action = $r['action'];
    if ($target === '') {
        return [null, null];
    }
    if (str_starts_with($action, 'export_audit_log')) {
        return ['Reference', $target];
    }
    if ($action === 'verify_audit_log_failed') {
        return ['Entry', '#' . $target];   // where the check found the problem
    }
    if (in_array($action, SIGN_IN_ACTIONS, true)) {
        return [$action === 'staff_activation_failed' ? 'Username or email' : 'Username', $target];
    }
    // A class roster invite stores a roster entry number, not an account number: no name lookup.
    if ($action === 'invite_unregistered_students') {
        return [null, null];
    }
    // Only numbers that can be a users.id (INT) are looked up.
    if (in_array($r['target_type'], ['user', 'student'], true) && ctype_digit($target) && strlen($target) <= 10 && (int) $target <= 2147483647) {
        if ($r['actor_user_id'] !== null && (int) $r['actor_user_id'] === (int) $target) {
            return [null, null];   // their own account: the Name column already says who
        }
        $find = $pdo->prepare('SELECT u.role, u.username, u.full_name, u.staff_position, st.first_name_enc, st.last_name_enc
                               FROM users u LEFT JOIN students st ON st.user_id = u.id WHERE u.id = ?');
        $find->execute([(int) $target]);
        $account = $find->fetch();
        if (!$account) {
            return ['Account', "#$target (removed)"];
        }
        if ($account['role'] === 'student') {
            if (!$viewerIsAdmin) {
                return [null, null];
            }
            try {
                $name = trim(Crypto::dec($account['last_name_enc'] ?? '') . ', ' . Crypto::dec($account['first_name_enc'] ?? ''), ' ,');
            } catch (Throwable $e) {
                $name = '';
            }
            return ['Account', ($name !== '' ? $name : "#$target") . ' (Student)'];
        }
        $name = trim((string) $account['full_name']) !== '' ? $account['full_name'] : $account['username'];
        $position = $account['role'] === 'counselor' ? StaffPosition::label($account['staff_position']) : 'Administrator';
        return ['Account', "$name ($position)"];
    }
    if (ctype_digit($target) || in_array($r['target_type'], ['security_policies', 'security_rbac'], true)) {
        return [null, null];
    }
    return ['About', $target];
}

/**
 * Older entries saved less. A question entry saved only "Dimension E" or "Dimension E#5": show the
 * type's name and, if the question still exists, its text now. Deletes that saved nothing say so.
 * Returns [detail, note].
 */
function olderEntryDetail(PDO $pdo, array $r, ?string $detail): array
{
    if ($detail !== null && $r['target_type'] === 'assessment_question' && preg_match('/^Dimension ([RIASEC])(?:#\d+)?$/', $detail, $m)) {
        $line = (QuestionBank::NAMES[$m[1]] ?? $m[1]) . ' question';
        $find = $pdo->prepare('SELECT question_text_enc FROM assessment_questions WHERE id = ?');
        $find->execute([(int) $r['target_id']]);
        $enc = $find->fetchColumn();
        if ($enc !== false && $r['action'] !== 'delete_question') {
            try {
                return [$line . "\nText now: \"" . Crypto::dec($enc) . '"', null];
            } catch (Throwable $e) {
                // fall through to "not saved"
            }
        }
        return [$line . "\nIts text was not saved (older entry).", null];
    }
    if ($detail === null && in_array($r['action'], ['delete_exam_schedule', 'delete_announcement'], true)) {
        return [null, 'What was deleted was not saved (older entry).'];
    }
    // Exam schedules are saved as "2026-10-14 09:20-13:00, room R301, AY 2026-2027, strand STEM";
    // show the date and times the way people write them, one fact per line.
    if ($detail !== null && $r['target_type'] === 'exam_schedule'
        && preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::\d{2})?-(\d{2}:\d{2})(?::\d{2})?, room (.+?), AY ([^,]+)(.*)$/', $detail, $m)) {
        $time = fn(string $hm) => DateTime::createFromFormat('H:i', $hm)->format('g:i A');
        $lines = [DateTime::createFromFormat('Y-m-d', $m[1])->format('M j, Y') . ', ' . $time($m[2]) . ' to ' . $time($m[3]), "Room {$m[4]}", "AY {$m[5]}"];
        foreach (array_filter(array_map('trim', explode(',', $m[6]))) as $extra) {
            $lines[] = ucfirst($extra);   // "strand STEM" -> "Strand STEM", "section S1243" -> "Section S1243"
        }
        return [implode("\n", $lines), null];
    }
    return [$detail, null];
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
    $role = is_string($q['role'] ?? null) && isset(ROLE_BUCKETS[$q['role']]) ? ROLE_BUCKETS[$q['role']] : 'All';
    $search = is_string($q['search'] ?? null) ? trim($q['search']) : '';
    if (mb_strlen($search) > 60) {
        $search = mb_substr($search, 0, 60) . '...';
    }
    return "Dates: $dates | Status: $status | Category: $category | Role: $role | Search: " . ($search === '' ? 'none' : '"' . $search . '"');
}

function buildFilters(array $q, array $actionLabels, array $roleLabels, array $categoryLabels, bool $searchIp = false): array
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

            // IP address: administrators only. Other staff see masked addresses, and searching by IP would let
            // them work out the hidden part one digit at a time.
            if ($searchIp) {
                $or[] = 'al.ip_address::text ILIKE ?';
                $params[] = $like;
            }

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

            // Role, as shown on screen. Counselors and facilitators share actor_role 'counselor'.
            $roles = array_keys(array_filter(array_diff_key($roleLabels, ['counselor' => true]), fn($label) => $has($label)));
            if ($roles) {
                $or[] = 'al.actor_role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
                array_push($params, ...$roles);
            }
            if ($has(StaffPosition::LABELS['counselor'])) {
                $or[] = "(al.actor_role = 'counselor' AND COALESCE(u.staff_position, 'counselor') <> 'facilitator')";
            }
            if ($has(StaffPosition::LABELS['facilitator'])) {
                $or[] = "(al.actor_role = 'counselor' AND u.staff_position = 'facilitator')";
            }

            // Rows with no user behind them.
            if ($has('Unregistered user')) {
                $or[] = "(al.actor_user_id IS NULL AND al.action IN ('login_failed', 'register_failed'))";
            }
            if ($has('System')) {
                $or[] = "(al.actor_user_id IS NULL AND al.action NOT IN ('login_failed', 'register_failed'))";
            }

            // Status.
            if ($has('Failed')) {
                $or[] = FAILED_SQL;
            }
            if ($has('Success')) {
                $or[] = 'NOT ' . FAILED_SQL;
            }
            if ($has('Blocked')) {
                $or[] = "al.action IN ('" . implode("', '", BLOCKED_ACTIONS) . "')";
            }

            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }

    // Exact role (set by clicking a role in a card breakdown). Searching a role name is not exact:
    // "Student" also matches actions such as "Activated student account". It goes last, so the
    // filters without it come back too: the card breakdowns count every role with them.
    $allRoles = [implode(' AND ', $where), $params];
    if (is_string($q['role'] ?? null) && isset(ROLE_BUCKETS[$q['role']])) {
        $where[] = ROLE_BUCKET_SQL . ' = ?';
        $params[] = $q['role'];
    }
    return [implode(' AND ', $where), $params, ...$allRoles];
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
    [$where, $params] = buildFilters($_GET, $actionLabels, $roleLabels, $categoryLabels, $seeFullIp);
    $sql = "SELECT al.created_at, u.username, u.full_name, u.staff_position, al.actor_role, al.action, al.target_type, al.ip_address, st.first_name_enc, st.last_name_enc
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
                roleLabel($row['actor_role'], $roleLabels, $row['staff_position']),
                actionLabel($row['action'], $actionLabels),
                categoryLabel($row['target_type'], $categoryLabels),
                (string) ($showIp($row['ip_address']) ?? '-'),
                statusOf($row['action']),
            ];
        }
        $pdfBytes = AuditLogPdf::build($rows, [
            'exportedBy' => ($user['displayName'] ?? $user['username']) . " ($position)",
            'exportedAt' => (new DateTime())->format('M j, Y g:i A'),
            'reference' => $reference,
            'filters' => $filters,
            'ipMasked' => !$seeFullIp,
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    // One activity for the details panel (same rac full access check as the list, at the top).
    $stmt = $pdo->prepare(
        'SELECT al.id, al.created_at, al.actor_user_id, u.username, u.full_name, u.staff_position, al.actor_role, al.action, al.target_type,
                al.target_id, al.detail_enc, al.ip_address, st.first_name_enc, st.last_name_enc
         FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN students st ON st.user_id = al.actor_user_id
         WHERE al.id = ?'
    );
    $stmt->execute([(int) $_GET['id']]);
    $r = $stmt->fetch();
    if (!$r) {
        jsonResponse(['error' => 'That activity was not found.'], 404);
    }

    $target = (string) ($r['target_id'] ?? '');
    if ($target !== '' && in_array($r['action'], SIGN_IN_ACTIONS, true)) {
        $target = $r['actor_user_id'] === null ? '***' : maskTyped($target);
    }
    // Which student a counseling note, invite, retake decision or account change was about: other pages
    // show that only with the right module access and inside StaffScope, so here only admins see it.
    $studentAccountAction = in_array($r['action'], STUDENT_ACCOUNT_ACTIONS, true);
    $hideStudent = $user['role'] !== 'admin' && ($r['target_type'] === 'student' || $studentAccountAction);
    if ($hideStudent) {
        $target = '';
    }
    [$targetLabel, $targetText] = readableTarget($pdo, $r, $target, $user['role'] === 'admin');

    $detail = null;
    $detailNote = null;
    $changes = [];
    // Other roles' permissions are for the administrator only (Security Configuration shows other staff just their
    // own access), so a permission change shows that it happened, not what changed.
    $hidePermissions = $r['action'] === 'update_rbac' && $user['role'] !== 'admin';
    if ($hidePermissions) {
        $targetLabel = null;
        $targetText = null;
        $detailNote = 'Hidden: permission changes are shown only to the administrator.';
    } elseif (in_array($r['action'], HIDDEN_DETAIL_ACTIONS, true) || ($hideStudent && $studentAccountAction)) {
        $detailNote = "Hidden: sensitive student data. Open the student's record to see it.";
    } elseif ($r['detail_enc'] !== null) {
        try {
            $plain = Crypto::dec($r['detail_enc']);
            // Edits saved by AuditChange carry before -> after; anything else is shown as plain text.
            $parsed = AuditChange::parse($plain);
            if ($parsed !== null) {
                $changes = $parsed['changes'];
                $detail = $parsed['summary'] !== '' ? $parsed['summary'] : null;
                // An announcement's message is for people with Announcements access (drafts too);
                // anyone else only learns that it changed.
                $messageHidden = false;
                if ($r['target_type'] === 'announcement' && Rbac::accessLevel('announcements', Rbac::roleKey($user)) === 'none') {
                    $kept = array_values(array_filter($changes, fn($c) => $c['field'] !== 'Message'));
                    $messageHidden = count($kept) < count($changes);
                    $changes = $kept;
                }
                if ($messageHidden) {
                    $detail = trim(($detail ?? '') . "\nThe message changed too (hidden: you have no access to Announcements).");
                } elseif (!$changes) {
                    $detail = trim(($detail ?? '') . "\nSaved with no changes.");
                }
            } else {
                $detail = readableDetail($plain);
            }
        } catch (Throwable $e) {
            $detailNote = 'Could not be read.';
        }
    }
    if ($detailNote === null) {
        [$detail, $detailNote] = olderEntryDetail($pdo, $r, $detail);
    }
    try {
        $name = actorLabel($r);
    } catch (Throwable $e) {
        $name = 'Could not be read';
    }
    // Some details name addresses too ("Possible shared account": signed in from one IP while another was recent).
    if (!$seeFullIp && $detail !== null) {
        $detail = maskIpsInText($detail);
        // An administrator's export saves what was typed in the search, which can be part of an address
        // ("112.198.45"); other staff see the search with every run of dotted numbers hidden.
        if (str_starts_with($r['action'], 'export_audit_log')) {
            $detail = preg_replace_callback('/Search: ".*"/s',
                fn($m) => preg_replace('/\b\d{1,3}(?:\.\d{1,3})+\.?/', 'xx.xx', $m[0]), $detail);
        }
    }

    jsonResponse([
        'id' => (int) $r['id'],
        'timestamp' => (new DateTime($r['created_at']))->format('D, M j, Y g:i:s A'),
        'name' => $name,
        'role' => roleLabel($r['actor_role'], $roleLabels, $r['staff_position']),
        'action' => actionLabel($r['action'], $actionLabels),
        'category' => categoryLabel($r['target_type'], $categoryLabels),
        'status' => statusOf($r['action']),
        'ip' => $showIp($r['ip_address']),
        'targetLabel' => $targetLabel,
        'target' => $targetText,
        'detail' => $detail,
        'detailNote' => $detailNote,
        'changes' => $changes,
    ]);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
// The page picks the page size (25, 50 or 100); anything else is kept between 1 and 100.
$pageSize = min(100, max(1, (int) ($_GET['pageSize'] ?? 10)));
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

[$where, $params, $whereAllRoles, $paramsAllRoles] = buildFilters($_GET, $actionLabels, $roleLabels, $categoryLabels, $seeFullIp);

$countSql = "SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$sql = "SELECT al.id, al.created_at, u.username, u.full_name, u.staff_position, al.actor_role, al.action, al.target_type, al.target_id, al.ip_address, st.first_name_enc, st.last_name_enc
        FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN students st ON st.user_id = al.actor_user_id
        WHERE $where
        ORDER BY al.created_at $sort
        LIMIT $pageSize OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = array_map(function ($r) use ($actionLabels, $roleLabels, $showIp) {
    return [
        'id' => (int) $r['id'],
        'timestamp' => (new DateTime($r['created_at']))->format('M j, Y g:i:s A'),
        'user' => actorLabel($r),
        'role' => roleLabel($r['actor_role'], $roleLabels, $r['staff_position']),
        'action' => actionLabel($r['action'], $actionLabels),
        'module' => $r['target_type'] ?? '-',
        'ip' => $showIp($r['ip_address']),
        'status' => statusOf($r['action']),
    ];
}, $stmt->fetchAll());

$modules = $pdo->query('SELECT DISTINCT target_type FROM audit_log WHERE target_type IS NOT NULL ORDER BY target_type')
    ->fetchAll(PDO::FETCH_COLUMN);
    $firstDate = $pdo->query('SELECT MIN(created_at)::date FROM audit_log')->fetchColumn() ?: null;

// Summary cards follow the same search/filters as the table, so they always describe the rows
// below it. Successful is Total minus Failed over that same set, so the two always add up to
// Total and the percentages stay meaningful. With no filters (a picked role aside), Users Active
// keeps its usual "last 7 days" meaning; with filters, it counts the distinct users in the
// filtered rows.
$filtered = $where !== '1=1';
$activeLastWeek = $whereAllRoles === '1=1';
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) FILTER (WHERE " . FAILED_SQL . "), COUNT(DISTINCT al.actor_user_id)
     FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
     WHERE $where"
);
$summaryStmt->execute($params);
[$failedCount, $usersInResults] = array_map('intval', $summaryStmt->fetch(PDO::FETCH_NUM));
$successCount = $total - $failedCount;
$overallTotal = $filtered ? (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() : $total;
if ($activeLastWeek) {
    $activeStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT al.actor_user_id) FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
         WHERE al.created_at >= NOW() - INTERVAL '7 days' AND $where"
    );
    $activeStmt->execute($params);
    $usersActive = (int) $activeStmt->fetchColumn();
} else {
    $usersActive = $usersInResults;
}

// Card breakdowns by role. They follow every filter except the picked role, so picking a role keeps
// the other roles' numbers in view, and the picked role's number matches the card. Every group is
// always listed, in a fixed order (the page leaves "No account" off all but the Total card).
$breakdownStmt = $pdo->prepare(
    'SELECT ' . ROLE_BUCKET_SQL . ' AS bucket, COUNT(*) AS total,
            COUNT(*) FILTER (WHERE ' . FAILED_SQL . ") AS failed,
            COUNT(*) FILTER (WHERE al.action IN ('" . implode("', '", BLOCKED_ACTIONS) . "')) AS blocked,
            COUNT(DISTINCT al.actor_user_id) AS people
     FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
     WHERE $whereAllRoles GROUP BY bucket"
);
$breakdownStmt->execute($paramsAllRoles);
$byBucket = [];
foreach ($breakdownStmt->fetchAll() as $b) {
    $byBucket[$b['bucket']] = $b;
}
// Without filters, Users Active means the last 7 days, so its breakdown does too.
$peopleLastWeek = $activeLastWeek ? $pdo->query(
    'SELECT ' . ROLE_BUCKET_SQL . " AS bucket, COUNT(DISTINCT al.actor_user_id)
     FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
     WHERE al.created_at >= NOW() - INTERVAL '7 days' GROUP BY bucket"
)->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$breakdown = [];
foreach (ROLE_BUCKETS as $key => $label) {
    $b = $byBucket[$key] ?? ['total' => 0, 'failed' => 0, 'blocked' => 0, 'people' => 0];
    $breakdown[] = [
        'role' => $key,
        'label' => $label,
        'total' => (int) $b['total'],
        'successful' => (int) $b['total'] - (int) $b['failed'],
        'failed' => (int) $b['failed'],
        'blocked' => (int) $b['blocked'],
        'people' => $key === 'none' ? 0 : (int) ($activeLastWeek ? ($peopleLastWeek[$key] ?? 0) : $b['people']),
    ];
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
        'activeLastWeek' => $activeLastWeek,
        'overallTotal' => $overallTotal,
        'breakdown' => $breakdown,
    ],
    // For the note under the table: what is really kept, and whether this person can change it.
    'retention' => AuditRetention::settings($pdo) + ['canChange' => $user['role'] === 'admin'],
    // "Check log" (api/audit-log-verify.php) is for administrators.
    'canCheckLog' => $user['role'] === 'admin',
    'ipMasked' => !$seeFullIp,
]);
