/*
 * Tombol "Display Monitor 2" pada halaman Panggil Antrian.
 *
 * Fungsi: mendeteksi apakah komputer punya LEBIH DARI SATU layar (mode extend, bukan mirror),
 * lalu membuka layar display pada monitor kedua dalam jendela browser baru yang diisi penuh
 * (sedekat mungkin dengan "maksimize") dan memintanya masuk mode layar penuh.
 *
 * Catatan penting soal keterbatasan browser (dijelaskan juga lewat notifikasi):
 *  - Deteksi layar memakai Window Management API (Chrome/Edge). Sekali pakai, browser akan
 *    meminta izin "melihat layar Anda" — bila izin ditolak, monitor 2 tidak bisa dideteksi.
 *  - Browser tidak menyediakan perintah "maximize" milik sistem operasi; yang bisa dilakukan adalah
 *    membuka jendela tepat seukuran area kerja monitor kedua (hasilnya tampak seperti maximize).
 *  - Mode layar penuh tetap perlu persetujuan browser; kalau ditolak, layar display menampilkan
 *    petunjuk untuk menekan tombol Layar Penuh (ikon TV) sekali.
 *  - Tidak ada bagian form panggil antrian yang diubah oleh skrip ini.
 */
(function () {
    const tombol = document.getElementById('btn-layar2');
    if (!tombol) { return; }

    /** Notifikasi kecil di pojok (memakai wadah toast milik halaman). */
    function kabar(pesan, jenis, lamaMs) {
        const wrap = document.getElementById('toast-wrap');
        if (!wrap) {
            window.alert(pesan);
            return;
        }
        const div = document.createElement('div');
        div.className = 'toast ' + (jenis === 'err' ? 'toast-err' : (jenis === 'info' ? 'toast-info' : 'toast-ok'));
        div.style.maxWidth = '420px';
        div.style.whiteSpace = 'pre-line';
        div.textContent = pesan;
        wrap.appendChild(div);
        setTimeout(function () { div.classList.add('is-hilang'); }, lamaMs || 6000);
        setTimeout(function () { div.remove(); }, (lamaMs || 6000) + 800);
    }

    function sedangSibuk(sibuk) {
        tombol.disabled = sibuk;
        tombol.dataset.sedang = sibuk ? '1' : '0';
    }

    /** "Sidik jari" sebuah layar: dipakai membandingkan layar tanpa bergantung pada objek yang sama. */
    function sidikLayar(l) {
        if (!l) { return ''; }
        return [
            l.label || '',
            l.availLeft !== undefined ? l.availLeft : l.left,
            l.availTop !== undefined ? l.availTop : l.top,
            l.availWidth !== undefined ? l.availWidth : l.width,
            l.availHeight !== undefined ? l.availHeight : l.height,
        ].join('|');
    }

    /**
     * Pilih monitor KEDUA (bukan layar yang sedang dipakai) dari daftar layar.
     * Tidak membandingkan referensi objek: browser boleh mengembalikan objek berbeda untuk layar
     * yang sama (pernah membuat pilihan jatuh ke monitor 1 — nomor layar jadi salah).
     */
    function pilihLayarKedua(detail, daftar) {
        const sekarang = detail && detail.currentScreen ? detail.currentScreen : null;
        const sidikSekarang = sidikLayar(sekarang);
        /* 1) Layar yang sidiknya berbeda dari layar sekarang (paling aman). */
        let kedua = daftar.find(function (s) { return sidikLayar(s) !== sidikSekarang; });
        /* 2) Bila semua sidik sama (mis. dua monitor kembar): pakai referensi objek. */
        if (!kedua && sekarang) { kedua = daftar.find(function (s) { return s !== sekarang; }); }
        /* 3) Cadangan terakhir: layar yang bukan utama. */
        if (!kedua) { kedua = daftar.find(function (s) { return !s.isPrimary; }); }
        return kedua || null;
    }

    /**
     * Deteksi jumlah layar.
     * Mengembalikan {ok, sumber, layar, jumlah, alasan, pesan}
     *   ok  = true bila monitor kedua terdeteksi (layar = info monitor kedua bila tersedia)
     */
    function deteksiLayar() {
        /* 1) Cara paling akurat: Window Management API (Chrome/Edge terbaru). */
        if (typeof window.getScreenDetails === 'function') {
            return window.getScreenDetails().then(function (detail) {
                const daftar = (detail && detail.screens) ? Array.prototype.slice.call(detail.screens) : [];
                if (daftar.length >= 2) {
                    const kedua = pilihLayarKedua(detail, daftar);
                    return { ok: true, sumber: 'screen-details', layar: kedua, jumlah: daftar.length };
                }
                return { ok: false, sumber: 'screen-details', jumlah: daftar.length, alasan: 'jumlah' };
            }).catch(function (e) {
                const pesan = String((e && e.message) || e || '');
                return {
                    ok: false, sumber: 'screen-details', jumlah: 0,
                    alasan: /denied|permission/i.test(pesan) ? 'izin' : 'gagal',
                    pesan: pesan,
                };
            });
        }

        /* 2) Cadangan: screen.isExtended (hanya memberi tahu ADA/TIDAK, tanpa koordinat). */
        if (typeof window.screen !== 'undefined' && typeof window.screen.isExtended === 'boolean') {
            return Promise.resolve({
                ok: window.screen.isExtended === true,
                sumber: 'is-extended',
                layar: null,
                jumlah: window.screen.isExtended ? 2 : 1,
                alasan: window.screen.isExtended ? '' : 'jumlah',
            });
        }

        /* 3) Browser tidak menyediakan API deteksi (mis. Firefox/Safari). */
        return Promise.resolve({ ok: false, sumber: 'tidak-didukung', jumlah: 0, alasan: 'tidak-didukung' });
    }

    /** Pesan notifikasi sesuai hasil deteksi. */
    function pesanGagal(hasil) {
        if (hasil.alasan === 'jumlah') {
            return 'Hanya satu monitor yang terdeteksi.\n'
                + 'Pastikan layar kedua tersambung dan diatur sebagai "Extend" (Perluas) di Windows — '
                + 'bukan "Duplicate"/"Mirror". Setelah itu klik tombol ini lagi.';
        }
        if (hasil.alasan === 'izin') {
            return 'Izin mengakses daftar layar ditolak browser.\n'
                + 'Klik ikon di samping alamat situs → izinkan "Window management"/"Lihat layar", '
                + 'lalu tekan tombol ini lagi.';
        }
        if (hasil.alasan === 'tidak-didukung') {
            return 'Browser ini tidak mendukung deteksi monitor.\n'
                + 'Gunakan Google Chrome atau Microsoft Edge. Sebagai gantinya: buka layar display '
                + 'di jendela baru (tombol "Display" di menu), lalu geser jendela itu ke monitor kedua '
                + 'dan tekan Layar Penuh.';
        }
        return 'Monitor kedua tidak terdeteksi'
            + (hasil.pesan ? ' (' + hasil.pesan + ')' : '')
            + '.\nPastikan layar kedua tersambung dan diatur sebagai "Extend" (Perluas).';
    }

    /** Buka display di monitor kedua. */
    function bukaDisplay(hasil) {
        const url = new URL('display.php', window.location.href);
        url.searchParams.set('fs', '1');          /* display mencoba masuk layar penuh */
        url.searchParams.set('sumber', 'monitor2');

        let fitur = 'menubar=no,toolbar=no,location=no,status=no,scrollbars=no,resizable=yes';
        let kiri = null, atas = null, lebar = null, tinggi = null;
        if (hasil.layar) {
            kiri   = Math.round(hasil.layar.availLeft  !== undefined ? hasil.layar.availLeft  : hasil.layar.left);
            atas   = Math.round(hasil.layar.availTop   !== undefined ? hasil.layar.availTop   : hasil.layar.top);
            lebar  = Math.round(hasil.layar.availWidth !== undefined ? hasil.layar.availWidth : hasil.layar.width);
            tinggi = Math.round(hasil.layar.availHeight !== undefined ? hasil.layar.availHeight : hasil.layar.height);
            fitur += ',left=' + kiri + ',top=' + atas + ',width=' + lebar + ',height=' + tinggi;
        }

        /* Nama jendela tetap: menekan tombol berulang kali memakai jendela display yang sama
           (tidak menumpuk banyak jendela baru). */
        const popup = window.open(url.toString(), 'display_monitor2', fitur);
        if (!popup || popup.closed) {
            kabar('Jendela display diblokir browser.\nIzinkan "pop-up" untuk situs ini, lalu coba lagi.', 'err');
            return false;
        }

        /* Isi penuh area kerja monitor kedua (browser tidak punya perintah "maximize" OS). */
        try {
            if (kiri !== null) { popup.moveTo(kiri, atas); popup.resizeTo(lebar, tinggi); }
            popup.focus();
        } catch (e) {
            /* Sebagian browser membatasi pemindahan jendela; jendela tetap terbuka di posisi yang diminta. */
        }

        kabar('Layar display dibuka di monitor kedua'
            + (lebar ? ' (' + lebar + '×' + tinggi + ')' : '')
            + '.\nBila belum penuh, tekan tombol Layar Penuh (ikon TV) sekali di jendela itu.', 'ok', 8000);
        return true;
    }

    tombol.addEventListener('click', function () {
        if (tombol.dataset.sedang === '1') { return; }
        sedangSibuk(true);
        deteksiLayar().then(function (hasil) {
            if (!hasil.ok) {
                kabar(pesanGagal(hasil), 'err', 12000);
                return;
            }
            bukaDisplay(hasil);
        }).catch(function (e) {
            kabar('Gagal memeriksa layar: ' + ((e && e.message) || e), 'err', 10000);
        }).then(function () {
            sedangSibuk(false);
        });
    });
})();
