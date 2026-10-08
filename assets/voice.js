/*
 * Suara panggilan antrian (dipakai halaman Panggil Antrian, Display, dan Pengaturan).
 * Memakai Web Speech API (speechSynthesis) dengan default suara Google Bahasa Indonesia (id-ID).
 */
window.AntrianVoice = (function () {
    const DIGIT = ['nol', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan'];
    let audioCtx = null;
    let voicesCache = [];
    let cekTerakhir = 0;

    function didukung() {
        return typeof window.speechSynthesis !== 'undefined' && typeof window.SpeechSynthesisUtterance !== 'undefined';
    }

    function muatSuara(paksa) {
        return new Promise(function (resolve) {
            if (!didukung()) { resolve([]); return; }
            let v = window.speechSynthesis.getVoices();
            if (v && v.length) { voicesCache = v; cekTerakhir = Date.now(); resolve(v); return; }

            /* Perangkat belum melaporkan daftar suara (biasanya baru tersedia setelah ada
               interaksi pengguna). Jangan menunggu 3 detik pada SETIAP pengumuman: cek panjang
               hanya sekali, lalu ulangi paling cepat setiap 30 detik. */
            if (!paksa && cekTerakhir > 0 && (Date.now() - cekTerakhir) < 30000) {
                resolve(voicesCache);
                return;
            }

            let selesai = false;
            const tunggu = function () {
                v = window.speechSynthesis.getVoices();
                if (v && v.length) {
                    if (selesai) { return true; }
                    selesai = true;
                    voicesCache = v;
                    cekTerakhir = Date.now();
                    resolve(v);
                    return true;
                }
                return false;
            };
            window.speechSynthesis.addEventListener('voiceschanged', tunggu);
            let coba = 0;
            const id = setInterval(function () {
                coba++;
                if (tunggu() || coba > 20) {
                    clearInterval(id);
                    if (!selesai) {
                        selesai = true;
                        cekTerakhir = Date.now();
                        resolve(voicesCache);
                    }
                }
            }, 150);
        });
    }

    /** Pilih voice: cocokkan nama tersimpan → Google + id → bahasa id → voice pertama. */
    function pilihVoice(cfg, voices) {
        const list = (voices && voices.length) ? voices : voicesCache;
        if (!list || !list.length) { return null; }
        const ingin = String((cfg && cfg.voice) || '').toLowerCase().trim();
        const lang = String((cfg && cfg.lang) || 'id-ID').toLowerCase();
        const base = lang.split('-')[0];
        let v = null;
        if (ingin) {
            v = list.find(function (x) { return x.name.toLowerCase() === ingin; })
             || list.find(function (x) { return x.name.toLowerCase().indexOf(ingin) === 0; })
             || list.find(function (x) { return x.name.toLowerCase().indexOf(ingin) >= 0; });
        }
        if (!v) { v = list.find(function (x) { return /google/i.test(x.name) && x.lang.toLowerCase().indexOf(base) === 0; }); }
        if (!v) { v = list.find(function (x) { return x.lang.toLowerCase().indexOf(base) === 0; }); }
        if (!v) { v = list.find(function (x) { return /indonesia|indonesian/i.test(x.name); }); }
        return v || list[0];
    }

    function ejaNomor(nomor, eja) {
        const s = String(nomor).padStart(3, '0');
        if (!eja || eja === '0' || eja === 0 || eja === false) { return String(nomor); }
        return s.split('').map(function (d) { return DIGIT[Number(d)] || d; }).join(' ');
    }

    /** Isi penanda {kode} {nomor} {kode_tiket} {loket} pada template pengumuman. */
    function buatTeks(cfg, tiket) {
        /* teks_langsung: teks diucapkan APA ADANYA. Dipakai pengumuman bebas (halaman Panggil
           Antrian) yang tidak berisi nomor tiket — jadi penanda {kode}/{nomor} tidak diisi
           dengan "undefined"/"nol nol nol". Pemanggil lama tidak memakai opsi ini sehingga
           perilakunya tidak berubah. */
        if (cfg && cfg.teks_langsung) { return String(cfg.template || ''); }
        let t = String((cfg && cfg.template) || 'Nomor antrian, kode {kode}, {nomor}. Silakan menuju loket pengambilan obat.');
        t = t.replace(/\{kode\}/g, tiket.kode)
             .replace(/\{nomor\}/g, ejaNomor(tiket.nomor, cfg ? cfg.eja_digit : 1))
             .replace(/\{kode_tiket\}/g, tiket.kode_tiket)
             .replace(/\{nomor_asli\}/g, String(tiket.nomor))
             .replace(/\{loket\}/g, (cfg && cfg.loket) ? cfg.loket : 'loket pengambilan obat');
        return t;
    }

    function nada(volume) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') { audioCtx.resume(); }
            const vol = (volume === undefined || volume === null) ? 0.6 : Number(volume);
            const bunyi = function (freq, mulai, durasi) {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, audioCtx.currentTime + mulai);
                gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol * 0.5), audioCtx.currentTime + mulai + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + mulai + durasi);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + mulai);
                osc.stop(audioCtx.currentTime + mulai + durasi + 0.05);
            };
            bunyi(988, 0, 0.28);
            bunyi(740, 0.32, 0.42);
        } catch (e) { /* perangkat tanpa audio tetap berjalan tanpa nada */ }
    }

    /**
     * Ucapkan sebuah tiket. cfg: {voice, lang, rate, volume, template, eja_digit, chime, ulang}
     * onStatus(pesan) opsional untuk menampilkan status di layar.
     */
    function bicara(cfg, tiket, onStatus) {
        return new Promise(function (resolve) {
            if (!didukung()) {
                if (onStatus) { onStatus('Perangkat ini tidak mendukung suara (Web Speech API).'); }
                resolve(false);
                return;
            }
            return muatSuara().then(function (voices) {
                const synth = window.speechSynthesis;
                /*
                 * Mode "antre": bila pengumuman sebelumnya masih berbunyi, JANGAN diputus —
                 * pengumuman baru akan menunggu (antrean bawaan speechSynthesis). Ini penting
                 * supaya petugas bisa memanggil beberapa nomor berurutan tanpa saling memotong.
                 */
                const sedangBicara = !!(synth.speaking || synth.pending);
                const antre = !!(cfg && (cfg.antre === true || cfg.antre === 1 || cfg.antre === '1'));
                if (!antre || !sedangBicara) {
                    synth.cancel();
                }
                const teks = buatTeks(cfg, tiket);
                const voice = pilihVoice(cfg, voices);
                const ulang = Math.max(1, Math.min(5, Number((cfg && cfg.ulang) || 3)));
                const pakaiChime = !!(cfg && cfg.chime && cfg.chime !== '0') && !sedangBicara;
                if (pakaiChime) { nada((cfg.volume === undefined ? 0.6 : cfg.volume)); }

                let selesai = 0;
                const mulaiBicara = function () {
                    for (let i = 0; i < ulang; i++) {
                        const u = new SpeechSynthesisUtterance(teks);
                        if (voice) { u.voice = voice; }
                        u.lang = (voice && voice.lang) ? voice.lang : ((cfg && cfg.lang) || 'id-ID');
                        u.rate = Number((cfg && cfg.rate) || 0.95);
                        u.volume = (cfg && cfg.volume !== undefined) ? Number(cfg.volume) : 1;
                        u.onend = function () {
                            selesai++;
                            if (selesai >= ulang) { resolve(true); }
                        };
                        u.onerror = function () {
                            selesai++;
                            if (selesai >= ulang) { resolve(false); }
                        };
                        synth.speak(u);
                    }
                    if (onStatus) {
                        onStatus('Mengumumkan ' + tiket.kode_tiket + (voice ? ' dengan suara ' + voice.name : ''));
                    }
                };
                /* Beri jeda kecil setelah nada pembuka agar tidak bertumpuk. */
                setTimeout(mulaiBicara, pakaiChime ? 850 : 60);
            });
        });
    }

    function namaVoiceTersedia() {
        return muatSuara().then(function (v) {
            return (v || []).map(function (x) { return x.name + ' — ' + x.lang; });
        });
    }

    return {
        didukung: didukung,
        muatSuara: muatSuara,
        pilihVoice: pilihVoice,
        buatTeks: buatTeks,
        ejaNomor: ejaNomor,
        nada: nada,
        bicara: bicara,
        namaVoiceTersedia: namaVoiceTersedia
    };
})();
