<?php
declare(strict_types=1);

/*
 * Pembuat gambar tiket (PNG) untuk dicetak melalui AGEN CETAK lokal.
 *
 * Kenapa gambar? Agen cetak di komputer apotek mengirim berkas ke printer tertentu
 * (bukan lewat dialog browser), dan untuk printer thermal yang paling pasti diterima
 * adalah berkas (PNG). Isinya sama dengan tiket HTML: kop apotek, nomor tiket besar,
 * keterangan, dan QR lacak obat — semuanya digambar di sini memakai GD.
 *
 * Lebar berkas dibuat 576 piksel (≈80 mm pada 203 dpi) supaya pas untuk printer thermal 80 mm.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/qr.php';

const TIKET_PNG_LEBAR = 576;      /* 80 mm @ 203 dpi */
const TIKET_PNG_MARGIN = 24;

/** Font TrueType yang tersedia di server (dipilih yang ada). */
function tiket_font(bool $tebal = false): string
{
    $kandidat = $tebal
        ? [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]
        : [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];
    foreach ($kandidat as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}

/** Lebar teks pada ukuran font tertentu (px). */
function tiket_lebar(string $teks, float $ukuran, bool $tebal = false): float
{
    $font = tiket_font($tebal);
    if ($font === '' || $teks === '') {
        return strlen($teks) * $ukuran * 0.55;
    }
    $kotak = imagettfbbox($ukuran, 0, $font, $teks);
    return $kotak ? (float) abs($kotak[2] - $kotak[0]) : strlen($teks) * $ukuran * 0.55;
}

/** Pecah teks agar muat dalam lebar tertentu (mengembalikan baris). */
function tiket_bungkus(string $teks, float $ukuran, float $lebar, bool $tebal = false): array
{
    $teks = trim(preg_replace('/\s+/', ' ', $teks) ?? '');
    if ($teks === '') {
        return [];
    }
    $kata = explode(' ', $teks);
    $baris = [];
    $kini = '';
    foreach ($kata as $k) {
        $coba = $kini === '' ? $k : $kini . ' ' . $k;
        if (tiket_lebar($coba, $ukuran, $tebal) <= $lebar || $kini === '') {
            $kini = $coba;
        } else {
            $baris[] = $kini;
            $kini = $k;
        }
    }
    if ($kini !== '') {
        $baris[] = $kini;
    }
    return $baris;
}

/**
 * Gambar POTONGAN RANGKAP KEDUA (tinggi 30 mm) untuk penanda resep:
 * hanya kode tiket besar + catatan (opsional) + nama apotek kecil.
 * 30 mm @203dpi ≈ 240 px; lebar 576 px (80 mm).
 */
function tiket_potongan_png(array $t): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $font = tiket_font(false);
    $fontTebal = tiket_font(true);
    if ($font === '' || $fontTebal === '') {
        return null;
    }
    $lebar  = TIKET_PNG_LEBAR;
    $tinggi = 240;                                   /* 30 mm @203dpi */
    $margin = 20;
    $isi    = $lebar - $margin * 2;

    $img = imagecreatetruecolor($lebar, $tinggi);
    $putih = imagecolorallocate($img, 255, 255, 255);
    $hitam = imagecolorallocate($img, 0, 0, 0);
    $abu   = imagecolorallocate($img, 110, 110, 110);
    imagefilledrectangle($img, 0, 0, $lebar, $tinggi, $putih);

    /* Bingkai tipis supaya potongan mudah dilihat & dipotong rapi. */
    imagerectangle($img, 3, 3, $lebar - 4, $tinggi - 4, $abu);

    $kode = (string) $t['kode_tiket'];
    $ukuran = 110;
    $w = tiket_lebar($kode, $ukuran, true);
    while ($w > $isi && $ukuran > 40) {
        $ukuran -= 6;
        $w = tiket_lebar($kode, $ukuran, true);
    }
    imagettftext($img, $ukuran, 0, (int) round($margin + ($isi - $w) / 2), (int) round($tinggi / 2 + $ukuran * 0.34),
        $hitam, $fontTebal, $kode);

    /* Nama apotek kecil di atas, catatan di bawah (bila ada). */
    $namaRs = (string) setting('nama_apotek', 'Apotek');
    $potongRs = $namaRs;
    $ukuranRs = 15;
    while (tiket_lebar($potongRs, $ukuranRs) > $isi && strlen($potongRs) > 6) {
        $potongRs = substr($potongRs, 0, -2);
    }
    if ($potongRs !== $namaRs) { $potongRs = rtrim($potongRs) . '..'; }
    imagettftext($img, $ukuranRs, 0, (int) round($margin + ($isi - tiket_lebar($potongRs, $ukuranRs)) / 2), 30,
        $abu, $font, $potongRs);

    $catatan = trim((string) $t['keterangan']);
    if ($catatan !== '') {
        while (tiket_lebar($catatan, 16, true) > $isi && strlen($catatan) > 4) {
            $catatan = substr($catatan, 0, -2);
        }
        if ($catatan !== trim((string) $t['keterangan'])) { $catatan = rtrim($catatan) . '..'; }
        imagettftext($img, 16, 0, (int) round($margin + ($isi - tiket_lebar($catatan, 16, true)) / 2), $tinggi - 26,
            $hitam, $fontTebal, $catatan);
    }

    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);
    return $png !== '' ? $png : null;
}

/**
 * Versi TEKS tiket (untuk mode teks agen cetak — tanpa QR, paling kompatibel).
 * Lebar kolom 42 = kertas 80 mm, 32 = 58 mm.
 */
function tiket_teks(array $t, int $lebar = 42): string
{
    $lebar = max(28, min(64, $lebar));
    $k = kode_row((string) $t['kode']);
    $layanan = $k ? kode_label($k) : ('Loket ' . $t['kode']);
    $tengah = static function (string $teks) use ($lebar): string {
        $teks = trim($teks);
        $sisa = max(0, $lebar - strlen($teks));
        return str_repeat(' ', intdiv($sisa, 2)) . $teks;
    };
    /*
     * Bungkus teks. wordwrap() TIDAK memotong kata yang terlalu panjang (mis. URL tautan
     * lacak obat), sehingga barisnya bisa melebihi lebar kertas dan terpotong di printer.
     * Karena itu kata yang melebihi lebar kolom dipotong paksa per $lebar karakter.
     */
    $bungkus = static function (string $teks) use ($lebar): array {
        $hasil = [];
        foreach (explode(PHP_EOL, wordwrap(trim($teks), $lebar, PHP_EOL, false)) as $b) {
            while (strlen($b) > $lebar) {
                $hasil[] = substr($b, 0, $lebar);
                $b = substr($b, $lebar);
            }
            $hasil[] = $b;
        }
        return $hasil;
    };
    $barisBaris = [];
    foreach ($bungkus((string) setting('nama_apotek', 'Apotek')) as $b) { $barisBaris[] = $tengah($b); }
    foreach ($bungkus((string) setting('alamat_apotek', '')) as $b) { $barisBaris[] = $tengah($b); }
    $barisBaris[] = $tengah((string) setting('printer_judul', 'TIKET ANTRIAN APOTEK'));
    $barisBaris[] = str_repeat('-', $lebar);
    $barisBaris[] = $tengah('NOMOR TIKET');
    $barisBaris[] = $tengah(str_repeat(' ', 0) . $t['kode'] . ' ' . str_pad((string) $t['nomor'], 3, '0', STR_PAD_LEFT));
    $barisBaris[] = $tengah($layanan);
    $barisBaris[] = str_repeat('-', $lebar);
    $tambah = static function (string $label, string $nilai) use (&$barisBaris, $lebar) {
        $nilai = trim($nilai);
        if ($nilai === '') { return; }
        $lebarLabel = 14;
        $sisa = $lebar - $lebarLabel - 1;
        $potongan = [];
        foreach (explode("
", wordwrap($nilai, $sisa, "
", false)) as $b) { $potongan[] = $b; }
        $barisBaris[] = str_pad($label, $lebarLabel) . ' ' . array_shift($potongan);
        foreach ($potongan as $b) {
            $barisBaris[] = str_repeat(' ', $lebarLabel + 1) . $b;
        }
    };
    if (setting_bool('simrs_tampil_tiket', true)) {
        $tambah('Nama pasien', (string) $t['nama_pasien']);
        $tambah('No. RM', (string) $t['no_rm']);
    }
    $tambah('Tanggal', date('d/m/Y', strtotime((string) $t['created_at'])));
    $tambah('Jam ambil', jam_teks((string) $t['created_at']));
    $tambah('Catatan', (string) $t['keterangan']);
    $tambah('Status', STATUS_LABEL[(string) $t['status']] ?? (string) $t['status']);
    if (trim((string) $t['diambil_oleh']) !== '') {
        $tambah('Diambil oleh', nama_pengguna((string) $t['diambil_oleh']));
    }
    $barisBaris[] = str_repeat('-', $lebar);
    $tautan = url_lacak($t);
    if ($tautan !== '') {
        foreach ($bungkus('Lacak status obat: ' . $tautan) as $b) { $barisBaris[] = $b; }
    }
    foreach ($bungkus((string) setting('printer_footer', '')) as $b) { $barisBaris[] = $tengah($b); }
    return implode("
", $barisBaris) . "
";
}

/**
 * Versi TEKS potongan rangkap kedua (penanda resep): kode tiket besar + catatan.
 */
function tiket_potongan_teks(array $t, int $lebar = 42): string
{
    $lebar = max(28, min(64, $lebar));
    $tengah = static function (string $teks) use ($lebar): string {
        $teks = trim($teks);
        return str_repeat(' ', max(0, intdiv($lebar - strlen($teks), 2))) . $teks;
    };
    $baris = [];
    $baris[] = $tengah((string) setting('nama_apotek', 'Apotek'));
    $baris[] = str_repeat('-', $lebar);
    $baris[] = '';
    $baris[] = $tengah((string) $t['kode_tiket']);
    if (trim((string) $t['keterangan']) !== '') {
        $baris[] = '';
        $baris[] = $tengah(substr(trim((string) $t['keterangan']), 0, $lebar));
    }
    return implode('\n', $baris) . '\n';
}

/**
 * Buat gambar tiket (PNG) untuk sebuah tiket.
 * Mengembalikan string PNG, atau null bila GD/font tidak tersedia.
 */
function tiket_png(array $t): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return null;
    }
    $font = tiket_font(false);
    $fontTebal = tiket_font(true);
    if ($font === '') {
        return null;
    }

    $lebar  = TIKET_PNG_LEBAR;
    $margin = TIKET_PNG_MARGIN;
    $isi    = $lebar - $margin * 2;
    $qrUkuran = 260;

    /* ---- Hitung tinggi dulu ---- */
    $k = kode_row((string) $t['kode']);
    $layanan = $k ? kode_label($k) : ('Loket ' . $t['kode']);
    $tampilPasien = setting_bool('simrs_tampil_tiket', true);

    $barisInfo = [];
    if ($tampilPasien && trim((string) $t['nama_pasien']) !== '') {
        $barisInfo[] = ['Nama pasien', (string) $t['nama_pasien']];
    }
    if ($tampilPasien && trim((string) $t['no_rm']) !== '') {
        $barisInfo[] = ['No. Rekam Medis', (string) $t['no_rm']];
    }
    $barisInfo[] = ['Tanggal', date('d/m/Y', strtotime((string) $t['created_at']))];
    $barisInfo[] = ['Jam ambil', jam_teks((string) $t['created_at'])];
    if (trim((string) $t['keterangan']) !== '') {
        $barisInfo[] = ['Catatan', (string) $t['keterangan']];
    }
    $barisInfo[] = ['Status', STATUS_LABEL[(string) $t['status']] ?? (string) $t['status']];
    if (trim((string) $t['diambil_oleh']) !== '') {
        $barisInfo[] = ['Diambil oleh', nama_pengguna((string) $t['diambil_oleh'])];
    }

    $alamatBaris = tiket_bungkus((string) setting('alamat_apotek', ''), 15, $isi);
    $footerBaris = tiket_bungkus((string) setting('printer_footer', ''), 14, $isi);
    $pantauBaris = tiket_bungkus('Pindai QR untuk melacak posisi obat Anda.', 15, $isi);

    $tinggi = 30;                                   /* atas */
    $tinggi += 34;                                  /* kop nama apotek */
    $tinggi += count($alamatBaris) * 19;
    $tinggi += 30;                                  /* judul tiket */
    $tinggi += 26;                                  /* garis pemisah */
    $tinggi += 20 + 130;                            /* label + nomor besar */
    $tinggi += 26;                                  /* layanan */
    $tinggi += 26;                                  /* pemisah */
    $tinggi += count($barisInfo) * 28;
    $tinggi += 26 + $qrUkuran + 30;                 /* pemisah + QR + caption */
    $tinggi += count($pantauBaris) * 19;
    $tinggi += count($footerBaris) * 18 + 30;
    $tinggi += 20;                                  /* bawah */

    $img = imagecreatetruecolor($lebar, $tinggi);
    $putih = imagecolorallocate($img, 255, 255, 255);
    $hitam = imagecolorallocate($img, 0, 0, 0);
    $abu   = imagecolorallocate($img, 110, 110, 110);
    imagefilledrectangle($img, 0, 0, $lebar, $tinggi, $putih);

    $tulis = function (float $x, float $y, string $teks, float $ukuran, bool $tebal = false, $warna = null, string $rata = 'kiri') use ($img, $hitam, $isi, $margin) {
        if ($teks === '') { return; }
        $font = tiket_font($tebal);
        if ($font === '') { return; }
        $w = tiket_lebar($teks, $ukuran, $tebal);
        if ($rata === 'tengah') { $x = $margin + ($isi - $w) / 2; }
        if ($rata === 'kanan')  { $x = $margin + $isi - $w; }
        imagettftext($img, $ukuran, 0, (int) round($x), (int) round($y), $warna ?? $hitam, $font, $teks);
    };
    $garis = function (float $y) use ($img, $abu, $margin, $lebar) {
        /* garis putus-putus */
        for ($x = $margin; $x < $lebar - $margin; $x += 12) {
            imageline($img, (int) $x, (int) $y, (int) min($x + 6, $lebar - $margin), (int) $y, $abu);
        }
    };

    $y = 44;
    $tulis(0, $y, (string) setting('nama_apotek', 'Apotek'), 24, true, null, 'tengah');
    $y += 26;
    foreach ($alamatBaris as $b) {
        $tulis(0, $y, $b, 15, false, $abu, 'tengah');
        $y += 19;
    }
    $y += 8;
    $tulis(0, $y, (string) setting('printer_judul', 'TIKET ANTRIAN APOTEK'), 17, true, null, 'tengah');
    $y += 14;
    $garis($y);
    $y += 26;

    $tulis(0, $y, 'NOMOR TIKET', 15, false, $abu, 'tengah');
    $y += 20;
    /* Nomor tiket besar (huruf + 3 digit) */
    $kode = (string) $t['kode'];
    $angka = str_pad((string) $t['nomor'], 3, '0', STR_PAD_LEFT);
    $ukuranKode = 96;
    $lebarGabung = tiket_lebar($kode, $ukuranKode, true) + tiket_lebar($angka, $ukuranKode, true) + 8;
    $xk = $margin + ($isi - $lebarGabung) / 2;
    $fontB = tiket_font(true);
    imagettftext($img, $ukuranKode, 0, (int) round($xk), (int) round($y + 96), $hitam, $fontB, $kode);
    imagettftext($img, $ukuranKode, 0, (int) round($xk + tiket_lebar($kode, $ukuranKode, true) + 8), (int) round($y + 96), $hitam, $fontB, $angka);
    $y += 96 + 30;

    $tulis(0, $y, $layanan, 20, true, null, 'tengah');
    $y += 12;
    $garis($y);
    $y += 28;

    foreach ($barisInfo as [$label, $nilai]) {
        $tulis($margin, $y, $label, 15, false, $abu);
        $sisa = $isi - tiket_lebar($label, 15) - 16;
        $potong = $nilai;
        while (tiket_lebar($potong, 16, true) > $sisa && strlen($potong) > 4) {
            $potong = substr($potong, 0, -2);
        }
        if ($potong !== $nilai) { $potong = rtrim($potong) . '..'; }
        $tulis($margin + $isi - tiket_lebar($potong, 16, true), $y, $potong, 16, true);
        $y += 28;
    }

    $y += 6;
    $garis($y);
    $y += 26;

    /* ---- QR lacak obat ---- */
    $tautan = url_lacak($t);
    if ($tautan !== '') {
        $qr = qr_matrik($tautan, 'M');
        $modul = $qr['size'] + 8;                     /* + zona tenang */
        $skala = max(2, (int) floor($qrUkuran / $modul));
        $ukuranQr = $modul * $skala;
        $x0 = (int) round($margin + ($isi - $ukuranQr) / 2);
        imagefilledrectangle($img, $x0, (int) $y, $x0 + $ukuranQr, (int) $y + $ukuranQr, $putih);
        for ($r = 0; $r < $qr['size']; $r++) {
            for ($c = 0; $c < $qr['size']; $c++) {
                if (!empty($qr['matrix'][$r][$c])) {
                    $px = $x0 + ($c + 4) * $skala;
                    $py = (int) $y + ($r + 4) * $skala;
                    imagefilledrectangle($img, $px, $py, $px + $skala - 1, $py + $skala - 1, $hitam);
                }
            }
        }
        $y += $ukuranQr + 22;
        foreach ($pantauBaris as $b) {
            $tulis(0, $y, $b, 15, false, null, 'tengah');
            $y += 19;
        }
        $tulis(0, $y + 4, (string) $t['kode_tiket'] . ' · ' . $tautan, 11, false, $abu, 'tengah');
        $y += 22;
    }

    foreach ($footerBaris as $b) {
        $y += 18;
        $tulis(0, $y, $b, 14, false, $abu, 'tengah');
    }

    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);
    return $png !== '' ? $png : null;
}
