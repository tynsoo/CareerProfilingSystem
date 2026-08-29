<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AnalyticsReport.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('monitoring', 'full');
$pdo = Database::get();

// Optional ?strand=STEM and ?section= filters — narrow every metric to
// matching students, except strandDistribution/sectionDistribution (kept
// unfiltered so each stays useful as a population-wide breakdown
// regardless of which filter is selected). See AnalyticsReport::compute()
// for the actual computation — this endpoint and the emailed Principal's
// report (api/send-principal-report.php) both read from that single
// source so the two never disagree.
$strand = trim((string) ($_GET['strand'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));

jsonResponse(AnalyticsReport::compute($pdo, $strand, $section));
