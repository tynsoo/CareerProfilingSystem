<?php
/**
 * Audit log PDF export (lib/PdfWriter.php, lib/AuditLogPdf.php): checks the file structure, the
 * text encoding and the report layout. No database needed.
 *
 *   php tests/pdf_export_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every name, IP address and reference below is made up to check the
 * software (IPs are from the 192.0.2.x documentation range). None of it is real audit data.
 */
require_once __DIR__ . '/../lib/AuditLogPdf.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

/** @return array<int,string> every page's drawing commands, decompressed */
function pageStreams(string $pdf): array
{
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    return array_map(fn($s) => (string) gzuncompress($s), $m[1]);
}

/** True when every xref entry points at the start of its "N 0 obj". */
function xrefIsValid(string $pdf): bool
{
    if (!preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m) || substr($pdf, (int) $m[1], 4) !== 'xref') {
        return false;
    }
    if (!preg_match('/xref\n0 (\d+)\n((?:\d{10} \d{5} [nf] \n)+)/', $pdf, $x)) {
        return false;
    }
    $entries = str_split($x[2], 20);
    if (count($entries) !== (int) $x[1]) {
        return false;
    }
    foreach ($entries as $id => $entry) {
        if ($id === 0) {
            continue;
        }
        if (substr($pdf, (int) substr($entry, 0, 10), strlen("$id 0 obj")) !== "$id 0 obj") {
            return false;
        }
    }
    return true;
}

function sampleRows(int $count): array
{
    $names = ['Dela Cruz, Juan P.', 'Peña, José Ñ', 'Unregistered user', 'Santos, Maria Clara Isabel Antonette Villanueva-Reyes'];
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'Oct 17, 2026 1:' . str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT) . ':00 PM',
            $names[$i % count($names)],
            $i % 3 === 0 ? 'Student' : 'Guidance Counselor',
            $i % 5 === 0 ? 'Failed sign-in' : 'Signed in',
            'User Accounts',
            '192.0.2.' . ($i % 250),
            $i % 5 === 0 ? 'Failed' : 'Success',
        ];
    }
    return $rows;
}

$meta = [
    'exportedBy' => 'Test, Staff A. (Guidance Counselor)',
    'exportedAt' => 'Oct 17, 2026 3:05 PM',
    'reference' => 'EXP-20261017-TEST',
    'filters' => 'Dates: Any time | Status: All | Category: All | Search: none',
];

echo "=== the file is a real PDF ===\n";
$w = new PdfWriter();
$w->addPage();
$w->text(36, 50, 'Cruz (Sons) \\ x', 10);
$w->text(36, 70, "Peña, José\nnext line 😀", 10);
$w->addPage();
$w->rect(36, 100, 200, 20, [0.9, 0.9, 0.9]);
$w->line(36, 130, 236, 130, [0, 0, 0]);
$simple = $w->output();
check('starts with %PDF-1.4', str_starts_with($simple, '%PDF-1.4'));
check('ends with %%EOF', str_ends_with($simple, "%%EOF\n"));
check('every xref offset points at its object', xrefIsValid($simple));
check('page tree counts 2 pages', str_contains($simple, '/Count 2'));
check('Letter landscape page size (792 x 612)', str_contains($simple, '/MediaBox [0 0 792 612]'));

echo "\n=== text ===\n";
$streams = pageStreams($simple);
check('two page streams that decompress', count($streams) === 2 && $streams[0] !== '');
check('( ) and \\ are escaped', str_contains($streams[0], '(Cruz \\(Sons\\) \\\\ x)'));
check('"Peña, José" is written as Windows-1252 bytes', str_contains($streams[0], "Pe\xF1a, Jos\xE9"));
check('a line break becomes a space, an emoji becomes "?"', str_contains($streams[0], 'next line ?'));
check('width of "WWW" at 10 pt is 28.32', abs($w->textWidth('WWW', 10) - 28.32) < 0.001);
$long = 'Santos, Maria Clara Isabel Antonette Villanueva-Reyes';
$cut = $w->fit($long, 80, 8.5);
check('long text is cut and ends with "..."', str_ends_with($cut, '...') && strlen($cut) < strlen($long));
check('cut text fits the width', $w->textWidth($cut, 8.5) <= 80);
check('short text is left as is', $w->fit('Signed in', 80, 8.5) === 'Signed in');

echo "\n=== the audit report ===\n";
$perPage = AuditLogPdf::rowsPerPage();
check("$perPage rows fit on a page (at least 20)", $perPage >= 20);
$rowCount = $perPage * 2 + 2;
$report = AuditLogPdf::build(sampleRows($rowCount), $meta);
$pages = pageStreams($report);
check("$rowCount rows make 3 pages", count($pages) === 3 && str_contains($report, '/Count 3'));
check('xref is valid', xrefIsValid($report));
$everyPage = true;
foreach ($pages as $i => $page) {
    $n = $i + 1;
    $everyPage = $everyPage && str_contains($page, "(Page $n of 3)") && str_contains($page, 'EXP-20261017-TEST')
        && str_contains($page, 'CONFIDENTIAL') && str_contains($page, '(Timestamp)');
}
check('every page has its page number, the reference, the CONFIDENTIAL line and the table header', $everyPage);
check('header shows who exported it and the activity count', str_contains($pages[0], 'Exported by Test, Staff A. \\(Guidance Counselor\\)')
    && str_contains($pages[0], 'Activities: ' . $rowCount));
check('Failed status is drawn in red', str_contains($pages[0], '0.8 0.11 0.14 rg') && str_contains($pages[0], '(Failed)'));
check('"Success" fits its column (not cut)', str_contains($pages[0], '(Success)'));
check('timestamps are not cut', str_contains(implode('', $pages), '(Oct 17, 2026 1:59:00 PM)'));
check('the very long name is cut in the table', str_contains($pages[0], 'Santos, Maria') && !str_contains($pages[0], 'Villanueva-Reyes'));
check('the title has the reference', str_contains($report, '/Title (ProfilePath Audit Log EXP-20261017-TEST)'));

$empty = AuditLogPdf::build([], $meta);
$emptyPages = pageStreams($empty);
check('no rows still makes one page', count($emptyPages) === 1 && str_contains($empty, '/Count 1'));
check('that page says nothing matched', str_contains($emptyPages[0], 'No activities match these filters.'));
check('and shows "Activities: 0" and "Page 1 of 1"', str_contains($emptyPages[0], 'Activities: 0') && str_contains($emptyPages[0], '(Page 1 of 1)'));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
