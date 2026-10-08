<?php
declare(strict_types=1);

/*
 * Laporan antrian apotek.
 * - Isi laporan HANYA tiket yang sudah dipanggil/diserahkan (called_at terisi), urut tanggal.
 * - Kolom: tanggal, kode tiket, jam ambil, jam siap, jam panggil, waktu tunggu,
 *   waktu penyiapan (tracking), dan status tracking.
 * - Unduhan: PDF (periode tanggal atau bulan) dan Excel/CSV.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/report.php';

$user = require_login();

/* ---------------- Filter periode ---------------- */
$mode   = (string) ($_GET['mode'] ?? 'bulan');
$mode   = in_array($mode, ['tanggal', 'bulan'], true) ? $mode : 'bulan';
$bulan  = (string) ($_GET['bulan'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$dari   = tgl_valid($_GET['dari'] ?? date('Y-m-01'));
$sampai = tgl_valid($_GET['sampai'] ?? date('Y-m-d'));
if ($sampai < $dari) {
    [$dari, $sampai] = [$sampai, $dari];
}
$kodeFilter = trim((string) ($_GET['kode'] ?? ''));
if ($kodeFilter !== '' && !preg_match('/^[A-Za-z]$/', $kodeFilter)) {
    $kodeFilter = '';
}
$kodeFilter = strtoupper($kodeFilter);

if ($mode === 'bulan') {
    $periodeDari   = $bulan . '-01';
    $periodeSampai = date('Y-m-t', strtotime($bulan . '-01'));
    $periodeLabel  = 'Bulan ' . bulan_label($bulan);
} else {
    $periodeDari   = $dari;
    $periodeSampai = $sampai;
    $periodeLabel  = ($dari === $sampai) ? tgl_label($dari, false) : tgl_label($dari, false) . ' s/d ' . tgl_label($sampai, false);
}

$rows      = laporan_rows($periodeDari, $periodeSampai, $kodeFilter);
$ringkas   = laporan_ringkas($rows);
$namaApotek = setting('nama_apotek', 'Apotek');
$alamat    = setting('alamat_apotek');

/* Kode layanan untuk keterangan kolom kode */
$layananKode = [];
foreach (kode_daftar(true) as $k) {
    $layananKode[(string) $k['kode']] = kode_label($k);
}

/* ---------------- Unduhan ---------------- */

$format = (string) ($_GET['format'] ?? '');
if ($format === 'csv' || $format === 'excel') {
    $header = ['Tanggal', 'Kode Tiket', 'Kode', 'Nomor', 'Layanan', 'Nama Pasien', 'No. Rekam Medis',
               'Jam Ambil', 'Jam Obat Siap', 'Jam Panggil/Serah', 'Waktu Tunggu', 'Waktu Tunggu (detik)',
               'Waktu Penyiapan', 'Waktu Penyiapan (detik)', 'Status Tracking', 'Catatan', 'Petugas'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['tanggal'], $r['kode_tiket'], $r['kode'], $r['nomor'], $layananKode[$r['kode']] ?? ('Loket ' . $r['kode']),
            $r['nama_pasien'], $r['no_rm'],
            $r['jam_ambil'], $r['jam_siap'] === '-' ? '' : $r['jam_siap'], $r['jam_panggil'],
            $r['tunggu_format'], (int) $r['tunggu_detik'], $r['siap_format'], $r['siap_detik'] === null ? '' : (int) $r['siap_detik'],
            $r['status_label'], $r['keterangan'], (string) $r['petugas'],
        ];
    }
    $namaFile = 'laporan-antrian-' . ($mode === 'bulan' ? $bulan : $dari . '_' . $sampai);
    $meta = [
        'judul'   => 'LAPORAN ANTRIAN APOTEK',
        'apotek'  => $namaApotek,
        'alamat'  => $alamat,
        'periode' => $periodeLabel,
        'ringkas' => $ringkas,
    ];
    if ($format === 'csv') {
        kirim_csv($namaFile . '.csv', $header, $data);
    }
    $xlsx = xlsx_bytes('Laporan', $header, $data);
    if ($xlsx === null) {
        kirim_csv($namaFile . '.csv', $header, $data);
    }
    kirim_file($namaFile . '.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx);
}

if ($format === 'pdf') {
    kirim_file('laporan-antrian-' . ($mode === 'bulan' ? $bulan : $dari . '_' . $sampai) . '.pdf',
        'application/pdf', laporan_pdf($rows, $nombre = [
            'judul'      => 'LAPORAN ANTRIAN APOTEK',
            'apotek'     => $namaApotek,
            'alamat'     => $alamat,
            'periode'    => $periodeLabel,
            'kodeFilter' => $kodeFilter,
            'ringkas'    => $ringkas,
            'layanan'    => $layananKode,
        ]));
}

page_head('Laporan', 'laporan.php');
?>
<section class="page-head">
    <div>
        <h1><?= icon('chart') ?> Laporan &amp; Unduhan</h1>
        <p>Laporan berisi <strong>hanya tiket yang sudah dipanggil/diserahkan</strong>, urut tanggal.
            Pilih periode tanggal tertentu atau satu bulan penuh, lalu unduh dalam PDF atau Excel/CSV.</p>
    </div>
    <div class="page-head-side">
        <span class="pill pill-teal"><?= count($rows) ?> baris data</span>
    </div>
</section>

<?php flash(); ?>

<article class="card">
    <header class="card-head">
        <h2><?= icon('chart') ?> Pilih Periode Laporan</h2>
        <span class="card-hint">Tanggal mengikuti tanggal tiket diambil</span>
    </header>
    <form method="get" class="form-grid laporan-form" id="form-laporan">
        <div class="radio-row">
            <label class="radio-pill">
                <input type="radio" name="mode" value="bulan" <?= $mode === 'bulan' ? 'checked' : '' ?>>
                <span>Per Bulan</span>
            </label>
            <label class="radio-pill">
                <input type="radio" name="mode" value="tanggal" <?= $mode === 'tanggal' ? 'checked' : '' ?>>
                <span>Per Tanggal / Rentang</span>
            </label>
        </div>

        <div class="filter-row">
            <label class="field field-bulan">
                <span>Bulan</span>
                <input type="month" name="bulan" value="<?= h($bulan) ?>">
            </label>
            <label class="field">
                <span>Dari tanggal</span>
                <input type="date" name="dari" value="<?= h($dari) ?>">
            </label>
            <label class="field">
                <span>Sampai tanggal</span>
                <input type="date" name="sampai" value="<?= h($sampai) ?>">
            </label>
            <label class="field">
                <span>Kode antrian</span>
                <select name="kode">
                    <option value="">Semua kode</option>
                    <?php foreach (kode_daftar(true) as $k): ?>
                        <option value="<?= h($k['kode']) ?>" <?= $kodeFilter === $k['kode'] ? 'selected' : '' ?>>
                            <?= h($k['kode']) ?> — <?= h(kode_label($k)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('grid') ?> Tampilkan</button>
            <button class="btn btn-accent" type="submit" name="format" value="pdf"><?= icon('print') ?> Unduh PDF</button>
            <button class="btn btn-ghost" type="submit" name="format" value="excel"><?= icon('chart') ?> Unduh Excel</button>
            <button class="btn btn-ghost" type="submit" name="format" value="csv">CSV</button>
        </div>
    </form>
    <p class="card-note">
        Periode terpilih: <strong><?= h($periodeLabel) ?></strong>
        <?= $kodeFilter !== '' ? ' · kode <strong>' . h($kodeFilter) . '</strong>' : ' · semua kode' ?>.
        File Excel dihasilkan dalam format <strong>.xlsx</strong> (otomatis menjadi .csv bila server tidak mendukung).
    </p>
</article>

<section class="grid-3">
    <article class="stat-card">
        <span class="stat-label">Tiket Diserahkan</span>
        <strong class="stat-value"><?= (int) $ringkas['total'] ?></strong>
        <span class="stat-sub">periode terpilih</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Rata-rata Waktu Tunggu</span>
        <strong class="stat-value"><?= h(durasi_format((int) $ringkas['rata_tunggu'])) ?></strong>
        <span class="stat-sub">ambil tiket → diserahkan</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Rata-rata Waktu Penyiapan</span>
        <strong class="stat-value"><?= h(durasi_format((int) $ringkas['rata_siap'])) ?></strong>
        <span class="stat-sub">resep masuk → obat siap</span>
    </article>
</section>

<?php if ($ringkas['per_kode']): ?>
<article class="card">
    <header class="card-head">
        <h2><?= icon('chart') ?> Ringkasan per Kode</h2>
    </header>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Kode</th><th>Layanan</th><th class="num">Jumlah Tiket</th><th class="num">Rata-rata Tunggu</th></tr></thead>
            <tbody>
            <?php foreach ($ringkas['per_kode'] as $p): ?>
                <tr>
                    <td><span class="kode-chip"><?= h($p['kode']) ?></span></td>
                    <td><?= h($layananKode[$p['kode']] ?? ('Loket ' . $p['kode'])) ?></td>
                    <td class="num"><?= (int) $p['jumlah'] ?></td>
                    <td class="num"><?= h(durasi_format((int) $p['rata_rata'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</article>
<?php endif; ?>

<article class="card">
    <header class="card-head">
        <h2><?= icon('track') ?> Detail Tiket Diserahkan</h2>
        <span class="card-hint"><?= count($rows) ?> baris</span>
    </header>
    <?php if (!$rows): ?>
        <p class="empty">Belum ada tiket yang diserahkan pada periode ini.</p>
    <?php else: ?>
        <div class="table-scroll table-tall">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal</th><th>Kode Tiket</th><th>Layanan</th><th>Nama Pasien</th><th>No. RM</th><th>Jam Ambil</th>
                        <th>Jam Siap</th><th>Jam Serah</th><th class="num">Waktu Tunggu</th>
                        <th class="num">Waktu Penyiapan</th><th>Status Tracking</th><th>Resep / Nama</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['tanggal']) ?></td>
                        <td><strong><?= h($r['kode_tiket']) ?></strong></td>
                        <td><?= h($layananKode[$r['kode']] ?? '-') ?></td>
                        <td><?= $r['nama_pasien'] !== '' ? h($r['nama_pasien']) : '—' ?></td>
                        <td><?= $r['no_rm'] !== '' ? h($r['no_rm']) : '—' ?></td>
                        <td><?= h($r['jam_ambil']) ?></td>
                        <td><?= h($r['jam_siap']) ?></td>
                        <td><?= h($r['jam_panggil']) ?></td>
                        <td class="num"><?= h($r['tunggu_format']) ?></td>
                        <td class="num"><?= h($r['siap_format']) ?></td>
                        <td><span class="status-pill status-diterima"><?= h($r['status_label']) ?></span></td>
                        <td><?= $r['keterangan'] !== '' ? h($r['keterangan']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>
<?php
page_end();
