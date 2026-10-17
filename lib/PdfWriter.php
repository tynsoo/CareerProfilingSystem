<?php

/**
 * Writes a small PDF 1.4 file with no library: text, filled boxes and lines on Letter landscape
 * pages, in the two built-in fonts every PDF reader has (Helvetica and Helvetica-Bold), so no font
 * is embedded. Text is converted from UTF-8 to Windows-1252 (the fonts' WinAnsi encoding), which
 * covers names like "Peña"; characters outside it print as "?". Page contents are compressed with
 * zlib, the same extension lib/XlsxWriter.php relies on.
 *
 * Coordinates are in points (1/72 inch) from the top-left corner of the page; for text, y is the
 * baseline. A PDF is not tamper proof: anyone with an editor can change one, so this is a
 * readable copy for people, not evidence on its own.
 */
class PdfWriter
{
    public const WIDTH = 792.0;   // Letter (short bond) landscape: 11 in
    public const HEIGHT = 612.0;  // 8.5 in

    // Character widths (1/1000 of the font size) for ASCII 32..126, from the standard Helvetica and
    // Helvetica-Bold font metrics. Used to cut text that would not fit a column.
    private const WIDTHS_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const WIDTHS_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];
    // Accented letters and symbols: a width on the wide side, so cut text never runs over.
    private const WIDTH_OTHER_REGULAR = 667;
    private const WIDTH_OTHER_BOLD = 722;

    /** @var array<int,string> drawing operators, one string per page */
    private array $pages = [];
    private string $title = '';

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** @param array{0:float,1:float,2:float} $rgb each 0..1 */
    public function text(float $x, float $y, string $utf8, float $size, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->draw(sprintf(
            "q %s rg BT /%s %s Tf %s %s Td (%s) Tj ET Q\n",
            self::rgb($rgb),
            $bold ? 'F2' : 'F1',
            self::num($size),
            self::num($x),
            self::num(self::HEIGHT - $y),
            self::escape(self::toWinAnsi($utf8))
        ));
    }

    /** Filled box; ($x, $y) is its top-left corner. */
    public function rect(float $x, float $y, float $w, float $h, array $fillRgb): void
    {
        $this->draw(sprintf(
            "q %s rg %s %s %s %s re f Q\n",
            self::rgb($fillRgb),
            self::num($x),
            self::num(self::HEIGHT - $y - $h),
            self::num($w),
            self::num($h)
        ));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width = 0.5): void
    {
        $this->draw(sprintf(
            "q %s RG %s w %s %s m %s %s l S Q\n",
            self::rgb($rgb),
            self::num($width),
            self::num($x1),
            self::num(self::HEIGHT - $y1),
            self::num($x2),
            self::num(self::HEIGHT - $y2)
        ));
    }

    /** Width of the text in points, as it will be printed. */
    public function textWidth(string $utf8, float $size, bool $bold = false): float
    {
        $table = $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
        $other = $bold ? self::WIDTH_OTHER_BOLD : self::WIDTH_OTHER_REGULAR;
        $units = 0;
        foreach (str_split(self::toWinAnsi($utf8)) as $ch) {
            $code = ord($ch);
            $units += ($code >= 32 && $code <= 126) ? $table[$code - 32] : $other;
        }
        return $units * $size / 1000;
    }

    /** The text, cut and ending in "..." if it is wider than $maxWidth. */
    public function fit(string $utf8, float $maxWidth, float $size, bool $bold = false): string
    {
        $utf8 = self::clean($utf8);
        if ($this->textWidth($utf8, $size, $bold) <= $maxWidth) {
            return $utf8;
        }
        for ($len = mb_strlen($utf8) - 1; $len > 0; $len--) {
            $cut = rtrim(mb_substr($utf8, 0, $len)) . '...';
            if ($this->textWidth($cut, $size, $bold) <= $maxWidth) {
                return $cut;
            }
        }
        return '...';
    }

    /** @return string the bytes of the finished .pdf file */
    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        // Objects: 1 catalog, 2 page tree, 3 and 4 fonts, 5 document info, then a page and its
        // contents for every page.
        $pageCount = count($this->pages);
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (6 + $i * 2) . ' 0 R';
        }
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => sprintf(
                '<< /Type /Pages /Kids [%s] /Count %d /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>',
                implode(' ', $kids),
                $pageCount,
                self::num(self::WIDTH),
                self::num(self::HEIGHT)
            ),
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => sprintf(
                '<< /Producer (ProfilePath) /Title (%s) /CreationDate (D:%s) >>',
                self::escape(self::toWinAnsi($this->title)),
                date('YmdHis')
            ),
        ];
        foreach ($this->pages as $i => $ops) {
            $pageId = 6 + $i * 2;
            $stream = gzcompress($ops, 6);
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /Contents %d 0 R >>', $pageId + 1);
            $objects[$pageId + 1] = sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($stream), $stream);
        }

        // The second line holds bytes above 127 so file tools treat the PDF as binary.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xrefAt = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xrefAt\n%%EOF\n";
        return $pdf;
    }

    private function draw(string $ops): void
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $this->pages[count($this->pages) - 1] .= $ops;
    }

    /** Valid UTF-8 with control characters (tabs, line breaks) turned into spaces. */
    private static function clean(string $utf8): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_scrub($utf8, 'UTF-8')) ?? '';
    }

    private static function toWinAnsi(string $utf8): string
    {
        return mb_convert_encoding(self::clean($utf8), 'Windows-1252', 'UTF-8');
    }

    /** Backslash and parentheses are special inside a PDF (string). */
    private static function escape(string $bytes): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }

    private static function rgb(array $rgb): string
    {
        return self::num($rgb[0]) . ' ' . self::num($rgb[1]) . ' ' . self::num($rgb[2]);
    }

    /** A number written the way PDF expects: a dot for decimals, no exponent, whatever the locale. */
    private static function num(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $n), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
