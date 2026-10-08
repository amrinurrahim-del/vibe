<?php
declare(strict_types=1);

/*
 * Helper aplikasi antrian apotek: pengaturan, kode antrian, tiket, tracking,
 * data display, laporan, dan kerangka tampilan.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ */
/* Pengaturan                                                          */
/* ------------------------------------------------------------------ */

function all_settings(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $s = [];
        foreach (db()->query('SELECT kunci, nilai FROM pengaturan') as $r) {
            $s[$r['kunci']] = (string) $r['nilai'];
        }
    }
    return $s;
}

function setting(string $k, string $default = ''): string
{
    $s = all_settings();
    return array_key_exists($k, $s) ? $s[$k] : $default;
}

function setting_bool(string $k, bool $default = false): bool
{
    $v = setting($k, $default ? '1' : '0');
    return $v === '1' || $v === 'true' || $v === 'ya';
}

function setting_num(string $k, float $default): float
{
    $v = setting($k, '');
    return is_numeric($v) ? (float) $v : $default;
}

function set_setting(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)
                         ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai');
    $st->execute([$k, $v]);
    all_settings(true);
}

/* ------------------------------------------------------------------ */
/* Tanggal & waktu                                                     */
/* ------------------------------------------------------------------ */

function hari_ini(): string
{
    return date('Y-m-d');
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function tgl_valid(?string $ymd): string
{
    return (is_string($ymd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) ? $ymd : hari_ini();
}

function tgl_label(string $ymd, bool $dengan_hari = true): string
{
    $ts = strtotime($ymd . ' 00:00:00');
    if ($ts === false) {
        return $ymd;
    }
    $label = (int) date('j', $ts) . ' ' . BULAN_ID[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    return $dengan_hari ? HARI_PANJANG[(int) date('N', $ts)] . ', ' . $label : $label;
}

function bulan_label(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    return BULAN_ID[(int) $m[2]] . ' ' . $m[1];
}

function jam_teks(?string $sqlDatetime): string
{
    if (!$sqlDatetime) {
        return '-';
    }
    $ts = strtotime($sqlDatetime);
    return $ts === false ? '-' : date('H:i:s', $ts);
}

function detik_antara(?string $a, ?string $b): int
{
    if (!$a || !$b) {
        return 0;
    }
    $x = strtotime($a);
    $y = strtotime($b);
    if ($x === false || $y === false) {
        return 0;
    }
    return max(0, $y - $x);
}

/** 12:30 atau 1:05:12 */
function durasi_format(int $d): string
{
    $j = intdiv($d, 3600);
    $m = intdiv($d % 3600, 60);
    $s = $d % 60;
    return $j > 0 ? sprintf('%d:%02d:%02d', $j, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

/** 12 mnt 30 dtk */
function durasi_teks(int $d): string
{
    $j = intdiv($d, 3600);
    $m = intdiv($d % 3600, 60);
    $s = $d % 60;
    if ($j > 0) {
        return $j . ' jam ' . $m . ' mnt';
    }
    if ($m > 0) {
        return $m . ' mnt ' . $s . ' dtk';
    }
    return $s . ' dtk';
}

/* ------------------------------------------------------------------ */
/* Kode antrian                                                        */
/* ------------------------------------------------------------------ */

function kode_daftar(bool $semua = false): array
{
    $sql = 'SELECT * FROM kode_antrian' . ($semua ? '' : ' WHERE aktif = 1') . ' ORDER BY urutan, kode';
    return db()->query($sql)->fetchAll();
}

function kode_row(string $kode): ?array
{
    $st = db()->prepare('SELECT * FROM kode_antrian WHERE kode = ? LIMIT 1');
    $st->execute([strtoupper(trim($kode))]);
    return $st->fetch() ?: null;
}

/** Periode penomoran: kode bulanan (J) memakai YYYY-MM, kode lain YYYY-MM-DD. */
function periode_kode(array $k): string
{
    return (($k['reset_mode'] ?? 'harian') === 'bulanan') ? date('Y-m') : date('Y-m-d');
}

function kode_label(array $k): string
{
    $nama = trim((string) ($k['nama'] ?? ''));
    return $nama !== '' ? $nama : 'Loket ' . $k['kode'];
}

/* ------------------------------------------------------------------ */
/* Tiket                                                               */
/* ------------------------------------------------------------------ */

function tiket_log(int $tiketId, string $status, string $oleh, string $catatan = ''): void
{
    db()->prepare('INSERT INTO tiket_log (tiket_id, status, waktu, oleh, catatan) VALUES (?, ?, ?, ?, ?)')
        ->execute([$tiketId, $status, now_sql(), $oleh, $catatan]);
}

/**
 * Ambil tiket baru. Penomoran dilakukan di dalam SATU transaksi (BEGIN IMMEDIATE)
 * agar dua loket yang menekan bersamaan tidak mendapat nomor kembar.
 */
function tiket_ambil(string $kode, string $keterangan, string $oleh, string $noRm = '', string $namaPasien = ''): array
{
    $pdo = db();
    $k   = kode_row($kode);
    if (!$k || (int) $k['aktif'] !== 1) {
        throw new RuntimeException('Kode antrian "' . $kode . '" tidak aktif.');
    }
    $kode    = (string) $k['kode'];
    $periode = periode_kode($k);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT nomor FROM nomor_counter WHERE kode = ? AND periode = ?');
        $st->execute([$kode, $periode]);
        if ($st->fetchColumn() === false) {
            /* Belum ada baris counter (aplikasi baru / data lama) → mulai dari nomor terakhir yang sudah ada. */
            $q = $pdo->prepare('SELECT COALESCE(MAX(nomor), 0) FROM tiket WHERE kode = ? AND periode = ?');
            $q->execute([$kode, $periode]);
            $pdo->prepare('INSERT INTO nomor_counter (kode, periode, nomor) VALUES (?, ?, ?)')
                ->execute([$kode, $periode, (int) $q->fetchColumn()]);
        }
        $pdo->prepare('UPDATE nomor_counter SET nomor = nomor + 1 WHERE kode = ? AND periode = ?')
            ->execute([$kode, $periode]);
        $st->execute([$kode, $periode]);
        $nomor = (int) $st->fetchColumn();

        $kodeTiket = $kode . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
        $waktu     = now_sql();
        $token     = bin2hex(random_bytes(10));   /* token acak unik untuk tautan lacak (QR) */
        $ins = $pdo->prepare('INSERT INTO tiket (kode, nomor, kode_tiket, tanggal, periode, status, keterangan,
                                                 created_at, siap_at, called_at, called_count, diambil_oleh, updated_at, token,
                                                 no_rm, nama_pasien)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?, ?, ?)');
        $ins->execute([$kode, $nomor, $kodeTiket, hari_ini(), $periode, ST_RESEP,
                       $keterangan, $waktu, $oleh, $waktu, $token,
                       trim($noRm), trim($namaPasien)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }

    tiket_log($id, ST_RESEP, $oleh, 'Tiket diambil — resep masuk antrean.');
    return tiket_by_id($id) ?? [];
}

/**
 * Waktu panggil "efektif": panggilan ULANG bila ada, kalau tidak panggilan awal.
 * Dipakai display (agar nomor yang dipanggil ulang tampil lagi sebagai nomor terpanggil)
 * dan daftar riwayat. Laporan tetap memakai called_at (waktu serah yang sebenarnya).
 */
function waktu_panggil_efektif(array $t): string
{
    $recall = (string) ($t['recall_at'] ?? '');
    return $recall !== '' ? $recall : (string) ($t['called_at'] ?? '');
}

function tiket_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tiket WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Nomor berikutnya untuk sebuah kode (untuk pratinjau di form Ambil Antrian).
 * Tidak menaikkan counter — hanya membaca.
 */
function tiket_nomor_berikutnya(string $kode): array
{
    $k = kode_row($kode);
    if (!$k) {
        return ['periode' => date('Y-m-d'), 'nomor' => 1, 'kode_tiket' => $kode . '001', 'terpakai' => 0];
    }
    $periode = periode_kode($k);
    $st = db()->prepare('SELECT nomor FROM nomor_counter WHERE kode = ? AND periode = ?');
    $st->execute([(string) $k['kode'], $periode]);
    $nomor = $st->fetchColumn();
    if ($nomor === false) {
        $q = db()->prepare('SELECT COALESCE(MAX(nomor), 0) FROM tiket WHERE kode = ? AND periode = ?');
        $q->execute([(string) $k['kode'], $periode]);
        $nomor = (int) $q->fetchColumn();
    }
    $berikut = (int) $nomor + 1;
    return [
        'periode'    => $periode,
        'nomor'      => $berikut,
        'kode_tiket' => (string) $k['kode'] . str_pad((string) $berikut, 3, '0', STR_PAD_LEFT),
        'terpakai'   => (int) $nomor,
    ];
}

/** Tiket hari ini (semua kode). Kode J dari hari sebelumnya memang tidak ikut. */
function tiket_hari_ini(string $kode = ''): array
{
    $sql = 'SELECT * FROM tiket WHERE tanggal = ?';
    $par = [hari_ini()];
    if ($kode !== '') {
        $sql .= ' AND kode = ?';
        $par[] = $kode;
    }
    $sql .= ' ORDER BY kode, nomor';
    $st = db()->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Pindah otomatis RESEP MASUK → OBAT SEDANG DISIAPKAN                 */
/* ------------------------------------------------------------------ */
/*
 * Permintaan pemilik: tiket yang masih RESEP MASUK berpindah sendiri ke OBAT SEDANG
 * DISIAPKAN setelah menunggu sekian menit (bawaan 5 menit) supaya petugas tidak perlu
 * menekan tombol. Tombol manual di halaman Tracking TETAP ada.
 *
 * Cara kerja: pemeriksaan dijalankan di server setiap kali data tiket dibaca
 * (display, tracking, dashboard). Jadi tidak perlu cron/penjadwal, dan perubahan
 * ikut terlihat di semua layar sekaligus — termasuk TV display yang hanya menyegarkan
 * diri bila "sidik jari" data berubah (lihat display_stamp()).
 *
 * Patokan waktunya: COALESCE(updated_at, created_at) — sama dengan waktu tiket mulai
 * menunggu. Bila petugas MENGGESER tiket kembali ke RESEP MASUK secara manual, patokan
 * ikut terbarui sehingga tidak langsung berpindah lagi (mencegah bolak-balik).
 */

function auto_siapkan_aktif(): bool
{
    return setting_bool('auto_siapkan_aktif', true);
}

function auto_siapkan_menit(): int
{
    $m = (int) setting_num('auto_siapkan_menit', 5);
    return max(1, min(600, $m));
}

/**
 * Kecepatan gulir baris "OBAT SUDAH DITERIMA" pada display (px/detik).
 * Dipisah dari kecepatan teks berjalan footer & dari kolom status, supaya bisa dibuat
 * lebih pelan tanpa mengubah bagian lain. Batas 3–200 px/detik.
 */
function kecepatan_gulir_selesai(): int
{
    $v = (int) setting_num('kecepatan_gulir_selesai', 30);
    return max(3, min(200, $v));
}

/** Patokan mulai menunggu untuk tiket tertentu (updated_at bila ada, kalau tidak created_at). */
function tiket_patokan_tunggu(array $t): string
{
    $u = (string) ($t['updated_at'] ?? '');
    return $u !== '' ? $u : (string) ($t['created_at'] ?? '');
}

/**
 * Pindahkan tiket RESEP MASUK hari ini yang sudah menunggu >= auto_siapkan_menit.
 * Mengembalikan jumlah tiket yang dipindahkan.
 *
 * Aman dipanggil sesering apa pun: bila tidak ada calon, hanya satu SELECT yang dijalankan
 * (tidak ada transaksi/tulis sama sekali), dan dalam satu permintaan hanya diperiksa sekali.
 */
function tiket_auto_siapkan(bool $paksaHitung = false): int
{
    static $sudahDiperiksa = false;
    if ($sudahDiperiksa && !$paksaHitung) { return 0; }
    $sudahDiperiksa = true;

    if (!auto_siapkan_aktif()) { return 0; }

    $batas = date('Y-m-d H:i:s', time() - auto_siapkan_menit() * 60);

    /* Calon: tiket hari ini yang masih RESEP MASUK dan patokan menunggunya sudah lewat.
       Satu query ringan; bila kosong, tidak ada proses tulis sama sekali. */
    try {
        $st = db()->prepare('SELECT id, kode_tiket, created_at, updated_at FROM tiket
                             WHERE tanggal = ? AND status = ?
                               AND COALESCE(NULLIF(updated_at, ""), created_at) <= ?
                             ORDER BY kode, nomor LIMIT 200');
        $st->execute([hari_ini(), ST_RESEP, $batas]);
        $calon = $st->fetchAll();
    } catch (Throwable $e) {
        return 0;   /* tabel belum siap → jangan gagalkan halaman */
    }
    if (!$calon) { return 0; }

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $waktu = now_sql();
        $upd = $pdo->prepare('UPDATE tiket SET status = ?, updated_at = ? WHERE id = ? AND status = ?');
        $ins = $pdo->prepare('INSERT INTO tiket_log (tiket_id, status, waktu, oleh, catatan) VALUES (?, ?, ?, ?, ?)');
        $pindah = 0;
        foreach ($calon as $t) {
            $upd->execute([ST_SIAPKAN, $waktu, (int) $t['id'], ST_RESEP]);
            if ($upd->rowCount() < 1) { continue; }   /* sudah diubah petugas di sela-sela ini */
            $ins->execute([
                (int) $t['id'],
                ST_SIAPKAN,
                $waktu,
                'otomatis',
                'Pindah otomatis: sudah menunggu ' . auto_siapkan_menit() . ' menit sejak resep masuk.',
            ]);
            $pindah++;
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        return 0;
    }
    return $pindah;
}

/** Tiket yang boleh dipanggil: status OBAT SIAP DISERAHKAN & belum pernah dipanggil. */
function tiket_siap_panggil(string $kode = ''): array
{
    $sql = 'SELECT * FROM tiket WHERE tanggal = ? AND status = ? AND called_at IS NULL';
    $par = [hari_ini(), ST_SIAP];
    if ($kode !== '') {
        $sql .= ' AND kode = ?';
        $par[] = $kode;
    }
    $sql .= ' ORDER BY kode, nomor';
    $st = db()->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}

/**
 * Panggil tiket → status berubah menjadi OBAT SUDAH DITERIMA dan tercatat waktu serahnya.
 * Hanya bisa dipanggil bila status tracking sudah OBAT SIAP DISERAHKAN.
 */
function tiket_panggil(int $id, string $oleh): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT * FROM tiket WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Tiket tidak ditemukan.');
        }
        if ((string) $t['tanggal'] !== hari_ini()) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' bukan tiket hari ini sehingga tidak dapat dipanggil.');
        }
        if ((string) $t['status'] === ST_DITERIMA) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' sudah pernah dipanggil/diserahkan.');
        }
        if ((string) $t['status'] !== ST_SIAP) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' masih berstatus '
                . STATUS_LABEL[(string) $t['status']] . '. Tombol panggil hanya aktif pada status OBAT SIAP DISERAHKAN.');
        }
        $waktu = now_sql();
        $first = $t['first_called_at'] ?: $waktu;   /* panggilan pertama tidak pernah ditimpa */
        $pdo->prepare('UPDATE tiket SET status = ?, called_at = ?, first_called_at = ?,
                       called_count = called_count + 1, dilayani_oleh = ?, updated_at = ? WHERE id = ?')
            ->execute([ST_DITERIMA, $waktu, $first, $oleh, $waktu, $id]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    tiket_log($id, ST_DITERIMA, $oleh, 'Dipanggil & obat diserahkan ke pasien.');
    return tiket_by_id($id) ?? [];
}

/**
 * Batalkan status OBAT SUDAH DITERIMA → kembali ke OBAT SIAP DISERAHKAN.
 * Dipakai bila nomor sudah dipanggil tetapi pasien tidak ada/belum menerima obat,
 * sehingga nomor tersebut dapat dipanggil lagi nanti.
 * Waktu tunggu TIDAK dihitung ulang dan tidak berjalan lagi: tetap berhenti pada
 * panggilan pertama (first_called_at). Jumlah panggilan (called_count) tetap tersimpan.
 */
function tiket_batal_selesai(int $id, string $oleh): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT * FROM tiket WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Tiket tidak ditemukan.');
        }
        if ((string) $t['tanggal'] !== hari_ini()) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' bukan tiket hari ini sehingga tidak dapat dibatalkan.');
        }
        if ((string) $t['status'] !== ST_DITERIMA) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' berstatus '
                . (STATUS_LABEL[(string) $t['status']] ?? $t['status'])
                . ', jadi tidak ada status selesai yang perlu dibatalkan.');
        }
        $waktu = now_sql();
        $pdo->prepare('UPDATE tiket SET status = ?, called_at = NULL, dilayani_oleh = ?, updated_at = ? WHERE id = ?')
            ->execute([ST_SIAP, $oleh, $waktu, $id]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    tiket_log($id, ST_SIAP, $oleh, 'Status selesai dibatalkan — pasien belum menerima obat, nomor dapat dipanggil lagi.');
    return tiket_by_id($id) ?? [];
}

/** Panggil ulang tiket terakhir yang sudah dipanggil hari ini untuk satu kode. */
function tiket_panggil_ulang(string $kode, string $oleh): array
{
    /* Tiket yang paling terakhir DIPANGGIL (termasuk panggil ulang) untuk kode tersebut. */
    $st = db()->prepare('SELECT * FROM tiket WHERE tanggal = ? AND kode = ? AND called_at IS NOT NULL
                         AND status = ?
                         ORDER BY COALESCE(NULLIF(recall_at, ""), called_at) DESC, id DESC LIMIT 1');
    $st->execute([hari_ini(), strtoupper(trim($kode)), ST_DITERIMA]);
    $t = $st->fetch();
    if (!$t) {
        throw new RuntimeException('Belum ada tiket kode ' . strtoupper(trim($kode)) . ' yang dipanggil hari ini.');
    }
    /*
     * recall_at diisi waktu sekarang → display menampilkan nomor ini lagi sebagai nomor terpanggil
     * (persis seperti saat pertama dipanggil). called_at TIDAK diubah supaya laporan tetap
     * memakai waktu penyerahan yang sebenarnya.
     */
    db()->prepare('UPDATE tiket SET recall_at = ?, called_count = called_count + 1, updated_at = ? WHERE id = ?')
        ->execute([now_sql(), now_sql(), (int) $t['id']]);
    tiket_log((int) $t['id'], ST_DITERIMA, $oleh, 'Panggilan diulang (panggil ulang) — nomor tampil lagi di display.');
    return tiket_by_id((int) $t['id']) ?? [];
}

/**
 * Update tracking oleh petugas. Petugas hanya bisa maju sampai OBAT SIAP DISERAHKAN;
 * status OBAT SUDAH DITERIMA hanya lewat tombol Panggil Antrian.
 */
function tiket_ubah_status(int $id, string $arah, string $oleh): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT * FROM tiket WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Tiket tidak ditemukan.');
        }
        $status = (string) $t['status'];
        $urutan = STATUS_URUT[$status] ?? 1;

        if ($status === ST_DITERIMA) {
            throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' sudah selesai (obat sudah diterima) dan tidak dapat diubah lagi.');
        }

        if ($arah === 'maju') {
            if ($urutan >= STATUS_URUT[ST_SIAP]) {
                throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' sudah siap diserahkan. Gunakan menu Panggil Antrian untuk menyerahkan obat.');
            }
            $baru = $urutan === 1 ? ST_SIAPKAN : ST_SIAP;
        } else {
            if ($urutan <= 1) {
                throw new RuntimeException('Tiket ' . $t['kode_tiket'] . ' sudah pada tahap awal (RESEP MASUK).');
            }
            $baru = $urutan === 2 ? ST_RESEP : ST_SIAPKAN;
        }

        $waktu   = now_sql();
        $siapAt  = $baru === ST_SIAP ? $waktu : ($status === ST_SIAP ? null : $t['siap_at']);
        $pdo->prepare('UPDATE tiket SET status = ?, siap_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$baru, $siapAt, $waktu, $id]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    tiket_log($id, $baru, $oleh, $arah === 'maju' ? 'Update tracking oleh petugas.' : 'Status dikembalikan satu tahap.');
    return tiket_by_id($id) ?? [];
}

/**
 * Hitung data tiket pada rentang tanggal (untuk pratinjau sebelum dihapus).
 * Dipakai fitur "Hapus Data Laporan" agar pemilik tahu berapa baris yang akan hilang.
 */
function data_hitung(string $dari, string $sampai): array
{
    $st = db()->prepare('SELECT COUNT(*) AS total,
                                SUM(called_at IS NOT NULL) AS dilayani,
                                SUM(called_at IS NULL) AS belum
                         FROM tiket WHERE tanggal >= ? AND tanggal <= ?');
    $st->execute([$dari, $sampai]);
    $r = $st->fetch() ?: [];
    $perHari = [];
    $st2 = db()->prepare('SELECT tanggal, COUNT(*) jml FROM tiket WHERE tanggal >= ? AND tanggal <= ?
                          GROUP BY tanggal ORDER BY tanggal');
    $st2->execute([$dari, $sampai]);
    foreach ($st2 as $row) {
        $perHari[(string) $row['tanggal']] = (int) $row['jml'];
    }
    return [
        'total'    => (int) ($r['total'] ?? 0),
        'dilayani' => (int) ($r['dilayani'] ?? 0),
        'belum'    => (int) ($r['belum'] ?? 0),
        'per_hari' => $perHari,
    ];
}

/**
 * Hapus data tiket (beserta riwayat tracking-nya) pada rentang tanggal.
 * Dipakai untuk membersihkan data sisa/uji supaya laporan bersih.
 * Penomoran harian TIDAK diubah (nomor sudah tercetak tidak dipakai ulang).
 * Dijalankan dalam satu transaksi dan dicatat ke jejak audit oleh pemanggilnya.
 */
function data_hapus(string $dari, string $sampai): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT id FROM tiket WHERE tanggal >= ? AND tanggal <= ?');
        $st->execute([$dari, $sampai]);
        $id = $st->fetchAll(PDO::FETCH_COLUMN);

        $hapusTiket = 0;
        $hapusLog   = 0;
        if ($id) {
            $daftar = implode(',', array_map('intval', $id));
            $hapusLog   = (int) $pdo->exec('DELETE FROM tiket_log WHERE tiket_id IN (' . $daftar . ')');
            $hapusTiket = (int) $pdo->exec('DELETE FROM tiket WHERE id IN (' . $daftar . ')');
        }
        /* Permintaan pencarian pasien (SIMRS) pada periode itu ikut dibersihkan. */
        $stP = $pdo->prepare('DELETE FROM simrs_permintaan WHERE substr(created_at, 1, 10) >= ? AND substr(created_at, 1, 10) <= ?');
        $stP->execute([$dari, $sampai]);
        $hapusSimrs = $stP->rowCount();

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['tiket' => $hapusTiket, 'log' => $hapusLog, 'simrs' => $hapusSimrs];
}

/**
 * Reset antrian sekarang: menghapus tiket hari ini yang BELUM selesai dan mengembalikan
 * penomoran ke 001. Tiket kode J tidak ikut (reset bulanan otomatis tiap tanggal 1).
 * Tiket yang sudah selesai tetap tersimpan supaya laporan tidak kehilangan riwayat.
 */
function reset_antrian(bool $termasukJ = false): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $sql = 'DELETE FROM tiket WHERE tanggal = ? AND status <> ? AND called_at IS NULL';
        $par = [hari_ini(), ST_DITERIMA];
        if (!$termasukJ) {
            $sql .= ' AND kode <> "J"';
        }
        $st = $pdo->prepare($sql);
        $st->execute($par);
        $hapus = $st->rowCount();

        /* Penomoran dikembalikan ke 001 (counter di-set 0, bukan dihapus, agar tidak
           dihitung ulang dari tiket lama yang sudah diserahkan dan tetap ada untuk laporan). */
        $sqlC = 'UPDATE nomor_counter SET nomor = 0 WHERE periode = ?';
        $parC = [hari_ini()];
        if (!$termasukJ) {
            $sqlC .= ' AND kode <> "J"';
        }
        $pdo->prepare($sqlC)->execute($parC);

        /* Kode J penomorannya bulanan (periode YYYY-MM), jadi counter-nya direset terpisah. */
        if ($termasukJ) {
            $pdo->prepare('UPDATE nomor_counter SET nomor = 0 WHERE kode = "J" AND periode = ?')
                ->execute([date('Y-m')]);
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    set_setting('reset_terakhir', now_sql() . ' oleh ' . sesi_username());
    log_admin('Reset antrean', $hapus . ' tiket belum selesai dihapus'
        . ($termasukJ ? ' (termasuk kode J)' : ' (kode J tidak diikutsertakan)'));
    return ['dihapus' => $hapus];
}

function sesi_username(): string
{
    $u = auth_user();
    return $u ? (string) $u['username'] : 'sistem';
}

/* ------------------------------------------------------------------ */
/* Uji alamat publik (untuk QR / halaman lacak)                        */
/* ------------------------------------------------------------------ */

/*
 * Kesalahan yang paling sering terjadi: alamat publik diisi MEMUAT nama folder aplikasi
 * padahal aplikasi dibuka lewat DOMAIN SENDIRI (di sana aplikasi disajikan di akar domain,
 * sehingga "/<slug>" justru tidak ada) — akibatnya QR menuju halaman yang tidak berfungsi.
 * Fungsi di bawah MEMBUKTIKAN alamat dengan permintaan HTTP sungguhan dan, bila alamatnya
 * salah, mencoba alamat induknya (tanpa segmen terakhir) untuk memberi saran perbaikan.
 */

/** Rapikan alamat: buang spasi & garis miring di ujung. */
function alamat_normal(string $url): string
{
    return rtrim(trim($url), '/');
}

/** Ambil alamat induk: buang satu segmen path terakhir (untuk saran perbaikan). */
function alamat_induk(string $url): string
{
    $bagian = parse_url($url);
    if (!$bagian || empty($bagian['scheme']) || empty($bagian['host'])) {
        return '';
    }
    $path = (string) ($bagian['path'] ?? '');
    if ($path === '' || $path === '/') {
        return '';
    }
    /* Nomor port WAJIB ikut disertakan — tanpa itu saran alamat menunjuk host yang salah. */
    $otoritas = (string) $bagian['host'] . (isset($bagian['port']) ? ':' . (int) $bagian['port'] : '');
    $induk = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if ($induk === '' || $induk === '.') {
        return $bagian['scheme'] . '://' . $otoritas;
    }
    return $bagian['scheme'] . '://' . $otoritas . $induk;
}

/** Permintaan HTTP ringan; mengembalikan ['kode'=>int, 'isi'=>string, 'galat'=>string]. */
function http_periksa(string $url, int $timeout = 10): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   /* status asli penting untuk mendeteksi pengalihan */
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AntrianApotek-CekAlamat/1.0',
        ]);
        $isi  = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $galat = (string) curl_error($ch);
        $lokasi = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['kode' => $kode, 'isi' => is_string($isi) ? $isi : '', 'galat' => $galat, 'lokasi' => $lokasi];
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "User-Agent: AntrianApotek-CekAlamat/1.0\r\n",
    ]]);
    $isi = @file_get_contents($url, false, $ctx);
    $kode = 0;
    $lokasi = '';
    foreach (($http_response_header ?? []) as $baris) {
        if (preg_match('#^HTTP/\S+\s(\d{3})#i', $baris, $m)) {
            $kode = (int) $m[1];
        }
        if (stripos($baris, 'location:') === 0) {
            $lokasi = trim(substr($baris, 9));
        }
    }
    return ['kode' => $kode, 'isi' => is_string($isi) ? $isi : '', 'galat' => $isi === false ? 'tidak dapat dihubungi' : '', 'lokasi' => $lokasi];
}

/**
 * Uji apakah sebuah alamat publik benar-benar melayani aplikasi ini.
 * Mengembalikan: ok, kode, pesan, dasar (alamat yang diuji), saran (alamat yang mungkin benar).
 */
function uji_alamat_publik(string $dasar): array
{
    $dasar = alamat_normal($dasar);
    if ($dasar === '') {
        return ['ok' => false, 'kode' => 0, 'dasar' => '', 'pesan' => 'Alamat publik belum diisi.', 'saran' => ''];
    }
    if (!preg_match('#^https?://#i', $dasar)) {
        return ['ok' => false, 'kode' => 0, 'dasar' => $dasar, 'pesan' => 'Alamat harus dimulai dengan http:// atau https://', 'saran' => ''];
    }

    $uji = function (string $base): array {
        $r = http_periksa($base . '/api.php?action=display', 10);
        $json = json_decode($r['isi'], true);
        $benar = $r['kode'] === 200 && is_array($json) && !empty($json['ok']);
        return ['benar' => $benar, 'kode' => $r['kode'], 'galat' => $r['galat']];
    };

    $r0 = http_periksa($dasar . '/api.php?action=display', 10);
    $j0 = json_decode($r0['isi'], true);
    $coba = ['benar' => $r0['kode'] === 200 && is_array($j0) && !empty($j0['ok']), 'kode' => $r0['kode'], 'galat' => $r0['galat']];
    if ($coba['benar']) {
        return ['ok' => true, 'kode' => 200, 'dasar' => $dasar, 'saran' => '',
                'pesan' => 'Alamat benar — aplikasi terjangkau di ' . $dasar . '.'];
    }

    /*
     * Belum benar: coba beberapa calon alamat yang benar.
     *  (a) alamat INDUK (dugaan salah menyertakan nama folder aplikasi — kasus paling umum), dan
     *  (b) alamat dari header Location pada pengalihan (server memberi tahu alamat sebenarnya,
     *      mis. platform mengalihkan ke alamat yang menyertakan nama folder aplikasi).
     */
    $calon = [];
    $induk = alamat_induk($dasar);
    if ($induk !== '' && $induk !== $dasar) { $calon[] = $induk; }
    $lokasi = (string) ($r0['lokasi'] ?? '');
    if ($lokasi !== '') {
        $bagianLokasi = parse_url($lokasi);
        if ($basah = ($bagianLokasi['scheme'] ?? '')) {
            $pathLokasi = (string) ($bagianLokasi['path'] ?? '');
            /* Buang nama berkas (api.php) dari lintasan pengalihan. */
            $dirLokasi = rtrim(str_replace('\\', '/', dirname($pathLokasi)), '/');
            $portLokasi = isset($bagianLokasi['port']) ? ':' . (int) $bagianLokasi['port'] : '';
            $kandidat = $basah . '://' . ($bagianLokasi['host'] ?? '') . $portLokasi . ($dirLokasi === '/' ? '' : $dirLokasi);
            if ($kandidat !== '' && $kandidat !== $dasar) { $calon[] = $kandidat; }
        }
    }
    $saran = '';
    foreach (array_unique($calon) as $c) {
        $rc = http_periksa($c . '/api.php?action=display', 8);
        $jc = json_decode($rc['isi'], true);
        if ($rc['kode'] === 200 && is_array($jc) && !empty($jc['ok'])) {
            $saran = $c;
            break;
        }
    }

    $pesan = 'Alamat ini belum melayani aplikasi (HTTP ' . $coba['kode'] . ')';
    if ($coba['kode'] === 302 || $coba['kode'] === 301 || $coba['kode'] === 308) {
        $pesan .= ' — permintaannya dialihkan ke halaman lain.';
    } elseif ($coba['kode'] === 404) {
        $pesan .= ' — halaman tidak ditemukan.';
    } elseif ($coba['kode'] === 0) {
        $pesan .= ' — tidak dapat dihubungi' . ($coba['galat'] !== '' ? ' (' . $coba['galat'] . ')' : '') . '.';
    } elseif ($coba['kode'] >= 500) {
        $pesan .= ' — kesalahan di server.';
    } else {
        $pesan .= '.';
    }
    if ($saran !== '') {
        $pesan .= ' Kemungkinan alamat yang benar: ' . $saran . '.';
    }

    return ['ok' => false, 'kode' => $coba['kode'], 'dasar' => $dasar, 'pesan' => $pesan, 'saran' => $saran];
}

/**
 * Hasil uji alamat dengan CACHE 60 detik (disimpan di pengaturan). Tanpa cache, setiap kali
 * halaman Pengaturan dibuka server akan memanggil dirinya sendiri — pemborosan yang tidak perlu.
 * $paksa = true → selalu uji ulang (dipakai tombol "Uji Alamat Sekarang").
 */
function uji_alamat_cache(bool $paksa = false): array
{
    $alamat = alamat_publik_aktif();
    if (!$paksa) {
        $simpan = json_decode((string) setting('lacak_uji', ''), true);
        if (is_array($simpan) && ($simpan['alamat'] ?? '') === $alamat
            && isset($simpan['waktu']) && (time() - (int) $simpan['waktu']) < 60) {
            return $simpan + ['dari_cache' => true];
        }
    }
    $hasil = uji_alamat_publik($alamat);
    $hasil['alamat'] = $alamat;
    $hasil['waktu'] = time();
    set_setting('lacak_uji', json_encode($hasil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $hasil + ['dari_cache' => false];
}

/** Alamat publik yang sedang dipakai (dari pengaturan, atau dideteksi otomatis). */
function alamat_publik_aktif(): string
{
    $manual = alamat_normal(setting('lacak_url', ''));
    return $manual !== '' ? $manual : url_publik_dasar();
}

/* ------------------------------------------------------------------ */
/* Panel video display (berkas video yang diunggah, diputar berulang)   */
/* ------------------------------------------------------------------ */

/*
 * Video TIDAK memakai embed YouTube: pemilik mengunggah berkasnya, lalu display
 * memutarnya berulang tanpa henti. Berkas disimpan di penyimpanan media platform
 * (bukan folder aplikasi) supaya:
 *   1) folder/penyimpanan aplikasi tidak cepat penuh,
 *   2) video tetap bisa diputar di mana pun display ditampilkan (disajikan dari CDN),
 *   3) berkas tetap utuh walau aplikasi dipublikasikan ulang.
 * Yang disimpan di database hanyalah daftar {url, nama, ukuran, waktu}.
 */

const VIDEO_EXT_OK = ['mp4', 'webm', 'mov', 'm4v', 'ogv'];

/** Daftar video yang diunggah (urut sesuai waktu unggah). */
function video_daftar(): array
{
    $mentah = (string) setting('video_daftar', '');
    if ($mentah === '') {
        return [];
    }
    $data = json_decode($mentah, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $v) {
        if (!is_array($v) || empty($v['url'])) {
            continue;
        }
        $out[] = [
            'url'    => (string) $v['url'],
            'nama'   => (string) ($v['nama'] ?? 'video'),
            'ukuran' => (int) ($v['ukuran'] ?? 0),
            'waktu'  => (string) ($v['waktu'] ?? ''),
        ];
    }
    return $out;
}

/** Simpan daftar video (maksimal 6 berkas agar pemutaran tetap wajar). */
function video_simpan(array $daftar): void
{
    $daftar = array_slice(array_values($daftar), 0, 6);
    set_setting('video_daftar', json_encode($daftar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function video_aktif(): bool
{
    return setting_bool('video_aktif', false) && count(video_daftar()) > 0;
}

/** Total ukuran berkas video yang terdaftar (untuk ditampilkan di Pengaturan). */
function video_total_ukuran(): int
{
    $total = 0;
    foreach (video_daftar() as $v) {
        $total += (int) $v['ukuran'];
    }
    return $total;
}

/**
 * Batas ukuran SATU berkas yang benar-benar berlaku di server (byte).
 * Dibaca dari konfigurasi PHP saat berjalan — batas ini dikunci platform, jadi aplikasi
 * menampilkan angkanya apa adanya kepada pemilik (bukan angka karangan).
 */
function video_batas_unggah(): int
{
    $nilai = (string) ini_get('upload_max_filesize');
    return video_parse_ukuran($nilai);
}

/** Batas total satu kali kirim (post_max_size) — kalau dilampaui, PHP membuang semua berkas. */
function video_batas_kirim(): int
{
    $nilai = (string) ini_get('post_max_size');
    return video_parse_ukuran($nilai);
}

/** Ubah nilai gaya PHP ("20M", "1G") menjadi byte. */
function video_parse_ukuran(string $nilai): int
{
    $nilai = trim($nilai);
    if ($nilai === '') {
        return 0;
    }
    $satuan = strtolower(substr($nilai, -1));
    $angka = (float) $nilai;
    switch ($satuan) {
        case 'g': return (int) ($angka * 1024 * 1024 * 1024);
        case 'm': return (int) ($angka * 1024 * 1024);
        case 'k': return (int) ($angka * 1024);
        default:  return (int) $angka;
    }
}

/* ------------------------------------------------------------------ */
/* Unggah video berpotongan (chunked)                                  */
/* ------------------------------------------------------------------ */

/*
 * Batas ukuran unggah PHP dikunci platform (tidak bisa dinaikkan), jadi video besar
 * dikirim BERPOTONGAN: potongan-potongan digabungkan menjadi satu berkas sementara di
 * folder data aplikasi, lalu diteruskan ke penyimpanan media dan berkas sementara DIHAPUS.
 * Dengan cara ini video besar tetap bisa diunggah tanpa menumpuk di penyimpanan aplikasi.
 */

function video_tmp_dir(): string
{
    $dir = __DIR__ . '/data/tmp-video';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Nama berkas sementara yang aman (id dari klien hanya boleh huruf/angka). */
function video_tmp_path(string $id): ?string
{
    $id = preg_replace('/[^A-Za-z0-9]/', '', $id);
    if ($id === '' || strlen($id) > 40) {
        return null;
    }
    return video_tmp_dir() . '/' . $id . '.part';
}

/** Info berkas sementara (untuk menghindari bentrokan/kelanjutan salah). */
function video_tmp_info(string $id): array
{
    $path = video_tmp_path($id);
    $meta = $path . '.json';
    $info = ['id' => $id, 'nama' => '', 'ukuran' => 0];
    if ($path && is_file($meta)) {
        $baca = json_decode((string) file_get_contents($meta), true);
        if (is_array($baca)) {
            $info = array_merge($info, $baca);
        }
    }
    return $info;
}

function video_tmp_simpan_info(string $id, array $info): void
{
    $path = video_tmp_path($id);
    if ($path) {
        @file_put_contents($path . '.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    }
}

/** Hapus berkas sementara sebuah unggahan. */
function video_tmp_hapus(string $id): void
{
    $path = video_tmp_path($id);
    if ($path) {
        @unlink($path);
        @unlink($path . '.json');
    }
}

/** Bersihkan berkas sementara yang tertinggal (mis. unggahan dibatalkan). */
function video_tmp_bersihkan(int $jam = 2): int
{
    $n = 0;
    foreach ((array) glob(video_tmp_dir() . '/*.part*') as $f) {
        if (is_file($f) && filemtime($f) < time() - $jam * 3600) {
            @unlink($f);
            $n++;
        }
    }
    return $n;
}

/** Maksimal video yang boleh tersimpan di panel display. */
const VIDEO_MAKS = 6;

/** Ukuran berkas dalam bentuk yang mudah dibaca (12,4 MB). */
function ukuran_teks(int $bytes): string
{
    if ($bytes <= 0) {
        return '-';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

/**
 * Sidik jari konfigurasi video — dipakai display untuk tahu kapan panel perlu dimuat ulang
 * (mis. pemilik menambah/menghapus video atau mengubah setelan suara).
 */
function video_stamp(): string
{
    $url = [];
    foreach (video_daftar() as $v) {
        $url[] = $v['url'];
    }
    return md5(implode('|', $url) . '|' . (setting_bool('video_suara', false) ? '1' : '0')
        . '|' . (string) setting('video_judul', ''));
}

/* ------------------------------------------------------------------ */
/* Jejak audit tindakan                                                */
/* ------------------------------------------------------------------ */

/** Catat tindakan penting (siapa, kapan, apa) + simpan hanya 500 catatan terakhir. */
function log_admin(string $aksi, string $rincian = ''): void
{
    try {
        db()->prepare('INSERT INTO log_admin (waktu, oleh, aksi, rincian) VALUES (?, ?, ?, ?)')
            ->execute([now_sql(), sesi_username(), $aksi, $rincian]);
        /* Pangkas agar database tidak membengkak. */
        db()->exec('DELETE FROM log_admin WHERE id <= (SELECT MAX(id) FROM log_admin) - 500');
    } catch (Throwable $e) {
        /* Pencatatan tidak boleh menggagalkan tindakan utama. */
    }
}

/** Catatan audit terbaru (untuk ditampilkan di Pengaturan). */
function log_admin_terakhir(int $batas = 15): array
{
    try {
        $st = db()->prepare('SELECT waktu, oleh, aksi, rincian FROM log_admin ORDER BY id DESC LIMIT ' . max(1, $batas));
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------------ */
/* Integrasi SIMRS (SIMGOS) lewat agen di jaringan rumah sakit          */
/* ------------------------------------------------------------------ */

/*
 * Alur metode `agen` (lama): petugas mengetik No. RM di form ambil antrian → aplikasi mencatat
 * PERMINTAAN → AGEN di jaringan RS mengambil permintaan itu (koneksi keluar dari jaringan RS),
 * membaca data pasien dari database SIMGOS, lalu mengirim jawabannya kembali ke aplikasi.
 *
 * Alur metode `rest` (permintaan pemilik, 2026-10-07): aplikasi memanggil REST API SIMGOS
 * langsung — cukup mengisi alamat server + jalur pencarian. Hanya No. RM dan NAMA PASIEN
 * yang diambil; data pasien lain tidak diambil dan tidak disimpan.
 */

const SIMRS_MENUNGGU = 'menunggu';
const SIMRS_SELESAI  = 'selesai';
const SIMRS_GAGAL    = 'gagal';
const SIMRS_KEDALUWARSA_DETIK = 90;   /* permintaan lama dianggap gagal agar display tidak menggantung */

/** Metode integrasi yang dipakai: 'rest' (panggil API SIMGOS langsung) atau 'agen' (metode lama). */
function simrs_metode(): string
{
    return (string) setting('simrs_metode', 'rest') === 'agen' ? 'agen' : 'rest';
}

/** Apakah integrasi siap dipakai (tergantung metode yang dipilih). */
function simrs_aktif(): bool
{
    if (!setting_bool('simrs_aktif', false)) {
        return false;
    }
    if (simrs_metode() === 'rest') {
        return trim(setting('simrs_rest_url', '')) !== '';
    }
    return setting('simrs_token', '') !== '';
}

function simrs_token(): string
{
    return (string) setting('simrs_token', '');
}

/** Apakah agen masih terlihat hidup (polling dalam 60 detik terakhir). */
function simrs_agen_online(): bool
{
    $t = (string) setting('simrs_agen_terakhir', '');
    if ($t === '') {
        return false;
    }
    $ts = strtotime($t);
    return $ts !== false && (time() - $ts) <= 60;
}

/** Catat waktu polling agen (dipanggil dari endpoint agen). */
function simrs_catat_agen(string $versi = ''): void
{
    set_setting('simrs_agen_terakhir', now_sql());
    if ($versi !== '') {
        set_setting('simrs_agen_versi', substr($versi, 0, 40));
    }
}

/** Buat permintaan pencarian No. RM. Mengembalikan id permintaan. */
function simrs_minta(string $noRm, string $oleh): int
{
    $noRm = trim($noRm);
    if ($noRm === '') {
        throw new RuntimeException('Nomor rekam medis belum diisi.');
    }
    if (strlen($noRm) > 24) {   /* No. RM berupa angka; hindari mb_* (tidak dijamin ada) */
        $noRm = substr($noRm, 0, 24);
    }

    /* Pakai ulang hasil yang SAMA dalam 5 menit terakhir supaya tidak memanggil SIMGOS berulang. */
    $st = db()->prepare('SELECT * FROM simrs_permintaan WHERE no_rm = ? AND status = ? AND created_at > ?
                         ORDER BY id DESC LIMIT 1');
    $st->execute([$noRm, SIMRS_SELESAI, date('Y-m-d H:i:s', time() - 300)]);
    $lama = $st->fetch();
    if ($lama) {
        return (int) $lama['id'];
    }

    db()->prepare('INSERT INTO simrs_permintaan (no_rm, status, oleh, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$noRm, SIMRS_MENUNGGU, $oleh, now_sql()]);
    return (int) db()->lastInsertId();
}

/** Permintaan yang belum dijawab agen (untuk endpoint agen). */
function simrs_ambil_tugas(int $batas = 10): array
{
    /* Tandai yang sudah kedaluwarsa lebih dulu. */
    simrs_bersihkan_kedaluwarsa();

    $st = db()->prepare('SELECT id, no_rm, created_at FROM simrs_permintaan WHERE status = ?
                         ORDER BY id ASC LIMIT ' . max(1, min(50, $batas)));
    $st->execute([SIMRS_MENUNGGU]);
    return $st->fetchAll();
}

/** Jawaban agen atas sebuah permintaan. */
function simrs_jawab(int $id, array $data): array
{
    $st = db()->prepare('SELECT * FROM simrs_permintaan WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        throw new RuntimeException('Permintaan tidak ditemukan.');
    }
    $ok      = !empty($data['ok']);
    $nama    = trim((string) ($data['nama'] ?? ''));
    $pesan   = trim((string) ($data['pesan'] ?? ''));
    $status  = ($ok && $nama !== '') ? SIMRS_SELESAI : SIMRS_GAGAL;

    db()->prepare('UPDATE simrs_permintaan SET status = ?, nama = ?, tgl_lahir = ?, jenis_kelamin = ?,
                   pesan = ?, selesai_at = ? WHERE id = ?')
        ->execute([
            $status,
            $nama,
            trim((string) ($data['tgl_lahir'] ?? '')),
            trim((string) ($data['jenis_kelamin'] ?? '')),
            $pesan !== '' ? $pesan : ($ok ? '' : 'Data pasien tidak ditemukan.'),
            now_sql(),
            $id,
        ]);
    return ['status' => $status];
}

/** Permintaan yang menggantung terlalu lama ditandai gagal (mis. agen mati). */
function simrs_bersihkan_kedaluwarsa(): int
{
    $st = db()->prepare('UPDATE simrs_permintaan SET status = ?, pesan = ?, selesai_at = ?
                         WHERE status = ? AND created_at < ?');
    $st->execute([
        SIMRS_GAGAL,
        'SIMRS tidak merespons (agen di jaringan RS mungkin mati). Isi nama pasien secara manual.',
        now_sql(),
        SIMRS_MENUNGGU,
        date('Y-m-d H:i:s', time() - SIMRS_KEDALUWARSA_DETIK),
    ]);
    return $st->rowCount();
}

/** Hapus riwayat permintaan lama (menjaga database tetap ringan & tidak menyimpan data pasien lama). */
function simrs_purge(int $hari = 2): int
{
    $st = db()->prepare('DELETE FROM simrs_permintaan WHERE created_at < ?');
    $st->execute([date('Y-m-d H:i:s', time() - $hari * 86400)]);
    return $st->rowCount();
}

/**
 * Status (untuk dipolling halaman petugas) sebuah permintaan pencarian.
 * Akses sudah dibatasi oleh sesi login di api.php (semua petugas melihat antrean yang sama).
 */
function simrs_status(int $id): array
{
    $st = db()->prepare('SELECT * FROM simrs_permintaan WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return ['ok' => false, 'error' => 'Permintaan tidak ditemukan.'];
    }
    /* Permintaan kedaluwarsa → beri tahu apa adanya. */
    if ($p['status'] === SIMRS_MENUNGGU && strtotime((string) $p['created_at']) < time() - SIMRS_KEDALUWARSA_DETIK) {
        simrs_bersihkan_kedaluwarsa();
        $st->execute([$id]);
        $p = $st->fetch();
    }
    return [
        'ok'           => true,
        'id'           => (int) $p['id'],
        'no_rm'        => (string) $p['no_rm'],
        'status'       => (string) $p['status'],
        'nama'         => (string) $p['nama'],
        'tgl_lahir'    => (string) $p['tgl_lahir'],
        'jenis_kelamin'=> (string) $p['jenis_kelamin'],
        'pesan'        => (string) $p['pesan'],
        'agen_online'  => simrs_agen_online(),
    ];
}

/* ================================================================== */
/* SIMRS metode REST API — memanggil server SIMGOS langsung            */
/* ================================================================== */
/*
 * Yang perlu diketahui saat mengubah bagian ini:
 * - Pemanggilan dilakukan SERVER-SIDE dengan curl (ada di runtime publik), dengan
 *   batas waktu pendek (8 detik) karena runtime PHP di sini melayani satu permintaan
 *   sekaligus — jangan sampai satu pencarian memblokir petugas lain terlalu lama.
 * - Jawaban SIMGOS berbeda-beda antar instalasi. Karena itu nama field TIDAK dipatok:
 *   bila pemilik mengisi jalur field (mis. `data.nama`) dipakai itu; kalau tidak, nama
 *   dicari otomatis dari kunci-kunci yang lazim (nama, nama_pasien, patient_name, ...).
 *   Ini yang membuat "cukup isi alamat server" bisa berhasil pada banyak SIMGOS.
 * - Hanya RM + nama pasien yang dipakai (permintaan pemilik); data lain diabaikan.
 */

/** Alamat dasar SIMGOS tanpa garis miring di ujung. */
function simrs_rest_url(): string
{
    return rtrim(trim((string) setting('simrs_rest_url', '')), '/');
}

/**
 * Rapikan alamat server: boleh ditulis tanpa "https://" (pemilik cukup menulis domain),
 * lalu ditambahkan sendiri. Ini yang membuat "cukup memasukkan domain server SIMGOS" bekerja.
 */
function simrs_rest_rapikan_dasar(string $url): string
{
    $url = trim($url);
    if ($url === '') { return ''; }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    return rtrim($url, '/');
}

/** Jalur pencarian (template, berisi {rm}) yang sudah dirapikan. */
function simrs_rest_jalur(): string
{
    $j = trim((string) setting('simrs_rest_jalur', '/api/pasien/{rm}'));
    if ($j === '') { $j = '/api/pasien/{rm}'; }
    return $j[0] === '/' ? $j : '/' . $j;
}

function simrs_rest_http(): string
{
    return strtoupper((string) setting('simrs_rest_http', 'GET')) === 'POST' ? 'POST' : 'GET';
}

/** Alamat lengkap pencarian: alamat dasar + jalur dengan {rm} diganti nomor rekam medis. */
function simrs_rest_susun(string $rm, string $jalur = '', string $dasar = ''): string
{
    $dasar = $dasar !== '' ? simrs_rest_rapikan_dasar($dasar) : simrs_rest_url();
    if ($dasar === '') { return ''; }
    $jalur = $jalur !== '' ? $jalur : simrs_rest_jalur();
    if ($jalur !== '' && $jalur[0] !== '/') { $jalur = '/' . $jalur; }
    $rm = rawurlencode(trim($rm));
    $isi = str_replace('{rm}', $rm, $jalur);
    /*
     * Bila jalur TIDAK memuat penanda {rm} (dan tidak memakai query "?..=.."), nomor rekam medis
     * ditempelkan di ujung jalur — tetapi HANYA untuk GET. Pada POST, nomor itu sudah dikirim di
     * badan permintaan, jadi jalurnya harus dibiarkan apa adanya.
     */
    if (strpos($jalur, '{rm}') === false && strpos($jalur, '=') === false && simrs_rest_http() === 'GET') {
        $isi = rtrim($isi, '/') . '/' . $rm;
    }
    return $dasar . $isi;
}

/**
 * Panggil satu alamat HTTP dan kembalikan ['ok','kode','body','error','waktu'].
 * $header/$nilai opsional (mis. Authorization: Bearer xxx).
 */
function simrs_rest_http_minta(string $url, string $metode = 'GET', string $rm = '', string $header = '', string $nilai = '', int $timeout = 8): array
{
    $kepala = ['Accept: application/json'];
    $header = trim($header);
    if ($header !== '' && trim($nilai) !== '') {
        $kepala[] = $header . ': ' . trim($nilai);
    }
    $body = '';
    if ($metode === 'POST') {
        $kepala[] = 'Content-Type: application/json';
        /* Dua nama kunci sekaligus supaya cocok dengan SIMGOS yang memakai "no_rm" maupun "rm". */
        $body = json_encode(['no_rm' => $rm, 'rm' => $rm, 'noRekamMedis' => $rm], JSON_UNESCAPED_UNICODE);
    }
    $mulai = microtime(true);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opsi = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $kepala,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];
        if ($metode === 'POST') {
            $opsi[CURLOPT_POST] = true;
            $opsi[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opsi);
        $out  = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);
        return [
            'ok'    => $out !== false && $kode >= 200 && $kode < 300,
            'kode'  => $kode,
            'body'  => $out === false ? '' : (string) $out,
            'error' => $out === false ? ('tidak dapat dihubungi (' . ($err !== '' ? $err : 'waktu habis') . ')') : '',
            'waktu' => round(microtime(true) - $mulai, 2),
        ];
    }

    /* Cadangan tanpa curl (mis. di server uji): stream context biasa. */
    $opsiHttp = [
        'method'        => $metode,
        'header'        => implode("\r\n", $kepala),
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ];
    if ($metode === 'POST') { $opsiHttp['content'] = $body; }
    $out = @file_get_contents($url, false, stream_context_create(['http' => $opsiHttp]));
    /* Status HTTP diambil dari baris pertama header jawaban (PHP mengisinya otomatis). */
    $kode = 0;
    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $baris) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $baris, $m)) {
                $kode = (int) $m[1];
                break;
            }
        }
    }
    return [
        'ok'    => $out !== false && $kode >= 200 && $kode < 300,
        'kode'  => $kode,
        'body'  => $out === false ? '' : (string) $out,
        'error' => $out === false ? 'tidak dapat dihubungi (php tanpa curl)' : '',
        'waktu' => round(microtime(true) - $mulai, 2),
    ];
}

/** Ambil nilai JSON berdasarkan jalur bertitik (mis. `data.pasien.nama`). */
function simrs_rest_ambil_jalur($data, string $jalur)
{
    $jalur = trim($jalur);
    if ($jalur === '') { return null; }
    $kunci = explode('.', $jalur);
    $kini  = $data;
    foreach ($kunci as $k) {
        $k = trim($k);
        if ($k === '') { continue; }
        if (is_array($kini) && array_key_exists($k, $kini)) {
            $kini = $kini[$k];
            continue;
        }
        /* dukung juga penulisan bentuk array: data.0.nama */
        if (is_array($kini) && preg_match('/^\d+$/', $k) && array_key_exists((int) $k, $kini)) {
            $kini = $kini[(int) $k];
            continue;
        }
        return null;
    }
    return is_scalar($kini) ? $kini : null;
}

/**
 * Cari nilai nama pasien di dalam jawaban JSON apa pun bentuknya.
 * Dipakai bila pemilik tidak mengisi jalur field. Mengembalikan ['nilai','kunci'] atau null.
 */
function simrs_rest_cari_nama($data): ?array
{
    $prioritas = [
        'namapasien' => 100, 'namalengkap' => 95, 'namapasienlengkap' => 98, 'patientname' => 96,
        'nm_pasien' => 94, 'nmpsien' => 90, 'nama' => 80, 'name' => 70, 'nama_penderita' => 88,
        'pasiennama' => 92, 'fullname' => 60,
    ];
    $terbaik = null;
    $kunjungi = function ($simpul, string $jejak, int $kedalaman) use (&$kunjungi, &$terbaik, $prioritas) {
        if ($kedalaman > 6) { return; }
        if (!is_array($simpul)) { return; }
        foreach ($simpul as $k => $v) {
            $kunci = is_int($k) ? (string) $k : (string) $k;
            $jejakBaru = $jejak === '' ? $kunci : $jejak . '.' . $kunci;
            if (is_array($v)) {
                $kunjungi($v, $jejakBaru, $kedalaman + 1);
                continue;
            }
            if (!is_string($v) && !is_int($v)) { continue; }
            $nilai = trim((string) $v);
            if ($nilai === '' || is_numeric($nilai)) { continue; }
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $kunci));
            $skor = 0;
            if (isset($prioritas[$norm])) {
                $skor = $prioritas[$norm];
            } elseif (strpos($norm, 'nama') !== false && (strpos($norm, 'pasien') !== false || strpos($norm, 'patient') !== false)) {
                $skor = 85;
            } elseif (strpos($norm, 'nama') !== false) {
                $skor = 50;
            } elseif (strpos($norm, 'name') !== false) {
                $skor = 40;
            }
            if ($skor <= 0) { continue; }
            /* Nama orang biasanya berupa kata (ada huruf) dan tidak terlalu panjang. */
            if (!preg_match('/[A-Za-z]/', $nilai) || strlen($nilai) > 80) { $skor -= 30; }
            if ($terbaik === null || $skor > $terbaik['skor']) {
                $terbaik = ['nilai' => $nilai, 'kunci' => $jejakBaru, 'skor' => $skor];
            }
        }
    };
    $kunjungi($data, '', 0);
    return $terbaik;
}

/** Ringkas jawaban SIMGOS untuk ditampilkan di Pengaturan (dipotong agar tidak membengkak). */
function simrs_rest_ringkas(string $body, int $batas = 600): string
{
    $b = trim(preg_replace('/\s+/', ' ', $body));
    if (function_exists('mb_substr')) { return mb_substr($b, 0, $batas, 'UTF-8'); }
    return substr($b, 0, $batas);
}

/**
 * Uji satu alamat/jalur: mengembalikan hasil apa adanya untuk ditampilkan ke pemilik.
 * $jalur kosong = pakai jalur dari Pengaturan; $dasar kosong = alamat dari Pengaturan.
 */
function simrs_rest_uji(string $rm, string $jalur = '', string $dasar = ''): array
{
    $url = simrs_rest_susun($rm, $jalur, $dasar);
    if ($url === '') {
        return ['ok' => false, 'url' => '', 'error' => 'Alamat server SIMGOS belum diisi.', 'kode' => 0,
                'ringkas' => '', 'nama' => '', 'kunci' => '', 'waktu' => 0];
    }
    $hasil = simrs_rest_http_minta(
        $url,
        simrs_rest_http(),
        $rm,
        (string) setting('simrs_rest_header', ''),
        (string) setting('simrs_rest_nilai', ''),
        8
    );
    $json = json_decode($hasil['body'], true);
    $nama = '';
    $kunci = '';
    if (is_array($json)) {
        $jalurNama = (string) setting('simrs_rest_field_nama', '');
        if ($jalurNama !== '') {
            $v = simrs_rest_ambil_jalur($json, $jalurNama);
            if (is_scalar($v) && trim((string) $v) !== '') { $nama = trim((string) $v); $kunci = $jalurNama; }
        }
        if ($nama === '') {
            $ketemu = simrs_rest_cari_nama($json);
            if ($ketemu) { $nama = $ketemu['nilai']; $kunci = $ketemu['kunci']; }
        }
    }
    return [
        'ok'      => $hasil['ok'] && $nama !== '',
        'url'     => $url,
        'kode'    => (int) $hasil['kode'],
        'error'   => $hasil['error'],
        'ringkas' => simrs_rest_ringkas($hasil['body']),
        'nama'    => $nama,
        'kunci'   => $kunci,
        'json'    => is_array($json),
        'waktu'   => $hasil['waktu'],
    ];
}

/**
 * Pencarian pasien lewat REST API: satu permintaan, jawaban langsung.
 * Mengembalikan ['ok','nama','no_rm','pesan','mentah'].
 */
function simrs_rest_cari(string $rm, string $oleh): array
{
    $hasil = simrs_rest_uji($rm);
    simrs_catat_hasil($rm, $oleh, $hasil['nama'], $hasil['ok'],
        $hasil['ok'] ? '' : simrs_rest_pesan_galat($hasil));

    if ($hasil['ok']) {
        return [
            'ok'     => true,
            'nama'   => $hasil['nama'],
            'no_rm'  => $rm,
            'pesan'  => 'Data pasien ditemukan (field "' . $hasil['kunci'] . '").',
            'mentah' => $hasil['ringkas'],
        ];
    }
    return [
        'ok'    => false,
        'nama'  => '',
        'no_rm' => $rm,
        'pesan' => simrs_rest_pesan_galat($hasil),
        'mentah'=> $hasil['ringkas'],
    ];
}

/** Pesan galat yang bisa dimengerti pemilik (bukan istilah teknis saja). */
function simrs_rest_pesan_galat(array $hasil): string
{
    if ($hasil['error'] !== '') {
        return 'Server SIMGOS tidak dapat dihubungi (' . $hasil['error'] . '). Periksa alamat server '
            . 'dan pastikan server SIMGOS bisa diakses dari internet.';
    }
    if ((int) $hasil['kode'] === 0) {
        return 'Tidak ada jawaban dari server SIMGOS.';
    }
    if ($hasil['kode'] >= 400) {
        return 'Server SIMGOS menjawab HTTP ' . (int) $hasil['kode']
            . '. Periksa jalur pencarian, cara masuk (header/token), dan contoh jawaban di bawah.';
    }
    if (empty($hasil['json'])) {
        return 'Jawaban server SIMGOS bukan JSON (mungkin alamatnya salah, atau halaman login SIMGOS yang terbuka). '
            . 'Periksa contoh jawaban di bawah.';
    }
    return 'Jawaban diterima, tetapi nama pasien tidak ditemukan di dalamnya. Isi kolom "Jalur field nama" '
        . 'sesuai contoh jawaban (mis. data.nama), atau kirimkan contoh jawaban itu untuk disesuaikan.';
}

/** Catat hasil pencarian ke riwayat (dipakai daftar riwayat di Pengaturan). */
function simrs_catat_hasil(string $rm, string $oleh, string $nama, bool $ok, string $pesan = ''): void
{
    try {
        db()->prepare('INSERT INTO simrs_permintaan (no_rm, status, nama, pesan, oleh, created_at, selesai_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                trim($rm), $ok ? SIMRS_SELESAI : SIMRS_GAGAL, trim($nama),
                $pesan, $oleh, now_sql(), now_sql(),
            ]);
    } catch (Throwable $e) {
        /* Riwayat tidak boleh menggagalkan pencarian. */
    }
}

/**
 * Pintu masuk tunggal pencarian pasien. REST = langsung dijawab di request yang sama
 * (tidak perlu polling); AGEN = permintaan dicatat lalu ditunggu jawabannya (metode lama).
 */
function simrs_cari_pasien(string $rm, string $oleh): array
{
    $rm = trim($rm);
    if ($rm === '') {
        throw new RuntimeException('Isi nomor rekam medis terlebih dahulu.');
    }
    if (simrs_metode() === 'rest') {
        $r = simrs_rest_cari($rm, $oleh);
        return [
            'mode'    => 'rest',
            'selesai' => true,
            'ok'      => $r['ok'],
            'nama'    => $r['nama'],
            'no_rm'   => $rm,
            'status'  => $r['ok'] ? SIMRS_SELESAI : SIMRS_GAGAL,
            'pesan'   => $r['pesan'],
        ];
    }
    $id = simrs_minta($rm, $oleh);
    return ['mode' => 'agen', 'selesai' => false, 'id' => $id, 'no_rm' => $rm, 'ok' => true];
}

/* ------------------------------------------------------------------ */
/* Tautan lacak (QR) & data publik tiket                              */
/* ------------------------------------------------------------------ */

/** Token acak unik per tiket (dipakai pada tautan QR). */
function token_baru(): string
{
    return bin2hex(random_bytes(10));
}

/**
 * Alamat dasar publik aplikasi (untuk isi QR). Diisi otomatis dari permintaan
 * yang sedang berjalan; bisa ditimpa di Pengaturan bila memakai domain sendiri.
 */
function url_publik_dasar(): string
{
    $manual = trim(setting('lacak_url', ''));
    if ($manual !== '') {
        return rtrim($manual, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }
    $https = request_https();

    /*
     * Path aplikasi (mis. /antrian-apotek).
     *
     * PENTING: di server publik, prefiks subpath DILEPAS dari SCRIPT_NAME
     * (SCRIPT_NAME = /lacak.php) sehingga dirname(SCRIPT_NAME) salah menjadi "/".
     * REQUEST_URI tetap membawa prefiksnya (/antrian-apotek/lacak.php), jadi
     * path diambil dari REQUEST_URI — dan hanya dipercaya bila nama berkasnya
     * memang cocok dengan skrip yang sedang berjalan (agar aman dari URL ber-rewrite).
     */
    $namaSkrip = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $uriPath   = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $dir = '';
    if ($uriPath !== '' && ($namaSkrip === '' || basename($uriPath) === $namaSkrip)) {
        $dir = (string) dirname($uriPath);
    } elseif (($skrip = (string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== '') {
        $dir = (string) dirname($skrip);
    }
    $dir = str_replace('\\', '/', $dir);
    $dir = ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/**
 * Tautan lengkap halaman lacak untuk sebuah tiket.
 * Mengembalikan string kosong bila alamat publik aplikasi tidak dapat ditentukan —
 * lebih baik QR tidak dicetak daripada berisi tautan relatif yang tidak berguna saat dipindai.
 */
function url_lacak(array $t): string
{
    $dasar = url_publik_dasar();
    $token = (string) ($t['token'] ?? '');
    if ($dasar === '' || $token === '') {
        return '';
    }
    return $dasar . '/lacak.php?t=' . rawurlencode((string) $t['kode_tiket']) . '&k=' . rawurlencode($token);
}

function tiket_by_token(string $kodeTiket, string $token): ?array
{
    $kodeTiket = strtoupper(trim($kodeTiket));
    $token = trim($token);
    if ($kodeTiket === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM tiket WHERE kode_tiket = ? AND token = ? LIMIT 1');
    $st->execute([$kodeTiket, $token]);
    $t = $st->fetch();
    if (!$t) {
        return null;
    }
    /* Bandingkan ulang dengan hash_equals agar tidak bisa ditebak lewat waktu respons. */
    if (!hash_equals((string) $t['token'], $token)) {
        return null;
    }
    return $t;
}

/**
 * Data publik sebuah tiket untuk halaman lacak.
 * Sengaja TIDAK memuat keterangan/resep (bisa berisi nama pasien) — hanya nomor & status.
 */
function lacak_data(array $t): array
{
    $status = (string) $t['status'];
    $urutan = STATUS_URUT[$status] ?? 1;
    $selesai = $status === ST_DITERIMA;

    $beku = !empty($t['first_called_at']);
    $tunggu = $beku
        ? detik_antara((string) $t['created_at'], (string) $t['first_called_at'])
        : detik_antara((string) $t['created_at'], now_sql());
    $siap = $t['siap_at'] ? detik_antara((string) $t['created_at'], (string) $t['siap_at']) : null;

    $k = kode_row((string) $t['kode']);
    return [
        'ok'            => true,
        'kode_tiket'    => (string) $t['kode_tiket'],
        'kode'          => (string) $t['kode'],
        'nomor'         => (int) $t['nomor'],
        'layanan'       => $k ? kode_label($k) : ('Loket ' . $t['kode']),
        'tanggal'       => (string) $t['tanggal'],
        'tanggal_label' => tgl_label((string) $t['tanggal'], false),
        'is_today'      => (string) $t['tanggal'] === hari_ini(),
        'status'        => $status,
        'status_label'  => STATUS_LABEL[$status] ?? $status,
        'urutan'        => $urutan,
        'selesai'       => $selesai,
        'jam_ambil'     => jam_teks((string) $t['created_at']),
        'jam_siap'      => $t['siap_at'] ? jam_teks((string) $t['siap_at']) : null,
        'jam_panggil'   => $t['called_at'] ? jam_teks((string) $t['called_at']) : null,
        'siap_detik'    => $siap,
        'siap_teks'     => $siap === null ? null : durasi_format($siap),
        'tunggu_detik'  => $tunggu,
        'tunggu_teks'   => durasi_format($tunggu),
        'tunggu_beku'   => $beku ? 1 : 0,
        'rev'           => (string) ($t['updated_at'] ?: $t['created_at']),
        'apotek'        => setting('nama_apotek', 'Apotek'),
        'alamat'        => setting('alamat_apotek'),
        'logo_url'      => setting('logo_url'),
        'poll_detik'    => max(3, min(60, (int) setting_num('lacak_poll', 5))),
        'notif_aktif'   => setting_bool('lacak_notif', true) ? 1 : 0,
        'suara_aktif'   => setting_bool('lacak_suara', true) ? 1 : 0,
        'suara'         => [
            'lang'      => setting('suara_lang', 'id-ID'),
            'voice'     => setting('suara_voice', 'Google Bahasa Indonesia'),
            'rate'      => (float) setting_num('suara_rate', 0.95),
            'volume'    => (float) setting_num('suara_volume', 1),
            'eja_digit' => 0,
            'chime'     => 1,
            'ulang'     => 1,
            'template'  => 'Tiket {kode_tiket}. Status obat Anda: ' . (STATUS_LABEL[$status] ?? $status) . '.',
        ],
        'server_time'   => date('H:i:s'),
    ];
}

/* ------------------------------------------------------------------ */
/* Data display & panggilan                                            */
/* ------------------------------------------------------------------ */

/** Beberapa tiket yang paling terakhir dipanggil hari ini (terbaru dulu). */
function panggilan_terakhir(int $batas = 8): array
{
    $st = db()->prepare('SELECT * FROM tiket WHERE tanggal = ? AND called_at IS NOT NULL AND status = ?
                         ORDER BY COALESCE(NULLIF(recall_at, ""), called_at) DESC, id DESC LIMIT ' . max(1, $batas));
    $st->execute([hari_ini(), ST_DITERIMA]);
    return array_map('tiket_kode_ringkas', $st->fetchAll());
}

function tiket_kode_ringkas(array $t): array
{
    $status = (string) $t['status'];
    /* Waktu tunggu berhenti pada panggilan PERTAMA (first_called_at). Tiket yang sudah pernah
       dipanggil lalu dibatalkan tidak lagi menambah waktu tunggu. */
    $beku = !empty($t['first_called_at']);
    $tunggu = $beku
        ? detik_antara((string) $t['created_at'], (string) $t['first_called_at'])
        : detik_antara((string) $t['created_at'], now_sql());
    return [
        'id'          => (int) $t['id'],
        'kode'        => (string) $t['kode'],
        'nomor'       => (int) $t['nomor'],
        'kode_tiket'  => (string) $t['kode_tiket'],
        'status'      => $status,
        'status_label'=> STATUS_LABEL[$status] ?? $status,
        'keterangan'  => (string) $t['keterangan'],
        'created_at'  => (string) $t['created_at'],
        'jam_ambil'   => jam_teks((string) $t['created_at']),
        /* jam_panggil = panggilan TERBARU (termasuk panggil ulang) supaya layar petugas & display
           menampilkan waktu panggilan yang paling relevan. */
        'jam_panggil' => jam_teks(waktu_panggil_efektif($t) ?: null),
        'jam_serah'   => jam_teks($t['called_at'] ?? null),
        'dipanggil_ulang' => !empty($t['recall_at']) ? 1 : 0,
        'tunggu_detik'=> $tunggu,
        'tunggu_teks' => durasi_format($tunggu),
        'selesai'     => $status === ST_DITERIMA,
        'beku'        => $beku ? 1 : 0,   /* waktu tunggu sudah berhenti */
        'called_count'=> (int) $t['called_count'],
        /* Data pasien — HANYA dipakai halaman petugas (panggil/tracking/dashboard/laporan).
           payload_display() & lacak_data() TIDAK memakai fungsi ini, jadi nama pasien
           tidak pernah terkirim ke layar TV maupun halaman lacak pasien. */
        'no_rm'       => (string) ($t['no_rm'] ?? ''),
        'nama_pasien' => (string) ($t['nama_pasien'] ?? ''),
        /* Patokan mulai menunggu (dipakai halaman Tracking untuk menghitung sisa waktu
           sebelum tiket pindah otomatis ke OBAT SEDANG DISIAPKAN). */
        'patokan_tunggu' => tiket_patokan_tunggu($t),
    ];
}

/** Peta warna per kode: ['A' => '#dc2626', ...] dari Pengaturan → Kode Antrian. */
function warna_kode_peta(): array
{
    $peta = [];
    try {
        foreach (db()->query('SELECT kode, warna FROM kode_antrian') as $k) {
            $kode = strtoupper((string) $k['kode']);
            $warna = trim((string) $k['warna']);
            $peta[$kode] = preg_match('/^#[0-9a-fA-F]{6}$/', $warna) ? $warna : warna_kode_bawaan($kode);
        }
    } catch (Throwable $e) {
        /* Bagian display tidak boleh gagal hanya karena warna tidak terbaca. */
    }
    return $peta;
}

/**
 * Versi ringkas tiket KHUSUS layar display: data pasien (No. RM & nama) DIBUANG,
 * karena display adalah TV di ruang tunggu yang dilihat banyak orang.
 * Semua data yang dikirim ke display.php / api.php?action=display WAJIB lewat fungsi ini.
 */
function tiket_ringkas_display(array $t): array
{
    $r = tiket_kode_ringkas($t);
    unset($r['no_rm'], $r['nama_pasien']);
    return $r;
}

/**
 * Sidik jari STRUKTUR tampilan display: berubah HANYA bila ada tiket baru atau statusnya berubah.
 * Hitungan waktu tunggu (detik) sengaja TIDAK diikutkan — kalau ikut, sidik jari berubah tiap
 * detik, kolom digambar ulang terus, dan animasi gulir otomatis akan selalu kembali ke atas
 * (gejala: gulir terlihat "tidak menyambung" / berkedip).
 */
function display_stamp(): string
{
    /* Periksa dulu apakah ada tiket yang harus pindah otomatis. Ini penting: display hanya
       menggambar ulang bila sidik jari ini BERUBAH, jadi perpindahan otomatis harus terjadi
       di sini juga — kalau tidak, TV tidak akan pernah tahu statusnya sudah berubah. */
    tiket_auto_siapkan();
    $bagian = [];
    foreach (tiket_hari_ini() as $t) {
        $bagian[] = $t['id'] . ':' . $t['status'] . ':' . ((string) ($t['called_at'] ?? '')) . ':' . ((string) ($t['first_called_at'] ?? ''));
    }
    /*
     * Setelan yang memengaruhi ANIMASI gulir ikut dimasukkan ke sidik jari. Alasannya nyata:
     * display hanya menyusun ulang kolom chip bila sidik jari berubah, sehingga mengubah
     * kecepatan gulir di Pengaturan tidak akan terlihat di TV yang sedang menyala sampai
     * ada tiket baru. Dengan ikut disertakan, kecepatan baru langsung dipakai (< 1 detik).
     * PENTING: jangan pernah memasukkan nilai yang berubah sendiri (mis. hitungan detik) —
     * itu membuat kolom digambar ulang terus dan animasi gulir selalu kembali ke awal.
     */
    $bagian[] = 'gulir:' . (int) setting_num('kecepatan_gulir_selesai', 30);
    return md5(implode('|', $bagian));
}

/** Data yang dipakai halaman display & endpoint auto-refresh (publik — tanpa data pasien). */
function payload_display(): array
{
    tiket_auto_siapkan();
    $hari = tiket_hari_ini();
    /*
     * SEMUA tiket hari ini dikirim ke display (panel bergulir otomatis menampilkan semuanya).
     * Angka ini hanya pengaman agar payload tidak membengkak pada keadaan tak wajar.
     */
    $batas = 600;

    $groups = [ST_RESEP => [], ST_SIAPKAN => [], ST_SIAP => [], ST_DITERIMA => []];
    foreach ($hari as $t) {
        $st = (string) $t['status'];
        if (!isset($groups[$st])) {
            continue;
        }
        $groups[$st][] = tiket_ringkas_display($t);
    }
    /* Tiket selesai tampil paling bawah, urut dari yang paling baru dipanggil. */
    usort($groups[ST_DITERIMA], static fn($a, $b) => strcmp($b['jam_panggil'], $a['jam_panggil']));

    /* Jumlah sebenarnya per status dihitung SEBELUM dipotong batas tampilan. */
    $jumlahStatus = [];
    foreach ($groups as $k => $rows) {
        $jumlahStatus[$k] = count($rows);
    }
    foreach ($groups as $k => $rows) {
        if (count($rows) > $batas) {
            $groups[$k] = array_slice($rows, 0, $batas);
        }
    }

    /* Nomor utama = tiket yang paling terakhir dipanggil hari ini. */
    $dipanggil = null;
    $riwayat   = [];
    /* Hanya tiket yang sudah diserahkan yang tampil sebagai panggilan (yang dibatalkan tidak). */
    $called = array_values(array_filter($hari, static fn($t) => !empty($t['called_at']) && (string) $t['status'] === ST_DITERIMA));
    /* Urutkan memakai waktu panggil EFEKTIF: tiket yang dipanggil ulang kembali ke posisi teratas
       sehingga nomornya tampil lagi sebagai nomor yang sedang dipanggil. */
    usort($called, static fn($a, $b) => strcmp(waktu_panggil_efektif($b), waktu_panggil_efektif($a)));
    foreach ($called as $i => $t) {
        $r = tiket_ringkas_display($t);
        if ($i === 0) {
            $dipanggil = $r;
        } elseif (count($riwayat) < 5) {
            $riwayat[] = $r;
        }
    }

    /* rev dipakai display untuk memutuskan perlu-tidaknya menggambar ulang kolom chip:
       harus berubah hanya saat struktur berubah (lihat display_stamp). */
    $stamp = display_stamp();
    $rev = $stamp;

    return [
        'ok'              => true,
        'rev'             => $rev,
        'stamp'           => $stamp,
        'nama_apotek'     => setting('nama_apotek', 'Apotek'),
        'alamat'          => setting('alamat_apotek'),
        'logo_url'        => setting('logo_url'),
        'judul'           => setting('display_judul', 'ANTRIAN PENGAMBILAN OBAT'),
        'loket'           => setting('loket_default', 'LOKET PENGAMBILAN OBAT'),
        'footer_teks'     => setting('footer_teks'),
        'tanggal'         => hari_ini(),
        'tanggal_label'   => tgl_label(hari_ini()),
        'server_time'     => date('H:i:s'),
        'kecepatan_gulir' => (int) setting_num('kecepatan_gulir', 40),
        /* Kecepatan gulir baris "OBAT SUDAH DITERIMA" (px/detik) — dipisah agar bisa dibuat
           lebih pelan tanpa mengubah kolom status maupun teks berjalan footer. */
        'kecepatan_gulir_selesai' => kecepatan_gulir_selesai(),
        'refresh_detik'   => max(3, (int) setting_num('refresh_detik', 6)),
        'durasi_panggil'  => max(4, (int) setting_num('durasi_panggil', 12)),
        'tema_warna'      => setting('tema_warna', '#0d9488'),
        /* Warna backpanel chip per kode (agar tiket mudah dibedakan dari jauh). */
        'warna_kode'      => warna_kode_peta(),
        'video'           => [
            'aktif'     => video_aktif() ? 1 : 0,
            'jumlah'    => count(video_daftar()),
            'stamp'     => video_stamp(),
            'judul'     => (string) setting('video_judul', 'INFORMASI KESEHATAN'),
            'suara'     => setting_bool('video_suara', false) ? 1 : 0,
        ],
        'tampil_riwayat'  => setting_bool('display_tampil_riwayat', true) ? 1 : 0,
        'tampil_tunggu'   => setting_bool('display_tampil_tunggu', true) ? 1 : 0,
        'suara'           => [
            'aktif'      => setting_bool('suara_display', true) ? 1 : 0,
            'lang'       => setting('suara_lang', 'id-ID'),
            'voice'      => setting('suara_voice', 'Google Bahasa Indonesia'),
            'rate'       => (float) setting_num('suara_rate', 0.95),
            'volume'     => (float) setting_num('suara_volume', 1),
            'eja_digit'  => setting_bool('suara_eja_digit', true) ? 1 : 0,
            'chime'      => setting_bool('suara_chime', true) ? 1 : 0,
            'ulang'      => max(1, (int) setting_num('suara_ulang', 3)),
            'template'   => setting('suara_template'),
        ],
        'dipanggil'       => $dipanggil,
        'riwayat'         => $riwayat,
        'groups'          => $groups,
        'jumlah'          => [
            'total'     => count($hari),
            ST_RESEP    => $jumlahStatus[ST_RESEP],
            ST_SIAPKAN  => $jumlahStatus[ST_SIAPKAN],
            ST_SIAP     => $jumlahStatus[ST_SIAP],
            ST_DITERIMA => $jumlahStatus[ST_DITERIMA],
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Laporan                                                             */
/* ------------------------------------------------------------------ */

/** Laporan hanya memuat tiket yang sudah dipanggil/diserahkan, urut tanggal. */
function laporan_rows(string $dari, string $sampai, string $kode = ''): array
{
    $sql = 'SELECT * FROM tiket WHERE called_at IS NOT NULL AND tanggal >= ? AND tanggal <= ?';
    $par = [$dari, $sampai];
    if ($kode !== '') {
        $sql .= ' AND kode = ?';
        $par[] = $kode;
    }
    $sql .= ' ORDER BY tanggal, created_at, kode, nomor';
    $st = db()->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $t) {
        $dasarTunggu = !empty($t['first_called_at']) ? (string) $t['first_called_at'] : (string) $t['called_at'];
        $tunggu = detik_antara((string) $t['created_at'], $dasarTunggu);
        $siap   = $t['siap_at'] ? detik_antara((string) $t['created_at'], (string) $t['siap_at']) : null;
        $out[] = [
            'id'            => (int) $t['id'],
            'tanggal'       => (string) $t['tanggal'],
            'tanggal_label' => tgl_label((string) $t['tanggal'], false),
            'kode'          => (string) $t['kode'],
            'nomor'         => (int) $t['nomor'],
            'kode_tiket'    => (string) $t['kode_tiket'],
            'keterangan'    => (string) $t['keterangan'],
            'jam_ambil'     => jam_teks((string) $t['created_at']),
            'jam_siap'      => jam_teks($t['siap_at']),
            'jam_panggil'   => jam_teks((string) $t['called_at']),
            'tunggu_detik'  => $tunggu,
            'tunggu_format' => durasi_format($tunggu),
            'siap_detik'    => $siap,
            'siap_format'   => $siap === null ? '-' : durasi_format($siap),
            'status'        => (string) $t['status'],
            'status_label'  => STATUS_LABEL[(string) $t['status']] ?? (string) $t['status'],
            'petugas'       => nama_pengguna((string) $t['dilayani_oleh']),
            'petugas_akun'  => (string) $t['dilayani_oleh'],
            /* Data pasien — dipakai laporan petugas (tampilan, Excel/CSV, dan PDF). */
            'no_rm'         => (string) ($t['no_rm'] ?? ''),
            'nama_pasien'   => (string) ($t['nama_pasien'] ?? ''),
        ];
    }
    return $out;
}

function laporan_ringkas(array $rows): array
{
    $total = count($rows);
    $sumT  = 0;
    $sumS  = 0;
    $nS    = 0;
    $perKode = [];
    foreach ($rows as $r) {
        $sumT += (int) $r['tunggu_detik'];
        if ($r['siap_detik'] !== null) {
            $sumS += (int) $r['siap_detik'];
            $nS++;
        }
        $k = $r['kode'];
        if (!isset($perKode[$k])) {
            $perKode[$k] = ['kode' => $k, 'jumlah' => 0, 'tunggu' => 0];
        }
        $perKode[$k]['jumlah']++;
        $perKode[$k]['tunggu'] += (int) $r['tunggu_detik'];
    }
    $per = [];
    foreach ($perKode as $k => $v) {
        $per[] = [
            'kode'      => $k,
            'jumlah'    => $v['jumlah'],
            'rata_rata' => $v['jumlah'] > 0 ? (int) round($v['tunggu'] / $v['jumlah']) : 0,
        ];
    }
    usort($per, static fn($a, $b) => strcmp($a['kode'], $b['kode']));
    return [
        'total'        => $total,
        'rata_tunggu'  => $total > 0 ? (int) round($sumT / $total) : 0,
        'rata_siap'    => $nS > 0 ? (int) round($sumS / $nS) : 0,
        'per_kode'     => $per,
    ];
}

/* ------------------------------------------------------------------ */
/* Unggah media (logo apotek) melalui proxy penyimpanan platform        */
/* ------------------------------------------------------------------ */

function media_token(): string
{
    $f = __DIR__ . '/.vibecoder-media-token';
    return is_readable($f) ? trim((string) file_get_contents($f)) : '';
}

/**
 * Kirim berkas ke penyimpanan media platform.
 * Hanya dipanggil dari sisi server — token tidak pernah dikirim ke browser.
 * Untuk berkas besar (video) dipakai jalur streaming (curl dari berkas) supaya
 * isinya TIDAK dimuat ke memori PHP.
 */
function media_upload(string $filePath, string $filename): array
{
    $token = media_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Penyimpanan media belum aktif (aplikasi belum dipublikasikan).'];
    }
    if (!is_file($filePath) || filesize($filePath) < 1) {
        return ['ok' => false, 'error' => 'File tidak dapat dibaca.'];
    }
    $url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($filename);

    /* Jalur streaming: isi berkas dikirim langsung dari disk (aman untuk video besar). */
    if (function_exists('curl_init')) {
        $fp = @fopen($filePath, 'rb');
        if ($fp !== false) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD         => true,
                CURLOPT_INFILE         => $fp,
                CURLOPT_INFILESIZE     => (int) filesize($filePath),
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 600,
            ]);
            $out  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($out === false) {
                return ['ok' => false, 'error' => 'Gagal menghubungi penyimpanan media: ' . $err];
            }
            $json = json_decode((string) $out, true);
            if (is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
                return ['ok' => true, 'url' => (string) $json['url']];
            }
            $pesan = 'Penyimpanan media menolak file ini';
            if (is_array($json)) {
                $pesan = (string) ($json['error'] ?? $json['message'] ?? $pesan);
            }
            if ($code === 403) {
                $pesan = 'Kuota penyimpanan media penuh. ' . $pesan;
            }
            return ['ok' => false, 'error' => $pesan];
        }
    }

    /* Cadangan tanpa curl (berkas kecil): isi dibaca ke memori lebih dulu. */
    $bytes = @file_get_contents($filePath);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'File tidak dapat dibaca.'];
    }
    $out  = false;
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return ['ok' => false, 'error' => 'Gagal menghubungi penyimpanan media: ' . $err];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "X-App-Media-Token: $token\r\nContent-Type: application/octet-stream\r\n",
            'content'       => $bytes,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($out === false) {
            return ['ok' => false, 'error' => 'Gagal menghubungi penyimpanan media.'];
        }
    }

    $json = json_decode((string) $out, true);
    if (is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
        return ['ok' => true, 'url' => (string) $json['url']];
    }
    $pesan = 'Penyimpanan media menolak file ini';
    if (is_array($json)) {
        $pesan = (string) ($json['error'] ?? $json['message'] ?? $pesan);
    }
    if ($code === 403) {
        $pesan = 'Kuota penyimpanan media penuh. ' . $pesan;
    }
    return ['ok' => false, 'error' => $pesan];
}

/* ------------------------------------------------------------------ */
/* Tampilan halaman                                                    */
/* ------------------------------------------------------------------ */

function flash(): void
{
    $ok  = (string) ($_GET['ok'] ?? '');
    $err = (string) ($_GET['err'] ?? '');
    if ($ok !== '') {
        echo '<div class="flash flash-ok">' . h($ok) . '</div>';
    }
    if ($err !== '') {
        echo '<div class="flash flash-err">' . h($err) . '</div>';
    }
}

function icon(string $nama): string
{
    $p = [
        'grid'  => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'tiket' => '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/>',
        'mic'   => '<path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.9V22h2v-3.1A7 7 0 0 0 19 12z"/>',
        'track' => '<path d="M6 2h9l5 5v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm8 1.5V8h4.5zM8 12h8v2H8zm0 4h8v2H8z"/>',
        'tv'    => '<path d="M3 5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1h-7l2 3h-2.4l-1.6-2.3L10.4 21H8l2-3H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/>',
        'chart' => '<path d="M4 20h16v2H4zM6 9h3v9H6zm4.5-5h3v14h-3zM15 12h3v6h-3z"/>',
        'cog'   => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9 4-.1 1.4 1.7 1.3-1.4 2.4-2-.7-1.2.7-.3 2.1h-2.8l-.3-2.1-1.2-.7-2 .7-1.4-2.4 1.7-1.3L11 12l-.1-1.4L9.2 9.3l1.4-2.4 2 .7 1.2-.7.3-2.1h2.8l.3 2.1 1.2.7 2-.7 1.4 2.4-1.7 1.3z"/>',
        'print' => '<path d="M7 3h10v4H7zM5 8h14a2 2 0 0 1 2 2v6h-4v5H7v-5H3v-6a2 2 0 0 1 2-2zm4 6v5h6v-5z"/>',
        'out'   => '<path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5zm4.6 3.4L13.2 7.8l3.2 3.2H8v2h8.4l-3.2 3.2 1.4 1.4L20 12z"/>',
        'user'  => '<path d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0 10c4 0 8 2 8 4.5V20H4v-1.5C4 16 8 14 12 14z"/>',
        'bell'  => '<path d="M12 2a6 6 0 0 0-6 6v4l-2 3v1h16v-1l-2-3V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 0 3-3H9a3 3 0 0 0 3 3z"/>',
        'db'    => '<path d="M12 2c4.4 0 8 1.3 8 3v14c0 1.7-3.6 3-8 3s-8-1.3-8-3V5c0-1.7 3.6-3 8-3zm6 9c-1.5.8-3.7 1.2-6 1.2S7.5 11.8 6 11v3c1.5.8 3.7 1.2 6 1.2s4.5-.4 6-1.2z"/>',
        /* Pengeras suara (megafon) — dipakai tombol & dialog "Pengumuman Suara". */
        'mega'  => '<path d="M19 3v18l-9-4H5a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h5zm-11 12v4.5a1 1 0 0 0 1 1h1.8V15z"/>',
        'stop'  => '<path d="M7 7h10v10H7z"/>',
    ];
    $d = $p[$nama] ?? $p['grid'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg>';
}

function menu_akses(): array
{
    return [
        'index.php'      => 'Dashboard',
        'ambil.php'      => 'Ambil Antrian',
        'panggil.php'    => 'Panggil Antrian',
        'tracking.php'   => 'Tracking Obat',
        'laporan.php'    => 'Laporan',
        'pengaturan.php' => 'Pengaturan',
    ];
}

function menu_diizinkan(array $user, string $file): bool
{
    $role = (string) $user['role'];
    if ($role === 'superadmin' || $role === 'admin_apotik') {
        return true;   /* admin_apotik: semua menu petugas + Pengaturan (isi tab dibatasi) */
    }
    return $file !== 'pengaturan.php';
}

/**
 * Nama tampilan pengguna diambil dari kolom `nama` (bukan username).
 * Dipakai di header, tiket, dan laporan. Bila kolom nama kosong, kembali ke username
 * supaya tidak ada bagian yang tampil kosong.
 */
function nama_pengguna(string $username): string
{
    static $peta = null;
    if ($peta === null) {
        $peta = [];
        try {
            foreach (db()->query('SELECT username, nama FROM user') as $r) {
                $peta[strtolower((string) $r['username'])] = trim((string) $r['nama']);
            }
        } catch (Throwable $e) {
            /* Tampilan tidak boleh gagal hanya karena nama tidak terbaca. */
        }
    }
    $kunci = strtolower(trim($username));
    $nama = $peta[$kunci] ?? '';
    return $nama !== '' ? $nama : trim($username);
}

/** Nama tampilan + username dalam tanda kurung (untuk jejak audit). */
function nama_pengguna_dengan_akun(string $username): string
{
    $nama = nama_pengguna($username);
    return $nama === trim($username) ? $nama : $nama . ' (' . trim($username) . ')';
}

/* ------------------------------------------------------------------ */
/* Pengumuman suara bebas + template tersimpan                          */
/* ------------------------------------------------------------------ */
/*
 * Halaman Panggil Antrian punya tombol "Pengumuman": petugas menulis teks bebas, lalu
 * teks itu diucapkan dengan suara yang SAMA seperti panggilan antrian (id-ID).
 * Teks yang sering dipakai bisa disimpan sebagai template di tabel pengaturan
 * (satu kunci JSON) supaya bisa dipakai ulang.
 *
 * Yang perlu dijaga saat mengubah bagian ini:
 * - Jangan mengembalikan teks ber-UTF-8 terpotong: json_encode gagal (false) dan
 *   seluruh daftar template hilang. Karena itu pemotongan memakai mb_substr bila ada,
 *   dan fallback-nya membuang byte lanjutan yang terpotong.
 * - Semua nilai dibaca kembali lewat lapisan rapi (nama/teks dibersihkan) supaya isi
 *   lama yang tidak lengkap tidak membuat halaman error.
 */

const PENGUMUMAN_MAKS_TEMPLATE = 20;   /* batas jumlah template tersimpan */
const PENGUMUMAN_MAKS_KARAKTER  = 400; /* batas panjang satu teks pengumuman */

/** Potong teks tanpa menghasilkan UTF-8 yang rusak (aman untuk json_encode). */
function pengumuman_potong(string $s, int $maks): string
{
    if ($maks <= 0) { return ''; }
    if (function_exists('mb_substr')) { return mb_substr($s, 0, $maks, 'UTF-8'); }
    $out = substr($s, 0, $maks);
    while ($out !== '' && (ord(substr($out, -1)) & 0xC0) === 0x80) {
        $out = substr($out, 0, -1);
    }
    return $out;
}

/** Setelan suara yang dipakai panggilan antrian — dipakai juga oleh pengumuman bebas. */
function suara_cfg(): array
{
    return [
        'lang'      => setting('suara_lang', 'id-ID'),
        'voice'     => setting('suara_voice', 'Google Bahasa Indonesia'),
        'rate'      => (float) setting_num('suara_rate', 0.95),
        'volume'    => (float) setting_num('suara_volume', 1),
        'eja_digit' => setting_bool('suara_eja_digit', true) ? 1 : 0,
        'chime'     => setting_bool('suara_chime', true) ? 1 : 0,
        'ulang'     => max(1, (int) setting_num('suara_ulang', 3)),
        'template'  => setting('suara_template'),
    ];
}

/** Daftar template pengumuman (urut dari yang terbaru). */
function pengumuman_template_daftar(): array
{
    $mentah = json_decode(setting('pengumuman_template', '[]'), true);
    if (!is_array($mentah)) { return []; }
    $out = [];
    foreach ($mentah as $t) {
        if (!is_array($t)) { continue; }
        $teks = trim((string) ($t['teks'] ?? ''));
        if ($teks === '') { continue; }
        $out[] = [
            'id'    => (int) ($t['id'] ?? 0),
            'nama'  => trim((string) ($t['nama'] ?? '')) !== '' ? trim((string) $t['nama']) : 'Tanpa nama',
            'teks'  => pengumuman_potong($teks, PENGUMUMAN_MAKS_KARAKTER),
            'oleh'  => trim((string) ($t['oleh'] ?? '')),
            'waktu' => trim((string) ($t['waktu'] ?? '')),
        ];
    }
    return $out;
}

function pengumuman_template_tulis(array $daftar): bool
{
    $json = json_encode(array_values($daftar), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) { return false; }
    set_setting('pengumuman_template', $json);
    return true;
}

/**
 * Simpan satu template baru. Mengembalikan ['ok'=>bool, 'pesan'=>string, 'template'=>array|null].
 * Nama boleh kosong → diberi nama otomatis ("Pengumuman N").
 */
function pengumuman_template_simpan(string $nama, string $teks, string $oleh = ''): array
{
    $teks = trim($teks);
    if ($teks === '') {
        return ['ok' => false, 'pesan' => 'Teks pengumuman masih kosong — tulis dulu teksnya.', 'template' => null];
    }
    $teks = pengumuman_potong($teks, PENGUMUMAN_MAKS_KARAKTER);

    $daftar = pengumuman_template_daftar();
    if (count($daftar) >= PENGUMUMAN_MAKS_TEMPLATE) {
        return ['ok' => false, 'pesan' => 'Template sudah mencapai batas ' . PENGUMUMAN_MAKS_TEMPLATE
            . '. Hapus salah satu template lama lebih dulu.', 'template' => null];
    }

    $nama = pengumuman_potong(trim($nama), 60);
    if ($nama === '') { $nama = 'Pengumuman ' . (count($daftar) + 1); }

    $id = 1;
    foreach ($daftar as $t) { $id = max($id, (int) $t['id'] + 1); }

    $baru = ['id' => $id, 'nama' => $nama, 'teks' => $teks, 'oleh' => $oleh, 'waktu' => now_sql()];
    array_unshift($daftar, $baru);
    if (!pengumuman_template_tulis($daftar)) {
        return ['ok' => false, 'pesan' => 'Gagal menyimpan template (isi teks tidak dapat disimpan).', 'template' => null];
    }
    return ['ok' => true, 'pesan' => 'Template "' . $nama . '" disimpan.', 'template' => $baru];
}

/** Hapus satu template berdasarkan id. */
function pengumuman_template_hapus(int $id): bool
{
    $daftar = pengumuman_template_daftar();
    $sisa = array_values(array_filter($daftar, static fn($t) => (int) $t['id'] !== $id));
    if (count($sisa) === count($daftar)) { return false; }
    return pengumuman_template_tulis($sisa);
}

/** Kepala halaman aplikasi (tema farmasi hijau-teal). */
function page_head(string $title, string $active = '', array $opt = []): void
{
    $user = auth_user();
    $menu = menu_akses();
    $tema = setting('tema_warna', '#0d9488');
    $logoUrl = setting('logo_url');
    $namaApotek = setting('nama_apotek', 'Apotek');
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — Antrian Apotek</title>
<?php if ($opt['refresh'] ?? false): ?>
<meta http-equiv="refresh" content="<?= (int) $opt['refresh'] ?>">
<?php endif; ?>
<link rel="stylesheet" href="assets/style.css">
<style>:root{--accent:<?= h($tema) ?>;}</style>
</head>
<body class="admin">
<header class="topbar">
    <a class="brand" href="index.php">
        <?php /* Logo yang diunggah di Pengaturan dipakai di SEMUA halaman (header ini).
                 Ikon bawaan hanya dipakai bila logo belum diunggah. */ ?>
        <?php if ($logoUrl !== ''): ?>
            <img class="brand-logo" src="<?= h($logoUrl) ?>" alt="<?= h($namaApotek) ?>">
        <?php else: ?>
            <span class="brand-mark"><?= icon('tiket') ?></span>
        <?php endif; ?>
        <span class="brand-text">
            <strong><?= h($namaApotek) ?></strong>
            <span class="brand-sub">Sistem Antrian &amp; Tracking Obat</span>
        </span>
    </a>
    <button class="nav-toggle" type="button" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')">
        <span></span><span></span><span></span>
    </button>
    <nav class="nav">
        <?php foreach ($menu as $file => $label): ?>
            <?php if ($user && menu_diizinkan($user, $file)): ?>
                <a class="nav-link<?= $active === $file ? ' is-active' : '' ?>" href="<?= h($file) ?>">
                    <?= icon(str_replace(['index.php','ambil.php','panggil.php','tracking.php','laporan.php','pengaturan.php'],
                        ['grid','tiket','mic','track','chart','cog'], $file)) ?>
                    <span><?= h($label) ?></span>
                </a>
            <?php elseif ($user): ?>
                <span class="nav-link is-locked" title="Hanya superadmin"><?= icon('cog') ?><span><?= h($label) ?></span></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <a class="nav-link nav-display" href="display.php" target="_blank" rel="noopener"><?= icon('tv') ?><span>Display</span></a>
        <?php if ($user): ?>
            <span class="nav-user">
                <?= icon('user') ?>
                <span><?= h($user['nama'] !== '' ? $user['nama'] : $user['username']) ?><em><?= h(label_role($user['role'])) ?></em></span>
            </span>
            <a class="nav-link nav-logout" href="logout.php"><?= icon('out') ?><span>Keluar</span></a>
        <?php else: ?>
            <a class="nav-link" href="login.php"><?= icon('user') ?><span>Masuk</span></a>
        <?php endif; ?>
    </nav>
</header>
<main class="wrap">
<?php
}

/**
 * Penutup halaman. $script boleh berupa satu nama berkas atau daftar berkas skrip
 * (mis. ['assets/panggil.js', 'assets/layar2.js']).
 */
function page_end(string|array $script = ''): void
{
    $daftarSkrip = is_array($script) ? $script : ($script !== '' ? [$script] : []);
    ?>
</main>
<footer class="footer-app">
    <span><?= h(setting('nama_apotek', 'Apotek')) ?> — Sistem Antrian Apotek</span>
    <span class="footer-hint">Jam server: <?= h(date('d/m/Y H:i')) ?> · <a href="display.php" target="_blank" rel="noopener">Buka layar display</a></span>
</footer>
<script src="assets/voice.js"></script>
<?php foreach ($daftarSkrip as $berkasSkrip): ?>
<script src="<?= h($berkasSkrip) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
