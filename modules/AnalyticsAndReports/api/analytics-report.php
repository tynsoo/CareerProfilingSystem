<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/AnalyticsReport.php';
require_once __DIR__ . '/../lib/AnalyticsXlsx.php';
require_once __DIR__ . '/../lib/AnalyticsReportPdf.php';
require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';

// The Analytics Report (Analytics & Report page), built from the same numbers the page shows
// (AnalyticsReport::compute). Totals and shares only, no student details.
//   GET                 the report as tables, for the page's preview
//   GET ?format=pdf     Export as PDF: the report as a PDF file (modules/AnalyticsAndReports/lib/AnalyticsReportPdf.php)
//   POST {email, ...}   Send Analytics Report: builds the report again from the latest data and emails it, as the
//                       same PDF attached, to an address the staff member types in (the principal, say; no account needed)
// Strand, section and academic year follow the page's filters, so the report matches what is on screen.
//
// Previewing and exporting: anyone who can see the whole analytics page (Full on Student Monitoring).
// Sending: the administrator (Head of Guidance) and Guidance Counselors, not Guidance Facilitators.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = Rbac::requireAccess('monitoring', 'full');
} else {
    $user = Auth::requireLogin();
    if (!in_array(Rbac::roleKey($user), ['admin', 'counselor'], true)) {
        jsonResponse(['success' => false, 'error' => 'Only the administrator and Guidance Counselors can send the analytics report.'], 403);
    }
}
$pdo = Database::get();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : $_GET;

$strand = trim((string) ($input['strand'] ?? ''));
if ($strand !== '' && !in_array($strand, ['STEM', 'ABM', 'ICT', 'HUMSS'], true)) {
    $strand = '';
}
$section = mb_substr(trim((string) ($input['section'] ?? '')), 0, 40);
$academicYear = trim((string) ($input['academicYear'] ?? ''));

$report = AnalyticsXlsx::summaryReport(
    AnalyticsReport::compute($pdo, $strand, $section, $academicYear),
    date('F j, Y, g:i A') . ' (Philippine time)'
);
$fileName = AnalyticsReportPdf::fileName();

/** The report as PDF bytes, or null (logged) when it could not be made. */
function buildPdf(array $report): ?string
{
    try {
        return AnalyticsReportPdf::build($report);
    } catch (Throwable $e) {
        error_log('[analytics-report] PDF build failed: ' . $e->getMessage());
        return null;
    }
}

// ---- GET: the preview tables, or Export as PDF
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($input['format'] ?? '') !== 'pdf') {
        jsonResponse(['success' => true, 'fileName' => $fileName] + $report);
    }
    $pdf = buildPdf($report);
    if ($pdf === null) {
        AuditLogger::log($user['id'], $user['role'], 'export_analytics_pdf_failed', 'analytics', null, 'The PDF could not be made');
        jsonResponse(['success' => false, 'error' => 'The PDF report could not be made. Please try again.'], 500);
    }
    AuditLogger::log($user['id'], $user['role'], 'export_analytics_pdf', 'analytics', null, "$fileName ({$report['scope']})");
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// ---- POST: Send Analytics Report
$email = trim((string) ($input['email'] ?? ''));
if ($email === '') {
    jsonResponse(['success' => false, 'field' => 'email', 'error' => 'Enter the recipient\'s email address.'], 400);
}
if (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'field' => 'email', 'error' => 'Enter a valid email address, like name@school.edu.ph.'], 400);
}

// A second click, or sending the same report to the same person again within a minute, is almost always a mistake.
// Only a send that went through counts (hit() below), so a failed attempt can be retried straight away.
$sentKey = 'analytics-report-to:' . (int) $user['id'] . ':' . strtolower($email);
if (RateLimiter::isLimited($sentKey, 1, 1)) {
    jsonResponse(['success' => false, 'error' => 'This report was just sent to ' . $email . '. Wait a minute before sending it again.'], 429);
}
// Each send is an email from the school's account: keep it from being used to flood inboxes.
if (RateLimiter::tooMany('analytics-report:' . (int) $user['id'], 10, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many reports sent in the last hour. Please try again later.'], 429);
}

$pdf = buildPdf($report);
if ($pdf === null) {
    AuditLogger::log($user['id'], $user['role'], 'send_analytics_report_failed', 'analytics', null, "To $email: the PDF could not be made");
    jsonResponse(['success' => false, 'error' => 'The PDF report could not be made, so nothing was sent. Please try again.'], 500);
}

$html = '<p style="margin:0 0 12px 0;">Good day,</p>'
    . '<p style="margin:0 0 12px 0;">Please find attached the latest Analytics Summary Report from the ProfilePath Career Profiling System. '
    . 'The report provides an overview of student assessment participation and the career interest and career-related trends available in the system.</p>'
    . '<p style="margin:0;">Thank you.</p>';
$text = "Good day,\n\nPlease find attached the latest Analytics Summary Report from the ProfilePath Career Profiling System. "
    . "The report provides an overview of student assessment participation and the career interest and career-related trends available in the system.\n\nThank you.";

$sent = Mailer::send($email, $email, 'ProfilePath Analytics Summary Report', $html, $text, [['name' => $fileName, 'content' => $pdf]]);
if (!$sent) {
    AuditLogger::log($user['id'], $user['role'], 'send_analytics_report_failed', 'analytics', null, "To $email: the email service did not accept the message");
    jsonResponse(['success' => false, 'error' => 'The email service did not accept the report, so it was not sent. Check the email settings or try again in a moment.'], 502);
}
RateLimiter::hit($sentKey);
AuditLogger::log($user['id'], $user['role'], 'send_analytics_report', 'analytics', null, "Analytics Report ($fileName, {$report['scope']}) sent to $email");
jsonResponse(['success' => true, 'email' => $email, 'fileName' => $fileName, 'message' => 'The Analytics Report (PDF) was sent to ' . $email . '.']);
