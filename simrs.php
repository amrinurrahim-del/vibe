<?php
declare(strict_types=1);

/*
 * Endpoint untuk AGEN SIMRS (dijalankan di jaringan rumah sakit).
 *
 * Hanya endpoint ini yang boleh dipanggil tanpa sesi login, dan WAJIB memakai header
 * X-Simrs-Token berisi token agen (lihat Pengaturan → SIMRS / Rekam Medis).
 * Arah komunikasi selalu dari jaringan RS ke aplikasi ini (tidak ada koneksi masuk ke RS).
 *
 *   GET  simrs.php?action=tugas&batas=10   → daftar permintaan No. RM yang menunggu
 *   POST simrs.php?action=jawab            → kirim data pasien (JSON atau form)
 *   GET  simrs.php?action=ping             → uji koneksi & token
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$action = (string) ($_GET['action'] ?? 'ping');

/** Token agen dibandingkan dengan aman (tidak bocor lewat waktu respons). */
function agen_token_valid(): bool
{
    $kirim = (string) ($_SERVER['HTTP_X_SIMRS_TOKEN'] ?? ($_POST['token'] ?? ''));
    $asli  = simrs_token();
    if ($asli === '' || $kirim === '') {
        return false;
    }
    return hash_equals($asli, $kirim);
}

/** Badan permintaan dalam bentuk array (mendukung JSON dan form biasa). */
function badan_json(): array
{
    $tipe = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($tipe, 'application/json') !== false) {
        $isi = file_get_contents('php://input');
        $data = json_decode((string) $isi, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

if (!agen_token_valid()) {
    json_out([
        'ok'    => false,
        'error' => 'Token agen tidak sah. Salin token terbaru dari Pengaturan → SIMRS / Rekam Medis.',
    ], 401);
}

try {
    switch ($action) {
        case 'ping':
        default:
            simrs_catat_agen((string) ($_GET['versi'] ?? ''));
            json_out([
                'ok'          => true,
                'aplikasi'    => setting('nama_apotek', 'Apotek'),
                'simrs_aktif' => simrs_aktif() ? 1 : 0,
                'server_time' => now_sql(),
                'pesan'       => 'Token agen diterima. Agen siap bertugas.',
            ]);
            break;

        case 'tugas': {
            $versi = (string) ($_GET['versi'] ?? '');
            simrs_catat_agen($versi);
            $batas = (int) ($_GET['batas'] ?? 10);
            $tugas = simrs_ambil_tugas($batas);
            json_out([
                'ok'          => true,
                'server_time' => now_sql(),
                'jumlah'      => count($tugas),
                'tugas'       => array_map(static fn($t) => [
                    'id'    => (int) $t['id'],
                    'no_rm' => (string) $t['no_rm'],
                ], $tugas),
            ]);
            break;
        }

        case 'jawab': {
            simrs_catat_agen((string) ($_GET['versi'] ?? ''));
            $data = badan_json();
            $id   = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'id permintaan wajib diisi.'], 400);
            }
            $hasil = simrs_jawab($id, $data);
            json_out([
                'ok'     => true,
                'status' => $hasil['status'],
                'pesan'  => $hasil['status'] === SIMRS_SELESAI
                    ? 'Data pasien tersimpan.'
                    : 'Permintaan ditandai gagal (data tidak ditemukan / agen melaporkan galat).',
            ]);
            break;
        }
    }
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Kesalahan server: ' . $e->getMessage()], 500);
}
