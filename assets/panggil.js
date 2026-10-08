/*
 * Halaman Panggil Antrian.
 * Tombol kode hanya aktif bila ada tiket berstatus OBAT SIAP DISERAHKAN.
 * Sekali dipanggil: status menjadi OBAT SUDAH DITERIMA + nomor disuarakan (id-ID).
 */
(function () {
    const grid = document.getElementById('panggil-grid');
    const toastWrap = document.getElementById('toast-wrap');
    let data = null;
    let drift = 0;
    /* Tidak ada penguncian global: tiap tombol dikunci sendiri saat dikirim, sehingga
       tombol lain tetap dapat dipakai walaupun pengumuman suara masih berbunyi. */

    const p2 = function (n) { return String(n).padStart(2, '0'); };

    function jamServerMs() { return Date.now() + drift; }

    function durasi(detik) {
        detik = Math.max(0, Math.floor(detik));
        const j = Math.floor(detik / 3600), m = Math.floor((detik % 3600) / 60), s = detik % 60;
        return j > 0 ? j + ':' + p2(m) + ':' + p2(s) : p2(m) + ':' + p2(s);
    }

    function parseSql(s) {
        if (!s) { return null; }
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    /**
     * Tampilkan waktu tunggu.
     * PENTING: elemen [data-sejak] akan terus dihitung oleh tik(). Untuk tiket yang waktu
     * tunggunya SUDAH BERHENTI (pernah dipanggil / dibatalkan, server mengirim beku=1),
     * jangan pasang data-sejak — kalau dipasang, angkanya terus berjalan padahal seharusnya
     * berhenti di panggilan pertama (bug yang dilaporkan pemilik).
     */
    function tungguHtml(t, kelas) {
        const kls = kelas ? ' class="' + kelas + '"' : '';
        if (Number(t.beku) === 1) {
            return '<span' + kls + ' title="Waktu tunggu berhenti di panggilan pertama">'
                + durasi(t.tunggu_detik) + '</span>';
        }
        return '<span' + kls + ' data-sejak="' + t.created_at + '">' + durasi(t.tunggu_detik) + '</span>';
    }

    /** Nama pasien + No. RM (hanya untuk layar petugas — display tidak memakainya). */
    function namaPasien(t) {
        const n = (t && t.nama_pasien) ? String(t.nama_pasien).trim() : '';
        const rm = (t && t.no_rm) ? String(t.no_rm).trim() : '';
        if (!n && !rm) { return ''; }
        return (n || 'tanpa nama') + (rm ? ' · RM ' + rm : '');
    }

    function toast(pesan, jenis) {
        if (!toastWrap) { return; }
        const div = document.createElement('div');
        div.className = 'toast ' + (jenis === 'err' ? 'toast-err' : 'toast-ok');
        div.textContent = pesan;
        toastWrap.appendChild(div);
        setTimeout(function () { div.classList.add('is-hilang'); }, 4200);
        setTimeout(function () { div.remove(); }, 5000);
    }

    function sinkronJam(serverTime) {
        const bagian = String(serverTime || '').split(':');
        if (bagian.length !== 3) { return; }
        const now = new Date();
        let beda = (Number(bagian[0]) * 3600 + Number(bagian[1]) * 60 + Number(bagian[2]))
                 - (now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds());
        if (beda > 43200) { beda -= 86400; }
        if (beda < -43200) { beda += 86400; }
        drift = beda * 1000;
    }

    function gambar() {
        if (!data) { return; }
        const dipanggil = document.getElementById('call-sekarang');
        const meta = document.getElementById('call-meta');
        const terakhir = data.panggilan_terakhir && data.panggilan_terakhir.length ? data.panggilan_terakhir[0] : null;
        if (dipanggil) { dipanggil.textContent = terakhir ? terakhir.kode_tiket : '—'; }
        if (meta) {
            const pas = terakhir ? namaPasien(terakhir) : '';
            meta.textContent = terakhir
                ? 'Dipanggil ' + terakhir.jam_panggil + (pas ? ' · ' + pas : '') + (terakhir.keterangan ? ' · ' + terakhir.keterangan : '')
                : 'Belum ada panggilan hari ini';
        }

        data.kodes.forEach(function (k) {
            const card = grid.querySelector('[data-kode="' + k.kode + '"]');
            if (!card) { return; }
            const badge = card.querySelector('[data-badge]');
            const tombol = card.querySelector('[data-panggil]');
            const info = card.querySelector('[data-info]');
            const queue = card.querySelector('[data-queue]');
            const ulang = card.querySelector('[data-ulang]');
            const stat = card.querySelector('[data-stat]');

            if (badge) {
                badge.textContent = k.siap.length;
                badge.classList.toggle('is-zero', k.siap.length === 0);
            }
            if (tombol) {
                /* Hanya bergantung pada ketersediaan tiket & status kode — TIDAK pada suara
                   yang sedang berbunyi (dulu tombol lain ikut mati selama pengumuman). */
                const sedangKirim = tombol.dataset.sedang === '1';
                tombol.disabled = k.siap.length === 0 || k.aktif !== 1 || sedangKirim;
                const label = tombol.querySelector('span');
                if (label) {
                    label.textContent = k.siap.length > 0
                        ? 'Panggil ' + k.siap[0].kode_tiket
                        : 'Belum Siap';
                }
                tombol.dataset.target = k.siap.length > 0 ? k.siap[0].id : '';
            }
            if (info) {
                const utama = info.querySelector('.panggil-next');
                if (k.siap.length > 0) {
                    const pas = namaPasien(k.siap[0]);
                    utama.innerHTML = 'Berikutnya <strong>' + k.siap[0].kode_tiket + '</strong>'
                        + (pas ? ' · <b class="nama-pasien">' + pas + '</b>' : '')
                        + ' · menunggu sejak '
                        + k.siap[0].jam_ambil + ' (' + tungguHtml(k.siap[0]) + ')'
                        + (Number(k.siap[0].beku) === 1 ? ' <em class="tunggu-berhenti">(waktu tunggu berhenti)</em>' : '');
                    utama.classList.add('is-ready');
                } else {
                    utama.textContent = 'Belum ada tiket siap diserahkan untuk kode ' + k.kode;
                    utama.classList.remove('is-ready');
                }
            }
            if (queue) {
                const sisa = k.siap.slice(1, 5);
                queue.innerHTML = sisa.length
                    ? '<span class="queue-label">Tiket siap lain:</span>' + sisa.map(function (t) {
                        return '<button class="btn btn-mini btn-queue" type="button" data-panggil-id="' + t.id + '">'
                            + t.kode_tiket + (t.nama_pasien ? ' · ' + t.nama_pasien : '')
                            + ' ' + tungguHtml(t, 'tunggu-antre') + '</button>';
                    }).join('')
                    : '';
            }
            if (ulang) {
                ulang.disabled = !k.terakhir || ulang.dataset.sedang === '1';
                ulang.dataset.kode = k.kode;
                ulang.title = k.terakhir ? 'Ulangi panggilan ' + k.terakhir.kode_tiket : 'Belum ada panggilan kode ini';
            }
            if (stat) {
                stat.textContent = k.jumlah_total + ' tiket · ' + k.dilayani + ' dilayani · rata ' + k.rata;
            }
            card.classList.toggle('is-ready', k.siap.length > 0);
        });

        gambarLog();
    }

    /** Riwayat panggilan: seluruh tiket selesai hari ini + pencarian. */
    function gambarLog() {
        const log = document.getElementById('log-panggil');
        if (!log || !data) { return; }
        const semua = data.panggilan_terakhir || [];
        const kata = (document.getElementById('cari-riwayat') || {}).value || '';
        const cari = kata.trim().toLowerCase();
        const list = cari === '' ? semua : semua.filter(function (t) {
            const teks = [t.kode_tiket, t.kode, t.nama_pasien, t.no_rm, t.keterangan, t.jam_panggil]
                .join(' ').toLowerCase();
            return teks.indexOf(cari) >= 0;
        });

        const jumlah = document.getElementById('log-jumlah');
        if (jumlah) {
            jumlah.textContent = (cari === ''
                ? semua.length + ' tiket selesai'
                : list.length + ' dari ' + semua.length + ' tiket cocok');
        }

        if (!list.length) {
            log.innerHTML = '<p class="empty">' + (cari === ''
                ? 'Belum ada panggilan hari ini.'
                : 'Tidak ada tiket yang cocok dengan pencarian "' + kata.trim() + '".') + '</p>';
            return;
        }
        log.innerHTML = list.map(function (t) {
            const pas = namaPasien(t);
            return '<div class="log-row">'
                + '<span class="log-nomor">' + t.kode_tiket + '</span>'
                + '<span class="log-info">' + (pas ? '<b>' + pas + '</b> · ' : '') + t.jam_panggil
                + ' · tunggu ' + t.tunggu_teks
                + (t.called_count > 1 ? ' · dipanggil ' + t.called_count + '×' : '') + '</span>'
                + '<button class="btn btn-mini btn-batal" type="button" data-batal="' + t.id + '"'
                + ' title="Pasien tidak ada? Batalkan status selesai supaya nomor ini bisa dipanggil lagi">'
                + 'Batalkan</button>'
                + '</div>';
        }).join('');
    }

    function tik() {
        document.querySelectorAll('[data-sejak]').forEach(function (n) {
            const mulai = parseSql(n.dataset.sejak);
            if (!mulai) { return; }
            n.textContent = durasi((jamServerMs() - mulai.getTime()) / 1000);
        });
    }

    function muat(pertama) {
        fetch('api.php?action=panggil_data', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal memuat'); }
                sinkronJam(d.server_time);
                data = d;
                gambar();
                tik();
            })
            .catch(function () {
                if (pertama) { toast('Gagal memuat data antrean. Periksa koneksi.', 'err'); }
            })
            .then(function () {
                setTimeout(function () { muat(false); }, 5000);
            });
    }

    function ucapkan(t) {
        const vt = document.getElementById('voice-text');
        if (!window.AntrianVoice || typeof window.AntrianVoice.bicara !== 'function') {
            if (vt) { vt.textContent = 'Pengumuman suara tidak tersedia di perangkat ini — nomor tetap tercatat sebagai dipanggil.'; }
            return;
        }
        if (!data) { return; }
        const cfg = Object.assign({}, data.suara, {
            loket: (document.querySelector('.loket-nama') || {}).textContent || 'loket pengambilan obat',
            /* antre: pengumuman berikutnya MENUNGGU yang sedang berbunyi (tidak memutus),
               sehingga menekan dua nomor berurutan tetap terdengar keduanya. */
            antre: true,
        });
        try {
            window.AntrianVoice.bicara(cfg, t, function (pesan) {
                if (vt) { vt.textContent = pesan; }
            }).then(function (ok) {
                if (vt && !ok) { vt.textContent = 'Suara tidak tersedia di perangkat ini — nomor tetap tercatat sebagai dipanggil.'; }
            }).catch(function () {
                if (vt) { vt.textContent = 'Pengumuman suara gagal diputar — nomor tetap tercatat sebagai dipanggil.'; }
            });
        } catch (e) {
            if (vt) { vt.textContent = 'Pengumuman suara gagal diputar — nomor tetap tercatat sebagai dipanggil.'; }
        }
    }

    /**
     * Batalkan status "OBAT SUDAH DITERIMA": tiket kembali ke OBAT SIAP DISERAHKAN
     * sehingga bisa dipanggil lagi. Waktu tunggu tetap berhenti di panggilan pertama.
     */
    function batalkan(tombol) {
        const id = Number(tombol.dataset.batal || 0);
        if (!id) { return; }
        const baris = tombol.closest('.log-row');
        const nomor = baris ? (baris.querySelector('.log-nomor') || {}).textContent : 'tiket ini';
        if (!window.confirm('Batalkan status selesai untuk ' + nomor + '?\n\n'
            + 'Tiket akan kembali ke status OBAT SIAP DISERAHKAN dan bisa dipanggil lagi. '
            + 'Waktu tunggu berhenti di panggilan pertama (tidak berjalan lagi).')) {
            return;
        }
        tombol.disabled = true;
        kirim('api.php?action=batal_selesai', { id: id }, function () {
            /* Fokuskan tombol panggil nomor itu supaya petugas bisa langsung memanggil. */
            const kartu = document.querySelector('.panggil-card.is-ready .btn-call');
            if (kartu) { kartu.focus(); }
        }, tombol);
    }

    /**
     * Kirim tindakan panggil / panggil ulang.
     * `tombol` (opsional) hanya dikunci selama permintaan berjalan — BUKAN selama pengumuman
     * suara berbunyi, supaya tombol lain tetap bisa dipakai kapan saja.
     */
    function kirim(url, body, sukses, tombol) {
        if (tombol && tombol.dataset.sedang === '1') { return; }   /* cegah kirim ganda */
        if (tombol) {
            tombol.dataset.sedang = '1';
            tombol.disabled = true;
        }
        const fd = new FormData();
        Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
        fetch(url, { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal'); }
                toast(d.pesan || 'Berhasil.', 'ok');
                /* Pengumuman dijalankan TANPA memblokir antarmuka (tidak di-await). */
                ucapkan(d.tiket);
                if (sukses) { sukses(d); }
            })
            .catch(function (e) { toast(e.message, 'err'); })
            .then(function () {
                if (tombol) {
                    tombol.dataset.sedang = '0';
                    tombol.disabled = false;
                }
                muat(false);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        /* Delegasi klik di tingkat document: tombol "Panggil" berada di #panggil-grid,
           sedangkan tombol "Batalkan" berada di daftar riwayat (#log-panggil) di luarnya. */
        document.addEventListener('click', function (ev) {
            const tombolPanggil = ev.target.closest('[data-panggil]');
            if (tombolPanggil && !tombolPanggil.disabled) {
                const id = Number(tombolPanggil.dataset.target || 0);
                if (id > 0) { kirim('api.php?action=panggil', { id: id }, null, tombolPanggil); }
                else { toast('Belum ada tiket berstatus OBAT SIAP DISERAHKAN untuk kode ini.', 'err'); }
                return;
            }
            const tombolQueue = ev.target.closest('[data-panggil-id]');
            if (tombolQueue) {
                kirim('api.php?action=panggil', { id: Number(tombolQueue.dataset.panggilId) });
                return;
            }
            const tombolUlang = ev.target.closest('[data-ulang]');
            if (tombolUlang && !tombolUlang.disabled) {
                kirim('api.php?action=panggil_ulang', { kode: tombolUlang.dataset.kode });
                return;
            }
            const tombolBatal = ev.target.closest('[data-batal]');
            if (tombolBatal) {
                batalkan(tombolBatal);
            }
        });

        /* Pencarian pada riwayat panggilan */
        const cariRiwayat = document.getElementById('cari-riwayat');
        if (cariRiwayat) { cariRiwayat.addEventListener('input', gambarLog); }
        const bersihCari = document.getElementById('bersih-cari');
        if (bersihCari) {
            bersihCari.addEventListener('click', function () {
                if (cariRiwayat) { cariRiwayat.value = ''; }
                gambarLog();
            });
        }

        const tes = document.getElementById('tes-suara') || document.getElementById('tes-suara-pengaturan');
        if (tes) {
            tes.addEventListener('click', function () {
                const vt = document.getElementById('voice-text');
                if (!window.AntrianVoice || typeof window.AntrianVoice.bicara !== 'function') {
                    const pesan = 'Perangkat/browser ini tidak mendukung pengumuman suara (Web Speech API).';
                    if (vt) { vt.textContent = pesan; } else { toast(pesan, 'err'); }
                    return;
                }
                const cfg = (data && data.suara) ? data.suara : {
                    voice: 'Google Bahasa Indonesia', lang: 'id-ID', rate: 0.95, volume: 1,
                    eja_digit: 1, chime: 1, ulang: 1,
                    template: 'Nomor antrian, kode {kode}, {nomor}. Silakan menuju loket pengambilan obat.'
                };
                window.AntrianVoice.muatSuara(true).then(function () {
                    window.AntrianVoice.bicara(cfg, { kode: 'A', nomor: 1, kode_tiket: 'A001' }, null);
                });
            });
        }

        muat(true);
        setInterval(tik, 1000);
    });
})();
