/*
 * Halaman Ambil Antrian.
 * - Jalan pintas keyboard huruf kode (A F K P G J I) untuk membuat tiket.
 * - Pencarian pasien dari SIMRS (SIMGOS) lewat AGEN di jaringan rumah sakit:
 *   isi No. RM → "Cari Pasien" → aplikasi mencatat permintaan, agen di RS membacanya
 *   dari database SIMGOS dan mengirim jawaban → nama pasien tampil & ikut ke tiket.
 *   Bila agen mati / RM tidak ada, petugas tetap bisa mengisi nama manual (antrean tidak macet).
 */
(function () {
    const form = document.getElementById('form-ambil');
    if (!form) { return; }

    /* ---------------- Jalan pintas huruf kode ---------------- */
    document.addEventListener('keydown', function (ev) {
        if (ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
        const t = ev.target;
        const diInput = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);

        /* Esc = lepas fokus dari kotak isian supaya jalan pintas huruf bisa dipakai. */
        if (ev.key === 'Escape' && diInput && t.tagName === 'INPUT') {
            t.blur();
            return;
        }
        if (diInput) { return; }

        const huruf = String(ev.key || '').toUpperCase();
        if (!/^[A-Z]$/.test(huruf)) { return; }
        const tombol = form.querySelector('.kode-btn[data-kode="' + huruf + '"]');
        if (!tombol || tombol.disabled) { return; }
        ev.preventDefault();
        tombol.classList.add('is-pressed');
        setTimeout(function () { form.requestSubmit ? form.requestSubmit(tombol) : tombol.click(); }, 90);
    });

    /* ---------------- Pencarian pasien SIMRS ---------------- */
    const kotak = document.getElementById('simrs-box');
    if (!kotak) { return; }   /* integrasi SIMRS belum dinyalakan */

    const inputRm    = document.getElementById('no-rm');
    const inputNama  = document.getElementById('nama-pasien');
    const tombolCari = document.getElementById('btn-cari');
    const pesan      = document.getElementById('simrs-pesan');
    const dot        = document.getElementById('simrs-dot');
    const hasil      = document.getElementById('simrs-hasil');
    let sedangCari = false;
    let timerPoll  = null;

    function tulisPesan(teks, jenis) {
        if (pesan) { pesan.innerHTML = teks; }
        if (dot) {
            dot.className = 'simrs-dot' + (jenis ? ' is-' + jenis : '');
        }
    }

    function tampilkanHasil(html, kelas) {
        if (!hasil) { return; }
        if (!html) { hasil.hidden = true; hasil.innerHTML = ''; return; }
        hasil.hidden = false;
        hasil.className = 'simrs-hasil' + (kelas ? ' ' + kelas : '');
        hasil.innerHTML = html;
    }

    function labelJk(jk) {
        const s = String(jk || '').trim().toUpperCase();
        if (s === 'L' || s === 'LAKI-LAKI' || s === 'LAKI LAKI' || s === '1') { return 'Laki-laki'; }
        if (s === 'P' || s === 'PEREMPUAN' || s === '2') { return 'Perempuan'; }
        return s;
    }

    function mulaiCari() {
        if (sedangCari) { return; }
        const rm = (inputRm && inputRm.value ? inputRm.value : '').trim();
        if (!rm) {
            tampilkanHasil('Isi nomor rekam medis terlebih dahulu.', 'simrs-hasil-err');
            if (inputRm) { inputRm.focus(); }
            return;
        }
        sedangCari = true;
        if (tombolCari) { tombolCari.disabled = true; }
        tampilkanHasil('');
        tulisPesan('Mengirim permintaan ke SIMRS…', 'proses');

        const fd = new FormData();
        fd.append('no_rm', rm);
        fetch('api.php?action=cari_pasien', { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal'); }
                /* Metode REST: jawaban sudah ada pada permintaan ini — tidak perlu menunggu. */
                if (d.selesai) {
                    if (Number(d.ditemukan) === 1) {
                        selesai({ nama: d.nama, no_rm: d.no_rm, jenis_kelamin: '', tgl_lahir: '' });
                    } else {
                        gagal(d.pesan || 'Data pasien tidak ditemukan pada SIMRS.');
                    }
                    return;
                }
                if (!d.agen_online) {
                    tampilkanHasil('Agen SIMRS belum terhubung. Pastikan program agen di jaringan RS berjalan — '
                        + 'nama pasien dapat diisi manual di bawah.', 'simrs-hasil-err');
                }
                pantau(d.id, rm, 0);
            })
            .catch(function (e) {
                gagal(e.message);
            });
    }

    /** Polling hasil sampai agen menjawab (maksimal ± SIMRS_KEDALUWARSA_DETIK detik). */
    function pantau(id, rm, putaran) {
        const batas = 60;   /* 60 × 1,5 s = 90 detik, sama dengan kedaluwarsa di server */
        if (putaran > batas) {
            gagal('SIMRS tidak merespons. Nama pasien dapat diisi manual.');
            return;
        }
        fetch('api.php?action=cari_status&id=' + encodeURIComponent(id), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal'); }
                if (d.status === 'menunggu') {
                    tulisPesan('Menunggu jawaban agen SIMRS… (No. RM ' + rm + ')', 'proses');
                    timerPoll = window.setTimeout(function () { pantau(id, rm, putaran + 1); }, 1500);
                    return;
                }
                if (d.status === 'selesai') {
                    selesai(d);
                    return;
                }
                gagal(d.pesan || 'Data pasien tidak ditemukan pada SIMRS.');
            })
            .catch(function (e) { gagal(e.message); });
    }

    function selesai(d) {
        sedangCari = false;
        if (tombolCari) { tombolCari.disabled = false; }
        if (inputNama) { inputNama.value = d.nama || ''; }
        const jk = labelJk(d.jenis_kelamin);
        const tgl = d.tgl_lahir ? d.tgl_lahir : '';
        let rincian = [];
        if (jk) { rincian.push(jk); }
        if (tgl) { rincian.push('lahir ' + tgl); }
        tampilkanHasil('<strong>' + (d.nama || '(tanpa nama)') + '</strong>'
            + (rincian.length ? '<span>' + rincian.join(' · ') + '</span>' : '')
            + '<span class="simrs-hasil-kecil">No. RM ' + (d.no_rm || '') + ' — periksa kesesuaian dengan pasien, lalu pilih kode tiket.</span>',
            'simrs-hasil-ok');
        tulisPesan('Data pasien ditemukan dan sudah diisikan ke kolom nama.', 'ok');
        if (inputNama) { inputNama.focus(); }
    }

    function gagal(teks) {
        sedangCari = false;
        if (tombolCari) { tombolCari.disabled = false; }
        tampilkanHasil(teks + ' Nama pasien dapat diisi manual.', 'simrs-hasil-err');
        tulisPesan('Pencarian tidak berhasil — isi nama pasien secara manual bila perlu.', 'err');
    }

    if (tombolCari) { tombolCari.addEventListener('click', mulaiCari); }
    if (inputRm) {
        inputRm.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); mulaiCari(); }
        });
    }

    /* Jam berjalan di header kartu */
    const jam = document.getElementById('jam-ambil');
    if (jam) {
        setInterval(function () {
            const d = new Date();
            const p = function (n) { return String(n).padStart(2, '0'); };
            jam.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        }, 1000);
    }
})();
