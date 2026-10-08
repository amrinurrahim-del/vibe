<?php
declare(strict_types=1);

/*
 * Pembuat kode QR sendiri (tanpa pustaka luar, seperti pdf.php).
 *
 * Mendukung: mode byte, versi 1-10, tingkat koreksi L/M/Q/H.
 * Keluaran: matriks boolean, SVG (untuk layar & cetak), dan PNG (tanpa GD).
 *
 * Catatan: kode ini diuji dengan decoder independen (zbarimg) — lihat _uji/uji-qr.php.
 */

/* Jumlah codeword total per versi. */
const QR_TOTAL_CW = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346];

/* Posisi alignment pattern per versi. */
const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

/* Struktur blok Reed-Solomon: [versi][level] => [ecc/blok, jmlBlok1, data1, jmlBlok2, data2] */
const QR_EC = [
    1  => ['L' => [7, 1, 19, 0, 0],   'M' => [10, 1, 16, 0, 0],  'Q' => [13, 1, 13, 0, 0],  'H' => [17, 1, 9, 0, 0]],
    2  => ['L' => [10, 1, 34, 0, 0],  'M' => [16, 1, 28, 0, 0],  'Q' => [22, 1, 22, 0, 0],  'H' => [28, 1, 16, 0, 0]],
    3  => ['L' => [15, 1, 55, 0, 0],  'M' => [26, 1, 44, 0, 0],  'Q' => [18, 2, 17, 0, 0],  'H' => [22, 2, 13, 0, 0]],
    4  => ['L' => [20, 1, 80, 0, 0],  'M' => [18, 2, 32, 0, 0],  'Q' => [26, 2, 24, 0, 0],  'H' => [16, 4, 9, 0, 0]],
    5  => ['L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0],  'Q' => [18, 2, 15, 2, 16], 'H' => [22, 2, 11, 2, 12]],
    6  => ['L' => [18, 2, 68, 0, 0],  'M' => [16, 4, 27, 0, 0],  'Q' => [24, 4, 19, 0, 0],  'H' => [28, 4, 15, 0, 0]],
    7  => ['L' => [20, 2, 78, 0, 0],  'M' => [18, 4, 31, 0, 0],  'Q' => [18, 2, 14, 4, 15], 'H' => [26, 4, 13, 1, 14]],
    8  => ['L' => [24, 2, 97, 0, 0],  'M' => [22, 2, 38, 2, 39], 'Q' => [22, 4, 18, 2, 19], 'H' => [26, 4, 14, 2, 15]],
    9  => ['L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37], 'Q' => [20, 4, 16, 4, 17], 'H' => [24, 4, 12, 4, 13]],
    10 => ['L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44], 'Q' => [24, 6, 19, 2, 20], 'H' => [28, 6, 15, 2, 16]],
];

/** Bit indikator tingkat koreksi (untuk format info). */
const QR_LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

/* ---------------- Aritmetika GF(256) ---------------- */

function qr_gf_tabel(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;   /* polinomial primitif QR */
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    $t = ['exp' => $exp, 'log' => $log];
    return $t;
}

function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    $t = qr_gf_tabel();
    return $t['exp'][($t['log'][$a] + $t['log'][$b]) % 255];
}

/** Polinomial generator untuk n codeword ECC. */
function qr_generator(int $n): array
{
    static $cache = [];
    if (isset($cache[$n])) {
        return $cache[$n];
    }
    $t = qr_gf_tabel();
    $poly = [1];
    for ($i = 0; $i < $n; $i++) {
        $baru = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $koef) {
            $baru[$j]     ^= qr_gf_mul($koef, 1);
            $baru[$j + 1] ^= qr_gf_mul($koef, $t['exp'][$i]);
        }
        $poly = $baru;
    }
    return $cache[$n] = $poly;
}

/** Hitung codeword ECC (n buah) untuk sebuah blok data. */
function qr_ecc(array $data, int $n): array
{
    $gen = qr_generator($n);
    $sisa = array_merge($data, array_fill(0, $n, 0));
    $jumlahData = count($data);
    for ($i = 0; $i < $jumlahData; $i++) {
        $koef = $sisa[$i];
        if ($koef === 0) {
            continue;
        }
        foreach ($gen as $j => $g) {
            $sisa[$i + $j] ^= qr_gf_mul($g, $koef);
        }
    }
    return array_slice($sisa, $jumlahData, $n);
}

/* ---------------- Penyusunan data ---------------- */

/** Bit yang dibutuhkan untuk indikator jumlah karakter (mode byte). */
function qr_count_bits(int $versi): int
{
    return $versi <= 9 ? 8 : 16;
}

/** Pilih versi terkecil yang cukup untuk data pada tingkat koreksi tertentu. */
function qr_pilih_versi(int $panjang, string $level): int
{
    for ($v = 1; $v <= 10; $v++) {
        $dataCw = qr_data_codewords($v, $level);
        $bit = 4 + qr_count_bits($v) + 8 * $panjang;
        if ($bit <= $dataCw * 8) {
            return $v;
        }
    }
    throw new RuntimeException('Data terlalu panjang untuk QR versi 1-10 (' . $panjang . ' byte).');
}

function qr_data_codewords(int $versi, string $level): int
{
    [$ecc, $b1, $d1, $b2, $d2] = QR_EC[$versi][$level];
    return $b1 * $d1 + $b2 * $d2;
}

/** Susun codeword data (termasuk padding) untuk versi & level tertentu. */
function qr_buat_codeword(string $data, int $versi, string $level): array
{
    $bits = [];
    $tambah = static function (int $nilai, int $jumlah) use (&$bits): void {
        for ($i = $jumlah - 1; $i >= 0; $i--) {
            $bits[] = ($nilai >> $i) & 1;
        }
    };
    $tambah(0b0100, 4);                                  /* mode byte */
    $tambah(strlen($data), qr_count_bits($versi));       /* jumlah karakter */
    foreach (str_split($data) as $ch) {
        $tambah(ord($ch), 8);
    }

    $dataCw = qr_data_codewords($versi, $level);
    $kapasitasBit = $dataCw * 8;
    /* Terminator maksimal 4 bit nol. */
    $sisa = $kapasitasBit - count($bits);
    $tambah(0, min(4, $sisa));
    /* Rapikan ke batas byte. */
    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }
    /* Ubah jadi byte lalu isi padding 0xEC / 0x11 bergantian. */
    $bytes = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $b = 0;
        for ($j = 0; $j < 8; $j++) {
            $b = ($b << 1) | $bits[$i + $j];
        }
        $bytes[] = $b;
    }
    $pad = [0xEC, 0x11];
    $k = 0;
    while (count($bytes) < $dataCw) {
        $bytes[] = $pad[$k % 2];
        $k++;
    }
    return $bytes;
}

/** Bagi jadi blok, hitung ECC, lalu jalin (interleave) sesuai standar. */
function qr_interleave(array $codeword, int $versi, string $level): array
{
    [$eccLen, $b1, $d1, $b2, $d2] = QR_EC[$versi][$level];
    $blokData = [];
    $idx = 0;
    for ($i = 0; $i < $b1; $i++) {
        $blokData[] = array_slice($codeword, $idx, $d1);
        $idx += $d1;
    }
    for ($i = 0; $i < $b2; $i++) {
        $blokData[] = array_slice($codeword, $idx, $d2);
        $idx += $d2;
    }
    $blokEcc = [];
    foreach ($blokData as $b) {
        $blokEcc[] = qr_ecc($b, $eccLen);
    }

    $hasil = [];
    $maksData = max(array_map('count', $blokData));
    for ($i = 0; $i < $maksData; $i++) {
        foreach ($blokData as $b) {
            if (isset($b[$i])) {
                $hasil[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $eccLen; $i++) {
        foreach ($blokEcc as $b) {
            if (isset($b[$i])) {
                $hasil[] = $b[$i];
            }
        }
    }
    return $hasil;
}

/* ---------------- Informasi format & versi ---------------- */

function qr_bch(int $data, int $poli, int $bitPoli): int
{
    $d = $data << ($bitPoli - 1);
    while (qr_bit_length($d) >= $bitPoli) {
        $d ^= $poli << (qr_bit_length($d) - $bitPoli);
    }
    return (($data << ($bitPoli - 1)) | $d);
}

function qr_bit_length(int $n): int
{
    $len = 0;
    while ($n !== 0) {
        $len++;
        $n >>= 1;
    }
    return $len;
}

function qr_format_info(string $level, int $mask): int
{
    $data = (QR_LEVEL_BITS[$level] << 3) | $mask;
    return qr_bch($data, 0x537, 11) ^ 0x5412;
}

function qr_version_info(int $versi): int
{
    return qr_bch($versi, 0x1F25, 13);
}

/* ---------------- Penempatan modul ---------------- */

function qr_mask_cocok(int $mask, int $i, int $j): bool
{
    switch ($mask) {
        case 0: return ($i + $j) % 2 === 0;
        case 1: return $i % 2 === 0;
        case 2: return $j % 3 === 0;
        case 3: return ($i + $j) % 3 === 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
        case 5: return (($i * $j) % 2 + ($i * $j) % 3) === 0;
        case 6: return ((($i * $j) % 2 + ($i * $j) % 3) % 2) === 0;
        case 7: return (((($i * $j) % 3) + ($i + $j) % 2) % 2) === 0;
    }
    return false;
}

/** Matriks modul: null = belum terisi, true/false = modul tetap (fungsi). */
function qr_siapkan_matriks(int $versi): array
{
    $size = 17 + 4 * $versi;
    $m = array_fill(0, $size, array_fill(0, $size, null));

    /* Finder pattern + separator. */
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$r, $c]) {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $rr = $r + $i;
                $cc = $c + $j;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }
                $dalam = ($i >= 0 && $i <= 6 && $j >= 0 && $j <= 6);
                $tepi = ($i === 0 || $i === 6 || $j === 0 || $j === 6);
                $inti = ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$rr][$cc] = ($dalam && ($tepi || $inti));
            }
        }
    }

    /* Alignment pattern. */
    $pos = QR_ALIGN[$versi];
    $n = count($pos);
    for ($a = 0; $a < $n; $a++) {
        for ($b = 0; $b < $n; $b++) {
            if (($a === 0 && $b === 0) || ($a === 0 && $b === $n - 1) || ($a === $n - 1 && $b === 0)) {
                continue;
            }
            $r = $pos[$a];
            $c = $pos[$b];
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $m[$r + $i][$c + $j] = (max(abs($i), abs($j)) !== 1);
                }
            }
        }
    }

    /* Timing pattern. */
    for ($i = 8; $i < $size - 8; $i++) {
        if ($m[6][$i] === null) {
            $m[6][$i] = ($i % 2 === 0);
        }
        if ($m[$i][6] === null) {
            $m[$i][6] = ($i % 2 === 0);
        }
    }

    /* Format info (diisi nanti, tandai dulu sebagai modul fungsi). */
    for ($i = 0; $i < 15; $i++) {
        if ($i < 6) { $m[$i][8] = false; }
        elseif ($i < 8) { $m[$i + 1][8] = false; }
        else { $m[$size - 15 + $i][8] = false; }
    }
    for ($i = 0; $i < 15; $i++) {
        if ($i < 8) { $m[8][$size - $i - 1] = false; }
        elseif ($i < 9) { $m[8][15 - $i] = false; }
        else { $m[8][15 - $i - 1] = false; }
    }
    $m[$size - 8][8] = true;   /* modul gelap tetap */

    /* Info versi (versi 7 ke atas). */
    if ($versi >= 7) {
        $bits = qr_version_info($versi);
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $m[intdiv($i, 3)][$i % 3 + $size - 8 - 3] = $bit;
            $m[$i % 3 + $size - 8 - 3][intdiv($i, 3)] = $bit;
        }
    }
    return $m;
}

/** Tempatkan bit data memakai pola zigzag standar. */
function qr_taruh_data(array $m, array $codeword, int $mask): array
{
    $size = count($m);
    $inc = -1;
    $row = $size - 1;
    $bitIndex = 7;
    $byteIndex = 0;
    $jumlahByte = count($codeword);

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        while (true) {
            for ($c = 0; $c < 2; $c++) {
                $cc = $col - $c;
                if ($m[$row][$cc] === null) {
                    $gelap = false;
                    if ($byteIndex < $jumlahByte) {
                        $gelap = ((($codeword[$byteIndex] >> $bitIndex) & 1) === 1);
                    }
                    if (qr_mask_cocok($mask, $row, $cc)) {
                        $gelap = !$gelap;
                    }
                    $m[$row][$cc] = $gelap;
                    $bitIndex--;
                    if ($bitIndex === -1) {
                        $byteIndex++;
                        $bitIndex = 7;
                    }
                }
            }
            $row += $inc;
            if ($row < 0 || $row >= $size) {
                $row -= $inc;
                $inc = -$inc;
                break;
            }
        }
    }
    return $m;
}

/** Tulis format info ke matriks (setelah mask dipilih). */
function qr_taruh_format(array $m, string $level, int $mask): array
{
    $size = count($m);
    $bits = qr_format_info($level, $mask);
    for ($i = 0; $i < 15; $i++) {
        $bit = (($bits >> $i) & 1) === 1;
        if ($i < 6) { $m[$i][8] = $bit; }
        elseif ($i < 8) { $m[$i + 1][8] = $bit; }
        else { $m[$size - 15 + $i][8] = $bit; }
    }
    for ($i = 0; $i < 15; $i++) {
        $bit = (($bits >> $i) & 1) === 1;
        if ($i < 8) { $m[8][$size - $i - 1] = $bit; }
        elseif ($i < 9) { $m[8][15 - $i] = $bit; }
        else { $m[8][15 - $i - 1] = $bit; }
    }
    $m[$size - 8][8] = true;
    return $m;
}

/** Nilai penalti standar untuk memilih mask terbaik (aturan 1-4). */
function qr_penalti(array $m): int
{
    $size = count($m);
    $nilai = 0;

    /* Aturan 1: deretan 5 modul sewarna atau lebih. */
    for ($i = 0; $i < $size; $i++) {
        for ($arah = 0; $arah < 2; $arah++) {
            $jalan = 1;
            for ($j = 1; $j < $size; $j++) {
                $a = $arah === 0 ? $m[$i][$j] : $m[$j][$i];
                $b = $arah === 0 ? $m[$i][$j - 1] : $m[$j - 1][$i];
                if ($a === $b) {
                    $jalan++;
                } else {
                    if ($jalan >= 5) {
                        $nilai += 3 + ($jalan - 5);
                    }
                    $jalan = 1;
                }
            }
            if ($jalan >= 5) {
                $nilai += 3 + ($jalan - 5);
            }
        }
    }

    /* Aturan 2: blok 2x2 sewarna. */
    for ($i = 0; $i < $size - 1; $i++) {
        for ($j = 0; $j < $size - 1; $j++) {
            $w = $m[$i][$j];
            if ($w === $m[$i][$j + 1] && $w === $m[$i + 1][$j] && $w === $m[$i + 1][$j + 1]) {
                $nilai += 3;
            }
        }
    }

    /* Aturan 3: pola mirip finder 1:1:3:1:1 dengan ruang kosong. */
    $polaA = [true, false, true, true, true, false, true, false, false, false, false];
    $polaB = [false, false, false, false, true, false, true, true, true, false, true];
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j <= $size - 11; $j++) {
            $cocokA = true;
            $cocokB = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$i][$j + $k] !== $polaA[$k]) { $cocokA = false; }
                if ($m[$i][$j + $k] !== $polaB[$k]) { $cocokB = false; }
                if (!$cocokA && !$cocokB) { break; }
            }
            if ($cocokA) { $nilai += 40; }
            if ($cocokB) { $nilai += 40; }
        }
    }
    for ($j = 0; $j < $size; $j++) {
        for ($i = 0; $i <= $size - 11; $i++) {
            $cocokA = true;
            $cocokB = true;
            for ($k = 0; $k < 11; $k++) {
                if ($m[$i + $k][$j] !== $polaA[$k]) { $cocokA = false; }
                if ($m[$i + $k][$j] !== $polaB[$k]) { $cocokB = false; }
                if (!$cocokA && !$cocokB) { break; }
            }
            if ($cocokA) { $nilai += 40; }
            if ($cocokB) { $nilai += 40; }
        }
    }

    /* Aturan 4: perbandingan modul gelap/terang. */
    $gelap = 0;
    foreach ($m as $baris) {
        foreach ($baris as $sel) {
            if ($sel) { $gelap++; }
        }
    }
    $rasio = $gelap * 100 / ($size * $size);
    $deviasi = (int) (abs($rasio - 50) / 5);
    $nilai += $deviasi * 10;
    return $nilai;
}

/* ---------------- Keluaran ---------------- */

/**
 * Buat matriks QR. Mengembalikan ['size'=>int, 'matrix'=>bool[][], 'version'=>int, 'mask'=>int, 'level'=>string].
 * Tingkat koreksi diturunkan otomatis bila diminta menyesuaikan ukuran.
 */
function qr_matrik(string $data, string $level = 'M'): array
{
    $level = in_array($level, ['L', 'M', 'Q', 'H'], true) ? $level : 'M';
    $versi = qr_pilih_versi(strlen($data), $level);
    $codeword = qr_buat_codeword($data, $versi, $level);
    $inter = qr_interleave($codeword, $versi, $level);

    $dasar = qr_siapkan_matriks($versi);
    $terbaik = null;
    $skorTerbaik = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = qr_taruh_data($dasar, $inter, $mask);
        $m = qr_taruh_format($m, $level, $mask);
        $skor = qr_penalti($m);
        if ($skor < $skorTerbaik) {
            $skorTerbaik = $skor;
            $terbaik = ['m' => $m, 'mask' => $mask];
        }
    }
    return [
        'size'    => count($terbaik['m']),
        'matrix'  => $terbaik['m'],
        'version' => $versi,
        'mask'    => $terbaik['mask'],
        'level'   => $level,
    ];
}

/** QR sebagai SVG (tajam saat dicetak). $ukuran = lebar akhir dalam px/mm bebas satuan. */
function qr_svg(string $data, float $ukuran = 120, string $level = 'M', int $zonaTenang = 4,
                 string $gelap = '#000000', string $terang = '#ffffff'): string
{
    $qr = qr_matrik($data, $level);
    $size = $qr['size'];
    $modulTotal = $size + $zonaTenang * 2;
    $satuan = $ukuran / $modulTotal;

    $path = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($qr['matrix'][$r][$c]) {
                $x = ($c + $zonaTenang) * $satuan;
                $y = ($r + $zonaTenang) * $satuan;
                $path .= 'M' . round($x, 3) . ' ' . round($y, 3)
                       . 'h' . round($satuan, 3) . 'v' . round($satuan, 3)
                       . 'h-' . round($satuan, 3) . 'z';
            }
        }
    }
    $besar = round($modulTotal * $satuan, 3);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $besar . '" height="' . $besar . '" '
         . 'viewBox="0 0 ' . $besar . ' ' . $besar . '" shape-rendering="crispEdges" role="img" '
         . 'aria-label="Kode QR tiket">';
    if ($terang !== '' && strtolower($terang) !== 'none') {
        $svg .= '<rect width="' . $besar . '" height="' . $besar . '" fill="' . $terang . '"/>';
    }
    $svg .= '<path d="' . $path . '" fill="' . $gelap . '"/></svg>';
    return $svg;
}

/** QR sebagai PNG (tanpa GD — memakai zlib bawaan PHP). */
function qr_png(string $data, int $skalaModul = 4, string $level = 'M', int $zonaTenang = 4): string
{
    $qr = qr_matrik($data, $level);
    $size = $qr['size'];
    $modulTotal = $size + $zonaTenang * 2;
    $px = $modulTotal * $skalaModul;

    /* Baris gambar: 1 byte filter (0) + RGB hitam/putih per piksel.
       Tiap modul diperbesar $skalaModul piksel ke arah mendatar DAN menurun. */
    $barisModul = [];
    for ($r = 0; $r < $modulTotal; $r++) {
        $satuBaris = '';
        for ($c = 0; $c < $modulTotal; $c++) {
            $mr = $r - $zonaTenang;
            $mc = $c - $zonaTenang;
            $gelap = ($mr >= 0 && $mr < $size && $mc >= 0 && $mc < $size) ? $qr['matrix'][$mr][$mc] : false;
            $satuBaris .= str_repeat($gelap ? "\x00\x00\x00" : "\xFF\xFF\xFF", $skalaModul);
        }
        $barisModul[] = $satuBaris;
    }
    $piksel = '';
    foreach ($barisModul as $satuBaris) {
        for ($k = 0; $k < $skalaModul; $k++) {
            $piksel .= "\x00" . $satuBaris;
        }
    }

    $ihdr = pack('NNCCCCC', $px, $px, 8, 2, 0, 0, 0);   /* 8 bit, truecolor RGB */
    $idat = gzcompress($piksel, 9);
    $png = "\x89PNG\r\n\x1a\n";
    $png .= qr_chunk('IHDR', $ihdr);
    $png .= qr_chunk('IDAT', $idat);
    $png .= qr_chunk('IEND', '');
    return $png;
}

function qr_chunk(string $tipe, string $isi): string
{
    $badan = $tipe . $isi;
    return pack('N', strlen($isi)) . $badan . pack('N', crc32($badan));
}

/** Data URI PNG (dipakai bila perlu gambar, bukan SVG). */
function qr_data_uri(string $data, int $skalaModul = 4, string $level = 'M'): string
{
    return 'data:image/png;base64,' . base64_encode(qr_png($data, $skalaModul, $level));
}
