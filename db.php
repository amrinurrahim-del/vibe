<?php
declare(strict_types=1);

/*
 * Koneksi database (SQLite via PDO) + pembuatan skema otomatis.
 *
 * Sistem Antrian Apotek:
 *  - kode antrian (A, F, K, P, G, J, I) masing-masing punya PENOMORAN sendiri.
 *  - kode harian (A,F,K,P,G,I) mulai lagi dari 001 setiap hari.
 *  - kode J (bulanan) menyambung terus setiap hari dan hanya mulai dari 001 pada
 *    tanggal 1 tiap bulan (periode = YYYY-MM).
 *  - penomoran memakai tabel `nomor_counter` (per kode + periode) supaya tidak
 *    pernah terjadi dobel nomor walau beberapa loket memanggil bersamaan.
 */

date_default_timezone_set('Asia/Makassar');

/* ------------------------------------------------------------------ */
/* Konstanta status tracking (urutan = alur resep)                     */
/* ------------------------------------------------------------------ */

const ST_RESEP    = 'RESEP_MASUK';   // 1. tiket diambil / resep masuk
const ST_SIAPKAN  = 'DISIAPKAN';     // 2. obat sedang disiapkan
const ST_SIAP     = 'SIAP';          // 3. obat siap diserahkan (tombol panggil aktif)
const ST_DITERIMA = 'DITERIMA';      // 4. dipanggil & obat sudah diterima

const STATUS_URUT = [
    ST_RESEP    => 1,
    ST_SIAPKAN  => 2,
    ST_SIAP     => 3,
    ST_DITERIMA => 4,
];

/** Label yang ditampilkan di layar display / tracking. */
const STATUS_LABEL = [
    ST_RESEP    => 'RESEP MASUK',
    ST_SIAPKAN  => 'OBAT SEDANG DISIAPKAN',
    ST_SIAP     => 'OBAT SIAP DISERAHKAN',
    ST_DITERIMA => 'OBAT SUDAH DITERIMA',
];

/** Label singkat untuk tombol/kartu sempit. */
const STATUS_LABEL_PENDEK = [
    ST_RESEP    => 'Resep Masuk',
    ST_SIAPKAN  => 'Sedang Disiapkan',
    ST_SIAP     => 'Siap Diserahkan',
    ST_DITERIMA => 'Sudah Diterima',
];

/*
 * Warna backpanel chip di display per kode tiket — dibedakan supaya mudah dikenali dari jauh.
 * Nilai ini disimpan di kolom kode_antrian.warna dan bisa diubah di Pengaturan → Kode Antrian.
 */
const WARNA_KODE = [
    'A' => '#dc2626',   /* merah */
    'F' => '#2563eb',   /* biru */
    'K' => '#d97706',   /* kuning tua (amber) */
    'P' => '#7c3aed',   /* ungu */
    'G' => '#059669',   /* hijau */
    'J' => '#db2777',   /* pink */
    'D' => '#4f46e5',   /* indigo */
    'I' => '#0891b2',   /* teal */
];

/* Cadangan untuk kode lain (mis. dibuat baru lewat Pengaturan) — dipilih berdasarkan huruf. */
const WARNA_KODE_CADANGAN = ['#ea580c', '#0e7490', '#65a30d', '#92400e', '#475569', '#be123c', '#0f766e', '#6d28d9'];

/** Warna untuk sebuah kode: pakai peta di atas, atau cadangan berdasarkan posisi huruf. */
function warna_kode_bawaan(string $kode): string
{
    $kode = strtoupper(substr(trim($kode), 0, 1));
    if ($kode === '') {
        return '#475569';
    }
    if (isset(WARNA_KODE[$kode])) {
        return WARNA_KODE[$kode];
    }
    $i = (ord($kode) - 65 + 26) % 26;
    return WARNA_KODE_CADANGAN[$i % count(WARNA_KODE_CADANGAN)];
}

const KODE_SEED = [
    ['A', 'Loket A', 'harian'],
    ['F', 'Loket F', 'harian'],
    ['K', 'Loket K', 'harian'],
    ['P', 'Loket P', 'harian'],
    ['G', 'Loket G', 'harian'],
    ['J', 'Resep Kronis (bulanan)', 'bulanan'],
    ['I', 'Loket I', 'harian'],
];

const BULAN_ID = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                  7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
const HARI_PANJANG = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

/* ------------------------------------------------------------------ */
/* Koneksi                                                            */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!class_exists('PDO')) {
        http_response_code(500);
        exit('Ekstensi database (PDO SQLite) tidak tersedia di server ini.');
    }

    /* ANTRIAN_DB hanya untuk pengujian di /tmp, produksi memakai default di bawah. */
    $path = getenv('ANTRIAN_DB') ?: (__DIR__ . '/data/antrian.sqlite');
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    /* Urutan penting: busy_timeout -> WAL -> synchronous (WAL aman dengan NORMAL). */
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    schema_ensure($pdo);

    /* Zona waktu bisa diubah dari Pengaturan (dipakai untuk jam display & penomoran). */
    $tz = (string) $pdo->query('SELECT nilai FROM pengaturan WHERE kunci = "zona_waktu"')->fetchColumn();
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
    return $pdo;
}

/**
 * Membuat tabel + data awal. Seluruhnya dibungkus SATU transaksi BEGIN IMMEDIATE
 * supaya tidak ada fsync per statement dan tidak ada balapan antar request.
 */
function schema_ensure(PDO $pdo): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS pengaturan (
            kunci TEXT PRIMARY KEY,
            nilai TEXT NOT NULL DEFAULT ""
        )');

        /* --- Akun petugas (superadmin & petugas) + sesi --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS user (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL DEFAULT "",
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            aktif INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL DEFAULT "",
            failed_count INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS sesi (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT "",
            expires_at TEXT NOT NULL DEFAULT "",
            last_seen TEXT NOT NULL DEFAULT ""
        )');

        /* --- Kode antrian --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS kode_antrian (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL DEFAULT "",
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            reset_mode TEXT NOT NULL DEFAULT "harian",
            warna TEXT NOT NULL DEFAULT "#0d9488",
            updated_at TEXT NOT NULL DEFAULT ""
        )');

        /* --- Tiket antrian --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS tiket (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode TEXT NOT NULL,
            nomor INTEGER NOT NULL,
            kode_tiket TEXT NOT NULL,
            tanggal TEXT NOT NULL,
            periode TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "RESEP_MASUK",
            keterangan TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL DEFAULT "",
            siap_at TEXT NULL,
            called_at TEXT NULL,
            called_count INTEGER NOT NULL DEFAULT 0,
            diambil_oleh TEXT NOT NULL DEFAULT "",
            dilayani_oleh TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_tanggal ON tiket (tanggal, kode)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_status ON tiket (tanggal, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_kode_tiket ON tiket (tanggal, kode_tiket)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_periode ON tiket (periode, kode)');

        /* Kolom tambahan pada database lama: token acak unik untuk tautan lacak (QR). */
        $kolomTiket = [];
        foreach ($pdo->query('PRAGMA table_info(tiket)') as $r) {
            $kolomTiket[(string) $r['name']] = true;
        }
        if (!isset($kolomTiket['token'])) {
            $pdo->exec('ALTER TABLE tiket ADD COLUMN token TEXT NOT NULL DEFAULT ""');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_token ON tiket (token)');

        /*
         * first_called_at = waktu panggilan PERTAMA sebuah tiket.
         * Dipakai sebagai dasar hitungan waktu tunggu yang berhenti pada panggilan pertama:
         * bila tiket dipanggil lalu dibatalkan (pasien tidak ada) dan dipanggil lagi,
         * angka waktu tunggu tidak dihitung ulang/dibiarkan berjalan terus.
         * called_at tetap berarti panggilan TERAKHIR (dikosongkan saat dibatalkan).
         */
        /*
         * Data pasien dari SIMRS (SIMGOS) — HANYA untuk tiket & layar petugas.
         * Sengaja TIDAK dimasukkan ke payload display/lacak (lihat payload_display & lacak_data).
         */
        if (!isset($kolomTiket['no_rm'])) {
            $pdo->exec('ALTER TABLE tiket ADD COLUMN no_rm TEXT NOT NULL DEFAULT ""');
        }
        if (!isset($kolomTiket['nama_pasien'])) {
            $pdo->exec('ALTER TABLE tiket ADD COLUMN nama_pasien TEXT NOT NULL DEFAULT ""');
        }

        /*
         * recall_at = waktu PANGGIL ULANG terakhir (tombol "Panggil Ulang").
         * Dipakai display untuk menampilkan kembali nomor yang dipanggil ulang, tanpa mengubah
         * called_at (waktu serah) yang dipakai laporan. Jadi laporan tetap mencatat waktu
         * penyerahan yang sebenarnya, sedangkan display mengikuti panggilan terbaru.
         */
        if (!isset($kolomTiket['recall_at'])) {
            $pdo->exec('ALTER TABLE tiket ADD COLUMN recall_at TEXT NULL');
        }

        if (!isset($kolomTiket['first_called_at'])) {
            $pdo->exec('ALTER TABLE tiket ADD COLUMN first_called_at TEXT NULL');
            /* Data lama: waktu tunggunya sudah final pada called_at. */
            $pdo->exec('UPDATE tiket SET first_called_at = called_at WHERE called_at IS NOT NULL');
        }

        /* --- Riwayat tracking tiap tiket (seperti lacak paket) --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS tiket_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tiket_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "",
            waktu TEXT NOT NULL DEFAULT "",
            oleh TEXT NOT NULL DEFAULT "",
            catatan TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiket_log ON tiket_log (tiket_id, id)');

        /*
         * Permintaan cari pasien ke SIMRS. Aplikasi (publik) mencatat permintaan,
         * lalu AGEN di jaringan rumah sakit mengambilnya dan mengisi jawabannya.
         * Tidak ada koneksi masuk ke jaringan RS: arah komunikasi selalu dari RS keluar.
         */
        $pdo->exec('CREATE TABLE IF NOT EXISTS simrs_permintaan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            no_rm TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "menunggu",
            nama TEXT NOT NULL DEFAULT "",
            tgl_lahir TEXT NOT NULL DEFAULT "",
            jenis_kelamin TEXT NOT NULL DEFAULT "",
            pesan TEXT NOT NULL DEFAULT "",
            oleh TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL DEFAULT "",
            selesai_at TEXT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_simrs_status ON simrs_permintaan (status, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_simrs_created ON simrs_permintaan (created_at)');

        /*
         * Jejak audit tindakan penting (hapus tiket, reset antrean, ubah kode, token SIMRS, ...).
         * Penting untuk aplikasi yang sudah dipakai sungguhan: bila ada tiket "hilang", pemilik
         * dapat melihat siapa yang menghapusnya dan kapan.
         */
        $pdo->exec('CREATE TABLE IF NOT EXISTS log_admin (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            waktu TEXT NOT NULL DEFAULT "",
            oleh TEXT NOT NULL DEFAULT "",
            aksi TEXT NOT NULL DEFAULT "",
            rincian TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_admin_waktu ON log_admin (waktu)');

        /* --- Penomoran per kode + periode --- */
        $pdo->exec('CREATE TABLE IF NOT EXISTS nomor_counter (
            kode TEXT NOT NULL,
            periode TEXT NOT NULL,
            nomor INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (kode, periode)
        )');

        /* --- Data awal: akun --- */
        $adaUser = (int) $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn();
        if ($adaUser === 0) {
            $insU = $pdo->prepare('INSERT INTO user (username, nama, password_hash, role, aktif, updated_at) VALUES (?, ?, ?, ?, 1, ?)');
            $now  = date('Y-m-d H:i:s');
            $insU->execute(['superadmin', 'Superadmin', password_hash('superadmin', PASSWORD_DEFAULT), 'superadmin', $now]);
            $insU->execute(['petugas', 'Petugas Apotek', password_hash('petugas', PASSWORD_DEFAULT), 'petugas', $now]);
        }

        /* --- Warna kode: yang masih memakai warna bawaan lama diberi warna berbeda-beda --- */
        $warnaDefaultLama = '#0d9488';
        $perluWarna = $pdo->query('SELECT id, kode, warna FROM kode_antrian')->fetchAll();
        $stWarna = $pdo->prepare('UPDATE kode_antrian SET warna = ? WHERE id = ?');
        foreach ($perluWarna as $k) {
            $w = strtolower(trim((string) $k['warna']));
            if ($w === '' || $w === $warnaDefaultLama) {
                $stWarna->execute([warna_kode_bawaan((string) $k['kode']), (int) $k['id']]);
            }
        }

        /* --- Data awal: kode antrian --- */
        $adaKode = (int) $pdo->query('SELECT COUNT(*) FROM kode_antrian')->fetchColumn();
        if ($adaKode === 0) {
            $insK = $pdo->prepare('INSERT INTO kode_antrian (kode, nama, urutan, aktif, reset_mode, updated_at) VALUES (?, ?, ?, 1, ?, ?)');
            $now  = date('Y-m-d H:i:s');
            foreach (KODE_SEED as $i => [$kode, $nama, $mode]) {
                $insK->execute([$kode, $nama, $i + 1, $mode, $now]);
            }
        }

        /* --- Pengaturan default --- */
        $defaults = [
            'nama_apotek'        => 'APOTEK SEHAT SENTOSA',
            'alamat_apotek'      => 'Jl. Kesehatan No. 1, Makassar — Telp. (0411) 123-4567',
            'logo_url'           => '',
            'display_judul'      => 'ANTRIAN PENGAMBILAN OBAT',
            'footer_teks'        => 'Selamat datang di Apotek kami — Mohon menunggu nomor antrian Anda dipanggil. Obat diserahkan setelah status OBAT SIAP DISERAHKAN.',
            'kecepatan_gulir'    => '40',
            /* Kecepatan gulir baris "OBAT SUDAH DITERIMA" (px/detik) — sengaja dipisah dan
               lebih pelan dari kolom status, supaya nomor yang sudah selesai tetap nyaman dibaca.
               Dulu baris ini memakai batas tetap 60 detik per putaran sehingga pada hari yang
               ramai (ratusan tiket) gulirnya menjadi sangat cepat (terukur ±846 px/detik). */
            'kecepatan_gulir_selesai' => '30',
            /* Interval PERCOBAAN ULANG display bila koneksi bermasalah (detik).
               Pemantauan perubahan selalu 1 detik di sisi display (lihat assets/display.js),
               dan tampilan hanya digambar ulang bila ada perubahan sungguhan. */
            'refresh_detik'      => '5',
            'durasi_panggil'     => '12',
            'zona_waktu'         => 'Asia/Makassar',
            'tema_warna'         => '#0d9488',
            'suara_lang'         => 'id-ID',
            'suara_voice'        => 'Google Bahasa Indonesia',
            'suara_rate'         => '0.95',
            'suara_volume'       => '1',
            'suara_eja_digit'    => '1',
            'suara_chime'        => '1',
            'suara_display'      => '1',
            'suara_ulang'        => '3',
            'suara_template'     => 'Nomor antrian, kode {kode}, {nomor}. Obat siap diserahkan, silakan menuju loket pengambilan obat. Terima kasih.',
            'printer_kertas'     => 'thermal80',
            'printer_otomatis'   => '1',
            'printer_lebar'      => '76',
            'printer_baris_kosong' => '1',
            'printer_judul'      => 'TIKET ANTRIAN APOTEK',
            /* printer_footer sudah tidak dipakai (catatan kaki tiket dihapus atas permintaan pemilik). */
            'printer_senyap_tip' => '1',
            /* --- Agen cetak: printer khusus aplikasi ini (tanpa dialog, tanpa mengubah
                   printer default Windows) --- */
            'cetak_agen_aktif'   => '0',
            'cetak_agen_url'     => 'http://127.0.0.1:17890',
            'cetak_agen_token'   => '',   /* dibuat otomatis; disalin ke config.php agen */
            'cetak_agen_printer' => '',
            'printer_dua_struk'  => '0',      /* 1 = cetak 2 rangkap (rangkap 2 = penanda resep, 30mm) */
            'display_tampil_riwayat' => '1',
            'display_tampil_tunggu'  => '1',
            /* batas_daftar_display tidak dipakai lagi: display menampilkan semua tiket dengan
               gulir otomatis (lihat payload_display). Baris ini hanya dipertahankan agar
               database lama tidak error saat dibaca. */
            'loket_default'      => 'LOKET PENGAMBILAN OBAT',
            /* --- Pindah otomatis ke tahap "Sedang Disiapkan" ---
               Tiket yang masih RESEP MASUK dipindahkan otomatis ke OBAT SEDANG DISIAPKAN
               setelah menunggu sekian menit, supaya petugas tidak perlu menekan tombol.
               Tombol manual di halaman Tracking TETAP ada (tidak dihapus). */
            'auto_siapkan_aktif' => '1',
            'auto_siapkan_menit' => '5',
            /* --- Panel video di display (link YouTube, maks 3, diputar berulang) --- */
            'video_aktif'        => '0',
            'video_1'            => '',
            'video_2'            => '',
            'video_3'            => '',
            'video_judul'        => 'INFORMASI KESEHATAN',
            'video_suara'        => '0',   /* 1 = dengan suara (perlu klik "Aktifkan Suara" di display) */

            /* --- QR pada tiket & halaman lacak --- */
            'qr_aktif'           => '1',
            'qr_ukuran'          => '34',      /* lebar QR di tiket (mm) */
            'qr_teks'            => 'Scan untuk melacak posisi obat Anda',
            'lacak_url'          => '',        /* kosong = alamat publik terdeteksi otomatis */
            'lacak_poll'         => '5',       /* selang pembaruan (detik) */
            'lacak_notif'        => '1',
            'lacak_uji'          => '',   /* cache hasil uji alamat publik (diperbarui otomatis) */
            'lacak_suara'        => '1',
            /* --- Integrasi SIMRS (SIMGOS) ---
               Dua metode yang bisa dipilih pemilik:
               `rest` = aplikasi memanggil REST API SIMGOS langsung (cukup isi alamat server).
               `agen` = program agen di jaringan RS yang membaca database SIMGOS (metode lama). */
            'simrs_metode'       => 'rest',
            'simrs_aktif'        => '0',      /* dinyalakan setelah alamat/agen diuji */
            'simrs_token'        => '',       /* dibuat otomatis; dipakai agen untuk autentikasi */
            'simrs_agen_terakhir'=> '',       /* waktu polling terakhir dari agen */
            'simrs_agen_versi'   => '',
            'simrs_wajib_rm'     => '0',      /* wajibkan No. RM saat ambil tiket */
            'simrs_tampil_tiket' => '1',      /* cetak No. RM + nama pasien di tiket */
            'simrs_tampil_petugas'=> '1',     /* tampilkan nama pasien di layar petugas */
            /* --- SIMRS metode REST API (dipanggil langsung dari aplikasi) --- */
            'simrs_rest_url'     => '',       /* mis. https://simrs.rsud.go.id (tanpa garis miring akhir) */
            'simrs_rest_jalur'   => '/api/pasien/{rm}',   /* {rm} diganti nomor rekam medis */
            'simrs_rest_http'    => 'GET',    /* GET atau POST (POST mengirim {"no_rm":"..."}) */
            'simrs_rest_header'  => '',       /* opsional, mis. Authorization / X-API-Key */
            'simrs_rest_nilai'   => '',       /* nilai header (mis. "Bearer abc123") */
            'simrs_rest_field_nama' => '',    /* titik di JSON, mis. data.nama (kosong = deteksi otomatis) */
            'simrs_rest_field_rm'   => '',    /* titik di JSON, mis. data.no_rm */
            'simrs_rest_uji'     => '',       /* hasil uji terakhir (ditampilkan di Pengaturan) */
            'demo_seeded'        => '0',
        ];
        $insSet = $pdo->prepare('INSERT OR IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $insSet->execute([$k, $v]);
        }

        /* Token agen cetak dibuat otomatis sekali (dipakai agen cetak di komputer apotek). */
        $tokenCetak = (string) $pdo->query('SELECT nilai FROM pengaturan WHERE kunci = "cetak_agen_token"')->fetchColumn();
        if ($tokenCetak === '') {
            $pdo->prepare('UPDATE pengaturan SET nilai = ? WHERE kunci = "cetak_agen_token"')
                ->execute([bin2hex(random_bytes(16))]);
        }

        /* Token agen SIMRS dibuat otomatis sekali (dipakai agen untuk membuktikan identitasnya). */
        $tokenSimrs = (string) $pdo->query('SELECT nilai FROM pengaturan WHERE kunci = "simrs_token"')->fetchColumn();
        if ($tokenSimrs === '') {
            $pdo->prepare('UPDATE pengaturan SET nilai = ? WHERE kunci = "simrs_token"')
                ->execute([bin2hex(random_bytes(24))]);
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }

    /* Tiket lama (dibuat sebelum fitur QR) diberi token unik sekali saja. */
    tiket_lengkapi_token($pdo);
}

/** Beri token lacak untuk tiket yang belum punya (idempotent, hanya bila perlu). */
function tiket_lengkapi_token(PDO $pdo): void
{
    $id = $pdo->query('SELECT id FROM tiket WHERE token = "" OR token IS NULL LIMIT 500')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$id) {
        return;
    }
    $st = $pdo->prepare('UPDATE tiket SET token = ? WHERE id = ?');
    foreach ($id as $satu) {
        $st->execute([bin2hex(random_bytes(10)), (int) $satu]);
    }
}

/** Path file database yang sedang dipakai (untuk halaman Pengaturan → Koneksi Database). */
function db_path(): string
{
    return getenv('ANTRIAN_DB') ?: (__DIR__ . '/data/antrian.sqlite');
}
