/*
 * Halaman Panggil Antrian — dialog "Pengumuman Suara".
 *
 * Petugas menulis teks bebas, lalu teks itu diucapkan dengan SUARA YANG SAMA seperti
 * panggilan antrian (setelan Pengaturan → Suara: biasanya Google Bahasa Indonesia/id-ID).
 * Teks yang sering dipakai dapat disimpan sebagai template (tersimpan di server, tabel
 * pengaturan) sehingga bisa dipakai ulang, bahkan dari komputer lain.
 *
 * Penting:
 * - Pengumuman ini TIDAK menyentuh tiket mana pun (tidak memanggil, tidak mengubah status).
 * - Daftar template digambar dengan createElement/textContent (bukan innerHTML) supaya teks
 *   yang ditulis petugas tidak bisa disalahartikan sebagai HTML.
 * - Tombol "Panggil" tetap memakai jalur lamanya; berkas ini tidak mengubah berkas itu.
 */
(function () {
    const lapis  = document.getElementById('pengumuman-lapis');
    const tombol = document.getElementById('btn-pengumuman');
    if (!lapis || !tombol || !window.fetch) { return; }

    const teksEl    = document.getElementById('pengumuman-teks');
    const hitungEl  = document.getElementById('pengumuman-hitung');
    const ulangEl   = document.getElementById('pengumuman-ulang');
    const namaEl    = document.getElementById('pengumuman-nama');
    const statusEl  = document.getElementById('pengumuman-status');
    const suaraEl   = document.getElementById('pengumuman-suara');
    const daftarEl  = document.getElementById('pengumuman-daftar');
    const jumlahEl  = document.getElementById('pengumuman-daftar-jumlah');
    const btnUmumkan = document.getElementById('pengumuman-umumkan');
    const btnHenti   = document.getElementById('pengumuman-henti');
    const btnSimpan  = document.getElementById('pengumuman-simpan');
    const btnTutup   = document.getElementById('pengumuman-tutup');
    const toastWrap  = document.getElementById('toast-wrap');

    let suara = null;            /* setelan suara dari server (sama dengan panggilan antrian) */
    let template = [];
    let maksTemplate = 20;
    let maksKarakter = 400;
    let sudahMuat = false;
    let fokusSebelum = null;

    /* ---------------- Utilitas tampilan ---------------- */

    function setStatus(pesan, jenis) {
        if (!statusEl) { return; }
        statusEl.textContent = pesan;
        statusEl.className = 'pengumuman-status' + (jenis ? ' is-' + jenis : '');
    }

    function tulisVoiceText(pesan) {
        const vt = document.getElementById('voice-text');
        if (vt) { vt.textContent = pesan; }
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

    function hitung() {
        const n = (teksEl && teksEl.value ? teksEl.value : '').length;
        if (hitungEl) { hitungEl.textContent = n + ' / ' + maksKarakter + ' karakter'; }
    }

    /* ---------------- Buka / tutup dialog ---------------- */

    function buka() {
        fokusSebelum = document.activeElement;
        lapis.hidden = false;
        document.body.classList.add('pengumuman-terbuka');
        muatData().then(function () {
            if (teksEl) { teksEl.focus(); }
        });
    }

    function tutup() {
        lapis.hidden = true;
        document.body.classList.remove('pengumuman-terbuka');
        /* Suara yang sedang berbunyi TIDAK dihentikan — pengumuman tetap terdengar. */
        if (fokusSebelum && typeof fokusSebelum.focus === 'function') {
            try { fokusSebelum.focus(); } catch (e) { /* elemen sudah hilang */ }
        }
    }

    /* ---------------- Data dari server (setelan suara + template) ---------------- */

    function muatData(paksa) {
        if (sudahMuat && !paksa) { return Promise.resolve(); }
        setStatus('Memuat template…', 'proses');
        return fetch('api.php?action=pengumuman_data', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'jawaban server tidak dikenal'); }
                suara = d.suara || {};
                template = d.template || [];
                maksTemplate = Number(d.maks_template || 20);
                maksKarakter = Number(d.maks_karakter || 400);
                if (teksEl) { teksEl.maxLength = maksKarakter; }
                hitung();
                gambarTemplate();
                if (suaraEl) {
                    suaraEl.textContent = suara.voice
                        ? suara.voice + ' · ' + (suara.lang || 'id-ID')
                        : (suara.lang || 'Bahasa Indonesia');
                }
                setStatus('Siap. Tulis teks lalu tekan Umumkan (atau Ctrl+Enter).', '');
                sudahMuat = true;
            })
            .catch(function (e) {
                setStatus('Gagal memuat data pengumuman: ' + e.message, 'err');
            });
    }

    /* ---------------- Daftar template ---------------- */

    function gambarTemplate() {
        if (!daftarEl) { return; }
        daftarEl.textContent = '';
        if (jumlahEl) {
            jumlahEl.textContent = template.length
                ? template.length + ' dari ' + maksTemplate + ' template'
                : 'belum ada';
        }
        if (!template.length) {
            const kosong = document.createElement('p');
            kosong.className = 'pengumuman-kosong';
            kosong.textContent = 'Belum ada template tersimpan. Tulis teks di atas, beri nama, '
                + 'lalu tekan "Simpan Template" supaya bisa dipakai ulang.';
            daftarEl.appendChild(kosong);
            return;
        }
        template.forEach(function (t) {
            const baris = document.createElement('div');
            baris.className = 'pengumuman-item';

            const isi = document.createElement('div');
            isi.className = 'pengumuman-item-isi';
            const nama = document.createElement('strong');
            nama.textContent = t.nama;
            const teks = document.createElement('span');
            teks.textContent = t.teks;
            isi.appendChild(nama);
            isi.appendChild(teks);
            if (t.waktu) {
                const w = document.createElement('em');
                w.textContent = 'disimpan ' + t.waktu + (t.oleh ? ' oleh ' + t.oleh : '');
                isi.appendChild(w);
            }

            const aksi = document.createElement('div');
            aksi.className = 'pengumuman-item-aksi';
            const pakai = document.createElement('button');
            pakai.type = 'button';
            pakai.className = 'btn btn-mini';
            pakai.dataset.pakai = String(t.id);
            pakai.textContent = 'Pakai';
            pakai.title = 'Isikan teks template ini ke kotak pengumuman';
            const hapus = document.createElement('button');
            hapus.type = 'button';
            hapus.className = 'btn btn-mini btn-danger';
            /* Nama atribut ditulis eksplisit: dataset.hapusTpl menghasilkan "data-hapus-tpl",
               dan selector [data-hapusTpl] TIDAK cocok dengannya (huruf besar/kecil & tanda hubung).
               Bug nyata: tombol Hapus template diam-diam tidak berfungsi karena hal ini. */
            hapus.setAttribute('data-hapus-tpl', String(t.id));
            hapus.textContent = 'Hapus';
            hapus.title = 'Hapus template ini dari daftar';
            aksi.appendChild(pakai);
            aksi.appendChild(hapus);

            baris.appendChild(isi);
            baris.appendChild(aksi);
            daftarEl.appendChild(baris);
        });
    }

    function pakaiTemplate(id) {
        const t = template.find(function (x) { return Number(x.id) === Number(id); });
        if (!t) { return; }
        if (teksEl) {
            teksEl.value = t.teks;
            hitung();
            teksEl.focus();
        }
        setStatus('Template "' + t.nama + '" dimasukkan ke kotak teks. Tekan Umumkan bila sudah pas.', '');
    }

    function hapusTemplate(id) {
        const t = template.find(function (x) { return Number(x.id) === Number(id); });
        if (!t) { return; }
        if (!window.confirm('Hapus template "' + t.nama + '"?\n\nTeks pengumumannya tidak akan tersimpan lagi di daftar.')) {
            return;
        }
        kirim('api.php?action=pengumuman_hapus', { id: id }, 'Template dihapus.');
    }

    /* ---------------- Kirim ke server (simpan / hapus) ---------------- */

    function kirim(url, body, pesanSukses) {
        const fd = new FormData();
        Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
        return fetch(url, { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal'); }
                template = d.template || template;
                gambarTemplate();
                setStatus(d.pesan || pesanSukses, 'ok');
                toast(d.pesan || pesanSukses, 'ok');
                return d;
            })
            .catch(function (e) {
                setStatus(e.message, 'err');
                toast(e.message, 'err');
            });
    }

    function simpanTemplate() {
        const teks = (teksEl && teksEl.value ? teksEl.value : '').trim();
        if (teks === '') {
            setStatus('Teks pengumuman masih kosong — tulis dulu teksnya, lalu simpan.', 'err');
            if (teksEl) { teksEl.focus(); }
            return;
        }
        if (btnSimpan) { btnSimpan.disabled = true; }
        kirim('api.php?action=pengumuman_simpan', {
            nama: (namaEl && namaEl.value ? namaEl.value : '').trim(),
            teks: teks,
        }, 'Template disimpan.').then(function () {
            if (namaEl) { namaEl.value = ''; }
            if (btnSimpan) { btnSimpan.disabled = false; }
        });
    }

    /* ---------------- Ucapkan pengumuman ---------------- */

    function ucapkan() {
        const teks = (teksEl && teksEl.value ? teksEl.value : '').trim();
        if (teks === '') {
            setStatus('Tulis dulu teks pengumuman yang ingin diucapkan.', 'err');
            if (teksEl) { teksEl.focus(); }
            return;
        }
        if (!window.AntrianVoice || typeof window.AntrianVoice.bicara !== 'function') {
            const pesan = 'Perangkat/browser ini tidak mendukung pengumuman suara (Web Speech API).';
            setStatus(pesan, 'err');
            tulisVoiceText(pesan);
            toast(pesan, 'err');
            return;
        }

        /* teks_langsung: teks diucapkan APA ADANYA (tanpa penanda {kode}/{nomor} seperti
           template panggilan) — pengumuman bebas tidak berisi nomor tiket. */
        const cfg = Object.assign({}, suara || {}, {
            template: teks,
            teks_langsung: true,
            ulang: Math.max(1, Math.min(3, Number((ulangEl && ulangEl.value) || 1))),
            /* Pengumuman baru MENGGANTIKAN yang sedang berbunyi (bukan mengantre), supaya
               petugas bisa memperbaiki teks yang salah tanpa dua suara bertumpuk. */
            antre: false,
        });

        if (btnHenti) { btnHenti.disabled = false; }
        setStatus('Mengumumkan…', 'proses');
        tulisVoiceText('Mengumumkan pengumuman bebas dari halaman Panggil Antrian…');

        let janji;
        try {
            janji = window.AntrianVoice.bicara(cfg, {}, function (pesan) {
                setStatus(pesan, 'proses');
            });
        } catch (e) {
            janji = Promise.reject(e);
        }
        Promise.resolve(janji).then(function (ok) {
            if (btnHenti) { btnHenti.disabled = true; }
            if (ok) {
                setStatus('Pengumuman selesai diucapkan.', 'ok');
                tulisVoiceText('Pengumuman suara selesai diucapkan: "' + ringkas(teks) + '"');
            } else {
                setStatus('Suara tidak dapat diputar di perangkat ini (pengumuman tidak terkirim ke mana pun).', 'err');
                tulisVoiceText('Pengumuman suara gagal diputar di perangkat ini.');
            }
        }).catch(function () {
            if (btnHenti) { btnHenti.disabled = true; }
            setStatus('Pengumuman suara gagal diputar di perangkat ini.', 'err');
        });
    }

    function ringkas(teks) {
        const t = String(teks).replace(/\s+/g, ' ').trim();
        return t.length > 60 ? t.slice(0, 57) + '…' : t;
    }

    function hentikan() {
        if (window.speechSynthesis) { window.speechSynthesis.cancel(); }
        if (btnHenti) { btnHenti.disabled = true; }
        setStatus('Suara dihentikan.', '');
    }

    /* ---------------- Pemasangan ---------------- */

    tombol.addEventListener('click', buka);
    if (btnTutup) { btnTutup.addEventListener('click', tutup); }
    if (btnUmumkan) { btnUmumkan.addEventListener('click', ucapkan); }
    if (btnHenti) { btnHenti.addEventListener('click', hentikan); }
    if (btnSimpan) { btnSimpan.addEventListener('click', simpanTemplate); }
    if (teksEl) {
        teksEl.addEventListener('input', hitung);
        /* Ctrl+Enter = Umumkan (mempercepat pemakaian di loket). */
        teksEl.addEventListener('keydown', function (ev) {
            if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
                ev.preventDefault();
                ucapkan();
            }
        });
    }

    /* Klik di area gelap (di luar kotak dialog) menutup dialog. */
    lapis.addEventListener('click', function (ev) {
        if (ev.target === lapis) { tutup(); }
    });

    /* Esc menutup dialog. */
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !lapis.hidden) { tutup(); }
    });

    /* Klik "Pakai" / "Hapus" pada daftar template (delegasi). */
    if (daftarEl) {
        daftarEl.addEventListener('click', function (ev) {
            const pakai = ev.target.closest('[data-pakai]');
            if (pakai) { pakaiTemplate(pakai.dataset.pakai); return; }
            /* Nama atribut memakai tanda hubung (data-hapus-tpl) — lihat catatan di gambarTemplate(). */
            const hapus = ev.target.closest('[data-hapus-tpl]');
            if (hapus) { hapusTemplate(hapus.getAttribute('data-hapus-tpl')); }
        });
    }

    /* Isi penghitung karakter sejak awal (daftar template menyusul saat dialog dibuka). */
    hitung();
})();
