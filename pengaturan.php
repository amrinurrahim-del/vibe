<?php
declare(strict_types=1);

/*
 * Pengaturan aplikasi antrian apotek (khusus superadmin).
 * Tab: Identitas & Tampilan · Kode Antrian · Suara & Panggilan · Printer ·
 *      Antrean & Reset · Koneksi Database · Laporan · Akun & Keamanan
 */

require_once __DIR__ . '/lib.php';

/* Superadmin: seluruh Pengaturan. Admin Apotik: hanya tab Antrean & Reset + Laporan. */
$user = require_pengaturan();
$tabOk = static fn(string $id): bool => pengaturan_tab_diizinkan((string) $user['role'], $id);

const LOGO_EXT_OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    /*
     * Izin tindakan diperiksa di SISI SERVER untuk setiap POST. Menyembunyikan tab saja
     * tidak melindungi data: admin_apotik hanya boleh tindakan pada tab Antrean & Reset.
     */
    if (!pengaturan_aksi_diizinkan((string) $user['role'], $action)) {
        redirect('pengaturan.php?err=' . urlencode('Akun Anda (' . label_role((string) $user['role'])
            . ') tidak berwenang melakukan tindakan itu.') . '#reset');
    }

    /* ---------------- Identitas, tampilan & logo ---------------- */
    if ($action === 'identitas_simpan') {
        set_setting('nama_apotek', trim((string) ($_POST['nama_apotek'] ?? 'Apotek')) ?: 'Apotek');
        set_setting('alamat_apotek', trim((string) ($_POST['alamat_apotek'] ?? '')));
        set_setting('display_judul', trim((string) ($_POST['display_judul'] ?? 'ANTRIAN PENGAMBILAN OBAT')));
        set_setting('footer_teks', trim((string) ($_POST['footer_teks'] ?? '')));
        set_setting('loket_default', trim((string) ($_POST['loket_default'] ?? 'LOKET PENGAMBILAN OBAT')));
        $tema = trim((string) ($_POST['tema_warna'] ?? '#0d9488'));
        set_setting('tema_warna', preg_match('/^#[0-9a-fA-F]{6}$/', $tema) ? $tema : '#0d9488');
        set_setting('kecepatan_gulir', (string) max(5, min(200, (int) ($_POST['kecepatan_gulir'] ?? 40))));
        set_setting('kecepatan_gulir_selesai', (string) max(3, min(200, (int) ($_POST['kecepatan_gulir_selesai'] ?? 30))));
        set_setting('refresh_detik', (string) max(3, min(60, (int) ($_POST['refresh_detik'] ?? 5))));
        set_setting('durasi_panggil', (string) max(4, min(120, (int) ($_POST['durasi_panggil'] ?? 12))));
        set_setting('display_tampil_riwayat', isset($_POST['display_tampil_riwayat']) ? '1' : '0');
        set_setting('display_tampil_tunggu', isset($_POST['display_tampil_tunggu']) ? '1' : '0');
        $tz = (string) ($_POST['zona_waktu'] ?? 'Asia/Makassar');
        if (in_array($tz, timezone_identifiers_list(), true)) {
            set_setting('zona_waktu', $tz);
        }

        /* --- Panel video display (link YouTube, maks 3) --- */
        set_setting('video_aktif', isset($_POST['video_aktif']) ? '1' : '0');
        set_setting('video_suara', isset($_POST['video_suara']) ? '1' : '0');
        set_setting('video_judul', trim((string) ($_POST['video_judul'] ?? 'INFORMASI KESEHATAN')) ?: 'INFORMASI KESEHATAN');
        $tautanVideo = [];
        foreach (['video_1', 'video_2', 'video_3'] as $kunci) {
            $nilai = trim((string) ($_POST[$kunci] ?? ''));
            set_setting($kunci, $nilai);
            if ($nilai !== '') {
                $tautanVideo[] = youtube_id($nilai) === null
                    ? 'Link ' . $kunci . ' tidak dikenali sebagai tautan YouTube'
                    : 'ok';
            }
        }
        $videoSalah = array_filter($tautanVideo, static fn($v) => $v !== 'ok');

        $pesan = 'Pengaturan identitas & tampilan disimpan.';

        if (isset($_POST['hapus_logo'])) {
            set_setting('logo_url', '');
            $pesan .= ' Logo dihapus.';
        } elseif (isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? 4) === 0) {
            $ext = strtolower((string) pathinfo((string) $_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, LOGO_EXT_OK, true)) {
                redirect('pengaturan.php?err=' . urlencode('Format logo harus JPG, PNG, GIF, WEBP, atau SVG.')
                    . '#tampilan');
            }
            $hasil = media_upload((string) $_FILES['logo']['tmp_name'], 'logo-apotek.' . $ext);
            if (!$hasil['ok']) {
                redirect('pengaturan.php?err=' . urlencode('Unggah logo gagal: ' . $hasil['error']) . '#tampilan');
            }
            set_setting('logo_url', (string) $hasil['url']);
            $pesan .= ' Logo baru tersimpan.';
        }
        if ($videoSalah) {
            $pesan .= ' Perhatian: ' . implode('; ', $videoSalah)
                . '. Gunakan format seperti https://youtu.be/ID atau https://www.youtube.com/watch?v=ID.';
        }
        redirect('pengaturan.php?ok=' . urlencode($pesan) . '#tampilan');
    }

    /* ---------------- Unggah & hapus video panel display ---------------- */
    if ($action === 'video_unggah') {
        /*
         * Kalau TOTAL berkas yang dikirim melebihi post_max_size, PHP membuang seluruh isi
         * $_FILES tanpa pesan galat — jadi diperiksa dari panjang badan permintaan supaya
         * pemilik mendapat penjelasan yang jelas (bukan "belum ada berkas dipilih").
         */
        $batasKirim = video_batas_kirim();
        $panjang = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($batasKirim > 0 && $panjang > $batasKirim && empty($_FILES['video'])) {
            redirect('pengaturan.php?err=' . urlencode('Total berkas yang dikirim melebihi batas server ('
                . ukuran_teks($batasKirim) . ' per unggahan). Unggah satu atau beberapa berkas yang lebih kecil.') . '#tampilan');
        }
        if (empty($_FILES['video']['name'][0])) {
            redirect('pengaturan.php?err=' . urlencode('Belum ada berkas video yang dipilih.') . '#tampilan');
        }
        $daftar = video_daftar();
        $berhasil = 0;
        $galat = [];
        $jumlah = count($_FILES['video']['name']);
        for ($i = 0; $i < $jumlah; $i++) {
            $namaAsli = (string) $_FILES['video']['name'][$i];
            $tmp      = (string) $_FILES['video']['tmp_name'][$i];
            $kodeGalat = (int) $_FILES['video']['error'][$i];
            $ukuran   = (int) $_FILES['video']['size'][$i];

            if ($kodeGalat === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($kodeGalat !== UPLOAD_ERR_OK) {
                $pesanKode = $kodeGalat === UPLOAD_ERR_INI_SIZE
                    ? ' — ukurannya melebihi batas server ' . ukuran_teks(video_batas_unggah())
                      . '. Kompres videonya lebih dulu (mis. 720p) atau potong menjadi beberapa bagian.'
                    : '';
                $galat[] = $namaAsli . ': unggahan gagal (kode ' . $kodeGalat . ')' . $pesanKode;
                continue;
            }
            if ($ukuran > video_batas_unggah()) {
                $galat[] = $namaAsli . ': ' . ukuran_teks($ukuran) . ' melebihi batas server '
                    . ukuran_teks(video_batas_unggah()) . '. Kompres videonya lebih dulu.';
                continue;
            }
            $ext = strtolower((string) pathinfo($namaAsli, PATHINFO_EXTENSION));
            if (!in_array($ext, VIDEO_EXT_OK, true)) {
                $galat[] = $namaAsli . ': format harus ' . strtoupper(implode('/', VIDEO_EXT_OK)) . '.';
                continue;
            }
            if (count($daftar) >= 6) {
                $galat[] = $namaAsli . ': maksimal 6 video.';
                continue;
            }
            $kirim = media_upload($tmp, 'panel-video-' . count($daftar) . '-' . $namaAsli);
            if (!$kirim['ok']) {
                $galat[] = $namaAsli . ': ' . $kirim['error'];
                continue;
            }
            $daftar[] = [
                'url'    => (string) $kirim['url'],
                'nama'   => $namaAsli,
                'ukuran' => $ukuran,
                'waktu'  => now_sql(),
            ];
            $berhasil++;
        }
        if ($berhasil > 0) {
            video_simpan($daftar);
            log_admin('Unggah video display', $berhasil . ' berkas · total kini ' . count($daftar) . ' video');
        }
        $pesan = $berhasil . ' video berhasil diunggah.';
        if ($galat) {
            redirect('pengaturan.php?err=' . urlencode($pesan . ' Sebagian gagal — ' . implode(' ', $galat)) . '#tampilan');
        }
        redirect('pengaturan.php?ok=' . urlencode($pesan . ' Panel video siap dipakai; jangan lupa centang "Tampilkan panel video" lalu simpan.') . '#tampilan');
    }

    if ($action === 'video_hapus') {
        $idx = (int) ($_POST['idx'] ?? -1);
        $daftar = video_daftar();
        if (!isset($daftar[$idx])) {
            redirect('pengaturan.php?err=' . urlencode('Video tidak ditemukan.') . '#tampilan');
        }
        $nama = $daftar[$idx]['nama'];
        $ukuran = (int) $daftar[$idx]['ukuran'];
        array_splice($daftar, $idx, 1);
        video_simpan($daftar);
        log_admin('Hapus video display', $nama . ' (' . ukuran_teks($ukuran) . ')');
        redirect('pengaturan.php?ok=' . urlencode('Video "' . $nama . '" dihapus dari daftar panel display.') . '#tampilan');
    }

    if ($action === 'video_kosongkan') {
        $jml = count(video_daftar());
        video_simpan([]);
        set_setting('video_aktif', '0');
        log_admin('Kosongkan daftar video display', $jml . ' video dihapus dari daftar');
        redirect('pengaturan.php?ok=' . urlencode('Semua video dihapus dari daftar (' . $jml . ' berkas).') . '#tampilan');
    }

    /* ---------------- Kode antrian ---------------- */
    if ($action === 'kode_simpan') {
        $id = (int) ($_POST['id'] ?? 0);

        /* Tombol "Hapus" pada baris yang sama mengirim name=hapus. */
        if (isset($_POST['hapus'])) {
            $st = db()->prepare('SELECT kode FROM kode_antrian WHERE id = ?');
            $st->execute([$id]);
            $kode = (string) $st->fetchColumn();
            if ($kode === '') {
                redirect('pengaturan.php?err=' . urlencode('Kode tidak ditemukan.') . '#kode');
            }
            $jml = db()->prepare('SELECT COUNT(*) FROM tiket WHERE kode = ?');
            $jml->execute([$kode]);
            if ((int) $jml->fetchColumn() > 0) {
                redirect('pengaturan.php?err=' . urlencode('Kode ' . $kode . ' sudah punya riwayat tiket sehingga tidak dihapus — nonaktifkan saja agar tombolnya hilang dari form ambil antrian.') . '#kode');
            }
            db()->prepare('DELETE FROM kode_antrian WHERE id = ?')->execute([$id]);
            db()->prepare('DELETE FROM nomor_counter WHERE kode = ?')->execute([$kode]);
            redirect('pengaturan.php?ok=' . urlencode('Kode ' . $kode . ' dihapus.') . '#kode');
        }

        $nama   = trim((string) ($_POST['nama'] ?? ''));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $aktif  = isset($_POST['aktif']) ? 1 : 0;
        $mode   = (string) ($_POST['reset_mode'] ?? 'harian');
        $mode   = $mode === 'bulanan' ? 'bulanan' : 'harian';
        $warna  = trim((string) ($_POST['warna'] ?? ''));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $warna)) {
            /* Kode huruf diambil dari database supaya warna cadangan tetap masuk akal. */
            $stK = db()->prepare('SELECT kode FROM kode_antrian WHERE id = ?');
            $stK->execute([$id]);
            $warna = warna_kode_bawaan((string) $stK->fetchColumn());
        }
        db()->prepare('UPDATE kode_antrian SET nama = ?, urutan = ?, aktif = ?, reset_mode = ?, warna = ?, updated_at = ? WHERE id = ?')
            ->execute([$nama, $urutan, $aktif, $mode, $warna, now(), $id]);
        log_admin('Ubah kode antrian', 'id ' . $id . ' → ' . $nama . ' · ' . $mode . ' · ' . ($aktif ? 'aktif' : 'nonaktif') . ' · warna ' . $warna);
        redirect('pengaturan.php?ok=' . urlencode('Kode antrian diperbarui.') . '#kode');
    }

    if ($action === 'kode_tambah') {
        $kode = strtoupper(trim((string) ($_POST['kode_baru'] ?? '')));
        $nama = trim((string) ($_POST['nama_baru'] ?? ''));
        if (!preg_match('/^[A-Z]$/', $kode)) {
            redirect('pengaturan.php?err=' . urlencode('Kode antrian harus 1 huruf (A-Z).') . '#kode');
        }
        if (kode_row($kode)) {
            redirect('pengaturan.php?err=' . urlencode('Kode ' . $kode . ' sudah ada.') . '#kode');
        }
        $urut = (int) db()->query('SELECT COALESCE(MAX(urutan), 0) + 1 FROM kode_antrian')->fetchColumn();
        db()->prepare('INSERT INTO kode_antrian (kode, nama, urutan, aktif, reset_mode, updated_at) VALUES (?, ?, ?, 1, "harian", ?)')
            ->execute([$kode, $nama !== '' ? $nama : 'Loket ' . $kode, $urut, now()]);
        log_admin('Tambah kode antrian', $kode . ($nama !== '' ? ' — ' . $nama : ''));
        redirect('pengaturan.php?ok=' . urlencode('Kode ' . $kode . ' ditambahkan.') . '#kode');
    }

    /* ---------------- Suara & panggilan ---------------- */
    if ($action === 'suara_simpan') {
        set_setting('suara_voice', trim((string) ($_POST['suara_voice'] ?? 'Google Bahasa Indonesia')));
        set_setting('suara_lang', trim((string) ($_POST['suara_lang'] ?? 'id-ID')));
        set_setting('suara_rate', (string) max(0.5, min(2, (float) ($_POST['suara_rate'] ?? 0.95))));
        set_setting('suara_volume', (string) max(0, min(1, (float) ($_POST['suara_volume'] ?? 1))));
        set_setting('suara_ulang', (string) max(1, min(5, (int) ($_POST['suara_ulang'] ?? 3))));
        set_setting('suara_eja_digit', isset($_POST['suara_eja_digit']) ? '1' : '0');
        set_setting('suara_chime', isset($_POST['suara_chime']) ? '1' : '0');
        set_setting('suara_display', isset($_POST['suara_display']) ? '1' : '0');
        set_setting('suara_template', trim((string) ($_POST['suara_template'] ?? '')) ?: 'Nomor antrian, kode {kode}, {nomor}. Silakan menuju loket pengambilan obat.');
        redirect('pengaturan.php?ok=' . urlencode('Pengaturan suara & panggilan disimpan.') . '#suara');
    }

    /* ---------------- Agen cetak (printer khusus aplikasi) ---------------- */
    if ($action === 'cetak_agen_simpan') {
        set_setting('cetak_agen_aktif', isset($_POST['cetak_agen_aktif']) ? '1' : '0');
        $url = alamat_normal((string) ($_POST['cetak_agen_url'] ?? 'http://127.0.0.1:17890'));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            redirect('pengaturan.php?err=' . urlencode('Alamat agen cetak harus dimulai dengan http:// atau https://') . '#printer');
        }
        set_setting('cetak_agen_url', $url);
        set_setting('cetak_agen_printer', trim((string) ($_POST['cetak_agen_printer'] ?? '')));
        if (!empty($_POST['cetak_agen_token_baru'])) {
            set_setting('cetak_agen_token', bin2hex(random_bytes(16)));
            log_admin('Buat ulang token agen cetak', 'token lama tidak berlaku lagi');
            redirect('pengaturan.php?ok=' . urlencode('Token agen cetak dibuat ulang. Perbarui token di config.php agen, '
                . 'lalu jalankan ulang agen cetak.') . '#printer');
        }
        log_admin('Simpan setelan agen cetak', 'aktif=' . (isset($_POST['cetak_agen_aktif']) ? '1' : '0')
            . ' · printer=' . (trim((string) ($_POST['cetak_agen_printer'] ?? '')) ?: '(belum dipilih)'));
        redirect('pengaturan.php?ok=' . urlencode('Setelan agen cetak disimpan.') . '#printer');
    }

    /* ---------------- QR tiket & halaman lacak ---------------- */
    if ($action === 'qr_simpan') {
        set_setting('qr_aktif', isset($_POST['qr_aktif']) ? '1' : '0');
        set_setting('qr_ukuran', (string) max(18, min(60, (int) ($_POST['qr_ukuran'] ?? 34))));
        set_setting('qr_teks', trim((string) ($_POST['qr_teks'] ?? '')) ?: 'Scan untuk melacak posisi obat Anda');

        $url = trim((string) ($_POST['lacak_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            redirect('pengaturan.php?err=' . urlencode('Alamat publik harus dimulai dengan http:// atau https://') . '#qr');
        }
        $url = alamat_normal($url);
        set_setting('lacak_url', $url);
        set_setting('lacak_poll', (string) max(3, min(60, (int) ($_POST['lacak_poll'] ?? 5))));
        set_setting('lacak_notif', isset($_POST['lacak_notif']) ? '1' : '0');
        set_setting('lacak_suara', isset($_POST['lacak_suara']) ? '1' : '0');

        /*
         * Alamat publik DIBUKTIKAN dengan permintaan HTTP sungguhan. Kesalahan umum:
         * menyertakan nama folder aplikasi padahal aplikasi dibuka lewat domain sendiri
         * (di domain, aplikasi disajikan di akar) — akibatnya QR menuju halaman yang gagal.
         */
        $hasilUji = uji_alamat_publik(alamat_publik_aktif());
        if ($hasilUji['ok']) {
            redirect('pengaturan.php?ok=' . urlencode('Pengaturan QR & halaman lacak disimpan. Alamat publik sudah diuji dan benar.') . '#qr');
        }
        $pesanGalat = 'Pengaturan disimpan, TETAPI alamat publik bermasalah: ' . $hasilUji['pesan'];
        if (!empty($hasilUji['saran'])) {
            $pesanGalat .= ' Perbaiki kolom "Alamat publik aplikasi" menjadi: ' . $hasilUji['saran'];
        }
        redirect('pengaturan.php?err=' . urlencode($pesanGalat) . '#qr');
    }

    /* ---------------- Printer ---------------- */
    if ($action === 'printer_simpan') {
        $kertas = (string) ($_POST['printer_kertas'] ?? 'thermal80');
        set_setting('printer_kertas', in_array($kertas, ['thermal80', 'a4'], true) ? $kertas : 'thermal80');
        set_setting('printer_otomatis', isset($_POST['printer_otomatis']) ? '1' : '0');
        set_setting('printer_lebar', (string) max(40, min(80, (int) ($_POST['printer_lebar'] ?? 76))));
        set_setting('printer_baris_kosong', (string) max(0, min(6, (int) ($_POST['printer_baris_kosong'] ?? 1))));
        set_setting('printer_judul', trim((string) ($_POST['printer_judul'] ?? 'TIKET ANTRIAN APOTEK')));
        set_setting('printer_senyap_tip', isset($_POST['printer_senyap_tip']) ? '1' : '0');
        set_setting('printer_dua_struk', isset($_POST['printer_dua_struk']) ? '1' : '0');
        redirect('pengaturan.php?ok=' . urlencode('Pengaturan printer disimpan.') . '#printer');
    }

    if ($action === 'tiket_hapus') {
        $id = (int) ($_POST['tiket_id'] ?? 0);
        if ($id <= 0) {
            redirect('pengaturan.php?err=' . urlencode('Tiket tidak jelas.') . '#reset');
        }
        try {
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $st = $pdo->prepare('SELECT kode_tiket FROM tiket WHERE id = ?');
            $st->execute([$id]);
            $kodeTiket = (string) $st->fetchColumn();
            if ($kodeTiket === '') {
                throw new RuntimeException('Tiket tidak ditemukan.');
            }
            $pdo->prepare('DELETE FROM tiket_log WHERE tiket_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM tiket WHERE id = ?')->execute([$id]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
            redirect('pengaturan.php?err=' . urlencode('Gagal menghapus tiket: ' . $e->getMessage()) . '#reset');
        }
        $alasan = trim((string) ($_POST['alasan'] ?? ''));
        log_admin('Hapus tiket', $kodeTiket . ($alasan !== '' ? ' — ' . $alasan : ''));
        redirect('pengaturan.php?ok=' . urlencode('Tiket ' . $kodeTiket . ' dihapus. Nomor berikutnya tidak berubah '
            . '(nomor yang sudah dicetak tidak dipakai ulang) — gunakan "Reset Antrean" bila ingin kembali ke 001.') . '#reset');
    }

    /* ---------------- Pindah otomatis ke "Sedang Disiapkan" ---------------- */
    if ($action === 'auto_siapkan_simpan') {
        set_setting('auto_siapkan_aktif', isset($_POST['auto_siapkan_aktif']) ? '1' : '0');
        $menit = (int) ($_POST['auto_siapkan_menit'] ?? 5);
        $menit = max(1, min(600, $menit));
        set_setting('auto_siapkan_menit', (string) $menit);
        log_admin('Simpan setelan pindah otomatis',
            'aktif=' . (isset($_POST['auto_siapkan_aktif']) ? '1' : '0') . ' · ' . $menit . ' menit');
        redirect('pengaturan.php?ok=' . urlencode('Setelan pindah otomatis disimpan: '
            . (isset($_POST['auto_siapkan_aktif']) ? 'aktif, tiket RESEP MASUK pindah sendiri setelah '
                . $menit . ' menit.' : 'dimatikan — perpindahan hanya lewat tombol manual.')) . '#reset');
    }

    /* ---------------- Hapus data laporan (bersihkan data sisa/uji) ---------------- */
    if ($action === 'data_hapus') {
        $dari   = tgl_valid($_POST['dari'] ?? '');
        $sampai = tgl_valid($_POST['sampai'] ?? '');
        if ($sampai < $dari) { [$dari, $sampai] = [$sampai, $dari]; }
        if (trim((string) ($_POST['konfirmasi_hapus'] ?? '')) !== 'HAPUS') {
            redirect('pengaturan.php?err=' . urlencode('Ketik HAPUS pada kotak konfirmasi untuk melanjutkan.') . '#reset');
        }
        /* Lindungi hari ini dari penghapusan tak sengaja: pakai Reset/Hapus tiket untuk itu. */
        $sebelum = data_hitung($dari, $sampai);
        if ($sebelum['total'] === 0) {
            redirect('pengaturan.php?err=' . urlencode('Tidak ada data tiket pada rentang ' . $dari . ' s/d ' . $sampai . '.') . '#reset');
        }
        $hasil = data_hapus($dari, $sampai);
        log_admin('Hapus data laporan', $dari . ' s/d ' . $sampai . ' — ' . $hasil['tiket']
            . ' tiket, ' . $hasil['log'] . ' baris riwayat tracking' . ($hasil['simrs'] > 0 ? ', ' . $hasil['simrs'] . ' permintaan SIMRS' : ''));
        redirect('pengaturan.php?ok=' . urlencode('Data laporan ' . $dari . ' s/d ' . $sampai . ' dihapus: '
            . $hasil['tiket'] . ' tiket (termasuk riwayat tracking-nya). Laporan & ringkasan per kode sudah bersih.') . '#reset');
    }

    /* ---------------- Reset antrean ---------------- */
    if ($action === 'reset_antrian') {
        $termasukJ = isset($_POST['termasuk_j']);
        if (trim((string) ($_POST['konfirmasi'] ?? '')) !== 'RESET') {
            redirect('pengaturan.php?err=' . urlencode('Ketik RESET pada kotak konfirmasi untuk melanjutkan.') . '#reset');
        }
        $hasil = reset_antrian($termasukJ);
        redirect('pengaturan.php?ok=' . urlencode('Reset antrean selesai — ' . (int) $hasil['dihapus'] . ' tiket belum selesai dihapus'
            . ($termasukJ ? ' (termasuk kode J).' : ' (kode J tidak direset).')
            . ' Tiket yang sudah selesai tetap tersimpan untuk laporan.') . '#reset');
    }

    /* ---------------- Database ---------------- */
    if ($action === 'db_optimize') {
        try {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            db()->exec('VACUUM');
            redirect('pengaturan.php?ok=' . urlencode('Database dioptimalkan (VACUUM + checkpoint WAL).') . '#database');
        } catch (Throwable $e) {
            redirect('pengaturan.php?err=' . urlencode('Optimasi gagal: ' . $e->getMessage()) . '#database');
        }
    }

    if ($action === 'db_uji') {
        try {
            $v = (string) db()->query('SELECT sqlite_version()')->fetchColumn();
            $n = (int) db()->query('SELECT COUNT(*) FROM tiket')->fetchColumn();
            redirect('pengaturan.php?ok=' . urlencode('Koneksi database OK — SQLite ' . $v . ', ' . $n . ' baris tiket terbaca.') . '#database');
        } catch (Throwable $e) {
            redirect('pengaturan.php?err=' . urlencode('Koneksi database bermasalah: ' . $e->getMessage()) . '#database');
        }
    }

    if ($action === 'db_arsip') {
        $bulan = max(1, min(120, (int) ($_POST['bulan_arsip'] ?? 12)));
        $batas = date('Y-m-d', strtotime('-' . $bulan . ' month'));
        try {
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $ids = $pdo->query('SELECT id FROM tiket WHERE tanggal < ' . $pdo->quote($batas))->fetchAll(PDO::FETCH_COLUMN);
            $hapusTiket = 0;
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $hapusTiket = (int) $pdo->exec('DELETE FROM tiket WHERE id IN (' . $in . ')');
                $pdo->exec('DELETE FROM tiket_log WHERE tiket_id IN (' . $in . ')');
            }
            $pdo->exec('COMMIT');
            redirect('pengaturan.php?ok=' . urlencode('Arsip dibersihkan: ' . $hapusTiket . ' tiket sebelum ' . $batas . ' dihapus.') . '#database');
        } catch (Throwable $e) {
            redirect('pengaturan.php?err=' . urlencode('Pembersihan arsip gagal: ' . $e->getMessage()) . '#database');
        }
    }

    /* ---------------- SIMRS (SIMGOS) ---------------- */
    if ($action === 'simrs_simpan') {
        $metode = (string) ($_POST['simrs_metode'] ?? 'rest');
        set_setting('simrs_metode', $metode === 'agen' ? 'agen' : 'rest');
        set_setting('simrs_aktif', isset($_POST['simrs_aktif']) ? '1' : '0');
        set_setting('simrs_wajib_rm', isset($_POST['simrs_wajib_rm']) ? '1' : '0');
        set_setting('simrs_tampil_tiket', isset($_POST['simrs_tampil_tiket']) ? '1' : '0');
        set_setting('simrs_tampil_petugas', isset($_POST['simrs_tampil_petugas']) ? '1' : '0');
        log_admin('Simpan pengaturan SIMRS', 'metode=' . ($metode === 'agen' ? 'agen (jaringan RS)' : 'REST API')
            . ' · aktif=' . (isset($_POST['simrs_aktif']) ? '1' : '0'));
        redirect('pengaturan.php?ok=' . urlencode('Pengaturan SIMRS disimpan.') . '#simrs');
    }

    /* Simpan setelan REST API SIMGOS; sekaligus bisa langsung menguji koneksinya. */
    if ($action === 'simrs_rest_simpan') {
        set_setting('simrs_rest_url', simrs_rest_rapikan_dasar((string) ($_POST['simrs_rest_url'] ?? '')));
        $jalur = trim((string) ($_POST['simrs_rest_jalur'] ?? ''));
        if ($jalur === '') { $jalur = '/api/pasien/{rm}'; }
        if ($jalur[0] !== '/') { $jalur = '/' . $jalur; }
        set_setting('simrs_rest_jalur', substr($jalur, 0, 200));
        set_setting('simrs_rest_http', strtoupper((string) ($_POST['simrs_rest_http'] ?? 'GET')) === 'POST' ? 'POST' : 'GET');
        set_setting('simrs_rest_header', substr(trim((string) ($_POST['simrs_rest_header'] ?? '')), 0, 60));
        set_setting('simrs_rest_nilai', substr(trim((string) ($_POST['simrs_rest_nilai'] ?? '')), 0, 300));
        set_setting('simrs_rest_field_nama', substr(trim((string) ($_POST['simrs_rest_field_nama'] ?? '')), 0, 80));
        set_setting('simrs_rest_field_rm', substr(trim((string) ($_POST['simrs_rest_field_rm'] ?? '')), 0, 80));

        if (empty($_POST['uji_sekarang'])) {
            redirect('pengaturan.php?ok=' . urlencode('Setelan REST API SIMRS disimpan.') . '#simrs');
        }

        /* Tombol "Simpan & Uji Koneksi": uji sekarang juga memakai No. RM contoh. */
        $rmUji = trim((string) ($_POST['rm_uji'] ?? '')) ?: '1';
        $hasil = simrs_rest_uji($rmUji);
        set_setting('simrs_rest_uji', json_encode([
            'waktu' => now_sql(), 'url' => $hasil['url'], 'ok' => $hasil['ok'] ? 1 : 0,
            'nama' => $hasil['nama'], 'kunci' => $hasil['kunci'],
        ], JSON_UNESCAPED_UNICODE));
        if ($hasil['ok']) {
            redirect('pengaturan.php?ok=' . urlencode('BERHASIL — nama pasien terbaca: "' . $hasil['nama']
                . '" (field "' . $hasil['kunci'] . '", ' . $hasil['waktu'] . ' detik). No. RM ' . $rmUji
                . ' sudah bisa dicari dari form Ambil Antrian.') . '#simrs');
        }
        redirect('pengaturan.php?err=' . urlencode('BELUM BERHASIL — ' . simrs_rest_pesan_galat($hasil)
            . ' Contoh jawaban server: ' . simrs_rest_ringkas($hasil['ringkas'], 200)) . '#simrs');
    }

    /* Pakai jalur hasil deteksi otomatis (dikirim dari halaman Pengaturan). */
    if ($action === 'simrs_rest_jalur_pakai') {
        $jalur = trim((string) ($_POST['jalur'] ?? ''));
        /* Pengaman: bila yang terkirim ternyata ALAMAT LENGKAP (mis. https://host/api/pasien/1),
           ambil bagian jalurnya saja — menyimpan alamat lengkap membuat alamat jadi ganda
           dan pencarian berikutnya gagal. */
        if (preg_match('~^https?://~i', $jalur)) {
            $bagian = parse_url($jalur);
            $jalur = (string) ($bagian['path'] ?? '');
            if (!empty($bagian['query'])) { $jalur .= '?' . $bagian['query']; }
        }
        if ($jalur === '') {
            redirect('pengaturan.php?err=' . urlencode('Jalur yang dipilih kosong.') . '#simrs');
        }
        set_setting('simrs_rest_jalur', substr($jalur, 0, 200));
        $rmUji = trim((string) ($_POST['rm_uji'] ?? '')) ?: '1';
        $hasil = simrs_rest_uji($rmUji);
        set_setting('simrs_rest_uji', json_encode([
            'waktu' => now_sql(), 'url' => $hasil['url'], 'ok' => $hasil['ok'] ? 1 : 0,
            'nama' => $hasil['nama'], 'kunci' => $hasil['kunci'],
        ], JSON_UNESCAPED_UNICODE));
        if ($hasil['ok']) {
            redirect('pengaturan.php?ok=' . urlencode('Jalur "' . $jalur . '" dipakai dan berhasil — nama pasien: "'
                . $hasil['nama'] . '" (field "' . $hasil['kunci'] . '").') . '#simrs');
        }
        redirect('pengaturan.php?err=' . urlencode('Jalur "' . $jalur . '" sudah disimpan, tetapi uji ulang belum berhasil: '
            . simrs_rest_pesan_galat($hasil)) . '#simrs');
    }

    if ($action === 'qr_uji_alamat') {
        $hasil = uji_alamat_cache(true);   /* paksa uji ulang */
        if ($hasil['ok']) {
            redirect('pengaturan.php?ok=' . urlencode('Uji alamat: BERHASIL — ' . $hasil['pesan']) . '#qr');
        }
        redirect('pengaturan.php?err=' . urlencode('Uji alamat: GAGAL — ' . $hasil['pesan']
            . (!empty($hasil['saran']) ? ' Gunakan tombol "Pakai alamat yang disarankan" untuk memperbaikinya.' : '')) . '#qr');
    }

    if ($action === 'qr_pakai_saran') {
        $saran = alamat_normal((string) ($_POST['saran'] ?? ''));
        $hasil = uji_alamat_publik($saran);
        if (!$hasil['ok']) {
            redirect('pengaturan.php?err=' . urlencode('Alamat saran itu juga tidak berhasil diuji: ' . $hasil['pesan']) . '#qr');
        }
        set_setting('lacak_url', $saran);
        log_admin('Ubah alamat publik QR', 'menjadi ' . $saran . ' (hasil uji alamat)');
        redirect('pengaturan.php?ok=' . urlencode('Alamat publik diperbarui menjadi ' . $saran
            . ' dan sudah diuji berhasil. Tiket yang dicetak setelah ini memakai alamat tersebut.') . '#qr');
    }

    if ($action === 'simrs_token_baru') {
        set_setting('simrs_token', bin2hex(random_bytes(24)));
        log_admin('Buat token agen SIMRS baru', 'token lama tidak berlaku lagi');
        redirect('pengaturan.php?ok=' . urlencode('Token agen dibuat ulang. Perbarui token di berkas konfigurasi agen, '
            . 'lalu jalankan ulang agen — token lama tidak berlaku lagi.') . '#simrs');
    }

    if ($action === 'simrs_riwayat_hapus') {
        $n = simrs_purge(0);
        redirect('pengaturan.php?ok=' . urlencode('Riwayat permintaan pencarian pasien dibersihkan (' . $n . ' baris).') . '#simrs');
    }

    if ($action === 'simrs_uji_agen') {
        if (!simrs_aktif()) {
            redirect('pengaturan.php?err=' . urlencode('Nyalakan integrasi SIMRS dan isi alamat/pengaturannya terlebih dahulu.') . '#simrs');
        }
        $rmUji = trim((string) ($_POST['rm_uji'] ?? '')) ?: '1';

        /* Metode REST: uji ini memanggil server SIMGOS langsung dan hasilnya langsung terlihat. */
        if (simrs_metode() === 'rest') {
            $r = simrs_rest_cari($rmUji, (string) $user['username']);
            if ($r['ok']) {
                redirect('pengaturan.php?ok=' . urlencode('Uji cari BERHASIL — No. RM ' . $rmUji . ' → "'
                    . $r['nama'] . '". Nama ini yang akan terisi otomatis di form Ambil Antrian.') . '#simrs');
            }
            redirect('pengaturan.php?err=' . urlencode('Uji cari BELUM BERHASIL — ' . $r['pesan']) . '#simrs');
        }

        $r = simrs_minta($rmUji, (string) $user['username']);
        redirect('pengaturan.php?ok=' . urlencode('Permintaan uji dikirim (id ' . $r . '). Bila agen berjalan, jawaban muncul di daftar permintaan di bawah dalam beberapa detik.') . '#simrs');
    }

    /* ---------------- Akun ---------------- */
    if ($action === 'akun_simpan') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $hasil = auth_ubah_kredensial(
            $id,
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_ulang'] ?? ''),
            (string) ($_POST['pengawas'] ?? ''),
            trim((string) ($_POST['nama'] ?? ''))
        );
        if (!$hasil['ok']) {
            redirect('pengaturan.php?err=' . urlencode($hasil['error']) . '#akun');
        }
        $pesan = 'Akun diperbarui.';
        if ((string) ($_POST['password'] ?? '') !== '') {
            $pesan .= ' Kata sandi baru aktif dan sesi login lain pada akun itu diputuskan.';
        }
        redirect('pengaturan.php?ok=' . urlencode($pesan) . '#akun');
    }

    if ($action === 'akun_tambah') {
        $hasil = auth_tambah_user(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_ulang'] ?? ''),
            (string) ($_POST['role'] ?? 'petugas'),
            trim((string) ($_POST['nama'] ?? ''))
        );
        if (!$hasil['ok']) {
            redirect('pengaturan.php?err=' . urlencode($hasil['error']) . '#akun');
        }
        redirect('pengaturan.php?ok=' . urlencode('Akun petugas baru dibuat.') . '#akun');
    }

    if ($action === 'akun_aktif') {
        $id     = (int) ($_POST['user_id'] ?? 0);
        $status = (int) ($_POST['aktif'] ?? 1) === 1 ? 1 : 0;
        if ($id === (int) $user['id'] && $status === 0) {
            redirect('pengaturan.php?err=' . urlencode('Tidak bisa menonaktifkan akun yang sedang Anda pakai.') . '#akun');
        }
        db()->prepare('UPDATE user SET aktif = ?, updated_at = ? WHERE id = ?')->execute([$status, now(), $id]);
        if ($status === 0) {
            db()->prepare('DELETE FROM sesi WHERE user_id = ?')->execute([$id]);
        }
        redirect('pengaturan.php?ok=' . urlencode($status === 1 ? 'Akun diaktifkan.' : 'Akun dinonaktifkan & sesinya diputuskan.') . '#akun');
    }

    if ($action === 'sesi_bersih') {
        db()->prepare('DELETE FROM sesi WHERE expires_at < ?')->execute([now()]);
        redirect('pengaturan.php?ok=' . urlencode('Sesi kedaluwarsa dibersihkan.') . '#akun');
    }
}

/* ---------------- Data untuk tampilan ---------------- */
$kodes   = kode_daftar(true);
$hari    = tiket_hari_ini();
$perKode = [];
foreach ($hari as $t) {
    $perKode[(string) $t['kode']] = ($perKode[(string) $t['kode']] ?? 0) + 1;
}

$dbFile  = db_path();
$dbUkuran = is_file($dbFile) ? (int) filesize($dbFile) : 0;
$dbWal   = is_file($dbFile . '-wal') ? (int) filesize($dbFile . '-wal') : 0;
$tabel   = ['kode_antrian', 'tiket', 'tiket_log', 'nomor_counter', 'user', 'sesi', 'pengaturan'];
$jumlahTabel = [];
foreach ($tabel as $tb) {
    try {
        $jumlahTabel[$tb] = (int) db()->query('SELECT COUNT(*) FROM ' . $tb)->fetchColumn();
    } catch (Throwable $e) {
        $jumlahTabel[$tb] = -1;
    }
}
$jurnal = (string) db()->query('PRAGMA journal_mode')->fetchColumn();
$sqliteVer = (string) db()->query('SELECT sqlite_version()')->fetchColumn();
$mediaAktif = media_token() !== '';
$peringatan  = kredensial_bawaan();
$kodeTerakhir = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM tiket')->fetchColumn();

page_head('Pengaturan', 'pengaturan.php');
?>
<?php flash(); ?>

<section class="page-head">
    <div>
        <h1><?= icon('cog') ?> Pengaturan Aplikasi</h1>
        <?php if ($user['role'] === 'admin_apotik'): ?>
            <p>Peran <strong>Admin Apotik</strong>: Anda dapat membuka tab
                <strong>Antrean &amp; Reset</strong> dan <strong>Laporan</strong>.
                Pengaturan lain hanya untuk superadmin.</p>
        <?php else: ?>
            <p>Atur identitas &amp; tampilan, kode antrian, suara panggilan, printer tiket, reset antrean,
                koneksi database, laporan, serta akun petugas.</p>
        <?php endif; ?>
    </div>
    <div class="page-head-side">
        <span class="pill pill-teal"><?= h($user['nama'] !== '' ? $user['nama'] : $user['username']) ?> · <?= h(label_role($user['role'])) ?></span>
    </div>
</section>

<?php if ($peringatan && $user['role'] === 'superadmin'): ?>
    <div class="flash flash-warn">
        <strong>Perhatian keamanan:</strong>
        <ul><?php foreach ($peringatan as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<nav class="tabs" id="tabs" data-tab-awal="<?= $tabOk('tampilan') ? 'tampilan' : 'reset' ?>">
    <?php if ($tabOk('tampilan')): ?>
        <a href="#tampilan" class="tab<?= $tabOk('tampilan') && !$tabOk('kode') ? ' is-active' : '' ?>"><?= icon('grid') ?> Identitas &amp; Tampilan</a>
    <?php endif; ?>
    <?php if ($tabOk('kode')): ?>
    <a href="#kode" class="tab"><?= icon('tiket') ?> Kode Antrian</a>
    <?php endif; ?>
    <?php if ($tabOk('suara')): ?>
    <a href="#suara" class="tab"><?= icon('bell') ?> Suara &amp; Panggilan</a>
    <?php endif; ?>
    <?php if ($tabOk('printer')): ?>
    <a href="#printer" class="tab"><?= icon('print') ?> Printer</a>
    <?php endif; ?>
    <?php if ($tabOk('qr')): ?>
    <a href="#qr" class="tab"><?= icon('tiket') ?> QR &amp; Lacak Obat</a>
    <?php endif; ?>
    <?php if ($tabOk('simrs')): ?>
    <a href="#simrs" class="tab"><?= icon('db') ?> SIMRS / Rekam Medis</a>
    <?php endif; ?>
    <?php if ($tabOk('reset')): ?>
    <a href="#reset" class="tab"><?= icon('track') ?> Antrean &amp; Reset</a>
    <?php endif; ?>
    <?php if ($tabOk('database')): ?>
    <a href="#database" class="tab"><?= icon('db') ?> Koneksi Database</a>
    <?php endif; ?>
    <?php if ($tabOk('laporan')): ?>
    <a href="#laporan" class="tab"><?= icon('chart') ?> Laporan</a>
    <?php endif; ?>
    <?php if ($tabOk('akun')): ?>
    <a href="#akun" class="tab"><?= icon('user') ?> Akun &amp; Keamanan</a>
    <?php endif; ?>
</nav>

<!-- ============ IDENTITAS & TAMPILAN ============ -->
<?php if ($tabOk('tampilan')): ?>
<section class="tab-panel is-active" id="tampilan">
    <form method="post" enctype="multipart/form-data" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="identitas_simpan">
        <header class="card-head">
            <h2><?= icon('grid') ?> Identitas Apotek &amp; Layar Display</h2>
            <span class="card-hint">Muncul di display, tiket, dan laporan</span>
        </header>

        <div class="field-row">
            <label class="field">
                <span>Nama apotek / rumah sakit</span>
                <input type="text" name="nama_apotek" value="<?= h(setting('nama_apotek')) ?>" maxlength="60" required>
            </label>
            <label class="field">
                <span>Alamat / telepon (baris kedua display)</span>
                <input type="text" name="alamat_apotek" value="<?= h(setting('alamat_apotek')) ?>" maxlength="120">
            </label>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Judul display</span>
                <input type="text" name="display_judul" value="<?= h(setting('display_judul')) ?>" maxlength="60">
            </label>
            <label class="field">
                <span>Nama loket (teks panggilan)</span>
                <input type="text" name="loket_default" value="<?= h(setting('loket_default')) ?>" maxlength="60">
            </label>
        </div>

        <label class="field">
            <span>Teks berjalan (footer display)</span>
            <textarea name="footer_teks" rows="2" maxlength="400"><?= h(setting('footer_teks')) ?></textarea>
        </label>

        <div class="logo-row">
            <div class="logo-preview">
                <?php if (setting('logo_url') !== ''): ?>
                    <img src="<?= h(setting('logo_url')) ?>" alt="Logo saat ini">
                <?php else: ?>
                    <span class="logo-placeholder"><?= icon('tiket') ?><em>Tanpa logo</em></span>
                <?php endif; ?>
            </div>
            <div class="logo-control">
                <label class="field">
                    <span>Ganti logo (dari penyimpanan perangkat)</span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                </label>
                <p class="hint">
                    Format JPG/PNG/GIF/WEBP/SVG, disarankan &lt; 1 MB.
                    <?php if (!$mediaAktif): ?>
                        <span class="warn-text">Unggah logo aktif setelah aplikasi dipublikasikan (token penyimpanan media belum ada).</span>
                    <?php endif; ?>
                </p>
                <?php if (setting('logo_url') !== ''): ?>
                    <button class="btn btn-mini btn-danger" type="submit" name="hapus_logo" value="1"
                            onclick="return confirm('Hapus logo dari display?')">Hapus logo</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Warna aksen tema</span>
                <input type="color" name="tema_warna" value="<?= h(setting('tema_warna', '#0d9488')) ?>">
            </label>
            <label class="field">
                <span>Zona waktu</span>
                <select name="zona_waktu">
                    <?php
                    $zonaPilihan = ['Asia/Makassar' => 'WITA — Asia/Makassar', 'Asia/Jakarta' => 'WIB — Asia/Jakarta',
                                    'Asia/Jayapura' => 'WIT — Asia/Jayapura', 'Asia/Kuala_Lumpur' => 'MYT — Kuala Lumpur'];
                    $zonaSekarang = setting('zona_waktu', 'Asia/Makassar');
                    foreach ($zonaPilihan as $z => $lbl): ?>
                        <option value="<?= h($z) ?>" <?= $zonaSekarang === $z ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                    <?php if (!isset($zonaPilihan[$zonaSekarang])): ?>
                        <option value="<?= h($zonaSekarang) ?>" selected><?= h($zonaSekarang) ?></option>
                    <?php endif; ?>
                </select>
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="field">
                <span>Interval percobaan ulang bila koneksi bermasalah (detik)</span>
                <input type="number" name="refresh_detik" min="3" max="60" value="<?= (int) setting_num('refresh_detik', 5) ?>">
                <em class="hint">Display memantau perubahan tiket <strong>tiap 1 detik</strong> (nyaris seketika) —
                    angka ini hanya dipakai bila koneksi ke server terputus.</em>
            </label>
            <label class="field">
                <span>Kecepatan teks berjalan (px/detik)</span>
                <input type="number" name="kecepatan_gulir" min="5" max="200" value="<?= (int) setting_num('kecepatan_gulir', 40) ?>">
            </label>
            <label class="field">
                <span>Kecepatan gulir baris "Obat Sudah Diterima" (px/detik)</span>
                <input type="number" name="kecepatan_gulir_selesai" min="3" max="200"
                       value="<?= (int) kecepatan_gulir_selesai() ?>">
                <em class="hint">
                    Semakin <strong>kecil angkanya, semakin pelan</strong> nomor yang sudah diterima
                    bergulir di bagian bawah display. Bawaan <strong>30</strong> (pelan &amp; nyaman dibaca).
                    Sebelumnya baris ini mengikuti batas 60 detik per putaran sehingga pada hari ramai
                    (ratusan tiket) gulirnya menjadi sangat cepat — pengaturan ini membuatnya tetap.
                    Contoh: <span class="mono">30</span> = pelan · <span class="mono">80</span> = sedang ·
                    <span class="mono">150</span> = cepat.
                </em>
            </label>
            <label class="field">
                <span>Highlight panggilan (detik)</span>
                <input type="number" name="durasi_panggil" min="4" max="120" value="<?= (int) setting_num('durasi_panggil', 12) ?>">
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="check">
                <input type="checkbox" name="display_tampil_riwayat" value="1" <?= setting_bool('display_tampil_riwayat', true) ? 'checked' : '' ?>>
                <span>Tampilkan riwayat panggilan di display</span>
            </label>
            <label class="check">
                <input type="checkbox" name="display_tampil_tunggu" value="1" <?= setting_bool('display_tampil_tunggu', true) ? 'checked' : '' ?>>
                <span>Tampilkan waktu tunggu tiap tiket</span>
            </label>
        </div>

        <p class="card-note">
            Setiap kolom status menampilkan <strong>seluruh tiket hari ini</strong>. Bila jumlahnya melebihi
            tinggi panel, isi kolom <strong>bergulir otomatis</strong> sehingga semua nomor tetap terlihat
            (tidak ada lagi ringkasan "+N tiket lainnya").
        </p>

        <div class="video-setelan">
            <header class="card-head">
                <h2><?= icon('tv') ?> Panel Video di Display</h2>
                <span class="card-hint">Unggah berkas video · diputar berulang tanpa henti</span>
            </header>
            <p class="hint">
                Panel video tampil di <strong>sisi kiri</strong> panel nomor panggilan, setinggi panel itu —
                videonya <strong>mengisi penuh panel</strong> (tanpa bingkai/label tambahan).
                Unggah berkas video dari perangkat Bapak/Ibu (MP4/WebM/MOV) — video diputar
                <strong>berurutan dan berulang terus-menerus</strong> di display, tanpa perlu internet ke YouTube.
                Berkas disimpan di penyimpanan media platform (bukan folder aplikasi) supaya
                <strong>penyimpanan aplikasi tidak cepat penuh</strong> dan video tetap dapat diputar di mana pun
                display ditampilkan.
            </p>

            <div class="radio-row">
                <label class="check">
                    <input type="checkbox" name="video_aktif" value="1" <?= setting_bool('video_aktif', false) ? 'checked' : '' ?>>
                    <span>Tampilkan panel video di display</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="video_suara" value="1" <?= setting_bool('video_suara', false) ? 'checked' : '' ?>>
                    <span>Putar dengan suara <em>(perlu tekan "Aktifkan Suara" di display sekali)</em></span>
                </label>
                <?php /* Judul panel tidak lagi tampil di display (panel video dibuat penuh).
                         Nilainya tetap disimpan hanya sebagai label pembaca layar/tooltip. */ ?>
                <label class="field">
                    <span>Nama panel <em>(hanya untuk keterangan/tooltip, tidak tampil di layar)</em></span>
                    <input type="text" name="video_judul" maxlength="40" value="<?= h(setting('video_judul', 'INFORMASI KESEHATAN')) ?>">
                </label>
            </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('grid') ?> Simpan Pengaturan</button>
            <a class="btn btn-ghost" href="display.php" target="_blank" rel="noopener"><?= icon('tv') ?> Lihat Display</a>
        </div>
    </form>

    <?php $videoList = video_daftar(); ?>
    <article class="card" id="video-berkas">
        <header class="card-head">
            <h2><?= icon('tv') ?> Berkas Video Panel</h2>
            <span class="card-hint">
                <?= count($videoList) ?> video · total <?= h(ukuran_teks(video_total_ukuran())) ?>
            </span>
        </header>

        <form method="post" action="pengaturan.php" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="video_unggah">
            <div class="field-row">
                <label class="field">
                    <span>Pilih berkas video dari perangkat</span>
                    <input type="file" name="video[]" accept="video/mp4,video/webm,video/quicktime,video/ogg" multiple>
                    <em class="hint">
                        Bisa pilih beberapa berkas sekaligus · disarankan <strong>MP4 (H.264)</strong> agar pasti
                        diputar di semua perangkat/TV · berkas disimpan di penyimpanan media platform sehingga
                        penyimpanan aplikasi tidak cepat penuh.
                        <br><strong>Batas ukuran per berkas di server ini: <?= h(ukuran_teks(video_batas_unggah())) ?></strong>
                        (dan total satu kali unggah <?= h(ukuran_teks(video_batas_kirim())) ?>).
                        Video yang lebih besar perlu dikompres lebih dulu (mis. 720p) atau dipotong menjadi beberapa bagian.
                    </em>
                </label>
                <div class="field field-btn">
                    <button class="btn btn-primary" type="submit"><?= icon('tv') ?> Unggah Video</button>
                </div>
            </div>
        </form>

        <?php if ($videoList): ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>#</th><th>Nama berkas</th><th class="num">Ukuran</th><th>Diunggah</th><th class="aksi">Hapus</th></tr></thead>
                    <tbody>
                    <?php foreach ($videoList as $i => $v): ?>
                        <tr>
                            <td><?= (int) $i + 1 ?></td>
                            <td><strong><?= h($v['nama']) ?></strong></td>
                            <td class="num"><?= h(ukuran_teks((int) $v['ukuran'])) ?></td>
                            <td><?= h((string) $v['waktu'] ?: '—') ?></td>
                            <td class="aksi">
                                <form method="post" action="pengaturan.php" class="inline-form"
                                      onsubmit="return confirm('Hapus video &quot;<?= h($v['nama']) ?>&quot; dari panel display?')">
                                    <input type="hidden" name="action" value="video_hapus">
                                    <input type="hidden" name="idx" value="<?= (int) $i ?>">
                                    <button class="btn btn-mini btn-danger" type="submit">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="card-note">
                Video diputar <strong>berurutan sesuai daftar di atas</strong>, lalu kembali ke video pertama
                (berulang tanpa henti). Menghapus video di sini menghapusnya dari panel display;
                berkas aslinya tetap tersimpan di penyimpanan media platform.
            </p>
            <form method="post" action="pengaturan.php"
                  onsubmit="return confirm('Hapus SEMUA video dari panel display?')">
                <input type="hidden" name="action" value="video_kosongkan">
                <button class="btn btn-danger" type="submit">Hapus Semua Video</button>
            </form>
        <?php else: ?>
            <p class="empty">Belum ada video yang diunggah. Unggah minimal satu video, lalu centang
                "Tampilkan panel video di display" di atas dan simpan.</p>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>

<!-- ============ KODE ANTRIAN ============ -->
<?php if ($tabOk('kode')): ?>
<section class="tab-panel" id="kode">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('tiket') ?> Kode Antrian &amp; Penomoran</h2>
            <span class="card-hint">Kode harian reset tiap hari · kode bulanan (J) reset tiap tanggal 1</span>
        </header>
        <div class="kode-editor">
            <div class="kode-editor-head">
                <span>Kode</span><span>Layanan</span><span>Urutan</span><span>Reset</span>
                <span>Aktif</span><span>Tiket hari ini</span><span>Aksi</span>
            </div>
            <?php foreach ($kodes as $k): ?>
                <form method="post" action="pengaturan.php" class="kode-row">
                    <input type="hidden" name="action" value="kode_simpan">
                    <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                    <span class="kode-kolom">
                        <span class="kode-chip kode-chip-lg" style="background:<?= h($k['warna']) ?>"><?= h($k['kode']) ?></span>
                        <input class="kode-warna" type="color" name="warna" value="<?= h($k['warna']) ?>"
                               title="Warna backpanel tiket kode <?= h($k['kode']) ?> di layar display"
                               aria-label="Warna kode <?= h($k['kode']) ?>">
                    </span>
                    <input class="input-inline" type="text" name="nama" value="<?= h($k['nama']) ?>" maxlength="40" aria-label="Nama layanan kode <?= h($k['kode']) ?>">
                    <input class="input-inline input-num" type="number" name="urutan" value="<?= (int) $k['urutan'] ?>" min="0" max="99" aria-label="Urutan">
                    <select name="reset_mode" class="input-inline" aria-label="Mode reset">
                        <option value="harian" <?= $k['reset_mode'] === 'harian' ? 'selected' : '' ?>>Harian (001 tiap hari)</option>
                        <option value="bulanan" <?= $k['reset_mode'] === 'bulanan' ? 'selected' : '' ?>>Bulanan (tanggal 1)</option>
                    </select>
                    <label class="check check-tight">
                        <input type="checkbox" name="aktif" value="1" <?= (int) $k['aktif'] === 1 ? 'checked' : '' ?>>
                        <span><?= (int) $k['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                    </label>
                    <span class="kode-row-stat"><?= (int) ($perKode[(string) $k['kode']] ?? 0) ?> tiket</span>
                    <span class="kode-row-aksi">
                        <button class="btn btn-mini btn-primary" type="submit">Simpan</button>
                        <button class="btn btn-mini btn-danger" type="submit" name="hapus" value="1"
                                formnovalidate onclick="return confirm('Hapus kode <?= h($k['kode']) ?>?')">Hapus</button>
                    </span>
                </form>
            <?php endforeach; ?>
        </div>
        <p class="card-note">
            <strong>Warna</strong> di samping tiap huruf kode dipakai sebagai warna backpanel tiket di layar
            display (sedikit transparan, teksnya tetap gelap) supaya tiap kode mudah dibedakan dari jauh.
            Kode dengan riwayat tiket tidak dapat dihapus — nonaktifkan agar tombolnya hilang dari form ambil antrian.
            Perubahan reset ke <strong>bulanan</strong> membuat penomoran menyambung antar hari dan hanya mulai dari 001 pada tanggal 1.
        </p>
    </article>

    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="kode_tambah">
        <header class="card-head"><h2>Tambah Kode Baru</h2></header>
        <div class="field-row field-row-3">
            <label class="field"><span>Kode (1 huruf)</span><input type="text" name="kode_baru" maxlength="1" pattern="[A-Za-z]" required></label>
            <label class="field"><span>Nama layanan</span><input type="text" name="nama_baru" maxlength="40" placeholder="Loket X"></label>
            <div class="field field-btn"><button class="btn btn-primary" type="submit">Tambah Kode</button></div>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ============ SUARA & PANGGILAN ============ -->
<?php if ($tabOk('suara')): ?>
<section class="tab-panel" id="suara">
    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="suara_simpan">
        <header class="card-head">
            <h2><?= icon('bell') ?> Suara Panggilan</h2>
            <span class="card-hint">Default: Google Bahasa Indonesia (id-ID)</span>
        </header>

        <div class="field-row">
            <label class="field">
                <span>Nama suara (voice)</span>
                <input type="text" name="suara_voice" id="suara-voice" list="daftar-voice"
                       value="<?= h(setting('suara_voice', 'Google Bahasa Indonesia')) ?>" maxlength="80">
                <datalist id="daftar-voice"></datalist>
                <em class="hint" id="voice-info">Daftar suara diambil dari perangkat/browser yang membuka halaman ini.</em>
            </label>
            <label class="field">
                <span>Kode bahasa</span>
                <input type="text" name="suara_lang" value="<?= h(setting('suara_lang', 'id-ID')) ?>" maxlength="10">
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="field">
                <span>Kecepatan bicara (0.5 - 2)</span>
                <input type="number" step="0.05" min="0.5" max="2" name="suara_rate" value="<?= h((string) setting_num('suara_rate', 0.95)) ?>">
            </label>
            <label class="field">
                <span>Volume (0 - 1)</span>
                <input type="number" step="0.05" min="0" max="1" name="suara_volume" value="<?= h((string) setting_num('suara_volume', 1)) ?>">
            </label>
            <label class="field">
                <span>Ulangi panggilan (kali)</span>
                <input type="number" min="1" max="5" name="suara_ulang" value="<?= (int) setting_num('suara_ulang', 3) ?>">
            </label>
        </div>

        <label class="field">
            <span>Teks pengumuman</span>
            <textarea name="suara_template" rows="3" maxlength="300" id="suara-template"><?= h(setting('suara_template')) ?></textarea>
            <em class="hint">Penanda yang tersedia: <code>{kode}</code> huruf kode · <code>{nomor}</code> nomor tiket ·
                <code>{kode_tiket}</code> kode lengkap (contoh A001) · <code>{loket}</code> nama loket.</em>
        </label>

        <div class="field-row field-row-3">
            <label class="check"><input type="checkbox" name="suara_eja_digit" value="1" <?= setting_bool('suara_eja_digit', true) ? 'checked' : '' ?>><span>Eja angka per digit (nol, nol, satu)</span></label>
            <label class="check"><input type="checkbox" name="suara_chime" value="1" <?= setting_bool('suara_chime', true) ? 'checked' : '' ?>><span>Bunyikan nada pembuka sebelum bicara</span></label>
            <label class="check"><input type="checkbox" name="suara_display" value="1" <?= setting_bool('suara_display', true) ? 'checked' : '' ?>><span>Layar display juga mengumumkan (perlu klik "Aktifkan Suara" di TV)</span></label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('bell') ?> Simpan Pengaturan Suara</button>
            <button class="btn btn-ghost" type="button" id="tes-suara-pengaturan"><?= icon('mic') ?> Tes Suara Sekarang</button>
            <a class="btn btn-ghost" href="panggil.php"><?= icon('mic') ?> Buka Halaman Panggil</a>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- ============ PRINTER ============ -->
<?php if ($tabOk('printer')): ?>
<section class="tab-panel" id="printer">
    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="printer_simpan">
        <header class="card-head">
            <h2><?= icon('print') ?> Printer Tiket</h2>
            <span class="card-hint">Thermal 80 mm default, dengan cadangan kertas A4</span>
        </header>

        <div class="radio-row">
            <?php $kertas = setting('printer_kertas', 'thermal80'); ?>
            <label class="radio-pill">
                <input type="radio" name="printer_kertas" value="thermal80" <?= $kertas === 'thermal80' ? 'checked' : '' ?>>
                <span>Thermal 80 mm</span>
            </label>
            <label class="radio-pill">
                <input type="radio" name="printer_kertas" value="a4" <?= $kertas === 'a4' ? 'checked' : '' ?>>
                <span>Kertas A4</span>
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="field"><span>Judul tiket</span><input type="text" name="printer_judul" value="<?= h(setting('printer_judul')) ?>" maxlength="60"></label>
            <label class="field"><span>Lebar area cetak thermal (mm)</span><input type="number" name="printer_lebar" min="40" max="80" value="<?= (int) setting_num('printer_lebar', 76) ?>"><em class="hint">80 mm gunakan 76 · kertas 58 mm gunakan 48</em></label>
            <label class="field"><span>Baris kosong bawah</span><input type="number" name="printer_baris_kosong" min="0" max="6" value="<?= (int) setting_num('printer_baris_kosong', 1) ?>"><em class="hint">ruang untuk memotong kertas</em></label>
        </div>

        <div class="field-row">
            <label class="check">
                <input type="checkbox" name="printer_otomatis" value="1" <?= setting_bool('printer_otomatis', true) ? 'checked' : '' ?>>
                <span>Cetak otomatis begitu tiket dibuat (langsung tanpa klik bila mode cetak senyap aktif)</span>
            </label>
            <label class="check">
                <input type="checkbox" name="printer_senyap_tip" value="1" <?= setting_bool('printer_senyap_tip', true) ? 'checked' : '' ?>>
                <span>Tampilkan tip cara mengaktifkan cetak senyap di halaman tiket</span>
            </label>
            <label class="check">
                <input type="checkbox" name="printer_dua_struk" value="1" <?= setting_bool('printer_dua_struk', false) ? 'checked' : '' ?>>
                <span><strong>Cetak 2 rangkap</strong> — rangkap kedua (tinggi ±30 mm) berisi kode tiket besar + catatan,
                    untuk ditempel pada resep sebagai penanda petugas farmasi. Saklar cepatnya ada di halaman
                    <a href="ambil.php">Ambil Antrian</a>, di samping tanggal.</span>
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('print') ?> Simpan Pengaturan Printer</button>
            <?php if ($kodeTerakhir > 0): ?>
                <a class="btn btn-ghost" href="cetak.php?id=<?= $kodeTerakhir ?>" target="_blank" rel="noopener"><?= icon('tiket') ?> Cetak Contoh Tiket Terakhir</a>
            <?php endif; ?>
        </div>
    </form>

    <article class="card" id="agen-cetak">
        <header class="card-head">
            <h2><?= icon('print') ?> Agen Cetak — Printer Khusus Aplikasi Ini</h2>
            <span class="card-hint">Cetak tanpa dialog ke printer thermal tertentu</span>
        </header>

        <p class="card-note">
            <strong>Kenapa perlu agen?</strong> Browser tidak diizinkan memilih printer sendiri (demi keamanan),
            jadi halaman web tidak bisa "mengunci" printer thermal tanpa dialog. Agen kecil yang dijalankan di
            komputer apotek menjembatani hal itu: aplikasi mengirim tiket ke agen, agen mencetaknya ke
            <strong>printer yang Bapak/Ibu tentukan</strong>.
            <br><strong>Printer default Windows tidak diubah</strong> — aplikasi lain di komputer yang sama
            (mis. yang memakai kertas A4) tetap memakai printer default sistem.
        </p>
        <p class="card-note">
            <strong>Perlu diketahui:</strong> tombol <em>Uji Koneksi Agen</em> hanya memeriksa apakah agen
            <strong>hidup</strong> di alamat di bawah — printer belum dipakai pada langkah itu. Jadi pesan
            "tidak dapat menghubungi agen" <strong>bukan</strong> karena printer belum terhubung, melainkan
            karena agen belum dijalankan atau berkas <span class="mono">config.php</span>-nya bermasalah.
            Agen sekarang melaporkan sendiri bila penyebabnya yang kedua.
        </p>

        <form method="post" action="pengaturan.php" class="form-grid">
            <input type="hidden" name="action" value="cetak_agen_simpan">
            <div class="field-row field-row-3">
                <label class="check">
                    <input type="checkbox" name="cetak_agen_aktif" value="1" <?= setting_bool('cetak_agen_aktif', false) ? 'checked' : '' ?>>
                    <span>Cetak tiket lewat agen (tanpa dialog)</span>
                </label>
                <label class="field">
                    <span>Alamat agen di komputer ini</span>
                    <input type="text" id="agen-url" name="cetak_agen_url" maxlength="120"
                           value="<?= h(setting('cetak_agen_url', 'http://127.0.0.1:17890')) ?>">
                </label>
                <label class="field">
                    <span>Nama printer thermal</span>
                    <select id="agen-printer" name="cetak_agen_printer">
                        <option value="<?= h(setting('cetak_agen_printer')) ?>"><?= h(setting('cetak_agen_printer') !== '' ? setting('cetak_agen_printer') : '— tekan "Ambil Daftar Printer" —') ?></option>
                    </select>
                </label>
            </div>

            <div class="field-row">
                <label class="field">
                    <span>Token agen <em>(disalin ke config.php agen)</em></span>
                    <input type="text" id="agen-token" value="<?= h(setting('cetak_agen_token')) ?>" readonly onclick="this.select()">
                </label>
                <div class="field field-btn">
                    <button class="btn btn-mini" type="button" data-salin="#agen-token">Salin token</button>
                </div>
            </div>

            <div class="uji-alamat-baris">
                <button class="btn btn-accent" type="button" id="agen-uji"><?= icon('print') ?> Uji Koneksi Agen</button>
                <button class="btn btn-ghost" type="button" id="agen-tes"><?= icon('print') ?> Tes Cetak</button>
                <button class="btn btn-ghost" type="button" id="agen-daftar"><?= icon('db') ?> Ambil Daftar Printer</button>
            </div>
            <div class="agen-status" id="agen-status">Belum diperiksa.</div>

            <?php $zipAgen = __DIR__ . '/unduh/agen-cetak.zip'; ?>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= icon('print') ?> Simpan Setelan Agen</button>
                <?php if (is_file($zipAgen)): ?>
                    <a class="btn btn-ghost" href="unduh/agen-cetak.zip" download>
                        <?= icon('print') ?> Unduh Paket Agen (<?= h(round(filesize($zipAgen) / 1024, 0)) ?> KB)
                    </a>
                <?php endif; ?>
                <button class="btn btn-danger" type="submit" name="cetak_agen_token_baru" value="1"
                        onclick="return confirm('Buat token baru? Agen akan berhenti bekerja sampai token di config.php diperbarui.')">
                    Buat Token Baru
                </button>
            </div>
        </form>

        <p class="hint">
            Langkah pemasangan: (1) salin folder <span class="mono">agen-cetak</span> ke komputer apotek,
            (2) salin <span class="mono">config.contoh.php</span> menjadi <span class="mono">config.php</span>
            dan isi <strong>nama printer</strong> + <strong>token</strong> di atas,
            (3) klik dua kali <span class="mono">jalankan-agen-cetak.bat</span>,
            (4) tekan <strong>Uji Koneksi Agen</strong> lalu <strong>Tes Cetak</strong> di halaman ini.
            Panduan lengkap ada di <span class="mono">agen-cetak/README.md</span>.
        </p>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('print') ?> Cetak Senyap — tanpa dialog cetak</h2>
            <span class="card-hint">Tombol Cetak Sekarang langsung ke printer yang aktif</span>
        </header>

        <p class="card-note">
            Dialog cetak tidak dapat dilewati oleh halaman web (batasan keamanan semua browser modern) —
            termasuk tombol <em>Cetak Sekarang</em> maupun cetak otomatis. Yang bisa: menjalankan browser di
            komputer loket dengan <strong>mode cetak senyap</strong>. Bila mode itu aktif, perintah cetak dari
            aplikasi ini langsung dikirim ke <strong>printer default Windows</strong> tanpa dialog dan tanpa klik.
        </p>

        <div class="panduan-grid">
            <div class="panduan-item">
                <h3>1. Chrome &amp; Edge (disarankan)</h3>
                <ol>
                    <li>Pastikan printer tiket sudah ditetapkan sebagai <strong>printer default</strong> di Windows.</li>
                    <li>Buat pintasan (shortcut) ke Chrome/Edge, lalu klik kanan → <strong>Properties</strong>.</li>
                    <li>Pada kolom <strong>Target</strong>, tambahkan <code>--kiosk-printing</code> setelah nama program, misalnya:</li>
                </ol>
                <label class="panduan-kode">
                    <span>Chrome</span>
                    <textarea readonly rows="2" id="cmd-chrome"><?= h($perintahChrome) ?></textarea>
                    <button class="btn btn-mini" type="button" data-salin="#cmd-chrome">Salin</button>
                </label>
                <label class="panduan-kode">
                    <span>Edge</span>
                    <textarea readonly rows="2" id="cmd-edge"><?= h($perintahEdge) ?></textarea>
                    <button class="btn btn-mini" type="button" data-salin="#cmd-edge">Salin</button>
                </label>
                <p class="hint">
                    Buka aplikasi <strong>dari pintasan itu</strong> (jangan dari pintasan biasa), lalu coba ambil
                    satu tiket — seharusnya tercetak tanpa dialog. Pintasan ini bisa diletakkan di Desktop komputer loket.
                </p>
            </div>

            <div class="panduan-item">
                <h3>2. Firefox</h3>
                <ol>
                    <li>Buka <code>about:config</code>, terima peringatannya.</li>
                    <li>Cari <code>print.always_print_silent</code> → ubah menjadi <strong>true</strong>.</li>
                    <li>Cari <code>print.show_print_progress</code> → ubah menjadi <strong>false</strong>.</li>
                    <li>Cetak sekali lewat dialog untuk memilih printer &amp; ukuran kertas 80 mm (Firefox mengingatnya),
                        lalu setelan itu dipakai terus tanpa dialog.</li>
                </ol>
            </div>

            <div class="panduan-item">
                <h3>3. Sekali saja: atur kertas printer</h3>
                <ol>
                    <li>Cetak satu tiket dengan cara biasa (dialog muncul) — hanya sekali untuk menyimpan setelan.</li>
                    <li>Pilih printer thermal, ukuran kertas <strong>80 mm (Roll)</strong>, dan margin <strong>None</strong>.</li>
                    <li>Setelah itu aktifkan mode cetak senyap; browser akan memakai setelan tersimpan tersebut.</li>
                </ol>
                <p class="hint">Halaman tiket sudah memakai ukuran kertas 80 mm otomatis (atau A4 bila dipilih di atas).</p>
            </div>
        </div>
    </article>
</section>
<?php endif; ?>

<!-- ============ QR & LACAK OBAT ============ -->
<?php if ($tabOk('qr')): ?>
<section class="tab-panel" id="qr">
    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="qr_simpan">
        <header class="card-head">
            <h2><?= icon('tiket') ?> QR pada Tiket &amp; Halaman Lacak Obat</h2>
            <span class="card-hint">Pemilik tiket memindai QR untuk memantau posisi obat</span>
        </header>

        <p class="card-note">
            Setiap tiket mendapat token acak unik, dan QR pada tiket berisi tautan
            <span class="mono">lacak.php?t=&lt;kode tiket&gt;&amp;k=&lt;token&gt;</span>. Setelah dipindai, ponsel
            pemilik tiket membuka halaman lacak yang memperbarui status otomatis dan menampilkan
            pemberitahuan setiap status obat berubah.
        </p>

        <div class="field-row field-row-3">
            <label class="check">
                <input type="checkbox" name="qr_aktif" value="1" <?= setting_bool('qr_aktif', true) ? 'checked' : '' ?>>
                <span>Cetak QR pada tiket</span>
            </label>
            <label class="field">
                <span>Lebar QR di tiket (mm)</span>
                <input type="number" name="qr_ukuran" min="18" max="60" value="<?= (int) setting_num('qr_ukuran', 34) ?>">
                <em class="hint">30 mm nyaman dipindai · thermal 80 mm muat hingga 60 mm</em>
            </label>
            <label class="field">
                <span>Selang pembaruan halaman lacak (detik)</span>
                <input type="number" name="lacak_poll" min="3" max="60" value="<?= (int) setting_num('lacak_poll', 5) ?>">
            </label>
        </div>

        <label class="field">
            <span>Keterangan di bawah QR</span>
            <input type="text" name="qr_teks" value="<?= h(setting('qr_teks', 'Scan untuk melacak posisi obat Anda')) ?>" maxlength="80">
        </label>

        <?php
        /* Alamat yang benar-benar akan ditulis di QR + hasil ujinya (dibuktikan dengan permintaan HTTP). */
        $alamatAktif = alamat_publik_aktif();
        $pratinjauQr = $alamatAktif . '/lacak.php?t=<kode tiket>&k=<token>';
        ?>
        <div class="field-row">
            <label class="field">
                <span>Alamat publik aplikasi <em>(opsional)</em></span>
                <input type="text" name="lacak_url" value="<?= h(setting('lacak_url')) ?>" maxlength="120"
                       placeholder="<?= h(url_publik_dasar() ?: 'https://domain-anda') ?>">
                <em class="hint">
                    Kosongkan untuk deteksi otomatis.
                    <br><strong>Penting:</strong> bila aplikasi dibuka lewat <strong>domain sendiri</strong>,
                    tulis <strong>hanya domainnya</strong> (contoh: <span class="mono">https://andidjemma.my.id</span>) —
                    <strong>tanpa</strong> nama folder aplikasi. Bila dibuka lewat alamat platform, sertakan
                    nama folder (contoh: <span class="mono"><?= h(url_publik_dasar() ?: 'https://domain/antrian-apotek') ?></span>).
                    Alamat yang salah membuat QR menuju halaman yang tidak berfungsi.
                </em>
            </label>
            <div class="field field-btn">
                <span>Alamat yang akan ditulis di QR</span>
                <strong class="mono"><?= h($pratinjauQr) ?></strong>
            </div>
        </div>



        <div class="field-row">
            <label class="check">
                <input type="checkbox" name="lacak_notif" value="1" <?= setting_bool('lacak_notif', true) ? 'checked' : '' ?>>
                <span>Kirim notifikasi browser saat status berubah</span>
            </label>
            <label class="check">
                <input type="checkbox" name="lacak_suara" value="1" <?= setting_bool('lacak_suara', true) ? 'checked' : '' ?>>
                <span>Bunyikan pengumuman suara (id-ID) di halaman lacak</span>
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('tiket') ?> Simpan Pengaturan QR</button>
            <?php if ($kodeTerakhir > 0): ?>
                <a class="btn btn-ghost" href="cetak.php?id=<?= $kodeTerakhir ?>" target="_blank" rel="noopener"><?= icon('print') ?> Lihat Contoh Tiket + QR</a>
            <?php endif; ?>
        </div>
    </form>

        <div class="uji-alamat">
            <?php /* Tombol uji diberi FORM SENDIRI: dua input bernama "action" dalam satu form
                           membuat tombol simpan dan tombol uji saling tertukar. */ ?>
            <form method="post" action="pengaturan.php" class="uji-alamat-baris">
                <input type="hidden" name="action" value="qr_uji_alamat">
                <button class="btn btn-accent" type="submit"><?= icon('grid') ?> Uji Alamat Sekarang</button>
                <span class="hint">Membuka alamat di atas dari server ini untuk memastikan halaman lacak benar-benar berfungsi.</span>
            </form>
            <?php
            /* Memakai cache 60 detik supaya membuka halaman Pengaturan tidak memanggil ulang
               alamat setiap kali (hasil tetap diperbarui otomatis & bisa dipaksa lewat tombol). */
            $hasilAlamat = uji_alamat_cache(false);
            ?>
            <?php if ($hasilAlamat['ok']): ?>
                <div class="flash flash-ok uji-alamat-hasil">
                    <strong>Alamat sudah diuji: BERHASIL.</strong> <?= h($hasilAlamat['pesan']) ?>
                    QR pada tiket akan mengarah ke <span class="mono"><?= h($alamatAktif) ?>/lacak.php</span>.
                    <span class="hint">(diuji <?= h(date('H:i:s', (int) ($hasilAlamat['waktu'] ?? time()))) ?>)</span>
                </div>
            <?php else: ?>
                <div class="flash flash-warn uji-alamat-hasil">
                    <strong>Alamat ini belum berfungsi.</strong> <?= h($hasilAlamat['pesan']) ?>
                    <span class="hint">(diuji <?= h(date('H:i:s', (int) ($hasilAlamat['waktu'] ?? time()))) ?>)</span>
                    <?php if (!empty($hasilAlamat['saran'])): ?>
                        <form method="post" action="pengaturan.php" class="inline-form" style="margin-top:10px">
                            <input type="hidden" name="action" value="qr_pakai_saran">
                            <input type="hidden" name="saran" value="<?= h($hasilAlamat['saran']) ?>">
                            <button class="btn btn-mini btn-primary" type="submit">
                                <?= icon('grid') ?> Pakai &amp; uji alamat: <?= h($hasilAlamat['saran']) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <br>Periksa kembali penulisan alamat (protokol, nama domain, dan nama folder bila dipakai).
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>


    <article class="card">
        <header class="card-head">
            <h2><?= icon('track') ?> Cara Kerja di Sisi Pemilik Tiket</h2>
        </header>
        <ul class="alur-list">
            <li><span class="dot dot-1"></span> Pemilik tiket memindai QR memakai kamera ponsel → halaman lacak terbuka, langsung menampilkan status terkini.</li>
            <li><span class="dot dot-2"></span> Menekan <strong>Aktifkan</strong> memberi izin notifikasi browser, lalu setiap perubahan status
                memunculkan <strong>pemberitahuan di layar ponsel</strong>, pop-up besar, getaran, dan suara pengumuman.</li>
            <li><span class="dot dot-3"></span> Halaman lacak memuat ulang status setiap <?= (int) setting_num('lacak_poll', 5) ?> detik dan berhenti sendiri setelah obat diterima.</li>
            <li><span class="dot dot-4"></span> <strong>Penting:</strong> pemberitahuan muncul selama halaman lacak terbuka — untuk pemantauan
                berkelanjutan, minta pasien menambahkan halaman itu ke layar utama ponsel.</li>
        </ul>
        <p class="card-note">
            Halaman lacak tidak menampilkan nama pasien atau nomor resep — hanya nomor tiket, status, dan waktu,
            supaya privasi pasien tetap terjaga bila tautannya terbuka di perangkat lain.
        </p>

        <details class="cek-alamat">
            <summary>Periksa alamat publik yang terdeteksi</summary>
            <div class="db-grid">
                <div class="db-item"><span>Alamat yang dipakai QR</span><strong class="mono"><?= h(url_publik_dasar() ?: '(tidak terdeteksi)') ?></strong></div>
                <div class="db-item"><span>Host</span><strong class="mono"><?= h((string) ($_SERVER['HTTP_HOST'] ?? '-')) ?></strong></div>
                <div class="db-item"><span>REQUEST_URI</span><strong class="mono"><?= h((string) ($_SERVER['REQUEST_URI'] ?? '-')) ?></strong></div>
                <div class="db-item"><span>SCRIPT_NAME</span><strong class="mono"><?= h((string) ($_SERVER['SCRIPT_NAME'] ?? '-')) ?></strong></div>
                <div class="db-item"><span>HTTPS</span><strong><?= request_https() ? 'ya' : 'tidak' ?></strong></div>
                <div class="db-item"><span>Token tiket hari ini</span><strong><?= (int) db()->query('SELECT COUNT(*) FROM tiket WHERE token <> ""')->fetchColumn() ?> tiket punya token lacak</strong></div>
            </div>
            <p class="hint">
                Bila alamat di atas salah (misalnya tanpa nama folder aplikasi), isi kolom
                <strong>Alamat publik aplikasi</strong> di atas dengan alamat lengkap aplikasi ini.
                Pada server publik, nama folder diambil dari REQUEST_URI karena SCRIPT_NAME sudah dilepas prefiksnya.
            </p>
        </details>
    </article>
</section>
<?php endif; ?>

<!-- ============ SIMRS / REKAM MEDIS ============ -->
<?php if ($tabOk('simrs')): ?>
<section class="tab-panel" id="simrs" data-metode-simrs="<?= h($metodeSimrs) ?>">
    <?php
    $metodeSimrs  = simrs_metode();
    $agenOnline   = simrs_agen_online();
    $agenTerakhir = setting('simrs_agen_terakhir', '');
    $riwayatSimrs = db()->query('SELECT * FROM simrs_permintaan ORDER BY id DESC LIMIT 12')->fetchAll();
    $ujiRest      = json_decode((string) setting('simrs_rest_uji', ''), true);
    if (!is_array($ujiRest)) { $ujiRest = []; }
    ?>

    <?php /* ========== Pilihan metode + pengaturan umum ========== */ ?>
    <form method="post" action="pengaturan.php" class="form-grid card" id="form-simrs">
        <input type="hidden" name="action" value="simrs_simpan">
        <header class="card-head">
            <h2><?= icon('db') ?> Integrasi SIMRS (SIMGOS)</h2>
            <span class="card-hint"><?= simrs_aktif() ? 'Aktif — kolom No. RM muncul di form Ambil Antrian' : 'Belum dinyalakan' ?></span>
        </header>

        <p class="card-note">
            Aplikasi ini memakai data pasien dari SIMGOS untuk mengisi <strong>No. RM</strong> dan
            <strong>nama pasien</strong> di form Ambil Antrian. Pilih cara yang dipakai:
        </p>

        <div class="metode-pilih">
            <label class="metode-opsi">
                <input type="radio" name="simrs_metode" value="rest" id="metode-rest"
                       <?= $metodeSimrs === 'rest' ? 'checked' : '' ?>>
                <span>
                    <strong><?= icon('db') ?> REST API SIMGOS (disarankan)</strong>
                    Aplikasi memanggil API SIMGOS langsung — cukup mengisi alamat server SIMGOS.
                    Tidak perlu memasang program apa pun di rumah sakit.
                </span>
            </label>
            <label class="metode-opsi">
                <input type="radio" name="simrs_metode" value="agen" id="metode-agen"
                       <?= $metodeSimrs === 'agen' ? 'checked' : '' ?>>
                <span>
                    <strong><?= icon('print') ?> Agen di jaringan RS (metode lama)</strong>
                    Program kecil di komputer RS membaca database SIMGOS lalu mengirim datanya ke aplikasi.
                </span>
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="check">
                <input type="checkbox" name="simrs_aktif" value="1" <?= setting_bool('simrs_aktif', false) ? 'checked' : '' ?>>
                <span>Nyalakan pencarian pasien dari SIMRS di form Ambil Antrian</span>
            </label>
            <label class="check">
                <input type="checkbox" name="simrs_wajib_rm" value="1" <?= setting_bool('simrs_wajib_rm', false) ? 'checked' : '' ?>>
                <span>Wajibkan No. Rekam Medis saat ambil tiket</span>
            </label>
            <label class="check">
                <input type="checkbox" name="simrs_tampil_tiket" value="1" <?= setting_bool('simrs_tampil_tiket', true) ? 'checked' : '' ?>>
                <span>Cetak nama pasien &amp; No. RM di tiket</span>
            </label>
        </div>

        <label class="check">
            <input type="checkbox" name="simrs_tampil_petugas" value="1" <?= setting_bool('simrs_tampil_petugas', true) ? 'checked' : '' ?>>
            <span>Tampilkan nama pasien pada halaman petugas (Panggil Antrian, Tracking, Dashboard, Laporan)</span>
        </label>

        <p class="card-note">
            <strong>Nama pasien tidak pernah dikirim ke layar display (TV ruang tunggu) maupun ke halaman lacak QR</strong> —
            kedua layar itu tetap hanya menampilkan nomor tiket dan status, agar privasi pasien terjaga.
        </p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('db') ?> Simpan Pengaturan SIMRS</button>
        </div>
    </form>

    <?php /* ========== Metode REST: alamat server + uji koneksi ========== */ ?>
    <form method="post" action="pengaturan.php" class="form-grid card" data-simrs-metode="rest"
          id="form-simrs-rest">
        <input type="hidden" name="action" value="simrs_rest_simpan">
        <header class="card-head">
            <h2><?= icon('db') ?> REST API SIMGOS — Alamat Server</h2>
            <span class="card-hint">Cukup alamat server + jalur pencarian</span>
        </header>

        <p class="card-note">
            Isi <strong>alamat domain server SIMGOS</strong> di rumah sakit (boleh tanpa
            <span class="mono">https://</span>). Aplikasi akan memanggil
            <span class="mono">alamat + jalur</span> dengan <span class="mono">{rm}</span> diganti nomor rekam medis,
            lalu mengambil <strong>nama pasien</strong> dari jawabannya. Hanya No. RM dan nama yang dipakai —
            data pasien lain tidak diambil dan tidak disimpan.
        </p>

        <div class="field-row">
            <label class="field">
                <span>Alamat server SIMGOS <em>(domain rumah sakit)</em></span>
                <input type="text" name="simrs_rest_url" id="rest-url" maxlength="200"
                       placeholder="https://simrs.rsud-anda.go.id"
                       value="<?= h(setting('simrs_rest_url', '')) ?>">
            </label>
            <label class="field">
                <span>Jalur pencarian pasien <em>({rm} diganti No. RM)</em></span>
                <input type="text" name="simrs_rest_jalur" id="rest-jalur" maxlength="200"
                       placeholder="/api/pasien/{rm}"
                       value="<?= h(setting('simrs_rest_jalur', '/api/pasien/{rm}')) ?>">
            </label>
        </div>

        <div class="field-row field-row-3">
            <label class="field">
                <span>Cara memanggil</span>
                <select name="simrs_rest_http" id="rest-http">
                    <option value="GET" <?= simrs_rest_http() === 'GET' ? 'selected' : '' ?>>GET (paling umum)</option>
                    <option value="POST" <?= simrs_rest_http() === 'POST' ? 'selected' : '' ?>>POST (kirim {"no_rm":"…"})</option>
                </select>
            </label>
            <label class="field">
                <span>Header tambahan <em>(opsional)</em></span>
                <input type="text" name="simrs_rest_header" id="rest-header" maxlength="60"
                       placeholder="Authorization / X-API-Key"
                       value="<?= h(setting('simrs_rest_header', '')) ?>">
            </label>
            <label class="field">
                <span>Nilai header <em>(opsional, mis. token)</em></span>
                <input type="text" name="simrs_rest_nilai" id="rest-nilai" maxlength="300"
                       placeholder="Bearer abc123…"
                       value="<?= h(setting('simrs_rest_nilai', '')) ?>">
            </label>
        </div>

        <details class="rest-lanjutan">
            <summary>Pengaturan lanjutan: kolom nama pada jawaban SIMGOS <em>(biasanya tidak perlu diubah)</em></summary>
            <div class="field-row">
                <label class="field">
                    <span>Jalur field nama <em>(kosongkan = dicari otomatis)</em></span>
                    <input type="text" name="simrs_rest_field_nama" maxlength="80"
                           placeholder="contoh: data.nama_pasien"
                           value="<?= h(setting('simrs_rest_field_nama', '')) ?>">
                </label>
                <label class="field">
                    <span>Jalur field No. RM <em>(opsional)</em></span>
                    <input type="text" name="simrs_rest_field_rm" maxlength="80"
                           placeholder="contoh: data.no_rm"
                           value="<?= h(setting('simrs_rest_field_rm', '')) ?>">
                </label>
            </div>
        </details>

        <div class="field-row">
            <label class="field">
                <span>No. RM untuk uji coba <em>(pakai RM pasien yang benar-benar ada)</em></span>
                <input type="text" name="rm_uji" id="rest-rm-uji" maxlength="24"
                       placeholder="contoh: 123456">
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="uji_sekarang" value="1">
                <?= icon('user') ?> Simpan &amp; Uji Koneksi
            </button>
            <button class="btn btn-ghost" type="submit">Simpan saja</button>
            <button class="btn btn-accent" type="button" id="btn-deteksi">
                <?= icon('db') ?> Deteksi Otomatis Jalur
            </button>
        </div>

        <p class="hint">
            <strong>Belum tahu jalur API-nya?</strong> Isi alamat server + No. RM contoh, lalu tekan
            <em>Deteksi Otomatis Jalur</em> — aplikasi mencoba beberapa jalur yang lazim dipakai SIMRS satu
            per satu dan memberi tahu jalur mana yang menjawab berisi nama pasien. Jalur hasil deteksi bisa
            langsung dipakai dengan satu klik.
        </p>

        <?php if ($ujiRest): ?>
            <div class="uji-rest-hasil <?= !empty($ujiRest['ok']) ? 'is-ok' : 'is-err' ?>">
                <strong><?= !empty($ujiRest['ok']) ? 'Uji terakhir BERHASIL' : 'Uji terakhir belum berhasil' ?></strong>
                <span><?= h((string) ($ujiRest['waktu'] ?? '')) ?> · <?= h((string) ($ujiRest['url'] ?? '')) ?></span>
                <?php if (!empty($ujiRest['ok'])): ?>
                    <span>Nama terbaca: <b><?= h((string) ($ujiRest['nama'] ?? '')) ?></b>
                        (field <span class="mono"><?= h((string) ($ujiRest['kunci'] ?? '')) ?></span>)</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="deteksi-hasil" id="deteksi-hasil" hidden></div>
    </form>

    <?php /* ========== Uji & riwayat pencarian (kedua metode) ========== */ ?>
    <article class="card">
        <header class="card-head">
            <h2><?= icon('chart') ?> Uji &amp; Riwayat Pencarian Terakhir</h2>
            <span class="card-hint"><?= $metodeSimrs === 'rest' ? 'Bukti server SIMGOS benar-benar menjawab' : 'Bukti agen benar-benar menjawab' ?></span>
        </header>
        <form method="post" action="pengaturan.php" class="inline-form simrs-uji">
            <input type="hidden" name="action" value="simrs_uji_agen">
            <label class="field">
                <span>Uji pencarian dengan No. RM</span>
                <input type="text" name="rm_uji" maxlength="24" placeholder="contoh: 123456">
            </label>
            <button class="btn btn-accent" type="submit"><?= icon('user') ?> Uji Cari Pasien</button>
        </form>
        <?php if (!$riwayatSimrs): ?>
            <p class="empty">Belum ada permintaan pencarian pasien.</p>
        <?php else: ?>
            <div class="table-scroll table-tall">
                <table class="table">
                    <thead><tr><th>Waktu</th><th>No. RM</th><th>Status</th><th>Nama Pasien</th><th>Pesan</th><th>Oleh</th></tr></thead>
                    <tbody>
                    <?php foreach ($riwayatSimrs as $r): ?>
                        <tr>
                            <td><?= h((string) $r['created_at']) ?></td>
                            <td><strong><?= h($r['no_rm']) ?></strong></td>
                            <td>
                                <?php
                                $kls = $r['status'] === 'selesai' ? 'tag-teal' : ($r['status'] === 'gagal' ? 'tag' : 'tag');
                                ?>
                                <span class="tag <?= h($kls) ?>"><?= h($r['status']) ?></span>
                            </td>
                            <td><?= h($r['nama'] !== '' ? $r['nama'] : '—') ?></td>
                            <td><?= h($r['pesan'] !== '' ? $r['pesan'] : '—') ?></td>
                            <td><?= h($r['oleh']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <form method="post" action="pengaturan.php" class="inline-form">
                <input type="hidden" name="action" value="simrs_riwayat_hapus">
                <button class="btn btn-ghost" type="submit">Bersihkan Riwayat Pencarian</button>
            </form>
        </div>
    </article>

    <?php /* ========== Bagian khusus metode AGEN (disembunyikan bila memakai REST) ========== */ ?>
    <div data-simrs-metode="agen" class="simrs-bagian-agen">
    <?php
    $berkasZip = __DIR__ . '/unduh/agen-simrs.zip';
    $zipAda    = is_file($berkasZip);
    $zipUkuran = $zipAda ? round(filesize($berkasZip) / 1024, 1) : 0;
    $zipWaktu  = $zipAda ? date('d/m/Y H:i', (int) filemtime($berkasZip)) : '';
    ?>
    <article class="card unduh-agen">
        <header class="card-head">
            <h2><?= icon('print') ?> Unduh Paket Agen (untuk komputer jaringan RS)</h2>
            <span class="card-hint"><?= $zipAda ? 'Paket siap: ' . h((string) $zipUkuran) . ' KB · dibuat ' . h($zipWaktu) : 'Paket belum tersedia' ?></span>
        </header>
        <p class="card-note">
            Paket ini berisi <strong>program agen</strong> yang dipasang di komputer jaringan rumah sakit
            (yang bisa mengakses database SIMGOS): <span class="mono">agen-simrs.php</span>,
            <span class="mono">config.contoh.php</span>, <span class="mono">README.md</span>, dan
            <span class="mono">jalankan-agen.bat</span>. Unduh di komputer RS, lalu ikuti panduan di dalamnya.
        </p>
        <div class="form-actions">
            <?php if ($zipAda): ?>
                <a class="btn btn-primary" href="unduh/agen-simrs.zip" download>
                    <?= icon('print') ?> Unduh Paket Agen (.zip)
                </a>
                <a class="btn btn-ghost" href="unduh/agen-simrs.zip" target="_blank" rel="noopener">Lihat isi paket</a>
            <?php else: ?>
                <span class="empty">Paket agen belum tersedia di server.</span>
            <?php endif; ?>
            <span class="hint">
                Berkas <span class="mono">config.php</span> berisi kredensial database SIMGOS
                <strong>tidak</strong> disertakan dalam paket — berkas itu Anda buat sendiri di komputer RS
                (salin dari <span class="mono">config.contoh.php</span>) supaya kredensial tidak pernah keluar dari jaringan RS.
            </span>
        </div>
    </article>

    

    <article class="card">
        <header class="card-head">
            <h2><?= icon('user') ?> Status Agen</h2>
            <span class="card-hint"><?= $agenTerakhir !== '' ? 'Terakhir menghubungi: ' . h($agenTerakhir) : 'Belum pernah menghubungi' ?></span>
        </header>
        <div class="db-grid">
            <div class="db-item">
                <span>Keadaan agen</span>
                <strong><?= $agenOnline ? 'Terhubung (aktif)' : 'Belum terhubung / mati' ?></strong>
            </div>
            <div class="db-item">
                <span>Versi agen</span>
                <strong><?= h(setting('simrs_agen_versi', '') ?: '—') ?></strong>
            </div>
            <div class="db-item">
                <span>Terakhir menghubungi</span>
                <strong><?= h($agenTerakhir ?: '—') ?></strong>
            </div>
            <div class="db-item">
                <span>Permintaan menunggu jawaban</span>
                <strong><?= (int) db()->query('SELECT COUNT(*) FROM simrs_permintaan WHERE status = "menunggu"')->fetchColumn() ?> permintaan</strong>
            </div>
        </div>

        <div class="form-actions">
            <form method="post" action="pengaturan.php" class="inline-form">
                <input type="hidden" name="action" value="simrs_riwayat_hapus">
                <button class="btn btn-ghost" type="submit">Bersihkan Riwayat Pencarian</button>
            </form>
        </div>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('tiket') ?> Token Agen</h2>
            <span class="card-hint">Disalin ke berkas konfigurasi agen di komputer RS</span>
        </header>
        <div class="simrs-token">
            <textarea readonly rows="2" id="simrs-token" spellcheck="false"><?= h(simrs_token()) ?></textarea>
            <div class="simrs-token-aksi">
                <button class="btn btn-mini" type="button" data-salin="#simrs-token">Salin token</button>
                <form method="post" action="pengaturan.php" class="inline-form"
                      onsubmit="return confirm('Buat token baru? Agen yang sedang berjalan akan berhenti bekerja sampai token di berkas konfigurasi agen diperbarui.')">
                    <input type="hidden" name="action" value="simrs_token_baru">
                    <button class="btn btn-mini btn-danger" type="submit">Buat token baru</button>
                </form>
            </div>
        </div>
        <p class="hint">
            Token ini hanya dipakai oleh agen Anda. Jangan dibagikan ke pihak lain dan jangan ditempel di halaman
            yang dapat dilihat pasien.
        </p>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('print') ?> Pemasangan Agen (langkah singkat)</h2>
        </header>
        <div class="panduan-grid">
            <div class="panduan-item">
                <h3>1. Di komputer jaringan RS</h3>
                <ol>
                    <li>Tekan <strong>Unduh Paket Agen (.zip)</strong> di atas, lalu ekstrak di komputer yang tersambung ke SIMGOS
                        (mis. ke <code>C:\agen-simrs</code>).</li>
                    <li>Butuh <strong>PHP CLI</strong> di komputer itu (SIMGOS sendiri berbasis PHP, jadi PHP biasanya sudah ada).</li>
                    <li>Salin <code>config.contoh.php</code> menjadi <code>config.php</code>, lalu isi: alamat aplikasi ini,
                        token agen di atas, dan kredensial database SIMGOS.</li>
                </ol>
            </div>
            <div class="panduan-item">
                <h3>2. Uji lalu jalankan</h3>
                <ol>
                    <li>Uji dulu: <code>php agen-simrs.php --uji</code> → memeriksa koneksi database SIMGOS
                        dan koneksi ke aplikasi ini.</li>
                    <li>Jalankan terus-menerus: <code>php agen-simrs.php</code>
                        (di Windows cukup klik <code>jalankan-agen.bat</code>).</li>
                    <li>Halaman ini akan menampilkan <strong>Agen terhubung</strong> dalam beberapa detik.</li>
                </ol>
            </div>
            <div class="panduan-item">
                <h3>3. Sesuaikan tabel SIMGOS</h3>
                <ol>
                    <li>Perintah <code>--uji</code> akan memberitahu bila nama tabel/kolom berbeda.</li>
                    <li>Nama tabel &amp; kolom diatur di <code>config.php</code> (disesuaikan dengan SIMGOS Anda).</li>
                    <li>Rincian lengkap ada di <code>agen-simrs/README.md</code>.</li>
                </ol>
            </div>
        </div>
    </article>
    </div><!-- /bagian khusus metode agen -->
</section>
<?php endif; ?>

<!-- ============ ANTREAN & RESET ============ -->
<?php if ($tabOk('reset')): ?>
<section class="tab-panel" id="reset">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('track') ?> Status Antrean Hari Ini</h2>
            <span class="card-hint"><?= h(tgl_label(hari_ini())) ?></span>
        </header>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Kode</th><th>Layanan</th><th>Reset</th><th class="num">Tiket hari ini</th><th class="num">Nomor berikutnya</th></tr></thead>
                <tbody>
                <?php foreach ($kodes as $k): ?>
                    <?php $next = tiket_nomor_berikutnya((string) $k['kode']); ?>
                    <tr>
                        <td><span class="kode-chip"><?= h($k['kode']) ?></span></td>
                        <td><?= h(kode_label($k)) ?></td>
                        <td><?= $k['reset_mode'] === 'bulanan' ? 'Bulanan (tanggal 1 · ' . h(bulan_label(date('Y-m'))) . ')' : 'Harian' ?></td>
                        <td class="num"><?= (int) ($perKode[(string) $k['kode']] ?? 0) ?></td>
                        <td class="num"><strong><?= h($next['kode_tiket']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('tiket') ?> Daftar Tiket Hari Ini</h2>
            <span class="card-hint">Hapus tiket yang salah dibuat / pasien batal</span>
        </header>
        <?php if (!$hari): ?>
            <p class="empty">Belum ada tiket hari ini.</p>
        <?php else: ?>
            <div class="table-scroll table-tall">
                <table class="table">
                    <thead>
                        <tr><th>Kode Tiket</th><th>Status</th><th>Diambil</th><th>Diserahkan</th><th class="num">Waktu Tunggu</th><th class="num">Dipanggil</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($hari) as $t): ?>
                        <?php $r = tiket_kode_ringkas($t); ?>
                        <tr>
                            <td><strong><?= h($r['kode_tiket']) ?></strong></td>
                            <td><span class="status-pill status-<?= h(strtolower(str_replace('_', '-', $r['status']))) ?>"><?= h($r['status_label']) ?></span></td>
                            <td><?= h($r['jam_ambil']) ?></td>
                            <td><?= h($r['called_at'] ? $r['jam_panggil'] : '—') ?></td>
                            <td class="num"><?= h($r['tunggu_teks']) ?></td>
                            <td class="num"><?= (int) $r['called_count'] ?>×</td>
                            <td class="aksi">
                                <form method="post" action="pengaturan.php" class="inline-form"
                                      onsubmit="return confirm('Hapus tiket <?= h($r['kode_tiket']) ?>? Tiket ini akan hilang dari tracking, display, dan laporan.')">
                                    <input type="hidden" name="action" value="tiket_hapus">
                                    <input type="hidden" name="tiket_id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-mini btn-danger" type="submit">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="card-note">
                Menghapus tiket juga menghapus riwayat tracking-nya dari laporan. Penomoran <strong>tidak</strong> ikut
                mundur (nomor yang sudah dicetak tidak dipakai ulang) — gunakan <strong>Reset Antrean</strong> bila
                ingin penomoran kembali ke 001.
            </p>
        <?php endif; ?>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('user') ?> Riwayat Tindakan (jejak audit)</h2>
            <span class="card-hint">Catatan siapa menghapus/menreset apa dan kapan</span>
        </header>
        <?php $logAdmin = log_admin_terakhir(15); ?>
        <?php if (!$logAdmin): ?>
            <p class="empty">Belum ada tindakan yang tercatat.</p>
        <?php else: ?>
            <div class="table-scroll table-tall">
                <table class="table">
                    <thead><tr><th>Waktu</th><th>Oleh</th><th>Tindakan</th><th>Rincian</th></tr></thead>
                    <tbody>
                    <?php foreach ($logAdmin as $l): ?>
                        <tr>
                            <td><?= h($l['waktu']) ?></td>
                            <td><?= h(nama_pengguna_dengan_akun((string) $l['oleh'])) ?></td>
                            <td><strong><?= h($l['aksi']) ?></strong></td>
                            <td><?= h($l['rincian'] !== '' ? $l['rincian'] : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="card-note">
            Catatan ini berguna bila ada tiket yang tidak ditemukan lagi: di sini terlihat apakah tiket
            dihapus, kapan, dan oleh akun siapa. Maksimal 500 catatan terakhir yang disimpan.
        </p>
    </article>

    <form method="post" action="pengaturan.php" class="form-grid card card-danger">
        <input type="hidden" name="action" value="reset_antrian">
        <header class="card-head">
            <h2><?= icon('track') ?> Reset Antrean Sekarang</h2>
            <span class="card-hint">Kode J tidak ikut tereset</span>
        </header>
        <p class="card-note">
            Reset menghapus tiket hari ini yang <strong>belum selesai</strong> (status RESEP MASUK / SEDANG DISIAPKAN /
            SIAP DISERAHKAN) dan mengembalikan penomoran ke <strong>001</strong>. Kode <strong>J</strong> dikecualikan karena
            penomorannya bulanan. Tiket yang sudah dipanggil/diserahkan tetap tersimpan supaya laporan tetap utuh —
            konsekuensinya nomor baru bisa mengulang nomor yang sudah diserahkan hari itu.
        </p>
        <div class="field-row">
            <label class="field">
                <span>Ketik <strong>RESET</strong> untuk konfirmasi</span>
                <input type="text" name="konfirmasi" placeholder="RESET" required>
            </label>
            <label class="check">
                <input type="checkbox" name="termasuk_j" value="1">
                <span>Sertakan kode J juga (penomoran bulanan ikut kembali ke 001)</span>
            </label>
        </div>
        <div class="form-actions">
            <button class="btn btn-danger" type="submit"><?= icon('track') ?> Reset Antrean</button>
        </div>
        <p class="hint">Reset terakhir: <?= h(setting('reset_terakhir', 'belum pernah')) ?></p>
    </form>

    <?php /* Pindah otomatis RESEP MASUK → OBAT SEDANG DISIAPKAN (hanya superadmin).
             Tombol manual di halaman Tracking TETAP ada; setelan ini hanya menambah jalur otomatis. */ ?>
    <?php if (($user['role'] ?? '') === 'superadmin'): ?>
    <form method="post" action="pengaturan.php" class="form-grid card" id="auto-siapkan">
        <input type="hidden" name="action" value="auto_siapkan_simpan">
        <header class="card-head">
            <h2><?= icon('track') ?> Pindah Otomatis ke "Sedang Disiapkan"</h2>
            <span class="card-hint"><?= auto_siapkan_aktif() ? 'Aktif · ' . h((string) auto_siapkan_menit()) . ' menit' : 'Mati' ?></span>
        </header>
        <p class="card-note">
            Tiket yang masih <strong>RESEP MASUK</strong> akan berpindah sendiri ke
            <strong>OBAT SEDANG DISIAPKAN</strong> setelah menunggu sekian menit, sehingga petugas tidak
            perlu menekan tombol lagi. <strong>Tombol manual di halaman Tracking tetap ada dan tetap bisa
            dipakai</strong> (mis. untuk mempercepat satu tiket tertentu). Perpindahan otomatis tercatat pada
            riwayat tracking tiket sebagai <span class="mono">otomatis</span>, sehingga tetap bisa ditelusuri.
        </p>
        <div class="auto-siapkan-baris">
            <label class="check">
                <input type="checkbox" name="auto_siapkan_aktif" value="1" <?= auto_siapkan_aktif() ? 'checked' : '' ?>>
                <span>Pindahkan otomatis ke "Sedang Disiapkan"</span>
            </label>
            <label class="field">
                <span>Setelah menunggu (menit)</span>
                <input type="number" name="auto_siapkan_menit" min="1" max="600" step="1"
                       value="<?= h((string) auto_siapkan_menit()) ?>">
            </label>
        </div>
        <p class="hint">
            Patokan waktunya adalah waktu tiket mulai menunggu. Bila petugas menggeser sebuah tiket
            kembali ke <strong>Resep Masuk</strong> secara manual, hitungan waktunya mulai dari awal lagi
            (supaya tidak langsung berpindah kembali).
        </p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('track') ?> Simpan Setelan</button>
        </div>
    </form>
    <?php endif; ?>


    <?php
    /* Pratinjau: berapa data yang akan terhapus pada rentang yang dipilih. */
    $pratinjauDari   = tgl_valid($_GET['dari'] ?? '');
    $pratinjauSampai = tgl_valid($_GET['sampai'] ?? '');
    if ($pratinjauSampai < $pratinjauDari) { [$pratinjauDari, $pratinjauSampai] = [$pratinjauSampai, $pratinjauDari]; }
    $adaPratinjau = isset($_GET['dari']) || isset($_GET['sampai']);
    $pratinjau = data_hitung($pratinjauDari, $pratinjauSampai);
    ?>
    <article class="card">
        <header class="card-head">
            <h2><?= icon('chart') ?> Hapus Data Laporan (bersihkan data sisa/uji)</h2>
            <span class="card-hint">Tidak memengaruhi nomor antrian hari ini</span>
        </header>
        <p class="card-note">
            Gunakan untuk membersihkan data yang <strong>tidak seharusnya muncul di laporan</strong>
            (mis. tiket percobaan/uji, atau sisa hari sebelum apotek mulai dipakai). Data yang dihapus:
            tiket beserta riwayat tracking-nya pada rentang tanggal yang dipilih. Tiket yang sudah
            diserahkan <strong>tidak</strong> hilang karena "Reset Antrean" — dihapus lewat fitur inilah.
            Laporan PDF/Excel akan bersih setelahnya.
        </p>
        <form method="get" action="pengaturan.php" class="form-grid" id="pratinjau-data">
            <input type="hidden" name="lihat" value="1">
            <div class="field-row field-row-3">
                <label class="field">
                    <span>Dari tanggal</span>
                    <input type="date" name="dari" value="<?= h($pratinjauDari) ?>" required>
                </label>
                <label class="field">
                    <span>Sampai tanggal</span>
                    <input type="date" name="sampai" value="<?= h($pratinjauSampai) ?>" required>
                </label>
                <div class="field field-btn">
                    <button class="btn btn-ghost" type="submit"><?= icon('grid') ?> Lihat Jumlah Data</button>
                </div>
            </div>
        </form>
        <?php if ($adaPratinjau): ?>
            <?php /* Kelas tersendiri (bukan flash-warn) supaya tidak rancu dengan peringatan keamanan. */ ?>
            <div class="flash flash-info pratinjau-data">
                Rentang <strong><?= h($pratinjauDari) ?></strong> s/d <strong><?= h($pratinjauSampai) ?></strong>:
                <strong><?= (int) $pratinjau['total'] ?></strong> tiket
                (<?= (int) $pratinjau['dilayani'] ?> sudah diserahkan, <?= (int) $pratinjau['belum'] ?> belum dipanggil).
                <?php if ($pratinjau['per_hari']): ?>
                    <br>Per hari:
                    <?php foreach ($pratinjau['per_hari'] as $tgl => $jml): ?>
                        <span class="tag"><?= h($tgl) ?>: <?= (int) $jml ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </article>

    <form method="post" action="pengaturan.php" class="form-grid card card-danger" id="hapus-laporan">
        <input type="hidden" name="action" value="data_hapus">
        <header class="card-head">
            <h2><?= icon('track') ?> Hapus Data Tiket pada Rentang Tanggal</h2>
            <span class="card-hint">Tindakan permanen</span>
        </header>
        <div class="field-row field-row-3">
            <label class="field">
                <span>Dari tanggal</span>
                <input type="date" name="dari" value="<?= h($pratinjauDari) ?>" required>
            </label>
            <label class="field">
                <span>Sampai tanggal</span>
                <input type="date" name="sampai" value="<?= h($pratinjauSampai) ?>" required>
            </label>
            <label class="field">
                <span>Ketik <strong>HAPUS</strong> untuk konfirmasi</span>
                <input type="text" name="konfirmasi_hapus" placeholder="HAPUS" required>
            </label>
        </div>
        <div class="form-actions">
            <button class="btn btn-danger" type="submit"
                    onclick="return confirm('Hapus permanen data tiket pada rentang ini? Laporan akan dibersihkan dan tindakan ini tercatat di Riwayat Tindakan.')">
                <?= icon('chart') ?> Hapus Data Laporan
            </button>
            <span class="hint">Tindakan ini <strong>tidak dapat dibatalkan</strong>. Untuk hari ini,
                gunakan "Reset Antrean" atau tombol Hapus pada Daftar Tiket Hari Ini.</span>
        </div>
    </form>

</section>
<?php endif; ?>

<!-- ============ DATABASE ============ -->
<?php if ($tabOk('database')): ?>
<section class="tab-panel" id="database">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('db') ?> Koneksi Database (SQLite)</h2>
            <span class="card-hint">Baca-saja — dikelola otomatis oleh aplikasi</span>
        </header>
        <div class="db-grid">
            <div class="db-item"><span>Jenis</span><strong>SQLite (PDO)</strong></div>
            <div class="db-item"><span>Versi SQLite</span><strong><?= h($sqliteVer) ?></strong></div>
            <div class="db-item"><span>File database</span><strong class="mono"><?= h($dbFile) ?></strong></div>
            <div class="db-item"><span>Ukuran file</span><strong><?= h(number_format($dbUkuran / 1024, 1, ',', '.')) ?> KB</strong></div>
            <div class="db-item"><span>Jurnal (WAL)</span><strong><?= h(strtoupper($jurnal)) ?> · <?= h(number_format($dbWal / 1024, 1, ',', '.')) ?> KB WAL</strong></div>
            <div class="db-item"><span>busy_timeout</span><strong>5000 ms</strong></div>
            <div class="db-item"><span>synchronous</span><strong>NORMAL</strong></div>
            <div class="db-item"><span>Zona waktu</span><strong><?= h(setting('zona_waktu', 'Asia/Makassar')) ?></strong></div>
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Tabel</th><th class="num">Jumlah baris</th></tr></thead>
                <tbody>
                <?php foreach ($jumlahTabel as $tb => $n): ?>
                    <tr><td class="mono"><?= h($tb) ?></td><td class="num"><?= $n < 0 ? '—' : number_format($n, 0, ',', '.') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <form method="post" action="pengaturan.php" class="inline-form">
                <input type="hidden" name="action" value="db_uji">
                <button class="btn btn-ghost" type="submit"><?= icon('db') ?> Uji Koneksi</button>
            </form>
            <form method="post" action="pengaturan.php" class="inline-form" onsubmit="return confirm('Optimalkan database sekarang? Proses ini sebentar dan aman.')">
                <input type="hidden" name="action" value="db_optimize">
                <button class="btn btn-primary" type="submit">Optimalkan (VACUUM)</button>
            </form>
        </div>
        <p class="card-note">
            Skema tabel dibuat otomatis saat aplikasi dijalankan (CREATE TABLE IF NOT EXISTS) di dalam satu transaksi,
            sehingga tidak perlu impor manual. Untuk menyimpan salinan berkas database ke perangkat Anda, gunakan fitur
            <strong>Export Database</strong> di halaman <a href="/pro.php" target="_blank" rel="noopener">VibeCoder Pro</a>.
        </p>
    </article>

    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="db_arsip">
        <header class="card-head"><h2>Pemeliharaan Arsip</h2></header>
        <div class="field-row field-row-3">
            <label class="field">
                <span>Hapus tiket lebih lama dari (bulan)</span>
                <input type="number" name="bulan_arsip" min="1" max="120" value="12">
            </label>
            <div class="field field-btn">
                <button class="btn btn-danger" type="submit" onclick="return confirm('Hapus tiket lama? Tindakan ini permanen.')">Bersihkan Arsip</button>
            </div>
        </div>
        <p class="hint">Laporan hanya tersedia untuk data yang masih tersimpan — jalankan pembersihan hanya bila data lama sudah tidak diperlukan.</p>
    </form>
</section>
<?php endif; ?>

<!-- ============ LAPORAN ============ -->
<?php if ($tabOk('laporan')): ?>
<section class="tab-panel" id="laporan">
    <form method="get" action="laporan.php" class="form-grid card">
        <header class="card-head">
            <h2><?= icon('chart') ?> Unduh Laporan</h2>
            <span class="card-hint">PDF / Excel berisi jam ambil, jam siap, jam serah, waktu tunggu, waktu penyiapan &amp; status tracking</span>
        </header>
        <div class="radio-row">
            <label class="radio-pill"><input type="radio" name="mode" value="bulan" checked><span>Per Bulan</span></label>
            <label class="radio-pill"><input type="radio" name="mode" value="tanggal"><span>Per Tanggal / Rentang</span></label>
        </div>
        <div class="field-row field-row-3">
            <label class="field"><span>Bulan</span><input type="month" name="bulan" value="<?= h(date('Y-m')) ?>"></label>
            <label class="field"><span>Dari tanggal</span><input type="date" name="dari" value="<?= h(date('Y-m-01')) ?>"></label>
            <label class="field"><span>Sampai tanggal</span><input type="date" name="sampai" value="<?= h(date('Y-m-d')) ?>"></label>
        </div>
        <div class="field-row">
            <label class="field">
                <span>Kode antrian</span>
                <select name="kode">
                    <option value="">Semua kode</option>
                    <?php foreach ($kodes as $k): ?>
                        <option value="<?= h($k['kode']) ?>"><?= h($k['kode']) ?> — <?= h(kode_label($k)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field field-btn">
                <button class="btn btn-ghost" type="submit">Tampilkan Pratinjau</button>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="format" value="pdf"><?= icon('print') ?> Unduh PDF</button>
            <button class="btn btn-accent" type="submit" name="format" value="excel"><?= icon('chart') ?> Unduh Excel (.xlsx)</button>
            <button class="btn btn-ghost" type="submit" name="format" value="csv">Unduh CSV</button>
        </div>
        <p class="hint">Laporan hanya memuat tiket yang sudah dipanggil/diserahkan, urut tanggal.</p>
    </form>
</section>
<?php endif; ?>

<!-- ============ AKUN ============ -->
<?php if ($tabOk('akun')): ?>
<section class="tab-panel" id="akun">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('user') ?> Akun Petugas</h2>
            <span class="card-hint">Superadmin: akses penuh · Admin Apotik: petugas + tab Antrean &amp; Laporan · Petugas Apotek: tanpa Pengaturan</span>
        </header>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Username</th><th>Nama</th><th>Peran</th><th>Status</th><th>Diperbarui</th><th></th></tr></thead>
                <tbody>
                <?php foreach (daftar_user() as $u): ?>
                    <tr>
                        <td><strong><?= h($u['username']) ?></strong></td>
                        <td><?= h($u['nama']) ?: '—' ?></td>
                        <td><?= h(label_role((string) $u['role'])) ?></td>
                        <td>
                            <?php if ((int) $u['aktif'] === 1): ?>
                                <span class="tag tag-teal">aktif</span>
                            <?php else: ?>
                                <span class="tag">nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string) $u['updated_at'] ?: '—') ?></td>
                        <td class="aksi">
                            <div class="aksi-baris">
                                <form method="post" action="pengaturan.php" class="inline-form">
                                    <input type="hidden" name="action" value="akun_aktif">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="aktif" value="<?= (int) $u['aktif'] === 1 ? 0 : 1 ?>">
                                    <button class="btn btn-mini" type="submit"><?= (int) $u['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <?php /* Tombol ikon saja: membuka/menutup panel ubah akun di bawah tabel. */ ?>
                                <button class="btn btn-mini btn-gear" type="button"
                                        data-gear="<?= (int) $u['id'] ?>"
                                        aria-expanded="false"
                                        title="Ubah akun <?= h($u['username']) ?>"
                                        aria-label="Ubah akun <?= h($u['username']) ?>">
                                    <?= icon('cog') ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <form method="post" action="pengaturan.php" class="inline-form">
                <input type="hidden" name="action" value="sesi_bersih">
                <button class="btn btn-ghost" type="submit">Bersihkan Sesi Kedaluwarsa</button>
            </form>
        </div>
    </article>

    <?php /* Panel ubah akun: disembunyikan sampai tombol gear di baris akun ditekan. */ ?>
    <?php foreach (daftar_user() as $u): ?>
        <form method="post" action="pengaturan.php" class="form-grid card panel-ubah-akun" id="ubah-akun-<?= (int) $u['id'] ?>" hidden>
            <input type="hidden" name="action" value="akun_simpan">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <header class="card-head">
                <h2>Ubah Akun: <?= h($u['username']) ?></h2>
                <span class="card-hint"><?= h(label_role((string) $u['role'])) ?></span>
                <button class="btn btn-mini btn-tutup-panel" type="button" data-tutup="<?= (int) $u['id'] ?>"
                        title="Tutup panel ubah">Tutup</button>
            </header>
            <div class="field-row field-row-3">
                <label class="field"><span>Username</span><input type="text" name="username" value="<?= h($u['username']) ?>" pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32" required></label>
                <label class="field"><span>Nama petugas</span><input type="text" name="nama" value="<?= h($u['nama']) ?>" maxlength="40"></label>
                <label class="field"><span>Kata sandi baru <em>(biarkan kosong bila tidak diubah)</em></span><input type="password" name="password" autocomplete="new-password"></label>
            </div>
            <div class="field-row">
                <label class="field"><span>Ulangi kata sandi baru</span><input type="password" name="password_ulang" autocomplete="new-password"></label>
                <label class="field"><span>Konfirmasi dengan kata sandi Anda</span><input type="password" name="pengawas" required autocomplete="current-password"></label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Simpan Akun</button>
            </div>
        </form>
    <?php endforeach; ?>

    <form method="post" action="pengaturan.php" class="form-grid card">
        <input type="hidden" name="action" value="akun_tambah">
        <header class="card-head"><h2>Tambah Akun Baru</h2></header>
        <div class="field-row field-row-3">
            <label class="field"><span>Username</span><input type="text" name="username" pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32" required></label>
            <label class="field"><span>Nama petugas</span><input type="text" name="nama" maxlength="40"></label>
            <label class="field">
                <span>Peran</span>
                <select name="role">
                    <?php foreach (daftar_peran() as $nilai => $label): ?>
                        <option value="<?= h($nilai) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="field-row">
            <label class="field"><span>Kata sandi</span><input type="password" name="password" autocomplete="new-password" required></label>
            <label class="field"><span>Ulangi kata sandi</span><input type="password" name="password_ulang" autocomplete="new-password" required></label>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Tambah Akun</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php
page_end(['assets/pengaturan.js', 'assets/agen-cetak.js', 'assets/simrs-rest.js']);
