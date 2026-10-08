<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/gambar-tiket.php';

$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$t  = $id > 0 ? tiket_by_id($id) : null;
if (!$t) {
    page_head('Cetak Tiket', 'ambil.php');
    echo '<section class="page-head"><div><h1>Tiket tidak ditemukan</h1><p>Tiket yang diminta sudah tidak ada (mungkin terhapus oleh reset antrian).</p></div></section>';
    echo '<p><a class="btn btn-primary" href="ambil.php">Kembali ke Ambil Antrian</a></p>';
    page_end();
    exit;
}

$baru   = (string) ($_GET['baru'] ?? '') === '1';
$auto   = (string) ($_GET['auto'] ?? '') === '1';
$kertas = setting('printer_kertas', 'thermal80');
$isThermal = $kertas !== 'a4';

$k = kode_row((string) $t['kode']);
$layanan = $k ? kode_label($k) : 'Loket ' . $t['kode'];
$status  = (string) $t['status'];
$lebar   = max(40, min(80, (int) setting_num('printer_lebar', 76)));   /* lebar area cetak thermal (mm) */
$qrAktif = setting_bool('qr_aktif', true);
$qrUkuran = max(18, min(60, (int) setting_num('qr_ukuran', 30)));      /* lebar QR di tiket (mm) */
$tautan  = ($qrAktif && ($t['token'] ?? '') !== '') ? url_lacak($t) : '';
$qrSvg   = $tautan !== '' ? qr_svg($tautan, $qrUkuran * 3.78, 'M', 4, '#000000', '#ffffff') : '';
$kosong  = max(0, min(6, (int) setting_num('printer_baris_kosong', 2)));
$logo    = setting('logo_url');
$tipSenyap = setting_bool('printer_senyap_tip', true);
$tampilPasien = setting_bool('simrs_tampil_tiket', true);   /* nama pasien & No. RM di tiket */
/* Cetak 2 rangkap: rangkap ke-2 = potongan ±30 mm (kode tiket besar + catatan) untuk penanda resep. */
$rangkap = (string) ($_GET['rangkap'] ?? '');
$duaStruk = $rangkap === '1' ? false : ($rangkap === '2' ? true : setting_bool('printer_dua_struk', false));

/*
 * --- AGEN CETAK: printer khusus aplikasi ini (tanpa dialog) ---
 * Bila agen diaktifkan dan nama printer sudah diisi, tiket dicetak lewat agen di komputer
 * petugas (browser mengirim gambar/teks tiket ke agen pada 127.0.0.1). Printer default
 * Windows tidak diubah, jadi aplikasi lain tetap memakai printer default sistemnya.
 */
$agenAktif = setting_bool('cetak_agen_aktif', false) && trim(setting('cetak_agen_printer', '')) !== '';
$agenPrinter = setting('cetak_agen_printer', '');
$agenUrl = setting('cetak_agen_url', 'http://127.0.0.1:17890');
$agenToken = setting('cetak_agen_token', '');
$tiketPng = null;
$tiketTeks = '';
$lebarKolom = (int) setting_num('printer_lebar', 42) > 50 ? 48 : 42;
$potonganPng = null;
$potonganTeks = '';
if ($agenAktif) {
    $tiketPng = tiket_png($t);
    $tiketTeks = tiket_teks($t, $lebarKolom);
    /* Mode 2 rangkap: potongan penanda resep ikut dikirim ke agen (cetakan kedua). */
    if ($duaStruk) {
        $potonganPng = tiket_potongan_png($t);
        $potonganTeks = tiket_potongan_teks($t, $lebarKolom);
    }
}
$alamatApp = url_publik_dasar();
$perintahKiosk = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing "'
    . ($alamatApp !== '' ? $alamatApp . '/ambil.php' : 'ALAMAT-APLIKASI/ambil.php') . '"';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tiket <?= h($t['kode_tiket']) ?> — <?= h(setting('nama_apotek', 'Apotek')) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>
<?php if ($isThermal): ?>
@page { size: 80mm auto; margin: 2mm; }
.print-thermal .tiket-paper { width: <?= (int) $lebar ?>mm; }
<?php else: ?>
@page { size: A4; margin: 12mm; }
<?php endif; ?>
</style>
</head>
<body class="print-body <?= $isThermal ? 'print-thermal' : 'print-a4' ?>">

<div class="print-toolbar no-print">
    <div>
        <strong>Tiket <?= h($t['kode_tiket']) ?></strong>
        <span class="muted"><?= $isThermal ? 'Printer thermal 80 mm' : 'Kertas A4' ?></span>
    </div>
    <div class="print-toolbar-actions">
        <button class="btn btn-primary" type="button" id="btn-cetak">
            <?= icon('print') ?> Cetak Sekarang<?= $duaStruk ? ' (2 rangkap)' : '' ?>
        </button>
        <?php if ($agenAktif): ?>
            <span class="pill pill-teal" title="Dicetak langsung ke printer ini melalui agen cetak">
                <?= icon('print') ?> <?= h($agenPrinter) ?>
            </span>
        <?php endif; ?>
        <?php if ($duaStruk): ?>
            <a class="btn btn-ghost" href="cetak.php?id=<?= (int) $t['id'] ?>&rangkap=1">Cetak 1 lembar saja</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="cetak.php?id=<?= (int) $t['id'] ?>&rangkap=2">Cetak 2 rangkap</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="ambil.php"><?= icon('tiket') ?> Ambil Antrian Lagi</a>
        <a class="btn btn-ghost" href="panggil.php"><?= icon('mic') ?> Panggil Antrian</a>
    </div>
</div>

<?php if ($tipSenyap): ?>
<div class="tip-senyap no-print" id="tip-senyap" hidden>
    <div class="tip-senyap-teks">
        <strong><?= icon('print') ?> Ingin tiket tercetak tanpa dialog cetak?</strong>
        <span>Jalankan browser di komputer loket dengan mode <em>cetak senyap</em>, maka tombol
            Cetak Sekarang (dan cetak otomatis) langsung mengirim ke printer yang aktif tanpa menampilkan dialog.
            Panduan lengkap: Pengaturan → Printer.</span>
        <div class="tip-senyap-kode">
            <label>Chrome / Edge — pintasan dengan mode cetak senyap:</label>
            <textarea readonly rows="1" id="perintah-kiosk"><?= h($perintahKiosk) ?></textarea>
            <button class="btn btn-mini" type="button" data-salin="#perintah-kiosk">Salin perintah</button>
        </div>
    </div>
    <div class="tip-senyap-aksi">
        <a class="btn btn-mini btn-ghost" href="pengaturan.php#printer" target="_blank" rel="noopener">Panduan</a>
        <button class="btn btn-mini btn-ghost" type="button" id="tip-tutup">Tutup</button>
        <button class="btn btn-mini btn-ghost" type="button" id="tip-jangan">Jangan tampilkan lagi</button>
    </div>
</div>
<?php endif; ?>

<div class="rangkap-info no-print">
    <?php if ($agenAktif && $tiketPng === null): ?>
        <span class="warn-text">Agen cetak aktif, TETAPI gambar tiket tidak dapat dibuat di server
            (ekstensi GD/font tidak tersedia). Tiket akan dicetak lewat dialog seperti biasa.</span>
    <?php endif; ?>
    <?php if ($agenAktif): ?>
        <span><strong>Cetak lewat agen:</strong> tiket dikirim langsung ke printer
            <strong><?= h($agenPrinter) ?></strong> di komputer ini — tanpa dialog cetak.
            Printer default Windows tidak diubah.</span>
    <?php endif; ?>
    <?php if ($duaStruk): ?>
        <span><strong>Mode 2 rangkap aktif.</strong> Menekan Cetak Sekarang akan mencetak
            <strong>dua kali</strong>: (1) struk lengkap, lalu (2) potongan tinggi ±30 mm berisi kode tiket besar
            &amp; catatan — untuk ditempel pada resep sebagai penanda petugas farmasi.
            Printer akan memotong kertas di antara keduanya.</span>
    <?php else: ?>
        <span>Mode 1 rangkap: tiket dicetak satu struk. Ingin dua rangkap? Nyalakan saklar
            <strong>Cetak 2 rangkap</strong> di halaman Ambil Antrian.</span>
    <?php endif; ?>
    <span class="rangkap-status" id="rangkap-status"></span>
</div>

<?php if ($baru): ?>
<div class="flash flash-ok no-print">
    Tiket <strong><?= h($t['kode_tiket']) ?></strong> berhasil dibuat — status tracking
    <strong><?= h(STATUS_LABEL[$status] ?? $status) ?></strong>.
</div>
<?php endif; ?>

<div class="cetak-set" id="cetak-1" data-rangkap="1">
<div class="tiket-paper">
    <div class="tiket-head">
        <?php if ($logo !== '' && !$isThermal): ?>
            <img class="tiket-logo" src="<?= h($logo) ?>" alt="Logo">
        <?php endif; ?>
        <div class="tiket-rs"><?= h(setting('nama_apotek', 'Apotek')) ?></div>
        <div class="tiket-alamat"><?= h(setting('alamat_apotek')) ?></div>
        <div class="tiket-judul"><?= h(setting('printer_judul', 'TIKET ANTRIAN APOTEK')) ?></div>
    </div>

    <div class="tiket-sep"></div>

    <div class="tiket-nomor-besar">
        <span class="tiket-prefix"><?= h($t['kode']) ?></span><span class="tiket-angka"><?= h(str_pad((string) $t['nomor'], 3, '0', STR_PAD_LEFT)) ?></span>
    </div>
    <div class="tiket-layanan"><?= h($layanan) ?></div>

    <div class="tiket-sep"></div>
    <table class="tiket-tabel">
        <?php if ($tampilPasien && trim((string) $t['nama_pasien']) !== ''): ?>
            <tr><td>Nama pasien</td><td><?= h($t['nama_pasien']) ?></td></tr>
        <?php endif; ?>
        <?php if ($tampilPasien && trim((string) $t['no_rm']) !== ''): ?>
            <tr><td>No. Rekam Medis</td><td><?= h($t['no_rm']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Tanggal</td><td><?= h(date('d/m/Y', strtotime((string) $t['created_at']))) ?></td></tr>
        <tr><td>Jam ambil</td><td><?= h(jam_teks((string) $t['created_at'])) ?></td></tr>
        <?php if (trim((string) $t['keterangan']) !== ''): ?>
            <tr><td>Catatan</td><td><?= h($t['keterangan']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Status</td><td><?= h(STATUS_LABEL[$status] ?? $status) ?></td></tr>
        <tr><td>Diambil oleh</td><td><?= h($t['diambil_oleh'] !== '' ? nama_pengguna((string) $t['diambil_oleh']) : '-') ?></td></tr>
    </table>

    <?php if ($qrAktif && $tautan === ''): ?>
        <div class="tiket-sep"></div>
        <div class="tiket-pesan-qr">
            QR lacak belum dapat dibuat karena alamat publik aplikasi belum terdeteksi.
            Isi <strong>Alamat publik aplikasi</strong> pada Pengaturan → QR &amp; Lacak Obat.
        </div>
    <?php endif; ?>
    <?php if ($qrSvg !== ''): ?>
        <div class="tiket-sep"></div>
        <div class="tiket-qr">
            <div class="tiket-qr-gambar" style="width:<?= (int) $qrUkuran ?>mm;height:<?= (int) $qrUkuran ?>mm"
                 data-lacak="<?= h($tautan) ?>" title="<?= h($tautan) ?>">
                <?= $qrSvg ?>
            </div>
            <div class="tiket-qr-teks">
                <strong><?= h(setting('qr_teks', 'Scan untuk melacak posisi obat Anda')) ?></strong>
                <span>Pindai QR ini dengan kamera ponsel, lalu biarkan halaman lacak terbuka untuk menerima
                    pemberitahuan setiap posisi obat berubah.</span>
                <em class="tiket-qr-kode"><?= h($t['kode_tiket']) ?></em>
            </div>
        </div>
    <?php endif; ?>

    <?php for ($i = 0; $i < $kosong; $i++): ?><div class="tiket-baris-kosong">&nbsp;</div><?php endfor; ?>
</div>
</div>

<?php if ($duaStruk): ?>
    <?php /* Rangkap 2 (hanya saat saklar 2 rangkap aktif): potongan 30 mm untuk penanda resep. */ ?>
    <div class="cetak-set" id="cetak-2" data-rangkap="2">
        <div class="tiket-paper tiket-potongan">
            <div class="potongan-kode"><?= h($t['kode_tiket']) ?></div>
            <?php if (trim((string) $t['keterangan']) !== ''): ?>
                <div class="potongan-catatan"><?= h($t['keterangan']) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
window.CETAK_CFG = {
    auto: <?= $auto ? 'true' : 'false' ?>,
    tip: <?= $tipSenyap ? 'true' : 'false' ?>,
    rangkap: <?= $duaStruk ? 2 : 1 ?>
};
/* --- Agen cetak (bila aktif): dikirim dari browser ke agen di komputer ini --- */
window.CETAK_AGEN = <?= json_encode([
    'aktif'   => ($agenAktif && $tiketPng !== null) ? 1 : 0,
    'url'     => $agenUrl,
    'token'   => $agenToken,
    'printer' => $agenPrinter,
    'nama'    => 'tiket-' . $t['kode_tiket'],
    'gambar'  => ($agenAktif && $tiketPng !== null) ? base64_encode($tiketPng) : '',
    'teks'    => $agenAktif ? $tiketTeks : '',
    'gambar_potongan' => ($agenAktif && $potonganPng !== null) ? base64_encode($potonganPng) : '',
    'teks_potongan'   => $agenAktif ? $potonganTeks : '',
    'rangkap' => $duaStruk ? 2 : 1,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/cetak.js"></script>
</body>
</html>
