/* Halaman Pengaturan — tab, daftar suara perangkat, tes suara, pratinjau warna tema. */
(function () {
    /* --- Tab --- */
    const tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
    const panels = Array.prototype.slice.call(document.querySelectorAll('.tab-panel'));

    const tabAwal = (document.getElementById('tabs') || {}).dataset
        ? (document.getElementById('tabs').dataset.tabAwal || 'tampilan')
        : 'tampilan';

    function aktifkan(id, gulir) {
        if (!id) { id = tabAwal; }
        let ketemu = false;
        panels.forEach(function (p) {
            const on = p.id === id;
            p.classList.toggle('is-active', on);
            if (on) { ketemu = true; }
        });
        if (!ketemu) {
            /* Panel yang diminta tidak tersedia untuk peran ini → pakai panel pertama yang ada. */
            const pertama = panels[0];
            panels.forEach(function (p) { p.classList.toggle('is-active', p === pertama); });
            id = pertama ? pertama.id : id;
        }
        tabs.forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('href') === '#' + id); });
        if (gulir) {
            const el = document.getElementById(id);
            if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            const id = t.getAttribute('href').replace('#', '');
            if (history.replaceState) { history.replaceState(null, '', '#' + id); }
            aktifkan(id, true);
        });
    });

    /* --- Daftar voice dari perangkat --- */
    const inputVoice = document.getElementById('suara-voice');
    const datalist = document.getElementById('daftar-voice');
    const infoVoice = document.getElementById('voice-info');
    if (window.AntrianVoice && datalist && inputVoice) {
        window.AntrianVoice.muatSuara(true).then(function (voices) {
            if (!voices || !voices.length) {
                if (infoVoice) {
                    infoVoice.textContent = 'Perangkat/browser ini tidak menyediakan daftar suara. '
                        + 'Halaman Panggil Antrian tetap memakai suara id-ID saat dijalankan di browser yang mendukung.';
                }
                return;
            }
            const id = voices.filter(function (v) { return v.lang.toLowerCase().indexOf('id') === 0; });
            const lain = voices.filter(function (v) { return v.lang.toLowerCase().indexOf('id') !== 0; });
            (id.concat(lain)).forEach(function (v) {
                const o = document.createElement('option');
                o.value = v.name;
                o.label = v.lang;
                datalist.appendChild(o);
            });
            if (infoVoice) {
                infoVoice.textContent = voices.length + ' suara tersedia di perangkat ini · ' + id.length + ' berbahasa Indonesia.'
                    + (id.length ? ' Disarankan: ' + id.map(function (v) { return v.name; }).join(', ') : '');
            }
            if (inputVoice.value && !voices.some(function (v) { return v.name === inputVoice.value; })) {
                infoVoice.textContent += ' Suara tersimpan "' + inputVoice.value + '" belum terpasang — sistem otomatis memakai suara id-ID terdekat.';
            }
        });
    }

    /* --- Tes suara memakai nilai form yang sedang diisi --- */
    const tes = document.getElementById('tes-suara-pengaturan');
    if (tes && window.AntrianVoice) {
        tes.addEventListener('click', function () {
            const ambil = function (nama, def) {
                const n = document.querySelector('[name="' + nama + '"]');
                return n ? n.value : def;
            };
            const cfg = {
                voice: ambil('suara_voice', 'Google Bahasa Indonesia'),
                lang: ambil('suara_lang', 'id-ID'),
                rate: Number(ambil('suara_rate', 0.95)),
                volume: Number(ambil('suara_volume', 1)),
                ulang: 1,
                chime: document.querySelector('[name="suara_chime"]') && document.querySelector('[name="suara_chime"]').checked ? 1 : 0,
                eja_digit: document.querySelector('[name="suara_eja_digit"]') && document.querySelector('[name="suara_eja_digit"]').checked ? 1 : 0,
                template: ambil('suara_template', 'Nomor antrian, kode {kode}, {nomor}. Silakan menuju loket pengambilan obat.')
            };
            window.AntrianVoice.muatSuara(true).then(function () {
                tes.textContent = 'Memutar contoh…';
                window.AntrianVoice.bicara(cfg, { kode: 'A', nomor: 1, kode_tiket: 'A001' }, null).then(function () {
                    tes.innerHTML = 'Tes Suara Sekarang';
                });
            });
        });
    }

    /* --- Pratinjau warna tema --- */
    const warna = document.querySelector('[name="tema_warna"]');
    if (warna) {
        warna.addEventListener('input', function () {
            document.documentElement.style.setProperty('--accent', warna.value);
        });
    }

    /* ---- Ubah akun: tombol gear membuka panel terkait (satu panel saja) ---- */
    /* ---- Unggah video berpotongan (chunked) ----
       Batas ukuran unggah PHP dikunci platform, jadi berkas besar dipecah di browser dan
       dikirim potongan demi potongan (parameter lewat query, badan permintaan = berkas). */
    function pasangUnggahVideo() {
        const form = document.querySelector('#video-berkas form');
        if (!form) { return; }
        const input = form.querySelector('input[type=file]');
        const tombol = form.querySelector('button[type=submit]');
        if (!input || !tombol) { return; }

        const UKURAN_POTONGAN = 8 * 1024 * 1024;   /* 8 MB per potongan (aman di bawah batas server) */

        const kabar = function (teks, jenis) {
            let kotak = document.getElementById('video-progress');
            if (!kotak) {
                kotak = document.createElement('div');
                kotak.id = 'video-progress';
                form.appendChild(kotak);
            }
            kotak.textContent = teks;
            kotak.className = 'video-progress' + (jenis ? ' is-' + jenis : '');
        };

        /* Kirim satu berkas secara berpotongan. */
        const kirimBerkas = function (berkas) {
            const total = Math.max(1, Math.ceil(berkas.size / UKURAN_POTONGAN));
            const id = 'v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
            let posisi = 0;
            let idx = 0;
            const langkah = function () {
                if (posisi >= berkas.size) { return Promise.resolve(); }
                const bagian = Math.min(UKURAN_POTONGAN, berkas.size - posisi);
                const par = {
                    upload_id: id, idx: idx, total: total, nama: berkas.name, chunk_size: UKURAN_POTONGAN,
                };
                const query = Object.keys(par).map(function (k) {
                    return encodeURIComponent(k) + '=' + encodeURIComponent(par[k]);
                }).join('&');
                return fetch('api.php?action=video_chunk&' + query, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/octet-stream' },
                    body: berkas.slice(posisi, posisi + bagian),
                    cache: 'no-store',
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || !d.ok) { throw new Error((d && d.error) || 'unggahan gagal'); }
                    posisi += bagian;
                    idx++;
                    kabar('Mengunggah ' + berkas.name + ' — ' + Math.round((posisi / berkas.size) * 100)
                        + '% (potongan ' + idx + '/' + total + ')', 'proses');
                    return langkah();
                });
            };
            return langkah();
        };

        form.addEventListener('submit', function (ev) {
            const berkas = Array.prototype.slice.call(input.files || []);
            if (!berkas.length) { return; }   /* tanpa berkas: biarkan server yang menjawab */
            ev.preventDefault();
            tombol.disabled = true;
            const berhasil = [];
            const gagalKirim = [];
            const antre = berkas.reduce(function (rantai, f, i) {
                return rantai.then(function () {
                    kabar('Berkas ' + (i + 1) + ' dari ' + berkas.length + ': ' + f.name, 'proses');
                    return kirimBerkas(f).then(function () {
                        berhasil.push(f.name);
                    }).catch(function (e) {
                        gagalKirim.push(f.name + ': ' + e.message);
                    });
                });
            }, Promise.resolve());
            antre.then(function () {
                if (gagalKirim.length) {
                    kabar('Selesai dengan catatan — ' + berhasil.length + ' berhasil, ' + gagalKirim.length
                        + ' gagal. ' + gagalKirim.join(' '), 'err');
                } else {
                    kabar(berhasil.length + ' video berhasil diunggah. Memuat ulang…', 'ok');
                }
                window.setTimeout(function () {
                    /*
                     * WAJIB memuat ulang sungguhan: kalau hanya mengubah bagian #hash,
                     * browser tidak memuat ulang halaman dan daftar video baru tidak muncul
                     * sampai pengguna memuat ulang sendiri (bug yang pernah terjadi).
                     * reload() tetap mempertahankan #tampilan sehingga tab yang sama terbuka.
                     */
                    window.location.reload();
                }, 1800);
            });
        });
    }

    function tutupSemuaPanelAkun(kecuali) {
        document.querySelectorAll('.panel-ubah-akun').forEach(function (p) {
            if (kecuali && p.id === kecuali) { return; }
            p.hidden = true;
        });
        document.querySelectorAll('[data-gear]').forEach(function (b) {
            if (kecuali && ('ubah-akun-' + b.dataset.gear) === kecuali) { return; }
            b.setAttribute('aria-expanded', 'false');
            b.classList.remove('is-aktif');
        });
    }

    document.addEventListener('click', function (ev) {
        const gear = ev.target.closest('[data-gear]');
        if (gear) {
            const panel = document.getElementById('ubah-akun-' + gear.dataset.gear);
            if (!panel) { return; }
            const akanBuka = panel.hidden;
            tutupSemuaPanelAkun(akanBuka ? panel.id : null);
            panel.hidden = !akanBuka;
            gear.setAttribute('aria-expanded', akanBuka ? 'true' : 'false');
            gear.classList.toggle('is-aktif', akanBuka);
            if (akanBuka) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                const pertama = panel.querySelector('input[name=username]');
                if (pertama) { pertama.focus(); }
            }
            return;
        }
        const tutup = ev.target.closest('[data-tutup]');
        if (tutup) {
            const panel = document.getElementById('ubah-akun-' + tutup.dataset.tutup);
            if (panel) { panel.hidden = true; }
            tutupSemuaPanelAkun(null);
        }
    });

    /* Bila panel dibuka lewat tautan/berpindah halaman karena ada pesan galat, buka panelnya. */
    function bukaPanelDariHash() {
        const m = (location.hash || '').match(/^#ubah-akun-(\d+)$/);
        if (!m) { return; }
        const gear = document.querySelector('[data-gear="' + m[1] + '"]');
        if (gear) { gear.click(); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        aktifkan((location.hash || '#' + tabAwal).replace('#', ''), false);
        bukaPanelDariHash();
        pasangUnggahVideo();
    });
    if (document.readyState !== 'loading') {
        aktifkan((location.hash || '#' + tabAwal).replace('#', ''), false);
    }
})();
