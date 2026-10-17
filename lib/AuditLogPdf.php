<?php

require_once __DIR__ . '/PdfWriter.php';

/**
 * The audit log as a PDF (api/audit-log.php?export=1). Takes rows already labeled the way the page
 * shows them, so it needs no database and can be tested on its own (tests/pdf_export_test.php).
 *
 * Every page repeats who exported it, when, the filters, the reference code (also saved in the
 * audit log entry for this export, so a printed or forwarded copy can be traced back) and an RA
 * 10173 confidentiality line, because pages of a long report get separated.
 */
class AuditLogPdf
{
    private const MARGIN = 36.0;
    private const FONT = 8.5;
    private const ROW_H = 15.0;
    private const HEADER_TOP = 116.0;   // grey table header box
    private const HEADER_H = 18.0;
    private const FIRST_ROW = 147.0;    // baseline of the first row
    private const LAST_ROW = 572.0;     // lowest baseline a row may use (the footer sits below)
    private const FOOTER_Y = 590.0;

    private const RED = [0.80, 0.11, 0.14];
    private const GREY_TEXT = [0.42, 0.45, 0.50];
    private const GREY_FILL = [0.94, 0.95, 0.96];
    private const GREY_LINE = [0.88, 0.89, 0.91];

    /** Column titles and widths in points (they add up to the 720 points between the margins). */
    private const COLUMNS = [
        ['Timestamp', 120.0],
        ['Name', 140.0],
        ['Role', 104.0],
        ['Action', 136.0],
        ['Category', 84.0],
        ['IP Address', 88.0],
        ['Status', 48.0],
    ];

    public static function rowsPerPage(): int
    {
        return (int) floor((self::LAST_ROW - self::FIRST_ROW) / self::ROW_H) + 1;
    }

    /**
     * @param array<int,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}> $rows
     *        timestamp, name, role, action, category, IP address, status ("Success" or "Failed")
     * @param array{exportedBy:string,exportedAt:string,reference:string,filters:string} $meta
     * @return string the bytes of the .pdf file
     */
    public static function build(array $rows, array $meta): string
    {
        $pdf = new PdfWriter();
        $pdf->setTitle('ProfilePath Audit Log ' . $meta['reference']);
        $perPage = self::rowsPerPage();
        $chunks = $rows ? array_chunk($rows, $perPage) : [[]];
        $total = count($chunks);

        foreach ($chunks as $i => $chunk) {
            $pdf->addPage();
            self::pageHeader($pdf, $meta, count($rows));
            if (!$chunk) {
                $pdf->text(self::MARGIN + 4, self::FIRST_ROW, 'No activities match these filters.', self::FONT, false, self::GREY_TEXT);
            }
            $y = self::FIRST_ROW;
            foreach ($chunk as $row) {
                $x = self::MARGIN;
                foreach (self::COLUMNS as $c => [, $width]) {
                    $value = (string) ($row[$c] ?? '');
                    $failed = $c === 6 && $value === 'Failed';
                    $pdf->text($x + 4, $y, $pdf->fit($value, $width - 8, self::FONT), self::FONT, false, $failed ? self::RED : [0.12, 0.13, 0.15]);
                    $x += $width;
                }
                $pdf->line(self::MARGIN, $y + 5, PdfWriter::WIDTH - self::MARGIN, $y + 5, self::GREY_LINE);
                $y += self::ROW_H;
            }
            $pdf->text(self::MARGIN, self::FOOTER_Y, 'Reference ' . $meta['reference'] . '  |  Confidential', 8, false, self::GREY_TEXT);
            $pageText = 'Page ' . ($i + 1) . ' of ' . $total;
            $pdf->text(PdfWriter::WIDTH - self::MARGIN - $pdf->textWidth($pageText, 8), self::FOOTER_Y, $pageText, 8, false, self::GREY_TEXT);
        }
        return $pdf->output();
    }

    private static function pageHeader(PdfWriter $pdf, array $meta, int $count): void
    {
        $left = self::MARGIN;
        $wide = PdfWriter::WIDTH - 2 * self::MARGIN;
        $pdf->text($left, 50, 'ProfilePath Audit Log', 14, true);
        $pdf->text($left, 66, $pdf->fit('Exported by ' . $meta['exportedBy'] . ' on ' . $meta['exportedAt'], $wide, 9), 9);
        $pdf->text($left, 79, 'Reference: ' . $meta['reference'] . '    Activities: ' . number_format($count), 9, true);
        $pdf->text($left, 92, $pdf->fit('Filters: ' . $meta['filters'], $wide, 9), 9);
        $pdf->text($left, 106, 'CONFIDENTIAL: contains personal data protected by the Data Privacy Act of 2012 (RA 10173). '
            . 'For authorized guidance staff only. Do not share or post.', 8, true, self::RED);

        $pdf->rect($left, self::HEADER_TOP, $wide, self::HEADER_H, self::GREY_FILL);
        $x = $left;
        foreach (self::COLUMNS as [$title, $width]) {
            $pdf->text($x + 4, self::HEADER_TOP + 12, $title, self::FONT, true, [0.25, 0.27, 0.30]);
            $x += $width;
        }
    }
}
