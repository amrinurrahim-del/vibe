/*
 * Layar display antrian apotek.
 * - Nomor utama = tiket yang sedang dipanggil (paling terakhir dipanggil hari ini).
 * - Kolom kanan = 3 kolom status tracking + bagian bawah khusus "OBAT SUDAH DITERIMA".
 * - Cocokkan jam dengan jam server (server_time) agar waktu tunggu akurat.
 */
(function () {
    const el = function (id) { return document.getElementById(id); };
    const STATUS_ORDER = ['RESEP_MASUK', 'DISIAPKAN', 'SIAP', 'DITERIMA'];

    let cfg = null;
    let drift = 0;          /* selisih jam perangkat vs jam server (ms) */
    let dipanggilId = null;
    let revChips = null;   /* revisi data terakhir yang dirender ke kolom chip */
    let audioOn = window.localStorage.getItem('ap_audio_display') === '1';
    let sudahSiap = false;

    function parseSql(s) {
        if (!s) { return null; }
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }
    function jamServerMs() { return Date.now() + drift; }

    function durasi(detik) {
        detik = Math.max(0, Math.floor(detik));
        const j = Math.floor(detik / 3600), m = Math.floor((detik % 3600) / 60), s = detik % 60;
        const p = function (n) { return String(n).padStart(2, '0'); };
        return j > 0 ? j + ':' + p(m) + ':' + p(s) : p(m) + ':' + p(s);
    }

    /* Warna cadangan bila aplikasi belum mengirim peta warna (mis. data lama). */
    const WARNA_CADANGAN = {
        A: '#dc2626', F: '#2563eb', K: '#d97706', P: '#7c3aed', G: '#059669',
        J: '#db2777', D: '#4f46e5', I: '#0891b2'
    };
    const WARNA_CADANGAN_LAIN = ['#ea580c', '#0e7490', '#65a30d', '#92400e', '#475569', '#be123c', '#0f766e', '#6d28d9'];

    /** Warna kode: dari Pengaturan → Kode Antrian, atau cadangan bila belum ada. */
    function warnaKode(kode) {
        const k = String(kode || '').toUpperCase();
        const peta = (cfg && cfg.warna_kode) || {};
        if (peta[k]) { return peta[k]; }
        if (WARNA_CADANGAN[k]) { return WARNA_CADANGAN[k]; }
        const i = (k.charCodeAt(0) - 65 + 26) % 26;
        return WARNA_CADANGAN_LAIN[i % WARNA_CADANGAN_LAIN.length];
    }

    /** Ubah #rrggbb → 'r,g,b'. */
    function rgbDariHex(hex) {
        const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex).trim());
        if (!m) { return null; }
        return [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)].join(',');
    }

    /**
     * Backpanel chip diberi warna sesuai kode tiket (sedikit transparan) supaya tiap kode
     * mudah dibedakan dari jauh. Warna TEKS tetap gelap agar tetap jelas terbaca.
     */
    function gayaWarna(kode, selesai) {
        const rgb = rgbDariHex(warnaKode(kode));
        if (!rgb) { return ''; }
        const alfa = selesai ? 0.16 : 0.26;
        return ' style="background:rgba(' + rgb + ',' + alfa + ');border-color:rgba(' + rgb + ',.6)"';
    }

    function chipHtml(t) {
        /* data-jalan=0 → waktu tunggu tidak dihitung terus (sudah selesai atau sudah berhenti
           di panggilan pertama karena pernah dipanggil lalu dibatalkan). */
        const jalan = (t.selesai || Number(t.beku) === 1) ? '0' : '1';
        return '<span class="chip chip-status" data-sejak="' + t.created_at + '"'
            + ' data-selesai="' + (t.selesai ? '1' : '0') + '" data-jalan="' + jalan + '"'
            + ' data-kode="' + t.kode + '"' + gayaWarna(t.kode, t.selesai)
            + (Number(t.beku) === 1 && !t.selesai ? ' title="Waktu tunggu berhenti di panggilan pertama"' : '') + '>'
            + '<b>' + t.kode_tiket + '</b>'
            + '<i class="chip-waktu">' + (t.selesai ? 'selesai' : durasi(t.tunggu_detik)) + '</i>'
            + '</span>';
    }

    /* Ukuran chip dasar (px) — dikecilkan otomatis bila tiket banyak. */
    const CHIP_FS = 22;
    const CHIP_FS_MIN = 18;

    function isiChips(status, list, totalAsli) {
        const box = document.querySelector('[data-chips="' + status + '"]');
        if (!box) { return; }
        box.style.fontSize = CHIP_FS + 'px';
        /* Baris "Obat Sudah Diterima" bergulir mendatar, kolom status lain menurun. */
        box.dataset.arah = status === 'DITERIMA' ? 'mendatar' : 'menurun';
        if (!list.length) {
            box.innerHTML = '<span class="chip-empty">—</span>';
        } else {
            /* Semua tiket dirender (tidak dipotong, tanpa penanda "+N tiket lainnya"). */
            box.innerHTML = '<div class="chips-gulir" data-arah="' + box.dataset.arah + '">'
                + '<div class="chips-isi">' + list.map(chipHtml).join('') + '</div></div>';
            aturGulir(box, true);
        }
        const count = document.querySelector('.status-panel-count[data-count="' + status + '"]');
        if (count) { count.textContent = totalAsli > 0 ? totalAsli : list.length; }
    }

    /** Nomor utama & baris riwayat juga tidak boleh terpotong. */
    function muatkanNomorUtama() {
        const p = document.querySelector('.call-panel-number');
        if (!p) { return; }
        const kode = el('d-kode'), angka = el('d-angka');
        if (!kode || !angka) { return; }
        kode.style.fontSize = '';
        angka.style.fontSize = '';
        const dasar = parseFloat(getComputedStyle(angka).fontSize) || 168;
        let fs = dasar;
        while (p.scrollWidth > p.clientWidth + 1 && fs > dasar * 0.45) {
            fs -= 4;
            kode.style.fontSize = fs + 'px';
            angka.style.fontSize = fs + 'px';
        }
    }

    /** Perkecil tulisan pada sebuah kotak sampai isinya muat (tidak terpotong). */
    function kecilkanSampaiMuat(kotak, bagian, batas) {
        if (!kotak || !bagian.length) { return; }
        const dasar = bagian.map(function (b) { return b.dasar; });
        const pasang = function (faktor) {
            bagian.forEach(function (b, i) {
                const px = Math.max(b.min || 10, Math.round(dasar[i] * faktor));
                b.el.style.fontSize = px + 'px';
            });
        };
        pasang(1);
        const lebih = function () { return kotak.scrollHeight > kotak.clientHeight + 1; };
        if (!lebih()) { return; }
        for (let faktor = 0.97; faktor >= (batas || 0.5); faktor -= 0.03) {
            pasang(faktor);
            if (!lebih()) { return; }
        }
    }

    function ukurFs(node) {
        return parseFloat(getComputedStyle(node).fontSize) || 16;
    }

    function muatkanPanelPanggilan() {
        const panel = el('call-panel');
        if (!panel) { return; }
        const kode = el('d-kode'), angka = el('d-angka');
        const loket = el('d-loket'), status = el('d-status');
        const bagian = [];
        if (kode) { bagian.push({ el: kode, dasar: ukurFs(kode) || 150, min: 60 }); }
        if (angka) { bagian.push({ el: angka, dasar: ukurFs(angka) || 168, min: 68 }); }
        if (loket) { bagian.push({ el: loket, dasar: ukurFs(loket) || 36, min: 18 }); }
        if (status) { bagian.push({ el: status, dasar: ukurFs(status) || 21, min: 12 }); }
        bagian.forEach(function (b) { b.el.style.fontSize = ''; });
        kecilkanSampaiMuat(panel, bagian, 0.5);
    }

    function muatkanJam() {
        const kotak = document.querySelector('.display-clock');
        const jam = document.querySelector('.clock-jam');
        const tgl = document.querySelector('.clock-tgl');
        if (!kotak || !jam) { return; }
        const bagian = [{ el: jam, dasar: ukurFs(jam) || 62, min: 28 }];
        if (tgl) { bagian.push({ el: tgl, dasar: ukurFs(tgl) || 38, min: 10 }); }
        bagian.forEach(function (b) { b.el.style.fontSize = ''; });
        kecilkanSampaiMuat(kotak, bagian, 0.55);
    }

    function muatkanRiwayat() {
        const list = el('d-riwayat');
        if (!list) { return; }
        const chips = Array.prototype.slice.call(list.querySelectorAll('.riwayat-chip'));
        chips.forEach(function (c) { c.style.display = ''; });
        if (!chips.length) { return; }
        let n = chips.length;
        while (n > 1 && list.scrollWidth > list.clientWidth + 1) {
            n--;
            chips.slice(n).forEach(function (c) { c.style.display = 'none'; });
        }
    }

    /**
     * Kanvas mengisi SELURUH layar (tanpa pita kosong di atas/bawah atau kiri/kanan).
     * Tinggi acuan tetap 1080 satuan desain; lebar menyesuaikan rasio layar, sehingga
     * pada monitor non-16:9 sisa ruang otomatis menjadi tambahan tinggi/lebar panel
     * (bukan ruang kosong). Ukuran huruf tetap proporsional terhadap tinggi layar.
     */
    function aturSkala() {
        const stage = el('display-stage');
        if (!stage) { return; }
        const de = document.documentElement;
        const vw = de.clientWidth || window.innerWidth || 1920;
        const vh = de.clientHeight || window.innerHeight || 1080;
        const skala = Math.max(0.25, Math.min(vh / 1080, 4));
        /* Lebar & tinggi kanvas dalam satuan desain, supaya setelah diskalakan pas memenuhi layar. */
        stage.style.width = (vw / skala) + 'px';
        stage.style.height = (vh / skala) + 'px';
        /* Hanya variabel skala yang diset — transform (scale) tetap dari CSS. */
        stage.style.setProperty('--skala', String(skala));
    }

    /*
     * Pemutaran video (berkas yang diunggah pemilik):
     *   - semua elemen <video> sudah disiapkan server; JS hanya menampilkan yang aktif,
     *     jadi tidak ada jeda/kedip saat berpindah video.
     *   - video berganti otomatis saat yang aktif selesai ('ended'), berkeliling (wrap),
     *     sehingga daftar diputar terus-menerus tanpa henti.
     *   - bila satu berkas gagal diputar (mis. rusak), langsung dilewati ke berikutnya
     *     supaya panel tidak berhenti di layar kosong.
     */
    function pasangVideo() {
        const frame = el('video-frame');
        if (!frame) { return; }
        const daftar = Array.prototype.slice.call(frame.querySelectorAll('.video-item'));
        if (!daftar.length) { return; }

        let aktif = 0;
        let gagalBerturut = 0;

        const tampilkan = function (i, putar) {
            daftar.forEach(function (v, k) {
                const on = k === i;
                v.classList.toggle('is-aktif', on);
                if (on) { v.removeAttribute('aria-hidden'); } else { v.setAttribute('aria-hidden', 'true'); }
            });
            /* Hentikan video lain supaya TIDAK ada dua video berjalan bersamaan
               (boros kuota & beban TV). */
            daftar.forEach(function (lain, k) {
                if (k !== i && !lain.paused) { lain.pause(); }
            });
            aktif = i;
            const v = daftar[i];
            if (!v) { return; }
            /* Muat data video ini lebih awal supaya perpindahan berikutnya mulus. */
            try { v.load && v.readyState === 0 && v.load(); } catch (e) {}
            if (putar) {
                const janji = v.play();
                if (janji && janji.catch) {
                    janji.catch(function () {
                        /* Browser menolak memutar (mis. kebijakan autoplay) → coba lagi tanpa suara. */
                        v.muted = true;
                        v.play().catch(function () {});
                    });
                }
            }
        };

        const berikutnya = function () {
            tampilkan((aktif + 1) % daftar.length, true);
        };

        daftar.forEach(function (v, i) {
            v.addEventListener('ended', function () {
                gagalBerturut = 0;
                berikutnya();
            });
            v.addEventListener('error', function () {
                /* Berkas bermasalah → lewati supaya video lain tetap jalan. */
                gagalBerturut++;
                if (gagalBerturut >= daftar.length) { return; }
                window.setTimeout(berikutnya, 500);
            });
        });

        tampilkan(0, true);
        /* Bila video dihentikan browser (mis. tab tidak aktif), lanjutkan saat kembali terlihat. */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && daftar[aktif] && daftar[aktif].paused) {
                daftar[aktif].play().catch(function () {});
            }
        });
    }

    /** Muat ulang halaman HANYA bila daftar video berubah (pemilik menambah/menghapus). */
    function periksaVideo(d) {
        const panel = el('video-panel');
        if (!panel || !d || !d.video) { return; }
        const baru = String(d.video.stamp || '');
        const lama = String(panel.dataset.stamp || '');
        if (baru === '' || baru === lama) { return; }
        panel.dataset.stamp = baru;
        window.location.reload();
    }

    /**
     * Gulir otomatis isi kolom: SEMUA tiket tetap ditampilkan; bila tingginya melebihi
     * panel, isinya digulir berulang (dua salinan + animasi) sehingga seluruh nomor
     * terlihat tanpa perlu tulisan "+N tiket lainnya".
     * paksa=true → susun ulang (isi berubah) · paksa=false → hanya sesuaikan ukuran.
     */
    function aturGulir(box, paksa) {
        const gulir = box.querySelector('.chips-gulir');
        if (!gulir) { return; }
        const isi = gulir.querySelector('.chips-isi');
        if (!isi) { return; }

        const mendatar = box.dataset.arah === 'mendatar';
        const arah = mendatar ? 'mendatar' : 'menurun';
        const ukuranIsi = () => (mendatar ? isi.scrollWidth : isi.scrollHeight);
        const ukuranKotak = () => (mendatar ? box.clientWidth : box.clientHeight);
        const perlu = ukuranIsi() > ukuranKotak() + 1;
        const sudahAdaSalinan = !!gulir.querySelector('.chips-isi[data-salinan]');

        /* Isi berubah → bersihkan salinan & animasi lama lalu disusun ulang. */
        if (paksa) {
            gulir.querySelectorAll('.chips-isi[data-salinan]').forEach(function (n) { n.remove(); });
            gulir.classList.remove('is-gulir');
            gulir.removeAttribute('data-arah');
            /* Bersihkan jarak lama: memastikan durasi baru selalu ditulis ulang, termasuk
               saat pemilik mengubah KECEPATAN gulir di Pengaturan (isi sama, kecepatan beda). */
            gulir.removeAttribute('data-jarak');
        }

        if (!perlu) {
            /* Semua muat → tidak perlu bergulir. */
            if (sudahAdaSalinan) {
                gulir.querySelectorAll('.chips-isi[data-salinan]').forEach(function (n) { n.remove(); });
                gulir.classList.remove('is-gulir');
            }
            return;
        }

        /* Belum ada salinan → tambahkan (hanya sekali) supaya gulir menyambung tanpa lompatan. */
        if (!sudahAdaSalinan) {
            const salinan = isi.cloneNode(true);
            salinan.setAttribute('data-salinan', '1');
            salinan.setAttribute('aria-hidden', 'true');
            gulir.appendChild(salinan);
        }

        /*
         * Jarak gulir diukur dari POSISI NYATA salinan kedua (bukan hasil hitungan gap di CSS).
         * Dengan begitu animasi berakhir tepat saat salinan kedua berada di posisi salinan
         * pertama → gulir menyambung tanpa lompatan, berapa pun ukuran celahnya.
         */
        const salinan = gulir.querySelector('.chips-isi[data-salinan]');
        const jarak = salinan
            ? (mendatar ? (salinan.offsetLeft - isi.offsetLeft) : (salinan.offsetTop - isi.offsetTop))
            : 0;
        if (!jarak || jarak <= 0) { return; }
        /*
         * Kecepatan dasar dibuat nyaman dibaca, tetapi bila isinya sangat banyak putaran
         * gulirnya dibatasi (maks 90 detik menurun) supaya tidak terlalu lambat.
         *
         * Baris "OBAT SUDAH DITERIMA" (arah mendatar) memakai KECEPATAN DARI PENGATURAN
         * (px/detik) apa adanya — tidak lagi memakai batas 60 detik per putaran. Alasan nyata:
         * pada hari ramai (274 tiket terukur 50.791 px) batas itu membuat gulirnya menjadi
         * ±846 px/detik sehingga nomornya melintas terlalu cepat dan tidak terbaca.
         * Kolom status (menurun) tidak diubah.
         */
        const kecepatanMendatar = Math.max(3, Number((cfg && cfg.kecepatan_gulir_selesai) > 0
            ? cfg.kecepatan_gulir_selesai : 30));
        const kecepatan = mendatar ? kecepatanMendatar : Math.max(32, jarak / 90);
        gulir.dataset.arah = arah;

        /*
         * Bila gulir sudah berjalan dan jaraknya praktis sama, JANGAN tulis ulang
         * --geser/--durasi: mengubah durasi di tengah animasi bisa membuat isi melompat.
         * Nilai hanya diperbarui bila memang berubah cukup banyak (isi benar-benar berubah).
         */
        const jarakBulat = Math.round(jarak * 100) / 100;
        const jarakLama = parseFloat(gulir.dataset.jarak || '0');
        const berjalan = gulir.classList.contains('is-gulir');
        if (berjalan && Math.abs(jarakLama - jarakBulat) <= 2) {
            return;
        }
        gulir.dataset.jarak = String(jarakBulat);
        gulir.style.setProperty('--geser', jarakBulat + 'px');
        gulir.style.setProperty('--durasi', Math.max(6, jarak / kecepatan).toFixed(1) + 's');
        gulir.classList.add('is-gulir');
    }

    /** Gulir untuk seluruh kolom (isi berubah → paksa; layar berubah → sesuaikan). */
    function aturGulirSemua(paksa) {
        document.querySelectorAll('[data-chips]').forEach(function (box) {
            if (box.querySelector('.chip-empty')) { return; }
            aturGulir(box, paksa);
        });
    }

    function muatkanSemua() {
        /* Isi tidak berubah → cukup sesuaikan ukuran gulirnya (animasi tidak dimulai ulang). */
        aturGulirSemua(false);
        muatkanRiwayat();
        muatkanNomorUtama();
        muatkanPanelPanggilan();
        muatkanJam();
    }

    /** Selaraskan jam display dengan jam server (dipakai pada setiap jawaban). */
    function sinkronJam(serverTime, stamp) {
        const bagian = String(serverTime || '').split(':');
        if (bagian.length === 3) {
            const now = new Date();
            const serverDetik = Number(bagian[0]) * 3600 + Number(bagian[1]) * 60 + Number(bagian[2]);
            const lokalDetik = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
            let beda = serverDetik - lokalDetik;
            if (beda > 43200) { beda -= 86400; }
            if (beda < -43200) { beda += 86400; }
            drift = beda * 1000;
        }
        if (stamp && cfg) { cfg.stamp = stamp; }
    }

    function terapkan(d) {
        cfg = d;
        sinkronJam(d.server_time, d.stamp);

        if (el('d-nama')) { el('d-nama').textContent = d.nama_apotek; }
        if (el('d-alamat')) { el('d-alamat').textContent = d.alamat || ''; }
        if (el('d-judul')) { el('d-judul').textContent = d.judul || ''; }
        if (el('d-tanggal')) { el('d-tanggal').textContent = d.tanggal_label || ''; }
        if (el('d-stamp')) {
            el('d-stamp').textContent = 'Total ' + d.jumlah.total + ' tiket hari ini · pembaruan seketika';
        }
        if (d.logo_url && el('d-logo')) {
            el('d-logo').src = d.logo_url;
            el('d-logo').classList.remove('is-hidden');
            if (el('d-mark')) { el('d-mark').classList.add('is-hidden'); }
        }

        /* Nomor utama */
        const dip = d.dipanggil;
        if (el('d-kode') && el('d-angka')) {
            if (dip) {
                el('d-kode').textContent = dip.kode;
                el('d-angka').textContent = String(dip.nomor).padStart(3, '0');
                /* Teks "silakan menuju loket ..." sengaja tidak ditampilkan (permintaan pemilik). */
                el('d-loket').textContent = '';
                el('d-status').textContent = dip.status_label;
                el('d-jam').textContent = 'Dipanggil ' + dip.jam_panggil;
                el('d-tunggu').textContent = d.tampil_tunggu ? '· waktu tunggu ' + dip.tunggu_teks : '';
            } else {
                el('d-kode').textContent = '—';
                el('d-angka').textContent = '---';
                el('d-loket').textContent = 'Silakan menunggu nomor Anda dipanggil';
                el('d-status').textContent = '';
                el('d-jam').textContent = '';
                el('d-tunggu').textContent = '';
            }
        }

        /* Riwayat panggilan sebelumnya */
        const riw = el('d-riwayat');
        if (riw) {
            if (!d.tampil_riwayat || !d.riwayat.length) {
                riw.innerHTML = '<em>belum ada</em>';
            } else {
                riw.innerHTML = d.riwayat.map(function (t) {
                    return '<span class="riwayat-chip">' + t.kode_tiket + '<em>' + t.jam_panggil + '</em></span>';
                }).join('');
            }
        }

        /* 4 kolom status tracking */
        /* Kolom chip hanya digambar ulang bila STRUKTUR berubah (tiket baru / status berubah).
           Perubahan hitungan waktu tunggu tiap detik TIDAK memicu gambar ulang, sehingga animasi
           gulir otomatis tidak pernah kembali ke atas di tengah jalan. */
        const cap = d.stamp || d.rev;
        if (cap !== revChips) {
            revChips = cap;
            STATUS_ORDER.forEach(function (st) {
                isiChips(st, d.groups[st] || [], (d.jumlah && d.jumlah[st]) || 0);
            });
        }

        /* Teks berjalan */
        pasangMarquee(d.footer_teks, d.kecepatan_gulir);

        /* Panggilan baru → sorot + (opsional) suarakan */
        if (dip && dip.id !== dipanggilId) {
            dipanggilId = dip.id;
            sorot();
            if (audioOn && d.suara && Number(d.suara.aktif) === 1) {
                window.AntrianVoice.bicara(Object.assign({}, d.suara, { loket: d.loket }), dip, null);
            }
        } else if (!dip) {
            dipanggilId = null;
        }

        periksaVideo(d);

        /* Pastikan semuanya masih dimuatkan setelah data berubah. */
        window.requestAnimationFrame(muatkanSemua);
    }

    function sorot() {
        const p = el('call-panel');
        if (!p) { return; }
        p.classList.remove('is-called');
        void p.offsetWidth;          /* paksa animasi dimulai ulang */
        p.classList.add('is-called');

        /* NOMOR TIKET berkedip halus 5 kali tiap ada panggilan (labelnya tidak ikut berkedip). */
        const nomor = el('d-nomor');
        if (nomor) {
            nomor.classList.remove('is-kedip');
            void nomor.offsetWidth;
            nomor.classList.add('is-kedip');
        }
    }

    function pasangMarquee(teks, kecepatan) {
        const track = el('d-marquee');
        const kotak = track ? track.parentElement : null;
        if (!track || !kotak) { return; }
        const isi = (teks || '').trim() || ' ';
        if (track.dataset.teks !== isi) {
            track.dataset.teks = isi;
            const aman = isi.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            track.innerHTML = '<span>' + aman + '</span><span aria-hidden="true">' + aman + '</span>';
        }
        /* Satu putaran = selebar satu salinan teks; durasi = lebar itu / kecepatan. */
        const satuSalinan = Math.max(1, track.scrollWidth / 2);
        const spd = Math.max(10, Number(kecepatan) || 40);
        track.style.animationDuration = Math.max(6, satuSalinan / spd) + 's';
    }

    function tick() {
        if (el('d-clock')) {
            const d = new Date(jamServerMs());
            const p = function (n) { return String(n).padStart(2, '0'); };
            el('d-clock').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        }
        document.querySelectorAll('.chip-status').forEach(function (c) {
            if (c.dataset.selesai === '1' || c.dataset.jalan === '0') { return; }
            /* Salinan kedua (untuk gulir menyambung) tidak perlu dihitung ulang —
               isinya sama dengan salinan pertama, jadi cukup satu kali per tiket. */
            if (c.closest('.chips-isi[data-salinan]')) { return; }
            const mulai = parseSql(c.dataset.sejak);
            if (!mulai) { return; }
            const detik = (jamServerMs() - mulai.getTime()) / 1000;
            const w = c.querySelector('.chip-waktu');
            if (w) { w.textContent = durasi(detik); }
        });
    }

    /**
     * Pemantauan perubahan:
     *  1) minta SIDIK JARI struktur (balasan sangat kecil, milidetik) setiap beberapa saat
     *  2) HANYA bila sidik jari berubah → ambil data penuh & gambar ulang
     *
     * Kenapa tidak menahan koneksi (long-poll/SSE)? Runtime PHP di server ini dapat melayani
     * satu permintaan sekaligus, jadi menahan koneksi akan membekukan aplikasi petugas.
     * Dengan cara ini perubahan muncul dalam ~1 detik, tanpa menahan koneksi, dan kolom chip
     * tidak digambar ulang tanpa alasan sehingga gulir otomatis tetap mulus.
     */
    function muatSekali(url) {
        return fetch(url, { cache: 'no-store' }).then(function (r) { return r.json(); });
    }

    function ambilDataPenuh() {
        return muatSekali('api.php?action=display').then(function (d) {
            if (!d || !d.ok) { throw new Error((d && d.error) || 'data tidak valid'); }
            terapkan(d);
            sudahSiap = true;
            if (el('d-stamp')) { el('d-stamp').classList.remove('is-error'); }
            return d;
        });
    }

    function pantauPerubahan() {
        const cap = (cfg && (cfg.stamp || cfg.rev)) ? (cfg.stamp || cfg.rev) : '';
        muatSekali('api.php?action=display_cek&stamp=' + encodeURIComponent(cap))
            .then(function (c) {
                if (!c || !c.ok) { throw new Error('gagal memeriksa perubahan'); }
                /* Selaraskan jam setiap kali memeriksa (jam display mengikuti jam server). */
                sinkronJam(c.server_time, c.stamp);
                if (cap !== '' && c.stamp !== cap) {
                    /* Ada perubahan struktur → ambil data penuh. */
                    return ambilDataPenuh();
                }
                if (cap === '') {
                    return ambilDataPenuh();
                }
                return null;
            })
            .catch(function () {
                if (!sudahSiap && el('d-stamp')) {
                    el('d-stamp').textContent = 'Menghubungkan ke server… (periksa koneksi jaringan)';
                    el('d-stamp').classList.add('is-error');
                }
                /* Kegagalan jaringan: coba muat data penuh agar tampilan kembali sinkron. */
                ambilDataPenuh().catch(function () {});
            })
            .then(function () {
                /* Pemeriksaan perubahan selalu 1 detik (tidak bergantung setelan) supaya
                   pembaruan terasa seketika. Setelan "interval percobaan ulang" hanya dipakai
                   ketika koneksi bermasalah (lihat cabang catch di atas). */
                window.setTimeout(pantauPerubahan, 1000);
            });
    }

    let videoBersuara = false;
    /**
     * Nyalakan suara video (hanya bila pemilik mengaktifkan "video bersuara").
     * Dipanggil setelah petugas menekan "Aktifkan Suara" — sebelum ada interaksi pengguna,
     * browser mengizinkan pemutaran otomatis HANYA tanpa suara.
     */
    function bunyikanVideo() {
        if (!cfg || !cfg.video || !cfg.video.aktif || Number(cfg.video.suara) !== 1) { return; }
        const frame = el('video-frame');
        if (!frame) { return; }
        frame.querySelectorAll('.video-item').forEach(function (v) { v.muted = false; });
        videoBersuara = true;
        const aktifVideo = frame.querySelector('.video-item.is-aktif');
        if (aktifVideo && aktifVideo.paused) { aktifVideo.play().catch(function () {}); }
    }

    /**
     * Layar penuh otomatis: dipakai saat display dibuka dari tombol "Display Monitor 2".
     * Browser hanya mengizinkan layar penuh setelah ada interaksi pengguna — jendela yang baru
     * dibuka dari klik tombol biasanya masih memilikinya, tapi kalau ditolak kita beri tahu
     * petugas untuk menekan tombol Layar Penuh sekali (tidak mengganggu tampilan display).
     */
    function cobaLayarPenuh() {
        const param = new URLSearchParams(window.location.search);
        if (param.get('fs') !== '1') { return; }
        const akar = document.documentElement;
        if (!akar.requestFullscreen) { return; }

        const petunjuk = function () {
            const wrap = el('toast-wrap');
            if (!wrap) { return; }
            const div = document.createElement('div');
            div.className = 'toast toast-info';
            div.textContent = 'Tekan tombol Layar Penuh (ikon TV, kanan atas) sekali untuk mode penuh.';
            wrap.appendChild(div);
            window.setTimeout(function () { div.classList.add('is-hilang'); }, 9000);
            window.setTimeout(function () { div.remove(); }, 9800);
        };

        const minta = function (beriPetunjuk) {
            try {
                const janji = akar.requestFullscreen();
                if (janji && janji.catch) {
                    janji.catch(function () { if (beriPetunjuk) { petunjuk(); } });
                }
            } catch (e) {
                if (beriPetunjuk) { petunjuk(); }
            }
        };

        minta(true);
        /* Bila percobaan pertama ditolak, coba lagi pada interaksi pertama pengguna. */
        window.setTimeout(function () {
            if (!document.fullscreenElement) { minta(false); }
        }, 1200);
        document.addEventListener('click', function sekali() {
            document.removeEventListener('click', sekali);
            if (!document.fullscreenElement) { minta(false); }
        });
    }

    /* --- tombol --- */
    function pasangTombol() {
        const btnSuara = el('btn-suara');
        const label = el('btn-suara-label');
        const perbaruiLabel = function () {
            const teks = audioOn ? 'Suara Aktif' : 'Aktifkan Suara';
            /* Teks tombol hanya untuk pembaca layar; status juga dijelaskan lewat
               tooltip (title) dan aria-label karena tombolnya kini hanya ikon. */
            if (label) { label.textContent = teks; }
            if (btnSuara) {
                btnSuara.classList.toggle('is-on', audioOn);
                btnSuara.title = teks + (audioOn ? ' — klik untuk mematikan pengumuman' : ' — klik untuk mengaktifkan pengumuman');
                btnSuara.setAttribute('aria-label', teks);
            }
        };
        perbaruiLabel();

        if (btnSuara) {
            btnSuara.addEventListener('click', function () {
                audioOn = !audioOn;
                window.localStorage.setItem('ap_audio_display', audioOn ? '1' : '0');
                perbaruiLabel();
                if (audioOn) {
                    /* Interaksi pengguna = momen daftar suara perangkat muncul: paksa muat ulang. */
                    window.AntrianVoice.nada(0.5);
                    window.AntrianVoice.muatSuara(true);
                    /* Bila pemilik mengaktifkan video bersuara, nyalakan suara video sekarang. */
                    bunyikanVideo();
                } else {
                    if (window.speechSynthesis) { window.speechSynthesis.cancel(); }
                    /* Matikan juga suara video. */
                    const frame = el('video-frame');
                    if (frame) {
                        frame.querySelectorAll('.video-item').forEach(function (v) { v.muted = true; });
                        videoBersuara = false;
                    }
                }
            });
        }

        const btnFull = el('btn-full');
        if (btnFull) {
            btnFull.addEventListener('click', function () {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(function () {});
                } else {
                    document.exitFullscreen();
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        /* Bersihkan penanda kedip setelah animasinya selesai. */
        const nomor = el('d-nomor');
        if (nomor) {
            nomor.addEventListener('animationend', function () {
                nomor.classList.remove('is-kedip');
            });
        }

        pasangTombol();
        aturSkala();
        pasangVideo();
        cobaLayarPenuh();
        tick();
        /* Muat data awal sekali, lalu mulai memantau perubahan. */
        ambilDataPenuh()
            .catch(function () {
                if (el('d-stamp')) {
                    el('d-stamp').textContent = 'Menghubungkan ke server… (periksa koneksi jaringan)';
                    el('d-stamp').classList.add('is-error');
                }
            })
            .then(pantauPerubahan);
        window.setInterval(tick, 1000);
        /* Suara butuh interaksi pengguna sekali; aktifkan otomatis bila sudah pernah dinyalakan. */
        if (audioOn) {
            window.AntrianVoice.muatSuara(true);
            /* Bila sebelumnya petugas sudah menekan "Aktifkan Suara" dan video diatur bersuara,
               coba nyalakan suara video juga (bila browser menolak, video tetap jalan tanpa suara). */
            window.setTimeout(bunyikanVideo, 1500);
        }
    });

    /* Layar penuh / ukuran jendela berubah → hitung ulang skala, isi, dan kotak video. */
    window.addEventListener('resize', function () {
        aturSkala();
        window.requestAnimationFrame(muatkanSemua);
    });
    window.addEventListener('orientationchange', function () {
        window.setTimeout(function () { aturSkala(); muatkanSemua(); }, 250);
    });
    document.addEventListener('fullscreenchange', function () {
        window.setTimeout(function () { aturSkala(); muatkanSemua(); }, 120);
    });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            aturSkala();
            window.requestAnimationFrame(muatkanSemua);
        });
    }
    /* Kembali dari layar mati / tab tidak aktif → ambil data terbaru segera. */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            muatSekali('api.php?action=display')
                .then(function (d) { if (d && d.ok) { terapkan(d); } })
                .catch(function () {});
        }
    });
})();
