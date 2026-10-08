<?php
declare(strict_types=1);

/*
 * Autentikasi & hak akses aplikasi antrian apotek.
 *
 * Dua jenis akun (tanpa pendaftaran):
 *   - superadmin : akses penuh termasuk Pengaturan (akun, printer, database, reset).
 *   - petugas    : Ambil Antrian, Panggil Antrian, Tracking, Laporan, Dashboard.
 * Layar display (display.php) tetap dapat dibuka tanpa login karena dipasang di TV.
 *
 * Sesi disimpan di tabel `sesi` memakai cookie acak (bukan session PHP), sehingga tidak
 * bergantung pada folder penyimpanan session server. Kata sandi selalu disimpan sebagai hash.
 */

require_once __DIR__ . '/db.php';

const DEFAULT_SUPER_USER  = 'superadmin';
const DEFAULT_SUPER_PASS  = 'superadmin';
const DEFAULT_PETUGAS_USER = 'petugas';
const DEFAULT_PETUGAS_PASS = 'petugas';

const SESI_COOKIE = 'ap_sesi';
const SESI_JAM    = 10;    // masa berlaku sesi (jam), diperpanjang tiap aktivitas
const MAX_GAGAL   = 5;     // percobaan login gagal sebelum dikunci sementara
const KUNCI_DETIK = 300;   // lama penguncian (detik)

function request_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function shift_time(int $detik): string
{
    return date('Y-m-d H:i:s', time() + $detik);
}

function sesi_set_cookie(string $token, int $detik): void
{
    setcookie(SESI_COOKIE, $token, [
        'expires'  => time() + $detik,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_https(),
    ]);
    $_COOKIE[SESI_COOKIE] = $token;
}

function sesi_hapus_cookie(): void
{
    setcookie(SESI_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_https(),
    ]);
    unset($_COOKIE[SESI_COOKIE]);
}

/* ------------------------------------------------------------------ */
/* Pengguna                                                           */
/* ------------------------------------------------------------------ */

function daftar_user(): array
{
    return db()->query('SELECT id, username, nama, role, aktif, updated_at, failed_count, locked_until
                        FROM user
                        ORDER BY CASE role
                                     WHEN "superadmin" THEN 1
                                     WHEN "admin_apotik" THEN 2
                                     ELSE 3
                                 END, username')->fetchAll();
}

/** Semua peran yang sah beserta labelnya. */
function daftar_peran(): array
{
    return [
        'superadmin'   => 'Superadmin',
        'admin_apotik' => 'Admin Apotik',
        'petugas'      => 'Petugas Apotek',
    ];
}

function label_role(string $role): string
{
    $p = daftar_peran();
    return $p[$role] ?? $role;
}

/**
 * Peran yang boleh membuka halaman Pengaturan.
 * - superadmin   : seluruh isi Pengaturan
 * - admin_apotik : hanya tab Antrean & Reset dan Laporan
 * - petugas      : tidak boleh (dialihkan)
 */
function role_pengaturan(): array
{
    return ['superadmin', 'admin_apotik'];
}

/** Tab Pengaturan yang boleh dibuka sebuah peran. */
function pengaturan_tab_diizinkan(string $role, string $tab): bool
{
    if ($role === 'superadmin') {
        return true;
    }
    if ($role === 'admin_apotik') {
        return in_array($tab, ['reset', 'laporan'], true);
    }
    return false;
}

/**
 * Tindakan (POST) yang boleh dilakukan sebuah peran di halaman Pengaturan.
 * Diperiksa di sisi server, jadi menyembunyikan tab saja tidak cukup untuk melindungi data.
 */
function pengaturan_aksi_diizinkan(string $role, string $aksi): bool
{
    if ($role === 'superadmin') {
        return true;
    }
    if ($role === 'admin_apotik') {
        /* Hanya tindakan pada tab Antrean & Reset (tab Laporan tidak punya tindakan POST). */
        return in_array($aksi, ['reset_antrian', 'tiket_hapus'], true);
        /* Catatan: unggah/hapus video display dan seluruh setelan lain hanya untuk superadmin. */
    }
    return false;
}

/** Peringatan di Pengaturan bila akun masih memakai kredensial bawaan. */
function kredensial_bawaan(): array
{
    $hasil = [];
    foreach (daftar_user() as $u) {
        $st = db()->prepare('SELECT password_hash FROM user WHERE id = ?');
        $st->execute([(int) $u['id']]);
        $hash = (string) $st->fetchColumn();
        if ($u['role'] === 'superadmin' && password_verify(DEFAULT_SUPER_PASS, $hash)) {
            $hasil[] = 'Akun superadmin masih memakai kata sandi bawaan "' . DEFAULT_SUPER_PASS . '".';
        }
        if ($u['role'] === 'petugas' && password_verify(DEFAULT_PETUGAS_PASS, $hash)) {
            $hasil[] = 'Akun petugas masih memakai kata sandi bawaan "' . DEFAULT_PETUGAS_PASS . '".';
        }
    }
    return $hasil;
}

/* ------------------------------------------------------------------ */
/* Sesi                                                               */
/* ------------------------------------------------------------------ */

function auth_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;

    $token = (string) ($_COOKIE[SESI_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);

    $st = db()->prepare('SELECT s.token_hash, s.expires_at, u.id, u.username, u.nama, u.role, u.aktif
                         FROM sesi s JOIN user u ON u.id = s.user_id
                         WHERE s.token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch();
    if (!$row || (int) $row['aktif'] !== 1) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        db()->prepare('DELETE FROM sesi WHERE token_hash = ?')->execute([$hash]);
        return null;
    }

    db()->prepare('UPDATE sesi SET last_seen = ?, expires_at = ? WHERE token_hash = ?')
        ->execute([now(), shift_time(SESI_JAM * 3600), $hash]);

    $cache = [
        'id'         => (int) $row['id'],
        'username'   => (string) $row['username'],
        'nama'       => (string) $row['nama'],
        'role'       => (string) $row['role'],
        'token_hash' => $hash,
    ];
    return $cache;
}

function auth_buat_sesi(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM sesi WHERE expires_at < ?')->execute([now()]);
    db()->prepare('INSERT INTO sesi (token_hash, user_id, created_at, expires_at, last_seen) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, now(), shift_time(SESI_JAM * 3600), now()]);
    sesi_set_cookie($token, SESI_JAM * 3600);
}

function auth_login(string $username, string $password): array
{
    $username = trim($username);
    $st = db()->prepare('SELECT * FROM user WHERE lower(username) = lower(?) LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user || (int) $user['aktif'] !== 1) {
        return ['ok' => false, 'error' => 'Username atau kata sandi salah.'];
    }
    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        $sisa = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
        return ['ok' => false, 'error' => 'Akun dikunci sementara karena percobaan gagal berulang. Coba lagi ' . $sisa . ' menit lagi.'];
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        $gagal = (int) $user['failed_count'] + 1;
        if ($gagal >= MAX_GAGAL) {
            db()->prepare('UPDATE user SET failed_count = 0, locked_until = ? WHERE id = ?')
                ->execute([shift_time(KUNCI_DETIK), (int) $user['id']]);
            return ['ok' => false, 'error' => 'Terlalu banyak percobaan gagal. Akun dikunci ' . (int) ceil(KUNCI_DETIK / 60) . ' menit.'];
        }
        db()->prepare('UPDATE user SET failed_count = ? WHERE id = ?')->execute([$gagal, (int) $user['id']]);
        return ['ok' => false, 'error' => 'Username atau kata sandi salah. Sisa ' . (MAX_GAGAL - $gagal) . ' percobaan sebelum dikunci sementara.'];
    }

    db()->prepare('UPDATE user SET failed_count = 0, locked_until = NULL WHERE id = ?')->execute([(int) $user['id']]);
    auth_buat_sesi((int) $user['id']);
    return ['ok' => true, 'role' => (string) $user['role'], 'username' => (string) $user['username']];
}

function auth_logout(): void
{
    $u = auth_user();
    if ($u) {
        db()->prepare('DELETE FROM sesi WHERE token_hash = ?')->execute([$u['token_hash']]);
    }
    sesi_hapus_cookie();
}

/* ------------------------------------------------------------------ */
/* Hak akses                                                          */
/* ------------------------------------------------------------------ */

function require_login(bool $bolehPetugas = true): array
{
    $u = auth_user();
    if (!$u) {
        redirect('login.php?err=' . urlencode('Silakan masuk terlebih dahulu.'));
    }
    if (!$bolehPetugas && $u['role'] !== 'superadmin') {
        redirect('index.php?err=' . urlencode('Halaman itu hanya dapat dibuka oleh superadmin.'));
    }
    return $u;
}

function require_superadmin(): array
{
    return require_login(false);
}

/**
 * Gerbang halaman Pengaturan: superadmin (semua) atau admin_apotik (tab terbatas).
 * Peran petugas dialihkan ke Dashboard dengan pesan.
 */
function require_pengaturan(): array
{
    $u = auth_user();
    if (!$u) {
        redirect('login.php?err=' . urlencode('Silakan masuk terlebih dahulu.'));
    }
    if (!in_array((string) $u['role'], role_pengaturan(), true)) {
        redirect('index.php?err=' . urlencode('Halaman Pengaturan tidak tersedia untuk peran akun ini.'));
    }
    return $u;
}

/**
 * Ubah username / kata sandi sebuah akun.
 * $pengawas = kata sandi pengguna yang sedang login (konfirmasi wajib).
 */
function auth_ubah_kredensial(int $id, string $username, string $password, string $ulang, string $pengawas, string $nama = ''): array
{
    $me = auth_user();
    if (!$me) {
        return ['ok' => false, 'error' => 'Sesi berakhir, silakan masuk kembali.'];
    }
    $st = db()->prepare('SELECT * FROM user WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) {
        return ['ok' => false, 'error' => 'Akun tidak ditemukan.'];
    }

    /* Konfirmasi memakai kata sandi akun yang sedang login. */
    $pw = db()->prepare('SELECT password_hash FROM user WHERE id = ?');
    $pw->execute([(int) $me['id']]);
    if (!password_verify($pengawas, (string) $pw->fetchColumn())) {
        return ['ok' => false, 'error' => 'Kata sandi konfirmasi salah. Perubahan dibatalkan.'];
    }

    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._\-]{3,32}$/', $username)) {
        return ['ok' => false, 'error' => 'Username 3-32 karakter, hanya huruf, angka, titik, garis bawah, atau strip.'];
    }
    $cek = db()->prepare('SELECT COUNT(*) FROM user WHERE lower(username) = lower(?) AND id <> ?');
    $cek->execute([$username, $id]);
    if ((int) $cek->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'Username sudah dipakai akun lain.'];
    }

    $ubahPw = $password !== '';
    if ($ubahPw) {
        if (strlen($password) < 5) {
            return ['ok' => false, 'error' => 'Kata sandi minimal 5 karakter.'];
        }
        if ($password !== $ulang) {
            return ['ok' => false, 'error' => 'Ulangi kata sandi tidak sama.'];
        }
    }

    db()->prepare('UPDATE user SET username = ?, nama = ?, updated_at = ? WHERE id = ?')
        ->execute([$username, $nama, now(), $id]);
    if ($ubahPw) {
        db()->prepare('UPDATE user SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        /* Putuskan sesi lain pada akun tersebut, kecuali sesi yang sedang dipakai. */
        $hash = (string) ($me['token_hash'] ?? '');
        db()->prepare('DELETE FROM sesi WHERE user_id = ? AND token_hash <> ?')->execute([$id, $hash]);
    }
    return ['ok' => true];
}

/** Tambah akun petugas baru (khusus superadmin, dari halaman Pengaturan). */
function auth_tambah_user(string $username, string $password, string $ulang, string $role, string $nama): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._\-]{3,32}$/', $username)) {
        return ['ok' => false, 'error' => 'Username 3-32 karakter, hanya huruf, angka, titik, garis bawah, atau strip.'];
    }
    if (strlen($password) < 5) {
        return ['ok' => false, 'error' => 'Kata sandi minimal 5 karakter.'];
    }
    if ($password !== $ulang) {
        return ['ok' => false, 'error' => 'Ulangi kata sandi tidak sama.'];
    }
    $role = array_key_exists($role, daftar_peran()) ? $role : 'petugas';
    $cek = db()->prepare('SELECT COUNT(*) FROM user WHERE lower(username) = lower(?)');
    $cek->execute([$username]);
    if ((int) $cek->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'Username sudah dipakai.'];
    }
    db()->prepare('INSERT INTO user (username, nama, password_hash, role, aktif, updated_at) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$username, $nama, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
    return ['ok' => true];
}
