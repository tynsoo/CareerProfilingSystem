<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
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
// for the actual computation — this endpoint and the Excel export
// (api/analytics-export-xlsx.php) both read from that single source so
// the two never disagree.
$strand = trim((string) ($_GET['strand'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));

// ?academicYear=2025-2026 looks back at a past school year (only years that exist, never a future one).
jsonResponse(AnalyticsReport::compute($pdo, $strand, $section, trim((string) ($_GET['academicYear'] ?? ''))));
