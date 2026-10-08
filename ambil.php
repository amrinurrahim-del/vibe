<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();

/* Saklar "cetak 2 rangkap" di samping tanggal (POST kecil, tanpa pindah halaman). */
if (is_post() && ($_POST['aksi'] ?? '') === 'toggle_rangkap') {
    $baru = setting_bool('printer_dua_struk', false) ? '0' : '1';
    set_setting('printer_dua_struk', $baru);
    redirect('ambil.php?ok=' . urlencode($baru === '1'
        ? 'Cetak 2 rangkap DIAKTIFKAN — setiap tiket dicetak 2 kali: struk lengkap + potongan 30 mm sebagai penanda resep.'
        : 'Cetak 2 rangkap DIMATIKAN — tiket dicetak 1 struk saja.'));
}

/* Form ambil antrian: klik salah satu tombol kode → tiket dibuat → langsung ke halaman cetak. */
if (is_post()) {
    $kode = strtoupper(trim((string) ($_POST['kode'] ?? '')));
    $ket  = trim((string) ($_POST['keterangan'] ?? ''));
    $noRm = trim((string) ($_POST['no_rm'] ?? ''));
    $nama = trim((string) ($_POST['nama_pasien'] ?? ''));
    try {
        if ($kode === '') {
            throw new RuntimeException('Pilih kode antrian terlebih dahulu.');
        }
        if (setting_bool('simrs_wajib_rm', false) && $noRm === '') {
            throw new RuntimeException('Nomor rekam medis wajib diisi (Pengaturan → SIMRS).');
        }
        $t = tiket_ambil($kode, $ket, (string) $user['username'], $noRm, $nama);
        redirect('cetak.php?id=' . (int) $t['id'] . '&baru=1'
            . (setting_bool('printer_otomatis', true) ? '&auto=1' : ''));
    } catch (RuntimeException $e) {
        redirect('ambil.php?err=' . urlencode($e->getMessage()));
    }
}

$kodes = kode_daftar(true);
$hari  = tiket_hari_ini();
/* Semua tiket hari ini ditampilkan (terbaru di atas); panelnya digulir MANUAL oleh petugas.
   Tinggi panel dibatasi ±8 baris (lihat CSS .list-tiket), jadi tidak memanjangkan halaman. */
$riwayat = array_reverse($hari);
$perKode = [];
foreach ($hari as $t) {
    $perKode[(string) $t['kode']] = ($perKode[(string) $t['kode']] ?? 0) + 1;
}

page_head('Ambil Antrian', 'ambil.php');
?>
<?php flash(); ?>

<section class="page-head">
    <div>
        <h1><?= icon('tiket') ?> Form Ambil Antrian</h1>
        <p>Klik kode antrian untuk membuat &amp; mencetak tiket. Nomor dihitung otomatis per kode
            (3 digit, mulai 001) dan tiket langsung masuk tracking dengan status <strong>RESEP MASUK</strong>.</p>
    </div>
    <div class="page-head-side">
        <span class="pill pill-teal"><?= h(tgl_label(hari_ini())) ?></span>
        <span class="pill"><?= count($hari) ?> tiket hari ini</span>

        <?php /* Saklar cetak 2 rangkap: rangkap 2 = potongan 30 mm sebagai penanda resep farmasi. */ ?>
        <form method="post" action="ambil.php" class="saklar-form">
            <input type="hidden" name="aksi" value="toggle_rangkap">
            <button class="saklar<?= setting_bool('printer_dua_struk', false) ? ' is-on' : '' ?>" type="submit"
                    role="switch" aria-checked="<?= setting_bool('printer_dua_struk', false) ? 'true' : 'false' ?>"
                    title="Cetak 2 rangkap: struk lengkap + potongan 30 mm sebagai penanda resep">
                <span class="saklar-rel" aria-hidden="true"><span class="saklar-knob"></span></span>
                <span class="saklar-teks">
                    Cetak 2 rangkap
                    <em><?= setting_bool('printer_dua_struk', false) ? 'Aktif' : 'Nonaktif' ?></em>
                </span>
            </button>
        </form>
    </div>
</section>

<div class="grid-ambil">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('tiket') ?> Pilih Kode Tiket</h2>
            <span class="card-hint">Nomor berikutnya tampil di tiap tombol</span>
        </header>

        <form method="post" id="form-ambil" class="form-ambil">
            <?php if (simrs_aktif()): ?>
                <div class="simrs-box" id="simrs-box"
                     data-agen-online="<?= simrs_agen_online() ? '1' : '0' ?>">
                    <div class="simrs-baris">
                        <label class="field simrs-rm">
                            <span>No. Rekam Medis <em>(dari SIMRS)</em></span>
                            <input type="text" name="no_rm" id="no-rm" maxlength="24" inputmode="numeric"
                                   autocomplete="off" placeholder="contoh: 123456"
                                   <?= setting_bool('simrs_wajib_rm', false) ? 'required' : '' ?>>
                        </label>
                        <div class="field simrs-cari">
                            <button class="btn btn-accent" type="button" id="btn-cari">
                                <?= icon('user') ?> Cari Pasien
                            </button>
                        </div>
                    </div>

                    <label class="field">
                        <span>Nama pasien <em>(terisi otomatis dari SIMRS, boleh diperbaiki)</em></span>
                        <input type="text" name="nama_pasien" id="nama-pasien" maxlength="80" autocomplete="off"
                               placeholder="nama pasien">
                    </label>

                    <div class="simrs-status" id="simrs-status">
                        <span class="simrs-dot" id="simrs-dot"></span>
                        <span id="simrs-pesan">
                            <?php if (simrs_metode() === 'rest'): ?>
                                Integrasi SIMRS aktif — isi No. RM lalu tekan <strong>Cari Pasien</strong>
                                (nama pasien diambil langsung dari server SIMRS).
                            <?php elseif (simrs_agen_online()): ?>
                                Agen SIMRS terhubung — isi No. RM lalu tekan <strong>Cari Pasien</strong>.
                            <?php else: ?>
                                Agen SIMRS belum terhubung. Pastikan program agen di jaringan RS berjalan;
                                nama pasien masih bisa diisi manual.
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="simrs-hasil" id="simrs-hasil" hidden></div>
                </div>
            <?php endif; ?>

            <label class="field">
                <span>No. resep / catatan tambahan <em>(opsional)</em></span>
                <input type="text" name="keterangan" maxlength="60" placeholder="contoh: R-10245 / resep kronis">
            </label>

            <div class="kode-grid">
                <?php foreach ($kodes as $k): ?>
                    <?php
                    $aktif = (int) $k['aktif'] === 1;
                    $next  = tiket_nomor_berikutnya((string) $k['kode']);
                    $jumlah = (int) ($perKode[(string) $k['kode']] ?? 0);
                    ?>
                    <button class="kode-btn<?= $aktif ? '' : ' is-off' ?>" type="submit"
                            name="kode" value="<?= h($k['kode']) ?>" <?= $aktif ? '' : 'disabled' ?>
                            data-kode="<?= h($k['kode']) ?>">
                        <span class="kode-btn-huruf"><?= h($k['kode']) ?></span>
                        <span class="kode-btn-nama"><?= h(kode_label($k)) ?></span>
                        <span class="kode-btn-next" data-next="<?= h($k['kode']) ?>">
                            <strong><?= h($next['kode_tiket']) ?></strong>
                            <?php if (($k['reset_mode'] ?? 'harian') === 'bulanan'): ?>
                                <em class="kode-btn-mode">bulanan · <?= h(bulan_label(date('Y-m'))) ?></em>
                            <?php endif; ?>
                        </span>
                        <span class="kode-btn-info"><?= $jumlah ?> tiket hari ini</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>

        <p class="card-note">
            Jalan pintas: tekan huruf kode <strong>di keyboard</strong> (A, F, K, P, G, J, I) untuk langsung membuat tiket —
            tekan <strong>Esc</strong> dulu bila kursor masih berada di kotak No. resep. Tiket yang baru dibuat langsung
            terbuka di halaman cetak. Cetak otomatis:
            <strong><?= setting_bool('printer_otomatis', true) ? 'aktif' : 'nonaktif' ?></strong>
            — diatur di Pengaturan → Printer.
            <?php if (setting_bool('printer_dua_struk', false)): ?>
                <br><strong>Mode 2 rangkap aktif:</strong> tiap tiket dicetak dua kali —
                struk lengkap, lalu potongan kecil (± 30 mm) berisi kode tiket besar + catatan,
                untuk ditempel pada resep sebagai penanda petugas farmasi.
            <?php endif; ?>
        </p>
    </article>

    <div class="col-stack">
        <article class="card">
            <header class="card-head">
                <h2><?= icon('print') ?> Tiket Terakhir Hari Ini</h2>
                <span class="card-hint">
                    <?= count($riwayat) ?> tiket · gulir untuk melihat semuanya · bisa dicetak ulang
                </span>
            </header>
            <?php if (!$riwayat): ?>
                <p class="empty">Belum ada tiket yang diambil hari ini.</p>
            <?php else: ?>
                <div class="list-tiket">
                    <?php foreach ($riwayat as $t): ?>
                        <?php $r = tiket_kode_ringkas($t); ?>
                        <div class="tiket-row">
                            <span class="tiket-nomor"><?= h($r['kode_tiket']) ?></span>
                            <span class="tiket-info">
                                <span class="status-pill status-<?= h(strtolower(str_replace('_', '-', $r['status']))) ?>"><?= h($r['status_label']) ?></span>
                                <span class="tiket-jam"><?= h($r['jam_ambil']) ?></span>
                                <?php if (trim((string) $t['nama_pasien']) !== ''): ?>
                                    <span class="tiket-pasien"><?= h($t['nama_pasien']) ?><?= trim((string) $t['no_rm']) !== '' ? ' · RM ' . h($t['no_rm']) : '' ?></span>
                                <?php endif; ?>
                            </span>
                            <a class="btn btn-mini" href="cetak.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener">
                                <?= icon('print') ?> Cetak
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="card">
            <header class="card-head">
                <h2><?= icon('bell') ?> Catatan Alur</h2>
            </header>
            <ul class="alur-list">
                <li><span class="dot dot-1"></span> Tiket dibuat → <strong>RESEP MASUK</strong>, masuk kolom pertama display.</li>
                <li><span class="dot dot-2"></span> Petugas update tracking → <strong>OBAT SEDANG DISIAPKAN</strong>.</li>
                <li><span class="dot dot-3"></span> Obat selesai disiapkan → <strong>OBAT SIAP DISERAHKAN</strong> (tombol panggil aktif).</li>
                <li><span class="dot dot-4"></span> Dipanggil di menu Panggil Antrian → <strong>OBAT SUDAH DITERIMA</strong> + masuk laporan.</li>
            </ul>
        </article>
    </div>
</div>
<?php
page_end('assets/ambil.js');
