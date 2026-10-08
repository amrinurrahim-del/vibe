/* Dashboard — ringkasan harian per kode & antrean berjalan, menyegarkan diri tanpa reload. */
(function () {
    const statNodes = document.querySelectorAll('[data-stat]');
    const jam = document.getElementById('jam-hero');
    let drift = 0;

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

    function gambar(d) {
        statNodes.forEach(function (n) {
            const k = n.dataset.stat;
            if (d.stat[k] !== undefined) { n.textContent = d.stat[k]; }
        });
        const nomor = document.getElementById('call-number');
        if (nomor) {
            if (d.dipanggil) {
                nomor.textContent = d.dipanggil.kode_tiket;
                nomor.classList.remove('is-empty');
            } else {
                nomor.textContent = '—';
                nomor.classList.add('is-empty');
            }
        }
        const hint = document.getElementById('antrean-hint');
        if (hint) { hint.textContent = d.aktif.length + ' tiket belum selesai'; }
        const box = document.getElementById('antrean');
        if (box) {
            if (!d.aktif.length) {
                box.innerHTML = '<p class="empty">Tidak ada antrean berjalan. Semua tiket hari ini sudah selesai.</p>';
            } else {
                box.innerHTML = d.aktif.map(function (t) {
                    const nama = (t.nama_pasien || '').trim();
                    const rm = (t.no_rm || '').trim();
                    /* Tiket yang waktu tunggunya sudah berhenti (pernah dipanggil/dibatalkan)
                       tidak diberi penanda tick agar angkanya tidak terus berjalan. */
                    const beku = Number(t.beku) === 1;
                    const waktu = beku
                        ? '<span class="antre-waktu" title="Waktu tunggu berhenti di panggilan pertama">' + t.tunggu_teks + '</span>'
                        : '<span class="antre-waktu" data-sejak="' + t.created_at + '">' + t.tunggu_teks + '</span>';
                    return '<div class="antre-row">'
                        + '<span class="antre-kode">' + t.kode_tiket + '</span>'
                        + '<span class="status-pill status-' + t.status.toLowerCase().replace(/_/g, '-') + '">' + t.status_label + '</span>'
                        + '<span class="antre-pasien">' + (nama ? nama : '—') + (rm ? ' · RM ' + rm : '') + '</span>'
                        + waktu
                        + '</div>';
                }).join('');
            }
        }
    }

    function tik() {
        if (jam) {
            const d = new Date(jamServerMs());
            jam.textContent = p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds());
        }
        document.querySelectorAll('.antre-waktu').forEach(function (n) {
            const mulai = parseSql(n.dataset.sejak);
            if (!mulai) { return; }
            n.textContent = durasi((jamServerMs() - mulai.getTime()) / 1000);
        });
    }

    function muat() {
        fetch('api.php?action=ringkasan', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                sinkronJam(d.server_time);
                gambar(d);
            })
            .catch(function () {})
            .then(function () { setTimeout(muat, 8000); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        tik();
        muat();
        setInterval(tik, 1000);
    });
})();
