<?php
declare(strict_types=1);

/*
 * Endpoint JSON aplikasi antrian apotek.
 *
 * Publik (dipakai layar display):
 *   api.php?action=display
 * Butuh login:
 *   api.php?action=ringkasan|panggil_data|tracking_data|tiket_hari
 * Butuh login + POST:
 *   api.php?action=ambil|panggil|panggil_ulang|tracking_update
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$action = (string) ($_GET['action'] ?? 'display');

function butuh_login(): array
{
    $u = auth_user();
    if (!$u) {
        json_out(['ok' => false, 'error' => 'Perlu login untuk tindakan ini.'], 401);
    }
    return $u;
}

function butuh_post(): void
{
    if (!is_post()) {
        json_out(['ok' => false, 'error' => 'Gunakan metode POST untuk tindakan ini.'], 405);
    }
}

try {
    switch ($action) {
        /* ---------------- Halaman lacak (publik, pakai token dari QR) ---------------- */
        case 'lacak': {
            $t = tiket_by_token((string) ($_GET['t'] ?? ''), (string) ($_GET['k'] ?? ''));
            if (!$t) {
                json_out(['ok' => false, 'error' => 'Tiket tidak ditemukan. Pastikan QR/tautan berasal dari tiket Anda.'], 404);
            }
            json_out(lacak_data($t));
            break;
        }

        /* ---------------- Display (publik) ---------------- */
        case 'display':
        default:
            json_out(payload_display());
            break;

        /*
         * ---------------- Display: cek perubahan (sangat ringan) ----------------
         * Hanya mengembalikan sidik jari struktur + jam server (balasan beberapa ratus byte,
         * selesai dalam milidetik). Dipakai display untuk tahu KAPAN perlu mengambil data penuh.
         *
         * Catatan penting: cara "server menahan permintaan" (long-poll/SSE) TIDAK dipakai karena
         * runtime PHP di sini dapat melayani satu permintaan sekaligus — permintaan yang ditahan
         * akan MEMBEKUKAN aplikasi petugas (terbukti terukur: permintaan lain tertahan 6,5 detik).
         * Dengan pemeriksaan ringan ini, perubahan muncul dalam ~1 detik tanpa risiko itu.
         */
        case 'display_cek': {
            json_out([
                'ok'          => true,
                'stamp'       => display_stamp(),
                'server_time' => date('H:i:s'),
            ]);
            break;
        }

        /* ---------------- Ringkasan dashboard ---------------- */
        case 'ringkasan': {
            butuh_login();
            tiket_auto_siapkan();
            $hari = tiket_hari_ini();
            $stat = ['total' => 0, 'dilayani' => 0, 'siap' => 0, 'menunggu' => 0, 'sum' => 0];
            $aktif = [];
            $dipanggil = null;
            foreach ($hari as $t) {
                $stat['total']++;
                if ($t['called_at'] && (string) $t['status'] === ST_DITERIMA) {
                    $stat['dilayani']++;
                    $dasar = !empty($t['first_called_at']) ? (string) $t['first_called_at'] : (string) $t['called_at'];
                    $stat['sum'] += detik_antara((string) $t['created_at'], $dasar);
                    if ($dipanggil === null || strcmp((string) $t['called_at'], (string) $dipanggil['called_at']) > 0) {
                        $dipanggil = $t;
                    }
                } elseif ((string) $t['status'] === ST_SIAP) {
                    $stat['siap']++;
                    $aktif[] = tiket_kode_ringkas($t);
                } else {
                    $stat['menunggu']++;
                    $aktif[] = tiket_kode_ringkas($t);
                }
            }
            usort($aktif, static fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
            json_out([
                'ok'         => true,
                'server_time'=> date('H:i:s'),
                'stat'       => [
                    'total'    => $stat['total'],
                    'dilayani' => $stat['dilayani'],
                    'siap'     => $stat['siap'],
                    'menunggu' => $stat['menunggu'],
                    'rata'     => $stat['dilayani'] > 0 ? durasi_format((int) round($stat['sum'] / $stat['dilayani'])) : '00:00',
                ],
                'dipanggil'  => $dipanggil ? tiket_kode_ringkas($dipanggil) : null,
                'aktif'      => $aktif,
            ]);
            break;
        }

        /* ---------------- Data halaman Panggil Antrian ---------------- */
        case 'panggil_data': {
            butuh_login();
            $kodes = kode_daftar(true);
            $out   = [];
            foreach ($kodes as $k) {
                $kode  = (string) $k['kode'];
                $siap  = tiket_siap_panggil($kode);
                $hari  = tiket_hari_ini($kode);
                $menunggu = 0;
                $dilayani = 0;
                $sumTunggu = 0;
                $terakhir = null;
                foreach ($hari as $t) {
                    /* Hanya tiket yang benar-benar sudah diserahkan (bukan yang dibatalkan). */
                    if ($t['called_at'] && (string) $t['status'] === ST_DITERIMA) {
                        $dilayani++;
                        $dasarTunggu = !empty($t['first_called_at']) ? (string) $t['first_called_at'] : (string) $t['called_at'];
                        $sumTunggu += detik_antara((string) $t['created_at'], $dasarTunggu);
                        if ($terakhir === null || strcmp((string) $t['called_at'], (string) $terakhir['called_at']) > 0) {
                            $terakhir = $t;
                        }
                    } else {
                        $menunggu++;
                    }
                }
                $out[] = [
                    'kode'        => $kode,
                    'nama'        => kode_label($k),
                    'aktif'       => (int) $k['aktif'],
                    'mode'        => (string) $k['reset_mode'],
                    'warna'       => (string) $k['warna'],
                    'siap'        => array_map('tiket_kode_ringkas', $siap),
                    'jumlah_total'=> count($hari),
                    'dilayani'    => $dilayani,
                    'menunggu'    => $menunggu,
                    'rata'        => $dilayani > 0 ? durasi_format((int) round($sumTunggu / $dilayani)) : '00:00',
                    'terakhir'    => $terakhir ? tiket_kode_ringkas($terakhir) : null,
                ];
            }
            json_out([
                'ok'          => true,
                'server_time' => date('H:i:s'),
                'tanggal'     => hari_ini(),
                'tanggal_label' => tgl_label(hari_ini()),
                'kodes'       => $out,
                /* SEMUA tiket yang sudah dipanggil hari ini (bukan hanya beberapa terakhir),
                   supaya petugas bisa membatalkan tiket mana pun lewat daftar riwayat. */
                'panggilan_terakhir' => panggilan_terakhir(500),
                /* Setelan suara diambil dari helper suara_cfg() supaya panggilan antrian dan
                   pengumuman bebas selalu memakai suara/pengaturan yang sama. */
                'suara'       => suara_cfg(),
            ]);
            break;
        }

        /* ---------------- Data halaman Tracking ---------------- */
        case 'tracking_data': {
            butuh_login();
            tiket_auto_siapkan();
            $hari = tiket_hari_ini();
            $groups = [ST_RESEP => [], ST_SIAPKAN => [], ST_SIAP => [], ST_DITERIMA => []];
            foreach ($hari as $t) {
                $st = (string) $t['status'];
                if (!isset($groups[$st])) {
                    continue;
                }
                $r = tiket_kode_ringkas($t);
                $r['siap_detik'] = $t['siap_at'] ? detik_antara((string) $t['created_at'], (string) $t['siap_at']) : null;
                $r['siap_teks']  = $r['siap_detik'] === null ? '-' : durasi_format((int) $r['siap_detik']);
                $groups[$st][] = $r;
            }
            usort($groups[ST_DITERIMA], static fn($a, $b) => strcmp((string) $b['jam_panggil'], (string) $a['jam_panggil']));
            json_out([
                'ok'          => true,
                'server_time' => date('H:i:s'),
                'groups'      => $groups,
                /* Info pindah otomatis ke "Sedang Disiapkan" (dipakai halaman Tracking untuk
                   menampilkan sisa waktu; tombol manualnya tetap ada). */
                'auto_siapkan' => [
                    'aktif' => auto_siapkan_aktif() ? 1 : 0,
                    'menit' => auto_siapkan_menit(),
                ],
                'jumlah'      => [
                    'total'     => count($hari),
                    ST_RESEP    => count($groups[ST_RESEP]),
                    ST_SIAPKAN  => count($groups[ST_SIAPKAN]),
                    ST_SIAP     => count($groups[ST_SIAP]),
                    ST_DITERIMA => count($groups[ST_DITERIMA]),
                ],
            ]);
            break;
        }

        /* ---------------- Ambil tiket baru (sekalian cetak) ---------------- */
        case 'ambil': {
            $u = butuh_login();
            butuh_post();
            $kode = strtoupper(trim((string) ($_POST['kode'] ?? '')));
            $ket  = trim((string) ($_POST['keterangan'] ?? ''));
            if ($kode === '') {
                json_out(['ok' => false, 'error' => 'Pilih kode antrian terlebih dahulu.'], 400);
            }
            $t = tiket_ambil($kode, $ket, (string) $u['username']);
            json_out([
                'ok'      => true,
                'tiket'   => tiket_kode_ringkas($t),
                'cetak'   => 'cetak.php?id=' . (int) $t['id'],
                'pesan'   => 'Tiket ' . $t['kode_tiket'] . ' berhasil dibuat. Status awal: ' . STATUS_LABEL[ST_RESEP] . '.',
            ]);
            break;
        }

        /* ---------------- Panggil tiket ---------------- */
        case 'panggil': {
            $u = butuh_login();
            butuh_post();
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'Tiket yang dipanggil tidak jelas.'], 400);
            }
            $t = tiket_panggil($id, (string) $u['username']);
            json_out([
                'ok'    => true,
                'tiket' => tiket_kode_ringkas($t),
                'pesan' => 'Nomor ' . $t['kode_tiket'] . ' dipanggil. Status: ' . STATUS_LABEL[ST_DITERIMA] . '.',
            ]);
            break;
        }

        /* ---------------- Batalkan status selesai (pasien tidak ada saat dipanggil) ---------------- */
        case 'batal_selesai': {
            $u = butuh_login();
            butuh_post();
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'Tiket yang dibatalkan tidak jelas.'], 400);
            }
            $t = tiket_batal_selesai($id, (string) $u['username']);
            json_out([
                'ok'    => true,
                'tiket' => tiket_kode_ringkas($t),
                'pesan' => 'Status selesai ' . $t['kode_tiket'] . ' dibatalkan — kembali ke '
                    . STATUS_LABEL[ST_SIAP] . ' dan siap dipanggil lagi. Waktu tunggu berhenti di panggilan pertama.',
            ]);
            break;
        }

        /* ---------------- Panggil ulang nomor terakhir ---------------- */
        case 'panggil_ulang': {
            $u = butuh_login();
            butuh_post();
            $kode = strtoupper(trim((string) ($_POST['kode'] ?? '')));
            $t = tiket_panggil_ulang($kode, (string) $u['username']);
            json_out([
                'ok'    => true,
                'tiket' => tiket_kode_ringkas($t),
                'pesan' => 'Panggilan ' . $t['kode_tiket'] . ' diulang.',
            ]);
            break;
        }

        /* ---------------- Pengumuman suara bebas (tidak mengubah status tiket) ----------------
           Dipakai dialog "Pengumuman" di halaman Panggil Antrian: petugas menulis teks bebas,
           diucapkan dengan suara yang sama seperti panggilan, dan teksnya bisa disimpan
           sebagai template untuk dipakai ulang. */
        case 'pengumuman_data': {
            butuh_login();
            json_out([
                'ok'            => true,
                'suara'         => suara_cfg(),
                'template'      => pengumuman_template_daftar(),
                'maks_karakter' => PENGUMUMAN_MAKS_KARAKTER,
                'maks_template' => PENGUMUMAN_MAKS_TEMPLATE,
            ]);
            break;
        }

        case 'pengumuman_simpan': {
            $u = butuh_login();
            butuh_post();
            $hasil = pengumuman_template_simpan(
                (string) ($_POST['nama'] ?? ''),
                (string) ($_POST['teks'] ?? ''),
                (string) $u['username']
            );
            if (!$hasil['ok']) {
                json_out(['ok' => false, 'error' => $hasil['pesan']], 400);
            }
            json_out([
                'ok'       => true,
                'pesan'    => $hasil['pesan'],
                'template' => pengumuman_template_daftar(),
            ]);
            break;
        }

        case 'pengumuman_hapus': {
            butuh_login();
            butuh_post();
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'Template yang dihapus tidak jelas.'], 400);
            }
            if (!pengumuman_template_hapus($id)) {
                json_out(['ok' => false, 'error' => 'Template tidak ditemukan (mungkin sudah dihapus).'], 404);
            }
            log_admin('Hapus template pengumuman', 'Template pengumuman suara id ' . $id . ' dihapus');
            json_out([
                'ok'       => true,
                'pesan'    => 'Template pengumuman dihapus.',
                'template' => pengumuman_template_daftar(),
            ]);
            break;
        }

        /* ---------------- Cari pasien di SIMRS (untuk petugas, wajib login) ----------------
           Metode REST: jawaban langsung di permintaan ini (aplikasi memanggil API SIMGOS).
           Metode agen (lama): permintaan dicatat lalu halaman memantau jawabannya. */
        case 'cari_pasien': {
            $u = butuh_login();
            butuh_post();
            if (!simrs_aktif()) {
                json_out(['ok' => false, 'error' => 'Integrasi SIMRS belum dinyalakan di Pengaturan → SIMRS / Rekam Medis.'], 400);
            }
            $noRm = trim((string) ($_POST['no_rm'] ?? ''));
            if ($noRm === '') {
                json_out(['ok' => false, 'error' => 'Isi nomor rekam medis terlebih dahulu.'], 400);
            }
            $hasil = simrs_cari_pasien($noRm, (string) $u['username']);

            if (!empty($hasil['selesai'])) {
                /* Metode REST — hasil sudah ada. Petugas tetap boleh mengisi nama manual
                   bila pencarian gagal (antrean tidak boleh macet). */
                json_out([
                    'ok'       => true,
                    'mode'     => 'rest',
                    'selesai'  => true,
                    'ditemukan'=> $hasil['ok'] ? 1 : 0,
                    'no_rm'    => $noRm,
                    'nama'     => (string) $hasil['nama'],
                    'agen_online' => true,
                    'pesan'    => (string) $hasil['pesan'],
                ]);
            }

            json_out([
                'ok'       => true,
                'mode'     => 'agen',
                'selesai'  => false,
                'id'       => (int) $hasil['id'],
                'no_rm'    => $noRm,
                'agen_online' => simrs_agen_online(),
                'pesan'    => simrs_agen_online()
                    ? 'Permintaan dikirim ke SIMRS. Menunggu jawaban agen…'
                    : 'Agen SIMRS belum terhubung — pastikan program agen di jaringan RS berjalan.',
            ]);
            break;
        }

        /* ---------------- Status pencarian pasien (dipolling halaman ambil antrian) ---------------- */
        case 'cari_status': {
            butuh_login();
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'id permintaan tidak valid.'], 400);
            }
            json_out(simrs_status($id));
            break;
        }

        /* ---------------- Uji koneksi REST SIMGOS (satu jalur per permintaan) ----------------
           Dipakai tombol "Uji & Deteksi" di Pengaturan. Sengaja SATU jalur per permintaan
           supaya tidak ada permintaan yang menggantung lama (runtime PHP di sini melayani
           satu permintaan sekaligus); halaman yang mengulanginya satu per satu. */
        case 'simrs_uji': {
            $u = butuh_login();
            butuh_post();
            if ((string) $u['role'] !== 'superadmin') {
                json_out(['ok' => false, 'error' => 'Hanya superadmin yang dapat menguji koneksi SIMRS.'], 403);
            }
            $rm    = trim((string) ($_POST['rm'] ?? ''));
            $jalur = (string) ($_POST['jalur'] ?? '');
            $dasar = (string) ($_POST['dasar'] ?? '');
            if ($rm === '') { $rm = '1'; }
            $hasil = simrs_rest_uji($rm, $jalur, $dasar);
            /* Simpan hasil uji jalur dari Pengaturan sebagai catatan terakhir. */
            if ($jalur === '') {
                set_setting('simrs_rest_uji', json_encode([
                    'waktu' => now_sql(),
                    'url'   => $hasil['url'],
                    'ok'    => $hasil['ok'] ? 1 : 0,
                    'nama'  => $hasil['nama'],
                    'kunci' => $hasil['kunci'],
                ], JSON_UNESCAPED_UNICODE));
            }
            json_out(['ok' => true, 'uji' => $hasil, 'metode_http' => simrs_rest_http()]);
            break;
        }

        /*
         * ---------------- Unggah video berpotongan (chunked) ----------------
         * Dipakai halaman Pengaturan: berkas besar dipecah di browser menjadi potongan
         * (mis. 8 MB) karena batas unggah PHP dikunci platform. Potongan digabungkan menjadi
         * satu berkas sementara, diteruskan ke penyimpanan media, lalu berkas sementara dihapus.
         */
        case 'video_chunk': {
            $u = butuh_login();
            butuh_post();
            if ((string) $u['role'] !== 'superadmin') {
                json_out(['ok' => false, 'error' => 'Hanya superadmin yang boleh mengunggah video.'], 403);
            }
            /*
             * Parameter dibaca dari QUERY ($_GET) karena badan permintaan dipakai murni untuk
             * isi berkas (application/octet-stream) — pada tipe itu PHP tidak mengisi $_POST.
             */
            $id    = (string) ($_GET['upload_id'] ?? $_POST['upload_id'] ?? '');
            $idx   = (int) ($_GET['idx'] ?? $_POST['idx'] ?? 0);
            $total = (int) ($_GET['total'] ?? $_POST['total'] ?? 1);
            $nama  = trim((string) ($_GET['nama'] ?? $_POST['nama'] ?? ''));
            $ukuranPotongan = (int) ($_GET['chunk_size'] ?? $_POST['chunk_size'] ?? 0);
            $path  = video_tmp_path($id);
            if ($path === null || $nama === '' || $total < 1 || $idx < 0 || $idx >= $total) {
                json_out(['ok' => false, 'error' => 'Permintaan unggahan tidak lengkap.'], 400);
            }
            $ext = strtolower((string) pathinfo($nama, PATHINFO_EXTENSION));
            if (!in_array($ext, VIDEO_EXT_OK, true)) {
                json_out(['ok' => false, 'error' => 'Format harus ' . strtoupper(implode('/', VIDEO_EXT_OK)) . '.'], 400);
            }
            if (count(video_daftar()) >= VIDEO_MAKS) {
                json_out(['ok' => false, 'error' => 'Maksimal ' . VIDEO_MAKS . ' video. Hapus salah satu dahulu.'], 400);
            }

            /* Bersihkan sisa unggahan lama (tidak menumpuk di penyimpanan aplikasi). */
            video_tmp_bersihkan(2);

            /* Potongan dikirim sebagai badan permintaan mentah. */
            $bagian = file_get_contents('php://input');
            if ($bagian === false || $bagian === '') {
                json_out(['ok' => false, 'error' => 'Potongan berkas kosong.'], 400);
            }
            /* Potongan harus berurutan supaya hasil gabungan tidak rusak. */
            $ukuranAda = is_file($path) ? (int) filesize($path) : 0;
            $info = video_tmp_info($id);
            if ($idx === 0 && $ukuranAda > 0) {
                /* Unggahan baru dengan id yang sama (ulang dari awal) → mulai bersih. */
                video_tmp_hapus($id);
                $ukuranAda = 0;
            }
            $harap = $idx * $ukuranPotongan;
            if ($ukuranPotongan > 0 && $ukuranAda !== $harap) {
                json_out([
                    'ok'    => false,
                    'error' => 'Urutan potongan tidak sesuai (server punya ' . $ukuranAda . ' byte, seharusnya ' . $harap . '). Ulangi unggahan.',
                ], 409);
            }
            $tulis = @file_put_contents($path, $bagian, FILE_APPEND);
            if ($tulis === false) {
                json_out(['ok' => false, 'error' => 'Gagal menulis berkas sementara di server.'], 500);
            }
            if ($idx === 0) {
                video_tmp_simpan_info($id, ['id' => $id, 'nama' => $nama, 'ukuran' => 0]);
            }

            /* Belum potongan terakhir → minta potongan berikutnya. */
            if ($idx < $total - 1) {
                json_out([
                    'ok'      => true,
                    'selesai' => false,
                    'diterima'=> (int) filesize($path),
                    'pesan'   => 'Potongan ' . ($idx + 1) . ' dari ' . $total . ' tersimpan.',
                ]);
            }

            /* Potongan terakhir → kirim ke penyimpanan media. */
            $ukuran = (int) filesize($path);
            $kirim = media_upload($path, 'panel-video-' . date('Ymd-His') . '-' . $nama);
            video_tmp_hapus($id);
            if (!$kirim['ok']) {
                json_out(['ok' => false, 'error' => 'Gagal menyimpan video: ' . $kirim['error']], 400);
            }
            $daftar = video_daftar();
            $daftar[] = [
                'url'    => (string) $kirim['url'],
                'nama'   => $nama,
                'ukuran' => $ukuran,
                'waktu'  => now_sql(),
            ];
            video_simpan($daftar);
            log_admin('Unggah video display', $nama . ' (' . ukuran_teks($ukuran) . ')');
            json_out([
                'ok'      => true,
                'selesai' => true,
                'ukuran'  => $ukuran,
                'ukuran_teks' => ukuran_teks($ukuran),
                'jumlah'  => count(video_daftar()),
                'pesan'   => 'Video "' . $nama . '" (' . ukuran_teks($ukuran) . ') tersimpan.',
            ]);
            break;
        }

        /* ---------------- Update tracking ---------------- */
        case 'tracking_update': {
            $u = butuh_login();
            butuh_post();
            $id   = (int) ($_POST['id'] ?? 0);
            $arah = (string) ($_POST['arah'] ?? 'maju');
            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'Tiket tidak jelas.'], 400);
            }
            $t = tiket_ubah_status($id, $arah === 'mundur' ? 'mundur' : 'maju', (string) $u['username']);
            json_out([
                'ok'    => true,
                'tiket' => tiket_kode_ringkas($t),
                'pesan' => 'Status ' . $t['kode_tiket'] . ' kini ' . STATUS_LABEL[(string) $t['status']] . '.',
            ]);
            break;
        }
    }
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
}
