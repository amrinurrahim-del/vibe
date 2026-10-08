/*
 * Halaman Pengaturan → SIMRS / Rekam Medis, bagian metode REST API SIMGOS.
 *
 * Dua hal yang dikerjakan berkas ini:
 * 1. Menampilkan/menyembunyikan bagian sesuai metode yang dipilih (REST atau agen)
 *    — hanya tampilan; penyimpanan tetap dilakukan server lewat form biasa.
 * 2. "Deteksi Otomatis Jalur": mencoba beberapa jalur REST yang lazim dipakai SIMRS
 *    SATU PER SATU (satu jalur per permintaan) supaya tidak ada permintaan web yang
 *    menggantung lama — runtime PHP di sini melayani satu permintaan sekaligus.
 */
(function () {
    const bagian = document.getElementById('simrs');
    if (!bagian) { return; }

    /* ---------------- 1. Tampilkan bagian sesuai metode ---------------- */
    const radioRest = document.getElementById('metode-rest');
    const radioAgen = document.getElementById('metode-agen');
    function perbaruiMetode() {
        const m = (radioAgen && radioAgen.checked) ? 'agen' : 'rest';
        bagian.setAttribute('data-metode-simrs', m);
    }
    if (radioRest) { radioRest.addEventListener('change', perbaruiMetode); }
    if (radioAgen) { radioAgen.addEventListener('change', perbaruiMetode); }
    perbaruiMetode();

    /* ---------------- 2. Deteksi otomatis jalur ---------------- */
    const tombol = document.getElementById('btn-deteksi');
    const kotak = document.getElementById('deteksi-hasil');
    if (!tombol || !kotak) { return; }

    const urlEl   = document.getElementById('rest-url');
    const jalurEl = document.getElementById('rest-jalur');
    const rmEl    = document.getElementById('rest-rm-uji');

    /* Jalur yang lazim dipakai SIMRS/SIMGOS. {rm} diganti nomor rekam medis. */
    const KANDIDAT = [
        '/api/pasien/{rm}',
        '/api/v1/pasien/{rm}',
        '/api/pasien/rm/{rm}',
        '/api/pasien?no_rm={rm}',
        '/api/pasien?id={rm}',
        '/api/pasien/detail/{rm}',
        '/api/patient/{rm}',
        '/api/v1/patient/{rm}',
        '/simrs/api/pasien/{rm}',
        '/api/rekam-medis/{rm}',
        '/api/pasien/cari?no_rm={rm}',
    ];

    let sedangJalan = false;

    function baris(pesan, kelas) {
        const p = document.createElement('p');
        p.className = 'deteksi-baris' + (kelas ? ' is-' + kelas : '');
        p.textContent = pesan;
        return p;
    }

    /** Buat kartu hasil satu jalur (memakai textContent — jawaban server tidak dipercaya sebagai HTML). */
    function kartuHasil(u, rmUji) {
        const box = document.createElement('div');
        box.className = 'deteksi-item' + (u.ok ? ' is-ok' : '');

        const kepala = document.createElement('div');
        kepala.className = 'deteksi-item-head';
        const jalur = document.createElement('span');
        jalur.className = 'mono';
        /* Yang ditampilkan: alamat lengkap yang benar-benar dipanggil (memudahkan pemilik
           mencocokkan). Yang DISIMPAN lewat "Pakai jalur ini": templat jalurnya saja. */
        jalur.textContent = (u.url || u.jalur || '') + '  →  HTTP ' + (u.kode || 0) + ' (' + (u.waktu || 0) + ' detik)';
        kepala.appendChild(jalur);
        if (u.ok) {
            const tanda = document.createElement('span');
            tanda.className = 'tag tag-teal';
            tanda.textContent = 'cocok';
            kepala.appendChild(tanda);
        }
        box.appendChild(kepala);

        const isi = document.createElement('div');
        isi.className = 'deteksi-item-isi';
        if (u.nama) {
            const nama = document.createElement('p');
            nama.className = 'deteksi-nama';
            nama.textContent = 'Nama terbaca: ' + u.nama + ' (field ' + (u.kunci || '?') + ')';
            isi.appendChild(nama);

            /* Tombol "Pakai jalur ini" — form biasa supaya server yang menyimpan. */
            const form = document.createElement('form');
            form.method = 'post';
            form.action = 'pengaturan.php';
            form.className = 'inline-form';
            const a1 = document.createElement('input');
            a1.type = 'hidden'; a1.name = 'action'; a1.value = 'simrs_rest_jalur_pakai';
            const a2 = document.createElement('input');
            a2.type = 'hidden'; a2.name = 'jalur'; a2.value = u.jalur || '';
            const a3 = document.createElement('input');
            a3.type = 'hidden'; a3.name = 'rm_uji'; a3.value = rmUji;
            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.className = 'btn btn-mini btn-primary';
            btn.textContent = 'Pakai jalur ini';
            form.appendChild(a1); form.appendChild(a2); form.appendChild(a3); form.appendChild(btn);
            isi.appendChild(form);
        } else if (u.error) {
            const err = document.createElement('p');
            err.className = 'deteksi-err';
            err.textContent = u.error;
            isi.appendChild(err);
        }
        if (u.ringkas) {
            const pre = document.createElement('pre');
            pre.className = 'deteksi-mentah';
            pre.textContent = u.ringkas.slice(0, 300);
            isi.appendChild(pre);
        }
        box.appendChild(isi);
        return box;
    }

    function bersihkan() {
        kotak.textContent = '';
        kotak.hidden = false;
    }

    async function ujiSatu(dasar, jalur, rm) {
        const fd = new FormData();
        fd.append('dasar', dasar);
        fd.append('jalur', jalur);
        fd.append('rm', rm);
        const r = await fetch('api.php?action=simrs_uji', { method: 'POST', body: fd, cache: 'no-store' });
        const d = await r.json();
        if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal menguji'); }
        return d.uji || {};
    }

    async function jalan() {
        if (sedangJalan) { return; }
        const dasar = (urlEl && urlEl.value ? urlEl.value : '').trim();
        const rm = (rmEl && rmEl.value ? rmEl.value : '').trim() || '1';
        if (!dasar) {
            bersihkan();
            kotak.appendChild(baris('Isi dulu alamat server SIMGOS di atas.', 'err'));
            if (urlEl) { urlEl.focus(); }
            return;
        }

        /* Jalur dari Pengaturan dicoba pertama, lalu kandidat lain (tanpa duplikat). */
        const jalurUtama = (jalurEl && jalurEl.value ? jalurEl.value : '').trim();
        const daftar = [];
        if (jalurUtama) { daftar.push(jalurUtama); }
        KANDIDAT.forEach(function (j) { if (daftar.indexOf(j) < 0) { daftar.push(j); } });

        sedangJalan = true;
        tombol.disabled = true;
        bersihkan();
        const info = baris('Mencoba ' + daftar.length + ' jalur satu per satu… (bisa dihentikan kapan saja)',
            'proses');
        kotak.appendChild(info);

        let ketemu = 0;
        try {
            for (let i = 0; i < daftar.length; i++) {
                const jalur = daftar[i];
                info.textContent = 'Menguji ' + (i + 1) + '/' + daftar.length + ': ' + jalur;
                let u = {};
                try {
                    u = await ujiSatu(dasar, jalur, rm);
                } catch (e) {
                    u = { jalur: jalur, ok: false, error: e.message, kode: 0 };
                }
                /* PENTING: jangan menimpa u.jalur dengan alamat lengkap (u.url). Nilai u.jalur
                   adalah TEMPLAT (mis. /api/pasien/{rm}) dan itulah yang disimpan saat pemilik
                   menekan "Pakai jalur ini" — kalau tertimpa alamat lengkap, penyimpanan akan
                   menghasilkan alamat ganda dan pencarian berikutnya gagal (bug nyata). */
                u.jalur = jalur;
                if (u.ok) { ketemu++; }
                /* Hanya tampilkan yang berhasil atau yang memberi jawaban resmi (bukan "tidak ada server"). */
                if (u.ok || u.ringkas || (u.kode && u.kode > 0)) {
                    kotak.appendChild(kartuHasil(u, rm));
                }
                if (u.ok) { break; }   /* sudah ketemu — tidak perlu mencoba sisanya */
            }
            if (ketemu === 0) {
                info.textContent = 'Belum ada jalur yang menjawab berisi nama pasien. '
                    + 'Periksa kembali alamat server, cara memanggil (GET/POST), dan header/token — '
                    + 'atau kirimkan contoh jawaban SIMRS di bawah ini agar jalurnya bisa disesuaikan.';
                info.className = 'deteksi-baris is-err';
            } else {
                info.textContent = 'Jalur ditemukan. Tekan "Pakai jalur ini", lalu "Simpan & Uji Koneksi".';
                info.className = 'deteksi-baris is-ok';
            }
        } finally {
            sedangJalan = false;
            tombol.disabled = false;
        }
    }

    tombol.addEventListener('click', jalan);
})();
