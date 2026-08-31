<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Same gate as the upload it's meant to prepare for (api/roster-upload.php).
Rbac::requireAccess('rac', 'full');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="roster-template.csv"');
$out = fopen('php://output', 'w');
// Column names and order match api/roster-upload.php's expected header row
// exactly (it does case-insensitive matching against these labels), plus
// one example row so it's obvious what belongs in each column.
fputcsv($out, ['School ID', 'First Name', 'Last Name', 'Strand', 'Section'], escape: '\\');
fputcsv($out, ['2026-00001', 'Juan', 'Dela Cruz', 'STEM', 'S1114'], escape: '\\');
fclose($out);
