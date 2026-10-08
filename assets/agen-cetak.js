/*
 * Halaman Pengaturan → Printer: bagian "Agen Cetak".
 * Semua permintaan ke agen dijalankan DARI BROWSER komputer ini (agen ada di 127.0.0.1
 * komputer apotek, server aplikasi tidak bisa menjangkaunya).
 */
(function () {
    const urlInput = document.getElementById('agen-url');
    const printerSelect = document.getElementById('agen-printer');
    const status = document.getElementById('agen-status');
    const btnUji = document.getElementById('agen-uji');
    const btnTes = document.getElementById('agen-tes');
    const btnDaftar = document.getElementById('agen-daftar');
    if (!urlInput || !status) { return; }

    const token = (document.getElementById('agen-token') || {}).value || '';

    function tulis(pesan, jenis) {
        status.textContent = pesan;
        status.className = 'agen-status' + (jenis ? ' is-' + jenis : '');
    }

    function alamat() {
        return (urlInput.value || '').replace(/\/+$/, '');
    }

    /** Tanya status agen (daftar printer, printer terpilih, mode). */
    function periksa(tampilkanError) {
        const dasar = alamat();
        if (!dasar) { tulis('Alamat agen belum diisi.', 'err'); return Promise.resolve(null); }
        tulis('Menghubungi agen di ' + dasar + ' …', 'proses');
        return fetch(dasar + '/status', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    /* Agen MENJAWAB → berarti agennya memang hidup. Yang bermasalah isinya
                       (paling sering config.php salah ketik) — tampilkan sebabnya. */
                    const galat = new Error((d && d.pesan) || 'jawaban agen tidak dikenal');
                    galat.jawabanAgen = true;
                    galat.configError = !!(d && d.config_error);
                    galat.berkas = (d && d.berkas) || '';
                    throw galat;
                }
                const jumlah = (d.daftar_printer || []).length;
                let pesan = 'Agen aktif (versi ' + d.versi + ', mode ' + d.mode + ')';
                if (d.kering) { pesan += ' · MODE UJI (tidak mencetak)'; }
                pesan += '\nPrinter disetel: ' + (d.printer || '(belum diisi di config.php agen)');
                if (d.windows) {
                    pesan += '\nPrinter terpasang di komputer: ' + (jumlah ? d.daftar_printer.join(', ') : '(tidak terbaca)');
                    if (d.printer && !d.printer_terpasang) {
                        pesan += '\nPERHATIAN: nama printer di config.php agen tidak cocok dengan daftar di atas.';
                    }
                } else if (d.catatan) {
                    pesan += '\n' + d.catatan;
                }
                tulis(pesan, d.printer && (d.printer_terpasang || !d.windows) ? 'ok' : 'warn');
                /* Isi pilihan printer bila belum ada. */
                isiPilihan(d.daftar_printer || [], d.printer || '');
                return d;
            })
            .catch(function (e) {
                if (e.configError) {
                    tulis('Agen SUDAH berjalan, tetapi berkas config.php di komputer ini bermasalah.'
                        + (e.berkas ? '\nBerkas: ' + e.berkas : '')
                        + '\n\n' + e.message, 'err');
                } else if (e.jawabanAgen) {
                    tulis('Agen menjawab, tetapi menolak permintaan ini.\n' + e.message, 'err');
                } else {
                    tulis('Tidak dapat menghubungi agen.\nPastikan "jalankan-agen-cetak.bat" sedang berjalan di komputer ini '
                        + '(' + alamat() + ').\nPesan: ' + e.message, 'err');
                }
                return null;
            });
    }

    function isiPilihan(daftar, terpilih) {
        if (!printerSelect) { return; }
        const sekarang = terpilih || printerSelect.value || '';
        if (!daftar.length) { return; }
        printerSelect.innerHTML = '';
        const kosong = document.createElement('option');
        kosong.value = '';
        kosong.textContent = '— pilih printer —';
        printerSelect.appendChild(kosong);
        daftar.forEach(function (nama) {
            const o = document.createElement('option');
            o.value = nama;
            o.textContent = nama;
            if (nama === sekarang) { o.selected = true; }
            printerSelect.appendChild(o);
        });
        if (sekarang === '') {
            /* Pilih otomatis bila hanya ada satu kandidat "thermal". */
            const kandidatThermal = daftar.filter(function (n) { return /thermal|receipt|pos|tm-|t82|80/i.test(n); });
            if (kandidatThermal.length === 1) { printerSelect.value = kandidatThermal[0]; }
        }
    }

    if (btnUji) { btnUji.addEventListener('click', function () { periksa(true); }); }
    if (btnDaftar) { btnDaftar.addEventListener('click', function () { periksa(true); }); }

    if (btnTes) {
        btnTes.addEventListener('click', function () {
            const dasar = alamat();
            tulis('Mengirim halaman uji ke printer…', 'proses');
            fetch(dasar + '/uji', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    tulis((d && (d.pesan || d.pesan === '' ? d.pesan : 'Selesai.')) || 'Selesai.', d && d.ok ? 'ok' : 'err');
                })
                .catch(function (e) {
                    tulis('Gagal tes cetak: ' + e.message + '\nPastikan agen cetak sedang berjalan.', 'err');
                });
        });
    }

    /* Periksa otomatis saat halaman dibuka (hanya sekali, ringan). */
    window.setTimeout(function () { periksa(false); }, 400);
})();
