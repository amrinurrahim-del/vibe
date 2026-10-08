<?php
declare(strict_types=1);

/*
 * Penulis PDF sederhana (tanpa pustaka luar).
 * Cukup untuk laporan tabel: font inti Helvetica, teks, garis, dan kotak.
 * Ukuran kertas dinyatakan dalam point (1 pt = 1/72 inci).
 */

final class SimplePdf
{
    private float $w;
    private float $h;
    private array $pages = [];      // tiap halaman: string stream
    private int $current = -1;

    /* Lebar karakter Helvetica (per 1000 pt) untuk pengukuran teks. */
    private const W_REGULAR = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];
    private const W_BOLD = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
        975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
        333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
        611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,
    ];

    public function __construct(float $width = 595.28, float $height = 841.89)
    {
        $this->w = $width;
        $this->h = $height;
    }

    public function width(): float
    {
        return $this->w;
    }

    public function height(): float
    {
        return $this->h;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /* ---------------- pengukuran & pengamanan teks ---------------- */

    public function widthOf(string $text, float $size, bool $bold = false): float
    {
        $tbl = $bold ? self::W_BOLD : self::W_REGULAR;
        $total = 0.0;
        $s = $this->enc($text);
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($s[$i]);
            if ($o >= 32 && $o <= 126) {
                $total += $tbl[$o - 32];
            } elseif ($o === 10) {
                /* baris baru: abaikan */
            } else {
                $total += 500;
            }
        }
        return $total * $size / 1000.0;
    }

    /** Potong teks agar muat dalam lebar tertentu (ditambahi … bila terpotong). */
    public function fit(string $text, float $size, float $maxWidth, bool $bold = false): string
    {
        if ($this->widthOf($text, $size, $bold) <= $maxWidth) {
            return $text;
        }
        $out = '';
        $s   = $this->enc($text);
        $n   = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $coba = $out . $s[$i];
            if ($this->widthOf($coba . '..', $size, $bold) > $maxWidth) {
                break;
            }
            $out = $coba;
        }
        return $out . '..';
    }

    /** Ubah UTF-8 → byte WinAnsi yang aman untuk font inti PDF. */
    private function enc(string $s): string
    {
        $s = str_replace(
            ["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99",
             "\xE2\x80\xA2", "\xE2\x80\xA6", "\xC2\xB0", "\xC2\xB1", "\xC2\xA9", "\xC2\xA0", "\xE2\x9C\x93",
             "\xC2\xB7", "\xE2\x86\x92", "\xE2\x86\x90"],
            ['-', '-', '"', '"', "'", "'", '-', '...', ' derajat ', '+/-', '(c)', ' ', 'v', '-', '->', '<-'],
            $s
        );
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($s[$i]);
            $out .= ($o <= 126) ? $s[$i] : '?';
        }
        return $out;
    }

    private function txt(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $this->enc($s));
    }

    /* ---------------- gambar ---------------- */

    /** Titik (0,0) ada di kiri-atas; y makin besar ke bawah. */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false,
                         array $rgb = [0, 0, 0], string $align = 'left', float $box = 0): void
    {
        if ($text === '') {
            return;
        }
        if ($align === 'right') {
            $x += ($box > 0 ? $box : 0) - $this->widthOf($text, $size, $bold);
        } elseif ($align === 'center') {
            $x += (($box > 0 ? $box : 0) - $this->widthOf($text, $size, $bold)) / 2;
        }
        $f = $bold ? '/F2' : '/F1';
        $this->pages[$this->current] .= sprintf(
            "BT %s %s %s rg %s %s Tf %.3F %.3F Td ( %s ) Tj ET\n",
            sprintf('%.3F', $rgb[0] / 255), sprintf('%.3F', $rgb[1] / 255), sprintf('%.3F', $rgb[2] / 255),
            $f, sprintf('%.2F', $size), $x, $this->h - $y, $this->txt($text)
        );
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill, ?array $stroke = null, float $lw = 0.6): void
    {
        $s = sprintf(
            "%s %s %s rg %.3F %.3F %.3F %.3F re f\n",
            sprintf('%.3F', $fill[0] / 255), sprintf('%.3F', $fill[1] / 255), sprintf('%.3F', $fill[2] / 255),
            $x, $this->h - $y - $h, $w, $h
        );
        if ($stroke !== null) {
            $s .= sprintf(
                "%s %s %s RG %.2F w %.3F %.3F %.3F %.3F re S\n",
                sprintf('%.3F', $stroke[0] / 255), sprintf('%.3F', $stroke[1] / 255), sprintf('%.3F', $stroke[2] / 255),
                $lw, $x, $this->h - $y - $h, $w, $h
            );
        }
        $this->pages[$this->current] .= $s;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [180, 180, 180], float $lw = 0.5): void
    {
        $this->pages[$this->current] .= sprintf(
            "%s %s %s RG %.2F w %.3F %.3F m %.3F %.3F l S\n",
            sprintf('%.3F', $rgb[0] / 255), sprintf('%.3F', $rgb[1] / 255), sprintf('%.3F', $rgb[2] / 255),
            $lw, $x1, $this->h - $y1, $x2, $this->h - $y2
        );
    }

    /* ---------------- keluaran ---------------- */

    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $n     = count($this->pages);
        $objs  = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $i => $stream) {
            $p = 5 + $i * 2;
            $c = 6 + $i * 2;
            $objs[$p] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . sprintf('%.2F %.2F', $this->w, $this->h) . '] '
                      . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $c . ' 0 R >>';
            $objs[$c] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        ksort($objs);

        $out      = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets  = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($out);
        $max     = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $off = $offsets[$i] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF\n";
        return $out;
    }
}
