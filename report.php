<?php
declare(strict_types=1);

/*
 * Pembuat berkas laporan (PDF & Excel/CSV).
 * Dipisah dari laporan.php agar dapat diuji langsung dari CLI.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pdf.php';

/* ================================================================== */
/* Fungsi pembuat berkas                                              */
/* ================================================================== */

function kirim_file(string $nama, string $mime, string $isi): void
{
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Content-Length: ' . strlen($isi));
    header('Cache-Control: no-store, max-age=0');
    echo $isi;
    exit;
}

function kirim_csv(string $nama, array $header, array $rows): void
{
    $pack = static function (array $cols): string {
        $out = [];
        foreach ($cols as $c) {
            $s = (string) $c;
            $out[] = (strpos($s, ';') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false)
                ? '"' . str_replace('"', '""', $s) . '"' : $s;
        }
        return implode(';', $out) . "\r\n";
    };
    $isi = "\xEF\xBB\xBF" . $pack($header);
    foreach ($rows as $r) {
        $isi .= $pack($r);
    }
    kirim_file($nama, 'text/csv; charset=utf-8', $isi);
}

/** Berkas .xlsx asli (ZIP + XML). Mengembalikan null bila ekstensi zip tidak tersedia. */
function xlsx_bytes(string $sheet, array $header, array $rows): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $kolom = static function (int $i): string {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    };

    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
    foreach (array_keys($header) as $i) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($i === 0 ? 12 : ($i < 6 ? 12 : 18)) . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    $baris = 1;
    $xml .= '<row r="' . $baris . '">';
    foreach (array_values($header) as $i => $judul) {
        $xml .= '<c r="' . $kolom($i) . $baris . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($judul) . '</t></is></c>';
    }
    $xml .= '</row>';

    foreach ($rows as $r) {
        $baris++;
        $xml .= '<row r="' . $baris . '">';
        foreach (array_values($r) as $i => $v) {
            $ref = $kolom($i) . $baris;
            if (is_int($v) || is_float($v)) {
                $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc((string) $v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        return null;
    }
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $esc($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** Laporan PDF (A4, tabel berlanjut ke halaman berikutnya + ringkasan di akhir). */
function laporan_pdf(array $rows, array $meta): string
{
    $pdf = new SimplePdf(595.28, 841.89);
    $mx  = 28.0;
    $lebar = $pdf->width() - $mx * 2;

    $kol = [
        ['No', 22, 'left'],
        ['Tanggal', 54, 'left'],
        ['Kode Tiket', 46, 'left'],
        ['Jam Ambil', 44, 'left'],
        ['Jam Siap', 42, 'left'],
        ['Jam Serah', 44, 'left'],
        ['Waktu Tunggu', 52, 'right'],
        ['Waktu Penyiapan', 56, 'right'],
        ['Status Tracking', 96, 'left'],
        ['Nama Pasien', 78, 'left'],
        ['No. RM', 40, 'left'],
        ['Catatan', 63, 'left'],
    ];
    $teal = [13, 148, 136];
    $abu  = [110, 120, 130];

    $halaman = 1;
    $gambarKepala = function (SimplePdf $pdf, int $halaman) use ($meta, $mx, $lebar, $teal, $abu): float {
        $y = 34.0;
        $pdf->text($mx, $y, (string) $meta['apotek'], 15, true, [15, 46, 42]);
        $y += 16;
        $pdf->text($mx, $y, (string) $meta['alamat'], 8, false, $abu);
        $y += 18;
        $pdf->rect($mx, $y - 11, $lebar, 30, $teal);
        $pdf->text($mx + 10, $y + 8, (string) $meta['judul'], 12, true, [255, 255, 255]);
        $pdf->text($mx + $lebar - 10, $y + 8, 'Periode: ' . $meta['periode'], 9, false, [255, 255, 255], 'right', 0);
        $y += 34;
        $ket = 'Kode: ' . ($meta['kodeFilter'] !== '' ? $meta['kodeFilter'] : 'semua kode')
             . '  ·  Total tiket diserahkan: ' . (int) $meta['ringkas']['total']
             . '  ·  Rata-rata tunggu: ' . durasi_format((int) $meta['ringkas']['rata_tunggu'])
             . '  ·  Rata-rata penyiapan: ' . durasi_format((int) $meta['ringkas']['rata_siap']);
        $pdf->text($mx, $y, $ket, 8, false, [60, 70, 80]);
        $y += 6;
        $pdf->text($mx + $lebar, $y, 'Dicetak: ' . date('d/m/Y H:i') . ' WITA  ·  Halaman ' . $halaman, 7.5, false, $abu, 'right', 0);
        $y += 12;
        return $y;
    };

    $pdf->addPage();
    $y = $gambarKepala($pdf, $halaman);

    $gambarHeaderTabel = function (float $y) use ($pdf, $kol, $mx, $teal): float {
        $pdf->rect($mx, $y, array_sum(array_column($kol, 1)), 16, [232, 250, 246]);
        $x = $mx;
        foreach ($kol as [$judul, $w, $align]) {
            $pdf->text($x + 3, $y + 11, (string) $judul, 8, true, [12, 80, 74], $align === 'right' ? 'right' : 'left', $w - 6);
            $x += $w;
        }
        return $y + 16;
    };

    $y = $gambarHeaderTabel($y);
    $no = 0;
    $batas = $pdf->height() - 52;

    foreach ($rows as $r) {
        if ($y > $batas) {
            $pdf->text($mx, $pdf->height() - 30, (string) $meta['apotek'] . ' — Laporan Antrian Apotek', 7.5, false, $abu);
            $halaman++;
            $pdf->addPage();
            $y = $gambarKepala($pdf, $halaman);
            $y = $gambarHeaderTabel($y);
        }
        $no++;
        $warnaBaris = ($no % 2 === 0) ? [250, 252, 252] : [255, 255, 255];
        $pdf->rect($mx, $y, array_sum(array_column($kol, 1)), 15, $warnaBaris, [225, 232, 232], 0.4);
        $nilai = [
            (string) $no,
            (string) $r['tanggal'],
            (string) $r['kode_tiket'],
            (string) $r['jam_ambil'],
            (string) $r['jam_siap'],
            (string) $r['jam_panggil'],
            (string) $r['tunggu_format'],
            (string) $r['siap_format'],
            (string) $r['status_label'],
            (string) ($r['nama_pasien'] !== '' ? $r['nama_pasien'] : '-'),
            (string) ($r['no_rm'] !== '' ? $r['no_rm'] : '-'),
            (string) ($r['keterangan'] !== '' ? $r['keterangan'] : '-'),
        ];
        $x = $mx;
        foreach ($kol as $i => [$judul, $w, $align]) {
            $t = $pdf->fit($nilai[$i], 7.6, $w - 6);
            $pdf->text($x + 3, $y + 10, $t, 7.6, false, [25, 40, 45], $align === 'right' ? 'right' : 'left', $w - 6);
            $x += $w;
        }
        $y += 15;
    }
    if (!$rows) {
        $pdf->text($mx + 6, $y + 14, 'Belum ada tiket yang diserahkan pada periode ini.', 9, false, [90, 100, 110]);
        $y += 26;
    }

    /* Ringkasan per kode di akhir laporan */
    if (!empty($meta['ringkas']['per_kode'])) {
        $y += 18;
        if ($y > $batas) {
            $halaman++;
            $pdf->addPage();
            $y = $gambarKepala($pdf, $halaman);
        }
        $pdf->text($mx, $y, 'Ringkasan per Kode Antrian', 10, true, [15, 46, 42]);
        $y += 8;
        $pdf->rect($mx, $y, 320, 15, [232, 250, 246]);
        $pdf->text($mx + 4, $y + 10.5, 'Kode', 8, true, [12, 80, 74]);
        $pdf->text($mx + 70, $y + 10.5, 'Layanan', 8, true, [12, 80, 74]);
        $pdf->text($mx + 210, $y + 10.5, 'Jumlah', 8, true, [12, 80, 74]);
        $pdf->text($mx + 300, $y + 10.5, 'Rata Tunggu', 8, true, [12, 80, 74], 'right', 0);
        $y += 15;
        foreach ($meta['ringkas']['per_kode'] as $p) {
            $pdf->line($mx, $y, $mx + 320, $y, [225, 232, 232]);
            $pdf->text($mx + 4, $y + 10, (string) $p['kode'], 8);
            $pdf->text($mx + 70, $y + 10, $pdf->fit((string) ($meta['layanan'][$p['kode']] ?? ('Loket ' . $p['kode'])), 8, 130), 8);
            $pdf->text($mx + 210, $y + 10, (string) $p['jumlah'], 8);
            $pdf->text($mx + 320, $y + 10, durasi_format((int) $p['rata_rata']), 8, false, [0, 0, 0], 'right', 0);
            $y += 14;
        }
    }

    $pdf->text($mx, $pdf->height() - 30, (string) $meta['apotek'] . ' — Laporan Antrian Apotek (' . $meta['periode'] . ')', 7.5, false, [110, 120, 130]);
    return $pdf->output();
}
