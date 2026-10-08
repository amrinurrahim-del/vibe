<?php
declare(strict_types=1);

/*
 * Halaman lacak obat (publik) — dibuka dari pemindaian QR pada tiket.
 * Memuat status tracking tiket secara berkala dan memberi tahu pemilik tiket
 * (notifikasi browser, pop-up dalam halaman, suara, dan getaran) setiap kali status berubah.
 */

require_once __DIR__ . '/lib.php';

$kode  = (string) ($_GET['t'] ?? '');
$token = (string) ($_GET['k'] ?? '');
$tiket = tiket_by_token($kode, $token);
$logo  = setting('logo_url');
$poll  = max(3, min(60, (int) setting_num('lacak_poll', 5)));

if (!$tiket) {
    http_response_code(404);
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tiket tidak ditemukan — <?= h(setting('nama_apotek', 'Apotek')) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>:root{--accent:<?= h(setting('tema_warna', '#0d9488')) ?>;}</style>
</head>
<body class="lacak-body">
<div class="lacak-wrap">
    <div class="lacak-card lacak-kosong">
        <span class="lacak-icon-warn">?</span>
        <h1>Tiket tidak ditemukan</h1>
        <p>QR atau tautan ini tidak cocok dengan tiket mana pun. Pastikan Anda memindai QR yang tercetak
            pada tiket antrian apotek ini (jangan mengubah alamat tautannya).</p>
        <a class="btn btn-primary" href="display.php">Lihat layar antrian</a>
    </div>
</div>
</body>
</html>
        <?php
    exit;
}

$data = lacak_data($tiket);
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= h(setting('tema_warna', '#0d9488')) ?>">
<title>Lacak <?= h($data['kode_tiket']) ?> — <?= h($data['apotek']) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>:root{--accent:<?= h(setting('tema_warna', '#0d9488')) ?>;}</style>
</head>
<body class="lacak-body">
<div class="lacak-wrap">

    <header class="lacak-head">
        <?php if ($logo !== ''): ?>
            <img src="<?= h($logo) ?>" alt="Logo" class="lacak-logo">
        <?php endif; ?>
        <div>
            <h1><?= h($data['apotek']) ?></h1>
            <p><?= h($data['alamat']) ?></p>
        </div>
    </header>

    <section class="lacak-nomor">
        <span class="lacak-nomor-label">Nomor tiket Anda</span>
        <div class="lacak-nomor-besar">
            <span><?= h($data['kode']) ?></span><strong><?= h(str_pad((string) $data['nomor'], 3, '0', STR_PAD_LEFT)) ?></strong>
        </div>
        <span class="lacak-nomor-sub"><?= h($data['layanan']) ?> · diambil <?= h($data['jam_ambil']) ?>
            <?= $data['is_today'] ? '' : '· ' . h($data['tanggal_label']) ?></span>
    </section>

    <section class="lacak-status" id="lacak-status">
        <div class="lacak-status-sekarang">
            <span class="lacak-status-label">Status obat saat ini</span>
            <strong class="lacak-status-nilai" id="lacak-nilai"><?= h($data['status_label']) ?></strong>
            <span class="lacak-status-waktu" id="lacak-waktu">Diperbarui <?= h(date('H:i:s')) ?></span>
        </div>

        <ol class="lacak-steps" id="lacak-steps">
            <?php
            $langkah = [
                1 => ['Resep Masuk', 'Tiket diterima apotek'],
                2 => ['Obat Sedang Disiapkan', 'Petugas menyiapkan obat Anda'],
                3 => ['Obat Siap Diserahkan', 'Obat siap, menunggu dipanggil'],
                4 => ['Obat Sudah Diterima', 'Obat telah diserahkan'],
            ];
            foreach ($langkah as $no => [$judul, $ket]):
                $kls = $no < $data['urutan'] ? 'is-done' : ($no === $data['urutan'] ? 'is-now' : '');
            ?>
                <li class="lacak-step <?= h($kls) ?>" data-step="<?= (int) $no ?>">
                    <span class="lacak-step-bullet"><?= $no < $data['urutan'] ? '&#10003;' : (int) $no ?></span>
                    <span class="lacak-step-isi">
                        <strong><?= h($judul) ?></strong>
                        <em><?= h($ket) ?></em>
                        <span class="lacak-step-jam" data-jam="<?= (int) $no ?>">
                            <?php
                            if ($no === 1) { echo h($data['jam_ambil']); }
                            elseif ($no === 3 && $data['jam_siap']) { echo h($data['jam_siap']); }
                            elseif ($no === 4 && $data['jam_panggil']) { echo h($data['jam_panggil']); }
                            ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="lacak-meta">
            <span>Waktu tunggu: <strong id="lacak-tunggu"><?= h($data['tunggu_teks']) ?></strong></span>
            <?php if ($data['siap_teks']): ?>
                <span>Waktu penyiapan: <strong><?= h($data['siap_teks']) ?></strong></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="lacak-notif" id="lacak-notif-box">
        <div class="lacak-notif-teks">
            <strong id="notif-judul">Aktifkan pemberitahuan</strong>
            <span id="notif-pesan">Dapatkan pemberitahuan langsung di layar ponsel setiap status obat berubah.
                Biarkan halaman ini tetap terbuka.</span>
        </div>
        <button class="btn btn-primary" type="button" id="btn-notif">Aktifkan</button>
    </section>

    <p class="lacak-catatan">
        Status diperbarui otomatis setiap <?= (int) $poll ?> detik — dan tetap dipantau dengan jeda lebih
        panjang setelah obat diterima, supaya Anda tetap diberi tahu bila petugas mengembalikan status
        (misalnya Anda belum sempat menerima obat sehingga nomor akan dipanggil kembali).
        Pemberitahuan muncul selama halaman ini terbuka — untuk hasil terbaik, biarkan halaman tetap terbuka
        atau tambahkan ke layar utama (menu browser → "Tambahkan ke layar Utama"), lalu nyalakan pemberitahuan.
    </p>

    <nav class="lacak-nav">
        <button class="btn btn-ghost btn-mini" type="button" id="btn-muat">Muat ulang sekarang</button>
        <button class="btn btn-ghost btn-mini" type="button" id="btn-tes">Tes pemberitahuan</button>
        <a class="btn btn-ghost btn-mini" href="display.php">Layar antrian</a>
    </nav>
</div>

<div class="lacak-popup" id="lacak-popup" hidden>
    <span class="lacak-popup-ikon">&#128276;</span>
    <div>
        <strong id="popup-judul">Status obat diperbarui</strong>
        <span id="popup-pesan"></span>
    </div>
    <button type="button" class="lacak-popup-tutup" id="popup-tutup" aria-label="Tutup">&times;</button>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script>
/* Data awal dari server supaya tampilan langsung benar tanpa menunggu permintaan pertama. */
window.LACAK_AWAL = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="assets/voice.js"></script>
<script src="assets/lacak.js"></script>
</body>
</html>
