/*
 * Halaman lacak obat (dibuka dari QR tiket).
 * - Memantau status tiket tiap beberapa detik (sesuai pengaturan).
 * - Saat status berubah: pop-up besar di layar, notifikasi browser (jika diizinkan),
 *   suara pengumuman (id-ID), getaran, dan penanda pada judul tab.
 *
 * Catatan penting: notifikasi di sini bekerja selama halaman lacak masih terbuka
 * (tidak ada server push pada aplikasi ini), jadi halaman perlu dibiarkan terbuka.
 */
(function () {
    const AWAL = window.LACAK_AWAL || {};
    const el = function (id) { return document.getElementById(id); };

    const STATUS_LABEL = {
        RESEP_MASUK: 'Resep Masuk',
        DISIAPKAN: 'Obat Sedang Disiapkan',
        SIAP: 'Obat Siap Diserahkan',
        DITERIMA: 'Obat Sudah Diterima'
    };
    const STATUS_URUT = { RESEP_MASUK: 1, DISIAPKAN: 2, SIAP: 3, DITERIMA: 4 };

    let data = AWAL;
    let revTerakhir = AWAL.rev || '';
    let sudahDibuka = false;
    let timer = null;
    let judulAsli = document.title;

    /* ---------------- Notifikasi browser ---------------- */

    function notifDidukung() {
        return typeof window.Notification !== 'undefined';
    }

    function statusIzin() {
        if (!notifDidukung()) { return 'tidak-didukung'; }
        return Notification.permission;
    }

    function perbaruiTombolNotif() {
        const judul = el('notif-judul');
        const pesan = el('notif-pesan');
        const tombol = el('btn-notif');
        if (!tombol) { return; }

        if (!notifDidukung()) {
            if (judul) { judul.textContent = 'Pemberitahuan tidak didukung'; }
            if (pesan) { pesan.textContent = 'Browser ini tidak mendukung notifikasi. Pop-up di halaman ini tetap muncul setiap status berubah.'; }
            tombol.disabled = true;
            tombol.textContent = 'Tidak tersedia';
            return;
        }
        if (statusIzin() === 'granted') {
            if (judul) { judul.textContent = 'Pemberitahuan aktif'; }
            if (pesan) { pesan.textContent = 'Anda akan diberi tahu di layar ponsel setiap status obat berubah. Biarkan halaman ini tetap terbuka.'; }
            tombol.disabled = true;
            tombol.textContent = 'Aktif';
            tombol.classList.add('is-done');
            return;
        }
        if (statusIzin() === 'denied') {
            if (judul) { judul.textContent = 'Pemberitahuan diblokir'; }
            if (pesan) { pesan.textContent = 'Izin notifikasi diblokir di pengaturan browser. Pop-up dalam halaman ini tetap bekerja.'; }
            tombol.disabled = false;
            tombol.textContent = 'Coba Lagi';
            return;
        }
        if (judul) { judul.textContent = 'Aktifkan pemberitahuan'; }
        if (pesan) { pesan.textContent = 'Dapatkan pemberitahuan langsung di layar ponsel setiap status obat berubah. Biarkan halaman ini tetap terbuka.'; }
        tombol.disabled = false;
        tombol.textContent = 'Aktifkan';
    }

    function mintaIzin() {
        if (!notifDidukung()) {
            toast('Browser ini tidak mendukung notifikasi. Pop-up di halaman ini tetap muncul.', 'err');
            return;
        }
        if (Notification.permission === 'granted') { perbaruiTombolNotif(); return; }
        Notification.requestPermission().then(function () {
            perbaruiTombolNotif();
            if (Notification.permission === 'granted') {
                toast('Pemberitahuan diaktifkan.', 'ok');
                kirimNotifikasi('Pemberitahuan aktif', 'Kami akan memberi tahu saat status obat ' + data.kode_tiket + ' berubah.', true);
            } else {
                toast('Izin pemberitahuan belum diberikan.', 'err');
            }
        });
    }

    function kirimNotifikasi(judul, pesan, paksa) {
        if (!notifDidukung() || Notification.permission !== 'granted') { return false; }
        if (!paksa && Number(data.notif_aktif) !== 1) { return false; }
        try {
            const n = new Notification(judul, {
                body: pesan,
                icon: data.logo_url || undefined,
                badge: data.logo_url || undefined,
                tag: 'lacak-' + data.kode_tiket,
                renotify: true,
                requireInteraction: false
            });
            n.onclick = function () { window.focus(); n.close(); };
            setTimeout(function () { try { n.close(); } catch (e) {} }, 20000);
            return true;
        } catch (e) {
            return false;
        }
    }

    /* ---------------- Pop-up & suara ---------------- */

    function popup(judul, pesan, jenis) {
        const box = el('lacak-popup');
        const t = el('popup-judul');
        const p = el('popup-pesan');
        if (!box) { return; }
        if (t) { t.textContent = judul; }
        if (p) { p.textContent = pesan; }
        box.classList.remove('is-baru', 'is-selesai', 'is-kembali');
        box.hidden = false;
        void box.offsetWidth;
        box.classList.add(jenis === 'selesai' ? 'is-selesai' : (jenis === 'kembali' ? 'is-kembali' : 'is-baru'));
        /* Sorot kartu status juga. */
        const kartu = el('lacak-status');
        if (kartu) {
            kartu.classList.remove('is-sorot');
            void kartu.offsetWidth;
            kartu.classList.add('is-sorot');
        }
    }

    function toast(pesan, jenis) {
        const wrap = el('toast-wrap');
        if (!wrap) { return; }
        const div = document.createElement('div');
        div.className = 'toast ' + (jenis === 'err' ? 'toast-err' : 'toast-ok');
        div.textContent = pesan;
        wrap.appendChild(div);
        setTimeout(function () { div.classList.add('is-hilang'); }, 4000);
        setTimeout(function () { div.remove(); }, 4800);
    }

    function getar(pola) {
        if (navigator.vibrate) {
            try { navigator.vibrate(pola); } catch (e) {}
        }
    }

    function bicara(teks) {
        if (Number(data.suara_aktif) !== 1) { return; }
        if (!window.AntrianVoice || typeof window.AntrianVoice.bicara !== 'function') { return; }
        const cfg = Object.assign({}, data.suara, { template: teks, eja_digit: 0, chime: 1, ulang: 1 });
        window.AntrianVoice.muatSuara(true).then(function () {
            window.AntrianVoice.bicara(cfg, {
                kode: data.kode,
                nomor: data.nomor,
                kode_tiket: data.kode_tiket
            }, null);
        });
    }

    function tandaiJudul(teks) {
        document.title = teks;
        window.setTimeout(function () { document.title = judulAsli; }, 12000);
    }

    /* ---------------- Gambar ulang tampilan ---------------- */

    function gambar(d) {
        const nilai = el('lacak-nilai');
        if (nilai) { nilai.textContent = d.status_label; }
        const waktu = el('lacak-waktu');
        if (waktu) {
            const j = new Date();
            const p2 = function (n) { return String(n).padStart(2, '0'); };
            waktu.textContent = 'Diperbarui ' + p2(j.getHours()) + ':' + p2(j.getMinutes()) + ':' + p2(j.getSeconds());
        }
        const tunggu = el('lacak-tunggu');
        if (tunggu) { tunggu.textContent = d.tunggu_teks; }

        /* Langkah-langkah status */
        document.querySelectorAll('.lacak-step').forEach(function (li) {
            const no = Number(li.dataset.step);
            li.classList.toggle('is-done', no < d.urutan);
            li.classList.toggle('is-now', no === d.urutan);
            if (no === 4 && d.selesai) { li.classList.add('is-done'); li.classList.remove('is-now'); }
            const bullet = li.querySelector('.lacak-step-bullet');
            if (bullet) { bullet.innerHTML = no < d.urutan ? '&#10003;' : String(no); }
            const jam = li.querySelector('.lacak-step-jam');
            if (jam) {
                if (no === 1) { jam.textContent = d.jam_ambil; }
                else if (no === 3) { jam.textContent = d.jam_siap || ''; }
                else if (no === 4) { jam.textContent = d.jam_panggil || ''; }
            }
        });

        const kartu = el('lacak-status');
        if (kartu) { kartu.classList.toggle('is-selesai', !!d.selesai); }
        const boxNotif = el('lacak-notif-box');
        /* Ikut dibalikkan bila status selesai dibatalkan petugas. */
        if (boxNotif) { boxNotif.classList.toggle('is-selesai', !!d.selesai); }
    }

    /**
     * Tampilkan pemberitahuan lengkap untuk perubahan status.
     * Menangani tiga arah: MAJU (resep→disiapkan→siap→diterima), SELESAI, dan
     * MUNDUR (mis. petugas membatalkan status selesai karena pasien belum menerima obat).
     */
    function beritahu(d, sebelumnya) {
        const sebelumUrut = STATUS_URUT[sebelumnya] || 0;
        const sesudahUrut = STATUS_URUT[d.status] || 1;
        const naik = sesudahUrut > sebelumUrut;
        const turun = sesudahUrut < sebelumUrut;

        let judul, pesan, jenis, ucapan;
        if (turun) {
            judul = 'Nomor Anda dikembalikan';
            pesan = 'Tiket ' + d.kode_tiket + ': ' + d.status_label + ' — Anda akan dipanggil kembali, mohon menunggu.';
            jenis = 'kembali';
            ucapan = 'Tiket ' + d.kode_tiket + '. Status obat Anda dikembalikan ke: ' + d.status_label
                + '. Mohon menunggu, Anda akan dipanggil kembali.';
        } else if (d.selesai) {
            judul = 'Obat Anda sudah diterima';
            pesan = 'Tiket ' + d.kode_tiket + ': ' + d.status_label + '.';
            jenis = 'selesai';
            ucapan = 'Tiket ' + d.kode_tiket + '. Status obat Anda sekarang: ' + d.status_label + '. Terima kasih.';
        } else {
            judul = 'Status obat diperbarui';
            pesan = 'Tiket ' + d.kode_tiket + ': ' + d.status_label + '.';
            jenis = 'baru';
            ucapan = 'Tiket ' + d.kode_tiket + '. Status obat Anda sekarang: ' + d.status_label + '.';
        }

        popup(judul, pesan, jenis);
        kirimNotifikasi(judul, pesan, false);
        getar(turun ? [120, 80, 120, 80, 120] : (d.selesai ? [200, 100, 200, 100, 400] : [180, 80, 180]));
        tandaiJudul((turun ? '\u21BA ' : (d.selesai ? '\u2713 ' : '\u23F0 ')) + d.status_label);
        bicara(ucapan);
        if (naik || turun) { toast(judul + ' — ' + d.status_label, 'ok'); }
    }

    /* ---------------- Pemantauan ---------------- */

    function muat(pertama) {
        return fetch('api.php?action=lacak&t=' + encodeURIComponent(AWAL.kode_tiket) + '&k=' + encodeURIComponent(AWAL.token || new URLSearchParams(location.search).get('k')), { cache: 'no-store' })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (!res.body || !res.body.ok) {
                    throw new Error((res.body && res.body.error) || 'gagal memuat');
                }
                const d = res.body;
                const berubah = d.rev !== revTerakhir;
                if (berubah && sudahDibuka) {
                    beritahu(d, data.status);
                    revTerakhir = d.rev;
                } else if (!sudahDibuka) {
                    revTerakhir = d.rev;
                }
                data = d;
                gambar(d);
                perbaruiTombolNotif();
                return d;
            })
            .catch(function (e) {
                if (pertama) { toast('Gagal memuat status: ' + e.message, 'err'); }
                return null;
            });
    }

    function jadwalkan() {
        if (timer) { window.clearTimeout(timer); }
        const dasar = Math.max(3, Number(data.poll_detik) || 5);
        /*
         * Pemantauan TIDAK dihentikan walau obat sudah diterima: petugas bisa membatalkan
         * status selesai (pasien tidak ada / belum menerima obat) dan nomor dikembalikan ke
         * OBAT SIAP DISERAHKAN. Pemilik tiket harus tetap diberi tahu, jadi jeda hanya
         * diperpanjang (tidak membebani server) — bukan dimatikan.
         */
        const jeda = data.selesai ? Math.max(dasar * 3, 15) : dasar;
        timer = window.setTimeout(function () {
            muat(false).then(function () { jadwalkan(); });
        }, jeda * 1000);
    }

    function mulai() {
        /* Kode token dari URL (tidak ditampilkan di halaman demi keamanan). */
        const params = new URLSearchParams(location.search);
        AWAL.token = params.get('k') || '';
        perbaruiTombolNotif();
        gambar(data);
        sudahDibuka = true;
        jadwalkan();

        document.addEventListener('visibilitychange', function () {
            /* Kembali ke halaman → langsung periksa sekali supaya tidak menunggu
               (termasuk saat status sudah "diterima", agar pembatalan cepat terlihat). */
            if (!document.hidden) { muat(false).then(function () { jadwalkan(); }); }
        });

        window.addEventListener('focus', function () {
            muat(false).then(function () { jadwalkan(); });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const btnNotif = el('btn-notif');
        if (btnNotif) { btnNotif.addEventListener('click', mintaIzin); }
        const btnMuat = el('btn-muat');
        if (btnMuat) {
            btnMuat.addEventListener('click', function () {
                muat(false).then(function () {
                    toast('Status diperbarui.', 'ok');
                    if (!data.selesai) { jadwalkan(); }
                });
            });
        }
        const btnTes = el('btn-tes');
        if (btnTes) {
            btnTes.addEventListener('click', function () {
                const dikirim = kirimNotifikasi('Tes pemberitahuan',
                    'Beginilah bentuk pemberitahuan untuk tiket ' + data.kode_tiket + '.', true);
                if (!dikirim) {
                    toast('Notifikasi browser belum aktif — menampilkan pop-up contoh.', 'err');
                }
                popup('Tes pemberitahuan', 'Beginilah bentuk pemberitahuan saat status obat berubah.', 'baru');
                getar([150, 60, 150]);
            });
        }
        const tutup = el('popup-tutup');
        if (tutup) {
            tutup.addEventListener('click', function () {
                const box = el('lacak-popup');
                if (box) { box.hidden = true; }
            });
        }
        mulai();
    });
})();
