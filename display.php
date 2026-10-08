<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/* Layar display TV — sengaja TIDAK dikunci login (TV tidak dapat login). */
$logo = setting('logo_url');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Display Antrian — <?= h(setting('nama_apotek', 'Apotek')) ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>:root{--accent:<?= h(setting('tema_warna', '#0d9488')) ?>;}</style>
</head>
<body class="display-body">
<div class="display-stage" id="display-stage">
<?php $videoTampil = video_aktif(); ?>
<div class="display-shell<?= $videoTampil ? ' has-video' : '' ?>">

    <header class="display-head">
        <div class="display-brand">
            <?php if ($logo !== ''): ?>
                <img class="display-logo" id="d-logo" src="<?= h($logo) ?>" alt="Logo apotek">
            <?php else: ?>
                <span class="display-mark" id="d-mark"><?= icon('tiket') ?></span>
                <img class="display-logo is-hidden" id="d-logo" src="" alt="Logo apotek">
            <?php endif; ?>
            <div class="display-ident">
                <h1 id="d-nama"><?= h(setting('nama_apotek', 'Apotek')) ?></h1>
                <p id="d-alamat"><?= h(setting('alamat_apotek')) ?></p>
            </div>
        </div>
        <div class="display-side">
            <h2 class="display-judul" id="d-judul"><?= h(setting('display_judul', 'ANTRIAN PENGAMBILAN OBAT')) ?></h2>
            <?php /* Tombol hanya ikon (rapat ke kanan & sejajar). Penjelasan lewat title
                     dan aria-label supaya tetap terbaca pembaca layar / saat disorot tetikus. */ ?>
            <div class="display-actions no-print">
                <button class="dbtn dbtn-ikon" type="button" id="btn-suara"
                        title="Aktifkan suara pengumuman" aria-label="Aktifkan suara pengumuman">
                    <?= icon('bell') ?>
                    <span class="sr-only" id="btn-suara-label">Aktifkan Suara</span>
                </button>
                <button class="dbtn dbtn-ikon" type="button" id="btn-full"
                        title="Layar penuh" aria-label="Layar penuh">
                    <?= icon('tv') ?>
                    <span class="sr-only">Layar Penuh</span>
                </button>
                <a class="dbtn dbtn-ikon" href="login.php"
                   title="Masuk sebagai petugas" aria-label="Masuk sebagai petugas">
                    <?= icon('user') ?>
                    <span class="sr-only">Petugas</span>
                </a>
            </div>
        </div>
    </header>

    <section class="display-main">
        <?php if ($videoTampil): ?>
            <?php
            /* Panel video: elemen <video> dibuat di server supaya JS tidak pernah mengganti src-nya
               (mengganti src = video terulang dari awal). Perpindahan antar video dilakukan dengan
               menampilkan elemen berikutnya yang sudah disiapkan, jadi tidak ada jeda/kedip. */
            $videoSuara = setting_bool('video_suara', false);
            $videoList  = video_daftar();
            ?>
            <?php
            /*
             * Panel video = BINGKAI VIDEO PENUH: tanpa padding/margin, tanpa judul dan tanpa label,
             * supaya gambar video seluas mungkin. Video mengisi penuh panel (object-fit: cover).
             * Judul panel hanya dipakai sebagai label pembaca layar / tooltip (tidak tampil).
             */
            $judulVideo = (string) setting('video_judul', 'INFORMASI KESEHATAN');
            ?>
            <article class="video-panel" id="video-panel" data-stamp="<?= h(video_stamp()) ?>"
                     aria-label="<?= h($judulVideo) ?>" title="<?= h($judulVideo) ?>">
                <div class="video-frame" id="video-frame">
                    <?php foreach ($videoList as $i => $v): ?>
                        <video class="video-item<?= $i === 0 ? ' is-aktif' : '' ?>"
                               data-i="<?= (int) $i ?>"
                               src="<?= h($v['url']) ?>"
                               preload="<?= $i === 0 ? 'auto' : 'metadata' ?>"
                               playsinline muted
                               <?= $i === 0 ? '' : 'aria-hidden="true"' ?>></video>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>

        <article class="call-panel" id="call-panel">
            <span class="call-panel-label" id="d-label">NOMOR TIKET DIPANGGIL</span>
            <div class="call-panel-number" id="d-nomor">
                <span class="call-panel-kode" id="d-kode">—</span>
                <span class="call-panel-angka" id="d-angka">---</span>
            </div>
            <div class="call-panel-loket" id="d-loket">Silakan menunggu nomor Anda dipanggil</div>
            <div class="call-panel-status" id="d-status"></div>
            <div class="call-panel-meta">
                <span id="d-jam">—</span>
                <span id="d-tunggu"></span>
            </div>
        </article>

        <div class="display-riwayat">
            <span class="riwayat-label">Panggilan sebelumnya</span>
            <div class="riwayat-list" id="d-riwayat"><em>—</em></div>
        </div>

        <div class="display-clock">
            <span class="clock-jam" id="d-clock">--:--:--</span>
            <span class="clock-tgl" id="d-tanggal">—</span>
        </div>
    </section>

    <section class="display-status">
        <?php
        $panel = [
            ST_RESEP => ['1', 'Resep Masuk'],
            ST_SIAPKAN => ['2', 'Obat Sedang Disiapkan'],
            ST_SIAP => ['3', 'Obat Siap Diserahkan'],
        ];
        foreach ($panel as $st => [$no, $judul]):
        ?>
            <article class="status-panel status-panel-<?= h(strtolower(str_replace('_', '-', $st))) ?>">
                <header class="status-panel-head">
                    <span class="status-panel-no"><?= h($no) ?></span>
                    <h3><?= h($judul) ?></h3>
                    <span class="status-panel-count" data-count="<?= h($st) ?>">0</span>
                </header>
                <div class="status-chips" data-chips="<?= h($st) ?>">
                    <span class="chip-empty">—</span>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="display-selesai">
        <header class="selesai-head">
            <span class="selesai-check">&#10003;</span>
            <h3>Obat Sudah Diterima <em>(selesai)</em></h3>
            <span class="status-panel-count" data-count="<?= h(ST_DITERIMA) ?>">0</span>
        </header>
        <div class="status-chips chips-selesai" data-chips="<?= h(ST_DITERIMA) ?>">
            <span class="chip-empty">—</span>
        </div>
    </section>

    <footer class="display-foot">
        <div class="marquee">
            <div class="marquee-track" id="d-marquee"><?= h(setting('footer_teks')) ?></div>
        </div>
        <span class="display-stamp" id="d-stamp">memuat…</span>
    </footer>
</div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>
<script src="assets/voice.js"></script>
<script src="assets/display.js"></script>
</body>
</html>
