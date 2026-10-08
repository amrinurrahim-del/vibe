<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user  = require_login();
$kodes = kode_daftar(true);

page_head('Panggil Antrian', 'panggil.php');
?>
<section class="page-head">
    <div>
        <h1><?= icon('mic') ?> Form Panggil Antrian</h1>
        <p>Tombol kode aktif bila ada tiket berstatus <strong>OBAT SIAP DISERAHKAN</strong>.
            Sekali ditekan, nomor dipanggil dengan suara (default: Google Bahasa Indonesia / id-ID),
            status tracking berubah menjadi <strong>OBAT SUDAH DITERIMA</strong> dan waktu tunggu dicatat.</p>
    </div>
    <div class="page-head-side">
        <span class="pill pill-teal"><?= h(tgl_label(hari_ini())) ?></span>
        <span class="pill" id="pill-login"><?= h($user['username']) ?></span>
        <button class="btn btn-mini" type="button" id="tes-suara"><?= icon('bell') ?> Tes Suara</button>
        <?php /* Tombol PENGUMUMAN SUARA: menulis teks bebas lalu diucapkan dengan suara yang sama
                 seperti panggilan antrian. Diletakkan di luar form supaya tidak mengubah apa pun
                 pada form panggil antrian — tidak mengubah status tiket mana pun. */ ?>
        <button class="btn btn-mini btn-accent" type="button" id="btn-pengumuman"
                title="Tulis pengumuman bebas lalu diucapkan dengan suara antrian (bisa disimpan sebagai template)">
            <?= icon('mega') ?> Pengumuman
        </button>
        <?php /* Tombol tambahan: buka display di monitor kedua (bila PC punya 2 layar).
                 Diletakkan di luar form supaya tidak mengubah apa pun pada form panggil antrian. */ ?>
        <button class="btn btn-mini btn-ghost" type="button" id="btn-layar2"
                title="Buka layar display di monitor kedua (bila komputer punya 2 monitor)">
            <?= icon('tv') ?> Display Monitor 2
        </button>
    </div>
</section>

<div class="grid-panggil">
    <section class="card">
        <header class="card-head">
            <h2><?= icon('mic') ?> Tombol Panggil per Kode</h2>
            <span class="card-hint">Hijau = ada tiket siap diserahkan · Abu = belum ada</span>
        </header>

        <div class="panggil-grid" id="panggil-grid">
            <?php foreach ($kodes as $k): ?>
                <article class="panggil-card<?= (int) $k['aktif'] === 1 ? '' : ' is-off' ?>" data-kode="<?= h($k['kode']) ?>">
                    <div class="panggil-head">
                        <span class="panggil-huruf"><?= h($k['kode']) ?></span>
                        <span class="panggil-nama"><?= h(kode_label($k)) ?></span>
                        <span class="badge-siap" data-badge="<?= h($k['kode']) ?>">0</span>
                    </div>
                    <button class="btn btn-call" type="button" data-panggil="<?= h($k['kode']) ?>" disabled>
                        <?= icon('mic') ?> <span>Panggil</span>
                    </button>
                    <div class="panggil-info" data-info="<?= h($k['kode']) ?>">
                        <span class="panggil-next">Belum ada tiket siap diserahkan</span>
                    </div>
                    <div class="panggil-queue" data-queue="<?= h($k['kode']) ?>"></div>
                    <div class="panggil-foot">
                        <button class="btn btn-mini" type="button" data-ulang="<?= h($k['kode']) ?>" disabled>Panggil Ulang</button>
                        <span class="panggil-stat" data-stat="<?= h($k['kode']) ?>">—</span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="col-stack">
        <article class="card card-call">
            <header class="card-head">
                <h2><?= icon('tv') ?> Sedang Dipanggil</h2>
            </header>
            <div class="call-now call-now-big">
                <span class="call-number" id="call-sekarang">—</span>
                <span class="call-meta" id="call-meta">Belum ada panggilan hari ini</span>
            </div>
            <div class="voice-state" id="voice-state">
                <span class="voice-dot"></span>
                <span id="voice-text">Suara siap dipakai di perangkat ini</span>
            </div>
        </article>

        <article class="card">
            <header class="card-head">
                <h2><?= icon('bell') ?> Riwayat Panggilan</h2>
                <span class="card-hint" id="log-jumlah">memuat…</span>
            </header>
            <?php /* Pencarian tiket pada riwayat (kode tiket, nama pasien, atau No. RM). */ ?>
            <div class="log-cari">
                <input type="search" id="cari-riwayat" class="input-search"
                       placeholder="Cari kode tiket / nama pasien / No. RM…" autocomplete="off">
                <button class="btn btn-mini btn-ghost" type="button" id="bersih-cari">Bersihkan</button>
            </div>
            <div class="list-log" id="log-panggil">
                <p class="empty">Belum ada panggilan.</p>
            </div>
            <p class="hint log-hint">
                Seluruh tiket yang sudah dipanggil hari ini tampil di sini (digulir bila banyak) —
                tombol <strong>Batalkan</strong> pada tiap baris mengembalikannya ke
                <strong>OBAT SIAP DISERAHKAN</strong> agar dapat dipanggil lagi.
            </p>
        </article>
    </aside>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<?php /* ============ Dialog "Pengumuman Suara" ============ */ ?>
<div class="pengumuman-lapis" id="pengumuman-lapis" hidden>
    <div class="pengumuman-kotak" role="dialog" aria-modal="true" aria-labelledby="pengumuman-judul">
        <header class="pengumuman-head">
            <h2 id="pengumuman-judul"><?= icon('mega') ?> Pengumuman Suara</h2>
            <button class="pengumuman-tutup" type="button" id="pengumuman-tutup"
                    title="Tutup (Esc)" aria-label="Tutup dialog pengumuman">✕</button>
        </header>

        <p class="pengumuman-note">
            Teks diucapkan dengan <strong>suara yang sama seperti panggilan antrian</strong>
            (<span id="pengumuman-suara">Bahasa Indonesia</span>).
            Pengumuman ini <strong>tidak mengubah status tiket</strong> mana pun.
        </p>

        <label class="field">
            <span>Teks pengumuman</span>
            <textarea id="pengumuman-teks" rows="4" maxlength="400"
                      placeholder="Contoh: Mohon perhatian, antrian sedang padat. Resep yang sudah siap akan dipanggil sesuai urutan. Terima kasih."></textarea>
        </label>

        <div class="pengumuman-meta">
            <span id="pengumuman-hitung" class="pengumuman-hitung">0 / 400 karakter</span>
            <label class="pengumuman-ulang">
                <span>Ulangi</span>
                <select id="pengumuman-ulang">
                    <option value="1" selected>1×</option>
                    <option value="2">2×</option>
                    <option value="3">3×</option>
                </select>
            </label>
        </div>

        <div class="pengumuman-aksi">
            <button class="btn btn-primary" type="button" id="pengumuman-umumkan">
                <?= icon('mega') ?> Umumkan
            </button>
            <button class="btn btn-ghost" type="button" id="pengumuman-henti" disabled>
                <?= icon('stop') ?> Hentikan Suara
            </button>
            <span class="pengumuman-status" id="pengumuman-status">Belum ada yang diumumkan.</span>
        </div>

        <div class="pengumuman-simpan">
            <div class="field">
                <span>Simpan sebagai template <em>(bisa dipakai ulang kapan saja)</em></span>
                <div class="pengumuman-simpan-baris">
                    <input type="text" id="pengumuman-nama" maxlength="60"
                           placeholder="Nama template, mis. Apotek tutup 10 menit">
                    <button class="btn btn-accent" type="button" id="pengumuman-simpan">Simpan Template</button>
                </div>
            </div>
        </div>

        <div class="pengumuman-daftar-wrap">
            <div class="pengumuman-daftar-head">
                <strong>Template tersimpan</strong>
                <span id="pengumuman-daftar-jumlah" class="pengumuman-hitung">memuat…</span>
            </div>
            <div class="pengumuman-daftar" id="pengumuman-daftar"></div>
        </div>
    </div>
</div>

<?php
page_end(['assets/panggil.js', 'assets/pengumuman.js', 'assets/layar2.js']);
