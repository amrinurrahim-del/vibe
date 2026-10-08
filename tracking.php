<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();
page_head('Tracking Obat', 'tracking.php');
?>
<section class="page-head">
    <div>
        <h1><?= icon('track') ?> Tracking Posisi Obat</h1>
        <p>Update status tiap tiket mengikuti alur resep: <strong>RESEP MASUK</strong> →
            <strong>OBAT SEDANG DISIAPKAN</strong> → <strong>OBAT SIAP DISERAHKAN</strong>.
            Status <strong>OBAT SUDAH DITERIMA</strong> hanya bisa dicapai lewat menu
            <a href="panggil.php">Panggil Antrian</a>.</p>
    </div>
    <div class="page-head-side">
        <span class="pill pill-teal"><?= h(tgl_label(hari_ini())) ?></span>
        <input type="search" class="input-search" id="cari-tiket" placeholder="Cari kode / nomor / resep…">
        <button class="btn btn-mini" type="button" id="refresh-tracking"><?= icon('grid') ?> Muat Ulang</button>
    </div>
</section>

<section class="track-cols" id="track-cols">
    <?php
    $kolom = [
        ST_RESEP => 'Resep Masuk',
        ST_SIAPKAN => 'Sedang Disiapkan',
        ST_SIAP => 'Siap Diserahkan',
        ST_DITERIMA => 'Sudah Diterima',
    ];
    foreach ($kolom as $st => $judul):
    ?>
        <article class="track-col" data-status="<?= h($st) ?>">
            <header class="track-col-head">
                <h2><?= h($judul) ?></h2>
                <span class="badge-count" data-count="<?= h($st) ?>">0</span>
            </header>
            <p class="track-col-note"><?= h(STATUS_LABEL[$st]) ?></p>
            <?php if ($st === ST_RESEP && auto_siapkan_aktif()): ?>
                <p class="track-col-note track-col-auto">
                    Pindah sendiri ke <strong>Sedang Disiapkan</strong> setelah menunggu
                    <?= h((string) auto_siapkan_menit()) ?> menit — tombol manual tetap bisa dipakai.
                </p>
            <?php endif; ?>
            <div class="track-list" data-list="<?= h($st) ?>">
                <p class="empty">Memuat data…</p>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<p class="card-note card-note-wide">
    Tiket kode <strong>J</strong> bersambung sepanjang bulan (reset otomatis tiap tanggal 1). Tiket kode J yang belum
    selesai pada hari sebelumnya tidak lagi tampil di tracking hari ini, namun tetap tercatat di laporan.
</p>
<div class="toast-wrap" id="toast-wrap"></div>
<?php
page_end('assets/tracking.js');
