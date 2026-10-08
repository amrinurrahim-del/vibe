<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if (auth_user()) {
    redirect('index.php');
}

$error = (string) ($_GET['err'] ?? '');
$info  = (string) ($_GET['ok'] ?? '');

if (is_post()) {
    $hasil = auth_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($hasil['ok']) {
        redirect('index.php?ok=' . urlencode('Selamat bertugas, ' . $hasil['username'] . '.'));
    }
    $error = (string) $hasil['error'];
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk — Antrian Apotek</title>
<link rel="stylesheet" href="assets/style.css">
<style>:root{--accent:<?= h(setting('tema_warna', '#0d9488')) ?>;}</style>
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-brand">
            <?php if (setting('logo_url') !== ''): ?>
                <img src="<?= h(setting('logo_url')) ?>" alt="Logo" class="auth-logo">
            <?php else: ?>
                <span class="auth-mark"><?= icon('tiket') ?></span>
            <?php endif; ?>
            <h1><?= h(setting('nama_apotek', 'Apotek')) ?></h1>
            <p>Sistem Antrian &amp; Tracking Obat</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="flash flash-err"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($info !== ''): ?>
            <div class="flash flash-ok"><?= h($info) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
            <label class="field">
                <span>Username</span>
                <input type="text" name="username" required autocapitalize="none"
                       pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32" placeholder="nama pengguna">
            </label>
            <label class="field">
                <span>Kata sandi</span>
                <input type="password" name="password" required placeholder="••••••">
            </label>
            <button class="btn btn-primary btn-block" type="submit"><?= icon('out') ?> Masuk</button>
        </form>

        <?php /* Pintasan ke layar display, diletakkan tepat di bawah tombol Masuk. */ ?>
        <a class="btn btn-ghost btn-block" href="display.php" target="_blank" rel="noopener">
            <?= icon('tv') ?> Buka Layar Display
        </a>

        <p class="auth-note">Lupa kata sandi? Mintalah superadmin mengubahnya di menu
            <strong>Pengaturan → Akun &amp; Keamanan</strong>.</p>
    </div>
</div>
</body>
</html>
