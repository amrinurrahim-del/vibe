/*
 * Halaman tiket (cetak.php).
 * - Tombol "Cetak Sekarang" memanggil window.print() → printer yang sedang aktif di komputer.
 * - Agar TIDAK muncul dialog cetak, browser di komputer loket perlu dijalankan dengan mode
 *   cetak senyap (Chrome/Edge: --kiosk-printing · Firefox: print.always_print_silent).
 *   Itu batasan browser, bukan kekurangan aplikasi — panduannya ada di Pengaturan → Printer.
 * - Bila mode senyap aktif, window.print() langsung mengirim ke printer tanpa dialog.
 */
(function () {
    const cfg = window.CETAK_CFG || {};
    const el = function (id) { return document.getElementById(id); };
    let sedangCetak = false;

    /** Setel rangkap yang akan dicetak (1 atau 2). */
    function setRangkap(n) {
        document.body.classList.remove('mode-cetak-1', 'mode-cetak-2');
        document.body.classList.add('mode-cetak-' + (n === 2 ? '2' : '1'));
        document.querySelectorAll('.cetak-set').forEach(function (k) {
            k.classList.toggle('is-dicetak', Number(k.dataset.rangkap) === n);
        });
        const status = el('rangkap-status');
        if (status) {
            status.textContent = 'Rangkap ' + n + ' dari ' + (cfg.rangkap === 2 ? 2 : 1);
        }
    }

    /** Cetak lewat browser (jalan lama: dialog cetak / cetak senyap kiosk-printing). */
    function cetakBrowser() {
        try {
            window.focus();
            window.print();
        } catch (e) {
            /* Sebagian browser memblokir print() bila bukan dari aksi pengguna. */
        }
    }

    /**
     * Cetak memakai AGEN CETAK (printer khusus aplikasi, tanpa dialog).
     * Bila agen tidak dapat dihubungi, otomatis kembali ke cara browser + beri tahu petugas.
     */
    function cetakLewatAgen() {
        const agen = window.CETAK_AGEN || {};
        const dasar = String(agen.url || '').replace(/\/+$/, '');
        kabar('Mengirim tiket ke printer ' + (agen.printer || '') + ' …', 'proses');
        /* Kirim satu tiket ke agen. */
        const kirimSatu = function (gambar, teks, nama) {
            const badan = JSON.stringify({
                token: agen.token || '',
                gambar: gambar || '',
                teks: teks || '',
                nama: nama,
            });
            return fetch(dasar + '/cetak', {
                method: 'POST',
                /* text/plain = permintaan sederhana (tanpa pra-permintaan CORS). */
                headers: { 'Content-Type': 'text/plain' },
                body: badan,
                cache: 'no-store',
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) {
                    /* Agen MENJAWAB (jadi agennya hidup) tetapi menolak — penyebab tersering:
                       config.php agen salah ketik, atau token/nama printer belum benar. */
                    const galat = new Error((d && d.pesan) || 'agen menolak');
                    galat.jawabanAgen = true;
                    galat.configError = !!(d && d.config_error);
                    throw galat;
                }
                return d;
            });
        };

        const nama = agen.nama || 'tiket';
        let rangkaian = kirimSatu(agen.gambar || '', agen.teks || '', nama);
        /* Mode 2 rangkap: cetakan kedua = potongan penanda resep. */
        if (Number(agen.rangkap) === 2 && (agen.gambar_potongan || agen.teks_potongan)) {
            rangkaian = rangkaian.then(function () {
                return new Promise(function (selesai) {
                    window.setTimeout(selesai, 1200);   /* beri jeda agar kertas pertama selesai */
                }).then(function () {
                    return kirimSatu(agen.gambar_potongan || '', agen.teks_potongan || '', nama + '-potongan');
                });
            });
        }
        return rangkaian.then(function (d) {
            kabar((d.pesan || ('Tiket dikirim ke printer ' + (agen.printer || '') + '.'))
                + (Number(agen.rangkap) === 2 ? '\nPotongan penanda resep juga dicetak (rangkap kedua).' : ''), 'ok');
            return true;
        }).catch(function (e) {
            if (e.configError) {
                kabar('Agen cetak AKTIF, tetapi config.php di komputer ini bermasalah:\n' + e.message
                    + '\n\nTiket dicetak lewat dialog seperti biasa.', 'err', 20000);
            } else if (e.jawabanAgen) {
                kabar('Agen cetak menolak cetakan ini (' + e.message
                    + ').\nTiket dicetak lewat dialog seperti biasa.', 'err', 16000);
            } else {
                kabar('Agen cetak tidak merespons (' + e.message + ').\nTiket dicetak lewat dialog seperti biasa — '
                    + 'pastikan "jalankan-agen-cetak.bat" berjalan di komputer ini.', 'err', 12000);
            }
            cetakBrowser();
            return false;
        });
    }

    function cetak() {
        /* Cegah dobel cetak (tombol ditekan saat cetak otomatis juga berjalan). */
        if (sedangCetak) { return; }
        sedangCetak = true;
        const agen = window.CETAK_AGEN || {};
        if (Number(agen.aktif) === 1) {
            cetakLewatAgen().then(function () {
                window.setTimeout(function () { sedangCetak = false; }, 800);
            });
            return;
        }
        cetakBrowser();
        window.setTimeout(function () { sedangCetak = false; }, 1200);
    }

    let kotakKabar = null;
    /** Kabar kecil di layar (tanpa memakai toast halaman lain). */
    function kabar(pesan, jenis, lamaMs) {
        if (!kotakKabar) {
            kotakKabar = document.createElement('div');
            kotakKabar.id = 'kabar-cetak';
            kotakKabar.className = 'kabar-cetak no-print';
            document.body.appendChild(kotakKabar);
        }
        kotakKabar.className = 'kabar-cetak no-print' + (jenis ? ' is-' + jenis : '');
        kotakKabar.textContent = pesan;
        kotakKabar.style.display = 'block';
        if (lamaMs) {
            window.setTimeout(function () {
                if (kotakKabar) { kotakKabar.style.display = 'none'; }
            }, lamaMs);
        }
    }

    /**
     * Cetak sesuai mode: 1 rangkap = sekali cetak; 2 rangkap = DUA kali cetak
     * (struk lengkap, lalu potongan 30 mm) supaya printer memotong kertas di antaranya.
     */
    function cetakSesuaiMode() {
        if (Number(cfg.rangkap) !== 2) {
            setRangkap(1);
            cetak();
            return;
        }
        if (sedangCetak) { return; }
        setRangkap(1);
        cetak();
        /* Beri jeda supaya pekerjaan cetak pertama selesai & kertas terpotong dulu. */
        window.setTimeout(function () {
            setRangkap(2);
            cetak();
            /* Setelah selesai, kembalikan tampilan ke rangkap 1 (struk utama). */
            window.setTimeout(function () { setRangkap(1); }, 2500);
        }, 2200);
    }

    function tampilkanTip() {
        const kotak = el('tip-senyap');
        if (kotak) { kotak.hidden = false; }
    }

    function sembunyikanTip(janganTampilLagi) {
        const kotak = el('tip-senyap');
        if (kotak) { kotak.hidden = true; }
        if (janganTampilLagi) {
            try { window.localStorage.setItem('ap_tip_senyap', '0'); } catch (e) {}
        }
    }

    function pasangSalinTombol() {
        document.querySelectorAll('[data-salin]').forEach(function (tombol) {
            tombol.addEventListener('click', function () {
                const target = document.querySelector(tombol.dataset.salin);
                if (!target) { return; }
                const teks = target.value !== undefined && target.value !== '' ? target.value : target.textContent;
                const beres = function () {
                    const asli = tombol.textContent;
                    tombol.textContent = 'Tersalin';
                    window.setTimeout(function () { tombol.textContent = asli; }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(teks.trim()).then(beres).catch(function () {
                        /* Fallback: pilih teksnya supaya bisa disalin manual. */
                        if (target.select) { target.select(); }
                    });
                } else if (target.select) {
                    target.select();
                    beres();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const tombol = el('btn-cetak');
        if (tombol) { tombol.addEventListener('click', cetakSesuaiMode); }
        /* Tampilkan rangkap 1 sebagai tampilan awal. */
        if (Number(cfg.rangkap) === 2) { setRangkap(1); }

        /* Cetak otomatis: dijalankan setelah halaman & QR siap. Dengan mode senyap
           browser, tiket langsung tercetak tanpa dialog maupun klik. */
        if (cfg.auto) {
            const mulai = function () { window.setTimeout(cetakSesuaiMode, 350); };
            if (document.readyState === 'complete') { mulai(); }
            else { window.addEventListener('load', mulai); }
        }

        /* Tip cara mengaktifkan cetak senyap (tidak ikut tercetak). */
        let tipDisembunyikan = false;
        try { tipDisembunyikan = window.localStorage.getItem('ap_tip_senyap') === '0'; } catch (e) {}
        if (cfg.tip && !tipDisembunyikan) { tampilkanTip(); }

        const tutup = el('tip-tutup');
        if (tutup) { tutup.addEventListener('click', function () { sembunyikanTip(false); }); }
        const jangan = el('tip-jangan');
        if (jangan) { jangan.addEventListener('click', function () { sembunyikanTip(true); }); }

        pasangSalinTombol();
    });

    /* Pintasan keyboard: Enter/C = cetak lagi (tanpa dialog bila mode senyap aktif). */
    document.addEventListener('keydown', function (ev) {
        const t = ev.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) { return; }
        if (ev.key === 'Enter') { ev.preventDefault(); cetakSesuaiMode(); }
    });
})();
