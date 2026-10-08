<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();

$hari   = tiket_hari_ini();
$kodes  = kode_daftar(true);

/* Ringkasan harian per kode (dilayani & rata-rata waktu tunggu). */
$per = [];
foreach ($kodes as $k) {
    $per[$k['kode']] = [
        'kode' => $k['kode'], 'nama' => kode_label($k), 'mode' => $k['reset_mode'], 'aktif' => (int) $k['aktif'],
        'total' => 0, 'dilayani' => 0, 'menunggu' => 0, 'siap' => 0, 'sum_tunggu' => 0, 'terakhir' => '',
    ];
}
$stat = ['total' => 0, 'dilayani' => 0, 'menunggu' => 0, 'siap' => 0, 'sum_tunggu' => 0];
foreach ($hari as $t) {
    $k = (string) $t['kode'];
    if (!isset($per[$k])) {
        $per[$k] = ['kode' => $k, 'nama' => 'Loket ' . $k, 'mode' => 'harian', 'aktif' => 1,
                    'total' => 0, 'dilayani' => 0, 'menunggu' => 0, 'siap' => 0, 'sum_tunggu' => 0, 'terakhir' => ''];
    }
    $per[$k]['total']++;
    $stat['total']++;
    if ($t['called_at'] && (string) $t['status'] === ST_DITERIMA) {
        $per[$k]['dilayani']++;
        $stat['dilayani']++;
        $dasarTunggu = !empty($t['first_called_at']) ? (string) $t['first_called_at'] : (string) $t['called_at'];
        $d = detik_antara((string) $t['created_at'], $dasarTunggu);
        $per[$k]['sum_tunggu'] += $d;
        $stat['sum_tunggu'] += $d;
    } elseif ((string) $t['status'] === ST_SIAP) {
        $per[$k]['siap']++;
        $stat['siap']++;
    } else {
        $per[$k]['menunggu']++;
        $stat['menunggu']++;
    }
    if ((string) $t['created_at'] > $per[$k]['terakhir']) {
        $per[$k]['terakhir'] = (string) $t['created_at'];
    }
}
foreach ($per as $k => $v) {
    $per[$k]['rata'] = $v['dilayani'] > 0 ? (int) round($v['sum_tunggu'] / $v['dilayani']) : 0;
}
$rataHari = $stat['dilayani'] > 0 ? (int) round($stat['sum_tunggu'] / $stat['dilayani']) : 0;

/* Antrean yang masih berjalan (belum selesai), terlama dulu. */
$aktif = array_values(array_filter($hari, static fn($t) => (string) $t['status'] !== ST_DITERIMA));
$dipanggilTerakhir = null;
$called = array_values(array_filter($hari, static fn($t) => !empty($t['called_at']) && (string) $t['status'] === ST_DITERIMA));
usort($called, static fn($a, $b) => strcmp((string) $b['called_at'], (string) $a['called_at']));
if ($called) {
    $dipanggilTerakhir = tiket_kode_ringkas($called[0]);
}

page_head('Dashboard', 'index.php');
?>
<?php flash(); ?>

<section class="hero">
    <div class="hero-main">
        <span class="pill pill-teal"><?= icon('grid') ?> Ringkasan Hari Ini</span>
        <h1>Dashboard Antrian Apotek</h1>
        <p><?= h(tgl_label(hari_ini())) ?> · jam server <strong id="jam-hero"><?= h(date('H:i:s')) ?></strong> WITA · login sebagai <strong><?= h($user['nama'] !== '' ? $user['nama'] : $user['username']) ?></strong></p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="ambil.php"><?= icon('tiket') ?> Ambil Antrian</a>
            <a class="btn btn-accent" href="panggil.php"><?= icon('mic') ?> Panggil Antrian</a>
            <a class="btn btn-ghost" href="tracking.php"><?= icon('track') ?> Update Tracking</a>
            <a class="btn btn-ghost" href="display.php" target="_blank" rel="noopener"><?= icon('tv') ?> Layar Display</a>
        </div>
    </div>
    <div class="hero-side">
        <div class="call-now">
            <span class="call-label">Sedang Dipanggil</span>
            <?php if ($dipanggilTerakhir): ?>
                <span class="call-number" id="call-number"><?= h($dipanggilTerakhir['kode_tiket']) ?></span>
                <span class="call-meta"><?= h($dipanggilTerakhir['jam_panggil']) ?> · tunggu <?= h($dipanggilTerakhir['tunggu_teks']) ?></span>
            <?php else: ?>
                <span class="call-number is-empty" id="call-number">—</span>
                <span class="call-meta">Belum ada nomor dipanggil hari ini</span>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="stats" id="stats">
    <article class="stat-card">
        <span class="stat-label">Tiket Hari Ini</span>
        <strong class="stat-value" data-stat="total"><?= (int) $stat['total'] ?></strong>
        <span class="stat-sub">semua kode</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Sudah Dilayani</span>
        <strong class="stat-value" data-stat="dilayani"><?= (int) $stat['dilayani'] ?></strong>
        <span class="stat-sub">obat sudah diterima</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Rata-rata Waktu Tunggu</span>
        <strong class="stat-value" data-stat="rata"><?= h(durasi_format($rataHari)) ?></strong>
        <span class="stat-sub">ambil tiket → diserahkan</span>
    </article>
    <article class="stat-card stat-card-siap">
        <span class="stat-label">Siap Diserahkan</span>
        <strong class="stat-value" data-stat="siap"><?= (int) $stat['siap'] ?></strong>
        <span class="stat-sub">siap dipanggil</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Masih Diproses</span>
        <strong class="stat-value" data-stat="menunggu"><?= (int) $stat['menunggu'] ?></strong>
        <span class="stat-sub">resep masuk / disiapkan</span>
    </article>
</section>

<section class="grid-2">
    <article class="card">
        <header class="card-head">
            <h2><?= icon('chart') ?> Ringkasan Harian per Kode</h2>
            <span class="card-hint">Jumlah dilayani &amp; rata-rata waktu tunggu</span>
        </header>
        <div class="table-scroll">
            <table class="table" id="tabel-ringkasan">
                <thead>
                    <tr>
                        <th>Kode</th><th>Layanan</th><th class="num">Tiket</th><th class="num">Dilayani</th>
                        <th class="num">Rata Tunggu</th><th class="num">Proses</th><th class="num">Siap</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($per as $v): ?>
                    <tr>
                        <td><span class="kode-chip"><?= h($v['kode']) ?></span></td>
                        <td>
                            <?= h($v['nama']) ?>
                            <?php if ($v['mode'] === 'bulanan'): ?><span class="tag tag-teal">bulanan</span><?php endif; ?>
                            <?php if (!$v['aktif']): ?><span class="tag">nonaktif</span><?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $v['total'] ?></td>
                        <td class="num"><?= (int) $v['dilayani'] ?></td>
                        <td class="num"><?= $v['dilayani'] > 0 ? h(durasi_format((int) $v['rata'])) : '—' ?></td>
                        <td class="num"><?= (int) $v['menunggu'] ?></td>
                        <td class="num"><?= (int) $v['siap'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="card-note">
            Kode harian (A, F, K, P, G, I) mulai dari <strong>001</strong> setiap hari.
            Kode <strong>J</strong> bersambung terus dan hanya mulai dari 001 pada tanggal <strong>1</strong> tiap bulan.
        </p>
    </article>

    <article class="card">
        <header class="card-head">
            <h2><?= icon('bell') ?> Antrean Berjalan</h2>
            <span class="card-hint" id="antrean-hint"><?= count($aktif) ?> tiket belum selesai</span>
        </header>
        <div class="list-antre" id="antrean">
            <?php if (!$aktif): ?>
                <p class="empty">Tidak ada antrean berjalan. Semua tiket hari ini sudah selesai.</p>
            <?php endif; ?>
            <?php foreach ($aktif as $t): ?>
                <?php $r = tiket_kode_ringkas($t); ?>
                <div class="antre-row">
                    <span class="antre-kode"><?= h($r['kode_tiket']) ?></span>
                    <span class="status-pill status-<?= h(strtolower(str_replace('_', '-', $r['status']))) ?>"><?= h($r['status_label']) ?></span>
                    <span class="antre-pasien"><?= h(trim((string) $t['nama_pasien']) !== '' ? $t['nama_pasien'] : '—') ?><?= trim((string) $t['no_rm']) !== '' ? ' · RM ' . h($t['no_rm']) : '' ?></span>
                    <span class="antre-waktu" data-sejak="<?= h($r['created_at']) ?>"><?= h($r['tunggu_teks']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="quick-menu">
    <?php
    $quick = [
        ['ambil.php', 'tiket', 'Ambil Antrian', 'Cetak tiket baru untuk pasien — nomor otomatis per kode.'],
        ['panggil.php', 'mic', 'Panggil Antrian', 'Panggil nomor dengan suara id-ID. Aktif saat obat siap diserahkan.'],
        ['tracking.php', 'track', 'Tracking Obat', 'Update status resep: disiapkan → siap diserahkan.'],
        ['display.php', 'tv', 'Display Antrian', 'Layar TV: nomor dipanggil + 4 kolom status tracking.'],
        ['laporan.php', 'chart', 'Laporan', 'Unduh PDF / Excel berdasarkan tanggal atau bulan.'],
        ['pengaturan.php', 'cog', 'Pengaturan', 'Logo, suara, printer, kode antrian, database & akun.'],
    ];
    foreach ($quick as [$file, $ic, $judul, $ket]):
        $ok = menu_diizinkan($user, $file);
    ?>
        <?php if ($ok): ?>
            <a class="quick-card" href="<?= h($file) ?>">
                <span class="quick-icon"><?= icon($ic) ?></span>
                <strong><?= h($judul) ?></strong>
                <span><?= h($ket) ?></span>
            </a>
        <?php else: ?>
            <span class="quick-card is-locked" title="Hanya superadmin">
                <span class="quick-icon"><?= icon('cog') ?></span>
                <strong><?= h($judul) ?></strong>
                <span>Hanya superadmin yang dapat membuka menu ini.</span>
            </span>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php
page_end('assets/dashboard.js');
