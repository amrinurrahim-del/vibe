/*
 * Halaman Tracking Obat.
 * Alur status: RESEP MASUK → OBAT SEDANG DISIAPKAN → OBAT SIAP DISERAHKAN → (hanya lewat menu Panggil) OBAT SUDAH DITERIMA.
 */
(function () {
    const STATUS = ['RESEP_MASUK', 'DISIAPKAN', 'SIAP', 'DITERIMA'];
    const LABEL = {
        RESEP_MASUK: 'Resep Masuk',
        DISIAPKAN: 'Sedang Disiapkan',
        SIAP: 'Siap Diserahkan',
        DITERIMA: 'Sudah Diterima'
    };
    const NEXT = { RESEP_MASUK: 'DISIAPKAN', DISIAPKAN: 'SIAP' };

    const toastWrap = document.getElementById('toast-wrap');
    const cari = document.getElementById('cari-tiket');
    let drift = 0;
    let data = null;
    let sibuk = false;
    /* Info pindah otomatis RESEP MASUK → DISIAPKAN dari server (aktif + menit). */
    let autoSiapkan = { aktif: 0, menit: 5 };

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
    function sinkronJam(serverTime) {
        const b = String(serverTime || '').split(':');
        if (b.length !== 3) { return; }
        const now = new Date();
        let beda = (Number(b[0]) * 3600 + Number(b[1]) * 60 + Number(b[2]))
                 - (now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds());
        if (beda > 43200) { beda -= 86400; }
        if (beda < -43200) { beda += 86400; }
        drift = beda * 1000;
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

    function steps(status) {
        const idx = STATUS.indexOf(status);
        return '<div class="track-steps">' + STATUS.map(function (s, i) {
            const kls = i < idx ? 'step-done' : (i === idx ? 'step-now' : 'step-todo');
            return '<span class="step ' + kls + '" title="' + LABEL[s] + '"></span>';
        }).join('') + '</div>';
    }

    function kartu(t) {
        const selesai = t.status === 'DITERIMA';
        const majuKe = NEXT[t.status];
        /* Tiket tahap awal: tampilkan sisa waktu sebelum pindah sendiri ke "Sedang Disiapkan".
           Tombol manualnya TETAP ada di sebelah kanan (permintaan pemilik). */
        const otomatis = (t.status === 'RESEP_MASUK' && Number(autoSiapkan.aktif) === 1)
            ? '<div class="track-otomatis">Pindah otomatis ke <b>Sedang Disiapkan</b> dalam '
              + '<b class="track-mundur-waktu" data-otomatis-sejak="' + (t.patokan_tunggu || t.created_at) + '">'
              + '—</b></div>'
            : '';
        return '<div class="track-card' + (selesai ? ' is-selesai' : '') + '" data-id="' + t.id + '" data-cari="' + (t.kode_tiket + ' ' + (t.keterangan || '')).toLowerCase() + '">'
            + '<div class="track-card-head">'
            + '<span class="track-nomor">' + (selesai ? '<em class="tick">✓</em>' : '') + t.kode_tiket + '</span>'
            + '<span class="track-badge">' + t.kode + '</span>'
            + '</div>'
            + '<div class="track-meta">'
            + '<span>Diambil <b>' + t.jam_ambil + '</b></span>'
            + (selesai
                ? '<span>Diserahkan <b>' + t.jam_panggil + '</b></span>'
                : (Number(t.beku) === 1
                    ? '<span>Tunggu terhenti <b title="Berhenti di panggilan pertama (nomor pernah dipanggil lalu dibatalkan)">'
                        + durasi(t.tunggu_detik) + '</b></span>'
                    : '<span>Menunggu <b class="track-tunggu" data-sejak="' + t.created_at + '">' + durasi(t.tunggu_detik) + '</b></span>'))
            + '</div>'
            + ((t.nama_pasien || t.no_rm)
                ? '<div class="track-pasien">' + (t.nama_pasien ? '<b>' + t.nama_pasien + '</b>' : '')
                  + (t.no_rm ? '<em>RM ' + t.no_rm + '</em>' : '') + '</div>'
                : '')
            + (t.keterangan ? '<div class="track-keterangan">' + t.keterangan.replace(/</g, '&lt;') + '</div>' : '')
            + otomatis
            + steps(t.status)
            + '<div class="track-kaki">'
            + (t.siap_detik !== null && t.siap_detik !== undefined && t.siap_detik !== '' && t.status !== 'RESEP_MASUK'
                ? '<span class="track-siap">Penyiapan: <b>' + (t.siap_teks || '-') + '</b></span>' : '<span class="track-siap"></span>')
            + '<span class="track-aksi">'
            + (selesai
                ? '<span class="tag tag-teal">selesai</span>'
                : (majuKe
                    ? '<button class="btn btn-mini btn-primary" type="button" data-maju="1">' + LABEL[majuKe] + ' →</button>'
                      + (t.status !== 'RESEP_MASUK' ? '<button class="btn btn-mini" type="button" data-mundur="1">↩</button>' : '')
                    : '<span class="tag tag-ready">siap dipanggil</span><button class="btn btn-mini" type="button" data-mundur="1">↩</button>'))
            + '</span>'
            + '</div>'
            + '</div>';
    }

    function gambar() {
        if (!data) { return; }
        STATUS.forEach(function (st) {
            const box = document.querySelector('[data-list="' + st + '"]');
            const count = document.querySelector('[data-count="' + st + '"]');
            const list = (data.groups && data.groups[st]) || [];
            if (count) { count.textContent = list.length; }
            if (!box) { return; }
            if (!list.length) {
                box.innerHTML = '<p class="empty">' + (st === 'DITERIMA' ? 'Belum ada tiket selesai.' : 'Tidak ada tiket di tahap ini.') + '</p>';
                return;
            }
            box.innerHTML = list.map(kartu).join('');
        });
        saring();
    }

    function saring() {
        const kata = (cari && cari.value ? cari.value : '').trim().toLowerCase();
        document.querySelectorAll('.track-card').forEach(function (c) {
            const cocok = !kata || (c.dataset.cari || '').indexOf(kata) >= 0;
            c.style.display = cocok ? '' : 'none';
        });
    }

    function tik() {
        document.querySelectorAll('.track-tunggu').forEach(function (n) {
            const mulai = parseSql(n.dataset.sejak);
            if (!mulai) { return; }
            n.textContent = durasi((jamServerMs() - mulai.getTime()) / 1000);
        });
        /* Hitung mundur menuju perpindahan otomatis ke "Sedang Disiapkan". */
        const total = Math.max(1, Number(autoSiapkan.menit || 5)) * 60;
        document.querySelectorAll('[data-otomatis-sejak]').forEach(function (n) {
            const mulai = parseSql(n.dataset.otomatisSejak);
            if (!mulai) { return; }
            const sisa = total - (jamServerMs() - mulai.getTime()) / 1000;
            n.textContent = sisa > 0 ? durasi(sisa) : 'sebentar lagi…';
        });
    }

    function muat() {
        fetch('api.php?action=tracking_data', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal memuat'); }
                sinkronJam(d.server_time);
                if (d.auto_siapkan) { autoSiapkan = d.auto_siapkan; }
                data = d;
                gambar();
                tik();
            })
            .catch(function () {})
            .then(function () { setTimeout(muat, 6000); });
    }

    function kirim(id, arah) {
        if (sibuk) { return; }
        sibuk = true;
        const fd = new FormData();
        fd.append('id', id);
        fd.append('arah', arah);
        fetch('api.php?action=tracking_update', { method: 'POST', body: fd, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'gagal'); }
                toast(d.pesan, 'ok');
            })
            .catch(function (e) { toast(e.message, 'err'); })
            .then(function () { sibuk = false; muat(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('click', function (ev) {
            const card = ev.target.closest('.track-card');
            if (!card) { return; }
            const id = Number(card.dataset.id || 0);
            if (!id) { return; }
            if (ev.target.closest('[data-maju]')) { kirim(id, 'maju'); }
            else if (ev.target.closest('[data-mundur]')) { kirim(id, 'mundur'); }
        });
        if (cari) { cari.addEventListener('input', saring); }
        const muatUlang = document.getElementById('refresh-tracking');
        if (muatUlang) { muatUlang.addEventListener('click', function () { muat(); toast('Data dimuat ulang.', 'ok'); }); }
        muat();
        setInterval(tik, 1000);
    });
})();
