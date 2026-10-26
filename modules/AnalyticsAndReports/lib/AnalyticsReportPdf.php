<?php

require_once __DIR__ . '/../../../shared/lib/PdfWriter.php';

/**
 * The Analytics Report as a PDF (api/analytics-report.php): exported from the report preview on the
 * Analytics & Report page, and attached to the email that Send Analytics Report sends. It is drawn from
 * AnalyticsXlsx::summaryReport(), the same tables the preview shows, so it needs no database and holds
 * only totals and percentages (no student names, Student Numbers or answers).
 *
 * Letter landscape (shared/lib/PdfWriter.php). Page 1 opens with the report header and the key figures, then
 * come bar charts drawn with boxes and lines (no pictures, so they stay sharp and readable in print),
 * then the tables. A chart or table that does not fit continues on the next page with its title and
 * column headings repeated. Every page ends with the generation date and "Page X of Y".
 *
 * Pages are planned first and drawn after, so the page count is known when the footers are written.
 */
class AnalyticsReportPdf
{
    private const M = 40.0;                               // page margin
    private const CW = PdfWriter::WIDTH - 2 * self::M;    // 712 points between the margins
    private const TOP_FIRST = 136.0;                      // content starts here on page 1, under the report header
    private const TOP = 64.0;                             // and here on the next pages, under the running header
    private const BOTTOM = 556.0;                         // nothing is drawn lower; the footer sits below
    private const GAP = 18.0;                             // space after a chart or table

    private const NAVY = [0.059, 0.165, 0.420];           // #0f2a6b, the app's button navy
    private const INK = [0.12, 0.13, 0.15];
    private const GREY_TEXT = [0.38, 0.41, 0.46];
    private const GREY_FILL = [0.95, 0.96, 0.97];
    private const CARD_FILL = [0.95, 0.96, 0.99];
    private const TRACK = [0.91, 0.92, 0.94];
    private const LINE = [0.86, 0.87, 0.89];
    private const BLUE = [0.098, 0.259, 0.482];           // #19427b, the page's chart blue
    private const ORANGE = [0.859, 0.439, 0.125];         // #db7020, the page's RIASEC orange
    private const STRANDS = [                             // the SHS strand colours (js/strand-colors.js), as on the page
        'STEM' => [0.863, 0.149, 0.149], 'ABM' => [0.859, 0.153, 0.467],     // #DC2626 red, #DB2777 pink
        'ICT' => [0.086, 0.639, 0.290], 'HUMSS' => [0.145, 0.388, 0.922],    // #16A34A green, #2563EB blue
    ];

    private PdfWriter $pdf;
    /** @var array<int,array<int,callable>> per page, the drawing steps */
    private array $pages = [[]];
    private float $y = self::TOP_FIRST;

    /** @param array<string,mixed> $report the result of AnalyticsXlsx::summaryReport() */
    private function __construct(private array $report)
    {
        $this->pdf = new PdfWriter();
    }

    /** ProfilePath_Analytics_Report_YYYY-MM-DD.pdf */
    public static function fileName(?int $time = null): string
    {
        return 'ProfilePath_Analytics_Report_' . date('Y-m-d', $time ?? time()) . '.pdf';
    }

    /**
     * @param array<string,mixed> $report the result of AnalyticsXlsx::summaryReport()
     * @return string the bytes of the .pdf file
     */
    public static function build(array $report): string
    {
        $doc = new self($report);
        $doc->layout();
        return $doc->render();
    }

    // ------------------------------------------------------------------
    // Content, in reading order
    // ------------------------------------------------------------------

    private function layout(): void
    {
        [, $participation, $bySection, $riasec, $programs, $strands, $byYear] = $this->report['sections'];
        $p = $participation['rows'];

        // Key figures: the same numbers as the Assessment participation table, as cards.
        $this->heading('Summary', 'Out of the students expected this academic year: registered students plus students on the class roster who have not registered yet.', 74);
        $cards = [
            ['STUDENTS EXPECTED', self::int($p[0][1]), (string) ($this->info('Academic year') !== '' ? 'Academic Year ' . $this->info('Academic year') : '')],
            ['REGISTERED', self::int($p[1][1]), self::pct($p[1][2]) . ' of expected'],
            ['COMPLETED ASSESSMENT', self::int($p[3][1]), self::pct($p[3][2]) . ' of expected'],
            ['COMPLETED WORKSHEET', self::int($p[5][1]), self::pct($p[5][2]) . ' of expected'],
            ['HIGH-CONFIDENCE MATCH', self::int($p[6][1]), self::pct($p[6][2]) . ' of expected'],
        ];
        $this->place(74 + self::GAP, function (PdfWriter $pdf, float $top) use ($cards) {
            $w = (self::CW - 4 * 12) / 5;
            foreach ($cards as $i => [$label, $value, $sub]) {
                $x = self::M + $i * ($w + 12);
                $pdf->rect($x, $top, $w, 74, self::CARD_FILL);
                $pdf->rect($x, $top, 3, 74, self::NAVY);
                $pdf->text($x + 12, $top + 18, $pdf->fit($label, $w - 18, 7.5, true), 7.5, true, self::GREY_TEXT);
                $pdf->text($x + 12, $top + 46, $value, 22, true, self::NAVY);
                $pdf->text($x + 12, $top + 63, $pdf->fit($sub, $w - 18, 8), 8, false, self::GREY_TEXT);
            }
        });

        // Charts.
        $this->bars(
            'Assessment completion by section',
            'Percent of the expected students in each section who completed the RIASEC assessment (bars run from 0 to 100%).',
            array_map(fn($r) => [$r[0], (float) $r[5], self::pct($r[5]) . '  (' . self::int($r[3]) . ' of ' . self::int($r[1]) . ')', self::NAVY], $bySection['rows']),
            100.0, 150.0, $bySection['note'] ?? ''
        );
        $riasecBars = array_map(fn($r) => [$r[0] . ' (' . $r[1] . ')', (float) $r[2], number_format((float) $r[2], 1), self::ORANGE], $riasec['rows']);
        $strandBars = array_map(fn($r) => [$r[0], (float) $r[1], self::int($r[1]) . '  (' . self::pct($r[2]) . ')', self::STRANDS[$r[0]] ?? self::BLUE], $strands['rows']);
        $this->barsPair(
            ['Average RIASEC scores', 'Students who completed the assessment; scores run from 10 to 50.', $riasecBars, 50.0, 110.0, $riasec['note'] ?? ''],
            ['Registered students by strand', 'Registered student accounts in ProfilePath.', $strandBars, self::maxOf($strandBars), 70.0, $strands['note'] ?? '']
        );
        $this->bars(
            'Top 5 programs most similar to students\' RIASEC profiles',
            'Students whose closest programs (Content-Based Filtering) include each program; a student can count toward more than one.',
            array_map(fn($r) => [$r[0] . '. ' . $r[1], (float) $r[2], self::int($r[2]) . ' ' . ($r[2] === 1 ? 'student' : 'students') . '  (' . self::pct($r[3]) . ')', self::BLUE], $programs['rows']),
            self::maxOf(array_map(fn($r) => [0, (float) $r[2]], $programs['rows'])), 300.0, $programs['note'] ?? ''
        );

        // Tables (the same tables as the report preview).
        $this->table($participation, [470, 121, 121]);
        $this->table($bySection, [182, 106, 106, 106, 106, 106]);
        $this->table($riasec, [356, 156, 200]);
        $this->table($programs, [60, 382, 110, 160]);
        $this->table($strands, [356, 178, 178]);
        $this->table($byYear, [208, 168, 168, 168]);
    }

    /** One of the report information rows (AnalyticsXlsx::summaryReport section 0), by its label. */
    private function info(string $label): string
    {
        foreach ($this->report['sections'][0]['rows'] as [$k, $v]) {
            if ($k === $label) {
                return (string) $v;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Building blocks
    // ------------------------------------------------------------------

    /** A section title with a line under it and an optional one-line explanation; kept with what follows. */
    private function heading(string $title, string $subtitle = '', float $keepWith = 40.0): void
    {
        $h = $subtitle !== '' ? 40.0 : 26.0;
        if ($this->y + $h + $keepWith > self::BOTTOM) {
            $this->newPage();
        }
        $this->place($h, function (PdfWriter $pdf, float $top) use ($title, $subtitle) {
            $pdf->text(self::M, $top + 13, $pdf->fit($title, self::CW, 12, true), 12, true, self::INK);
            $pdf->line(self::M, $top + 19, self::M + self::CW, $top + 19, self::LINE, 0.75);
            if ($subtitle !== '') {
                $pdf->text(self::M, $top + 32, $pdf->fit($subtitle, self::CW, 8.5), 8.5, false, self::GREY_TEXT);
            }
        });
    }

    /**
     * A horizontal bar chart across the page; long charts continue on the next page.
     *
     * @param array<int,array{0:string,1:float,2:string,3:array}> $rows label, value, value words, bar colour
     */
    private function bars(string $title, string $subtitle, array $rows, float $max, float $labelW, string $emptyNote): void
    {
        if (!$rows) {
            $this->heading($title, $subtitle, 24);
            $this->note($emptyNote !== '' ? $emptyNote : 'No data yet.');
            $this->y += self::GAP;
            return;
        }
        $heights = array_map(fn($r) => $this->barRowHeight($r[0], $labelW - 8), $rows);
        $this->flow(
            40.0,
            function (PdfWriter $pdf, float $top, bool $continued) use ($title, $subtitle) {
                $pdf->text(self::M, $top + 13, $pdf->fit($title . ($continued ? ' (continued)' : ''), self::CW, 12, true), 12, true, self::INK);
                $pdf->line(self::M, $top + 19, self::M + self::CW, $top + 19, self::LINE, 0.75);
                $pdf->text(self::M, $top + 32, $pdf->fit($subtitle, self::CW, 8.5), 8.5, false, self::GREY_TEXT);
            },
            $heights,
            fn(PdfWriter $pdf, int $i, float $top) => $this->drawBar($pdf, self::M, self::CW, $labelW, $rows[$i], $max, $top, $heights[$i])
        );
        $this->y += self::GAP;
    }

    /**
     * Two small bar charts side by side (they never need more than part of a page).
     *
     * @param array{0:string,1:string,2:array,3:float,4:float,5:string} $left  title, subtitle, rows, max, label width, empty note
     * @param array{0:string,1:string,2:array,3:float,4:float,5:string} $right
     */
    private function barsPair(array $left, array $right): void
    {
        $w = (self::CW - 24) / 2;
        $height = function (array $c) use ($w) {
            $rows = $c[2] ?: [['', 0.0, '', self::BLUE]];
            return 44 + array_sum(array_map(fn($r) => $this->barRowHeight($r[0], $c[4] - 8), $rows));
        };
        $h = max($height($left), $height($right));
        if ($this->y + $h > self::BOTTOM) {
            $this->newPage();
        }
        $this->place($h + self::GAP, function (PdfWriter $pdf, float $top) use ($left, $right, $w) {
            foreach ([[$left, self::M], [$right, self::M + $w + 24]] as [[$title, $subtitle, $rows, $max, $labelW, $empty], $x]) {
                $pdf->text($x, $top + 13, $pdf->fit($title, $w, 12, true), 12, true, self::INK);
                $pdf->line($x, $top + 19, $x + $w, $top + 19, self::LINE, 0.75);
                $pdf->text($x, $top + 32, $pdf->fit($subtitle, $w, 8.5), 8.5, false, self::GREY_TEXT);
                $y = $top + 44;
                if (!$rows) {
                    $pdf->text($x, $y + 12, $pdf->fit($empty !== '' ? $empty : 'No data yet.', $w, 8.5), 8.5, false, self::GREY_TEXT);
                    continue;
                }
                foreach ($rows as $r) {
                    $rh = $this->barRowHeight($r[0], $labelW - 8);
                    $this->drawBar($pdf, $x, $w, $labelW, $r, $max, $y, $rh);
                    $y += $rh;
                }
            }
        });
    }

    private function barRowHeight(string $label, float $labelW): float
    {
        return max(20.0, count($this->wrap($label, $labelW, 8.5, 2)) * 11 + 9);
    }

    /** One bar: its label (up to two lines) at the left, a grey track, the bar, and its value words after the track. */
    private function drawBar(PdfWriter $pdf, float $x, float $w, float $labelW, array $row, float $max, float $top, float $h): void
    {
        [$label, $value, $words, $color] = $row;
        $valueW = 120.0;
        $trackX = $x + $labelW;
        $trackW = $w - $labelW - $valueW;
        $mid = $top + $h / 2;
        $lines = $this->wrap($label, $labelW - 8, 8.5, 2);
        $first = $mid + 3 - (count($lines) - 1) * 5.5;
        foreach ($lines as $i => $line) {
            $pdf->text($x, $first + $i * 11, $line, 8.5, false, self::INK);
        }
        $pdf->rect($trackX, $mid - 5, $trackW, 10, self::TRACK);
        $len = $max > 0 ? max(0.0, min(1.0, $value / $max)) * $trackW : 0.0;
        if ($len > 0) {
            $pdf->rect($trackX, $mid - 5, max($len, 1.5), 10, $color);
        }
        $pdf->text($trackX + $trackW + 8, $mid + 3, $pdf->fit($words, $valueW - 8, 8.5, true), 8.5, true, self::INK);
    }

    /**
     * A table with a grey heading row; long tables continue on the next page with the headings repeated.
     * Text cells wrap instead of being cut; numbers are right-aligned.
     *
     * @param array{title:string,columns:array<int,string>,rows:array,note?:string} $section
     * @param array<int,float> $widths column widths in points (they add up to the content width)
     */
    private function table(array $section, array $widths): void
    {
        $size = 8.5;
        $headLines = array_map(fn($c, $w) => $this->wrap($c, $w - 12, $size, 3), $section['columns'], $widths);
        $headRowH = max(array_map('count', $headLines)) * 10.5 + 10;
        $numeric = array_map(fn($v) => is_int($v) || is_float($v), $section['rows'][0] ?? []);
        $isPercent = array_map(fn($c) => str_contains($c, '(%)'), $section['columns']);
        $cells = [];
        $heights = [];
        foreach ($section['rows'] as $r) {
            $row = [];
            foreach ($r as $c => $v) {
                $text = is_int($v) ? self::int($v) : (is_float($v) ? number_format($v, 1) : (string) $v);
                $row[] = ($numeric[$c] ?? false) ? [$text] : $this->wrap($text, $widths[$c] - 12, $size, 4);
            }
            $cells[] = $row;
            $heights[] = max(array_map('count', $row)) * 11 + 9;
        }
        if (!$cells) {
            $this->heading($section['title'], '', 24);
            $this->note(($section['note'] ?? '') !== '' ? $section['note'] : 'No data yet.');
            $this->y += self::GAP;
            return;
        }
        $titleH = 26.0;
        $this->flow(
            $titleH + $headRowH,
            function (PdfWriter $pdf, float $top, bool $continued) use ($section, $widths, $headLines, $headRowH, $titleH, $numeric, $size) {
                $pdf->text(self::M, $top + 13, $pdf->fit($section['title'] . ($continued ? ' (continued)' : ''), self::CW, 12, true), 12, true, self::INK);
                $pdf->rect(self::M, $top + $titleH, self::CW, $headRowH, self::GREY_FILL);
                $x = self::M;
                foreach ($headLines as $c => $lines) {
                    foreach ($lines as $i => $line) {
                        $tx = ($numeric[$c] ?? false) ? $x + $widths[$c] - 6 - $pdf->textWidth($line, $size, true) : $x + 6;
                        $pdf->text($tx, $top + $titleH + 14 + $i * 10.5, $line, $size, true, [0.25, 0.27, 0.30]);
                    }
                    $x += $widths[$c];
                }
            },
            $heights,
            function (PdfWriter $pdf, int $i, float $top) use ($cells, $widths, $numeric, $heights, $size) {
                $x = self::M;
                foreach ($cells[$i] as $c => $lines) {
                    foreach ($lines as $k => $line) {
                        $tx = ($numeric[$c] ?? false) ? $x + $widths[$c] - 6 - $pdf->textWidth($line, $size) : $x + 6;
                        $pdf->text($tx, $top + 14 + $k * 11, $line, $size, $c === 0, self::INK);
                    }
                    $x += $widths[$c];
                }
                $pdf->line(self::M, $top + $heights[$i], self::M + self::CW, $top + $heights[$i], self::LINE);
            }
        );
        if (($section['note'] ?? '') !== '') {
            $this->y += 4;
            $this->note($section['note']);
        }
        $this->y += self::GAP;
    }

    /** A grey note under a chart or table, wrapped over as many lines as it needs. */
    private function note(string $text): void
    {
        $lines = $this->wrap($text, self::CW, 8, 6);
        $this->place(count($lines) * 11 + 4, function (PdfWriter $pdf, float $top) use ($lines) {
            foreach ($lines as $i => $line) {
                $pdf->text(self::M, $top + 9 + $i * 11, $line, 8, false, self::GREY_TEXT);
            }
        });
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /** Reserves $h points at the current position (on a new page when it does not fit) for $draw. */
    private function place(float $h, callable $draw): void
    {
        if ($this->y + $h > self::BOTTOM && $this->pageHasContent()) {
            $this->newPage();
        }
        $top = $this->y;
        $this->pages[count($this->pages) - 1][] = fn(PdfWriter $pdf) => $draw($pdf, $top);
        $this->y += $h;
    }

    /**
     * A heading plus rows that may run over several pages: as many rows as fit go on each page, and every
     * page that holds rows starts with the heading again ($continued = true after the first).
     */
    private function flow(float $headH, callable $head, array $rowHeights, callable $row): void
    {
        $i = 0;
        $n = count($rowHeights);
        $continued = false;
        while ($i < $n) {
            // Start only where at least three rows (or all that are left) fit under the heading: no lone rows at a page's foot.
            $first = array_sum(array_slice($rowHeights, $i, 3));
            if ($this->y + $headH + $first > self::BOTTOM) {
                $this->newPage();
            }
            $this->place($headH, fn(PdfWriter $pdf, float $top) => $head($pdf, $top, $continued));
            while ($i < $n && $this->y + $rowHeights[$i] <= self::BOTTOM) {
                $k = $i;
                $this->place($rowHeights[$i], fn(PdfWriter $pdf, float $top) => $row($pdf, $k, $top));
                $i++;
            }
            $continued = true;
        }
    }

    private function newPage(): void
    {
        $this->pages[] = [];
        $this->y = self::TOP;
    }

    private function pageHasContent(): bool
    {
        return $this->pages[count($this->pages) - 1] !== [];
    }

    private function render(): string
    {
        $pdf = $this->pdf;
        $pdf->setTitle('ProfilePath Analytics Report ' . $this->info('Academic year'));
        $total = count($this->pages);
        foreach ($this->pages as $n => $steps) {
            $pdf->addPage();
            $n === 0 ? $this->firstHeader($pdf) : $this->runningHeader($pdf);
            foreach ($steps as $step) {
                $step($pdf);
            }
            $this->footer($pdf, $n + 1, $total);
        }
        return $pdf->output();
    }

    private function firstHeader(PdfWriter $pdf): void
    {
        $right = PdfWriter::WIDTH - self::M;
        $pdf->rect(0, 0, PdfWriter::WIDTH, 6, self::NAVY);
        $pdf->text(self::M, 40, 'PROFILEPATH CAREER PROFILING SYSTEM', 9, true, self::NAVY);
        $pdf->text(self::M, 68, 'Analytics Report', 24, true, self::INK);
        $pdf->text(self::M, 86, $pdf->fit($this->info('School'), 380, 9.5), 9.5, false, self::GREY_TEXT);
        $details = [
            ['Academic year', $this->info('Academic year')],
            ['Students covered', $this->info('Students covered')],
            ['Generated', $this->info('Generated')],
        ];
        foreach ($details as $i => [$label, $value]) {
            $words = $pdf->fit($label . ': ' . $value, 300, 9);
            $pdf->text($right - $pdf->textWidth($words, 9), 44 + $i * 14, $words, 9, false, self::INK);
        }
        $pdf->line(self::M, 100, $right, 100, self::LINE, 0.75);
        $pdf->text(self::M, 116, 'Totals and percentages only. This report contains no student names, Student Numbers or assessment answers.', 8.5, false, self::GREY_TEXT);
    }

    private function runningHeader(PdfWriter $pdf): void
    {
        $right = PdfWriter::WIDTH - self::M;
        $pdf->rect(0, 0, PdfWriter::WIDTH, 4, self::NAVY);
        $pdf->text(self::M, 34, 'ProfilePath Career Profiling System  ·  Analytics Report', 9, true, self::NAVY);
        $scope = $pdf->fit($this->report['scope'], 330, 8.5);
        $pdf->text($right - $pdf->textWidth($scope, 8.5), 34, $scope, 8.5, false, self::GREY_TEXT);
        $pdf->line(self::M, 42, $right, 42, self::LINE, 0.75);
    }

    private function footer(PdfWriter $pdf, int $page, int $total): void
    {
        $right = PdfWriter::WIDTH - self::M;
        $pdf->line(self::M, 570, $right, 570, self::LINE, 0.75);
        $left = $pdf->fit('Generated ' . $this->info('Generated') . '  ·  ProfilePath Career Profiling System', 560, 7.5);
        $pdf->text(self::M, 584, $left, 7.5, false, self::GREY_TEXT);
        $pageText = 'Page ' . $page . ' of ' . $total;
        $pdf->text($right - $pdf->textWidth($pageText, 7.5), 584, $pageText, 7.5, false, self::GREY_TEXT);
    }

    // ------------------------------------------------------------------
    // Text helpers
    // ------------------------------------------------------------------

    /**
     * Splits text into lines no wider than $width. Past $maxLines the last line ends in "...", and a single
     * word longer than a line is cut the same way (PdfWriter::fit).
     *
     * @return array<int,string>
     */
    private function wrap(string $text, float $width, float $size, int $maxLines, bool $bold = false): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->pdf->textWidth($try, $size, $bold) <= $width) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
        }
        if ($line !== '' || !$lines) {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines) {
            $kept = array_slice($lines, 0, $maxLines);
            $kept[$maxLines - 1] = $this->pdf->fit($kept[$maxLines - 1] . ' ' . implode(' ', array_slice($lines, $maxLines)), $width, $size, $bold);
            $lines = $kept;
        }
        return array_map(fn($l) => $this->pdf->fit($l, $width, $size, $bold), $lines);
    }

    private static function int(int|float $n): string
    {
        return number_format((float) $n);
    }

    private static function pct(int|float $n): string
    {
        return number_format((float) $n, 1) . '%';
    }

    /** @param array<int,array> $rows bar rows; the largest value, at least 1 so an all-zero chart still draws */
    private static function maxOf(array $rows): float
    {
        return max(1.0, ...array_map(fn($r) => (float) $r[1], $rows ?: [[0, 0.0]]));
    }
}
