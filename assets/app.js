/* ===== Supervisi Guru — interaksi halaman & grafik ===== */
(function () {
  'use strict';

  /* ---------- Sidebar (mobile) ---------- */
  function initNav() {
    var t = document.getElementById('navToggle');
    var s = document.getElementById('sidebar');
    var sc = document.getElementById('scrim');
    if (!t || !s) return;
    function close() { s.classList.remove('open'); if (sc) sc.classList.remove('on'); }
    t.addEventListener('click', function () {
      s.classList.toggle('open');
      if (sc) sc.classList.toggle('on', s.classList.contains('open'));
    });
    if (sc) sc.addEventListener('click', close);
  }

  /* ---------- Konfirmasi hapus ---------- */
  function initConfirm() {
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (f.matches('form[data-confirm]')) {
        if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
      }
    });
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest('a[data-confirm]');
      if (a && !window.confirm(a.getAttribute('data-confirm'))) ev.preventDefault();
    });
  }

  /* ---------- Skala skor: highlight + hitung nilai live ---------- */
  function predikatOf(n) {
    var a = window.VS_TH || { a: 90, b: 80, c: 70 };
    if (n >= a.a) return 'A';
    if (n >= a.b) return 'B';
    if (n >= a.c) return 'C';
    return 'D';
  }
  var LBL = { A: 'Amat Baik', B: 'Baik', C: 'Cukup', D: 'Perlu Pembinaan' };

  function initSkorForm() {
    var form = document.getElementById('formPenilaian');
    if (!form) return;
    var radios = form.querySelectorAll('input[type=radio][data-maks]');
    if (!radios.length) return;

    var out = {
      total: document.getElementById('liveTotal'),
      maks: document.getElementById('liveMaks'),
      nilai: document.getElementById('liveNilai'),
      pred: document.getElementById('livePredikat'),
      bar: document.getElementById('liveBar'),
      terisi: document.getElementById('liveTerisi')
    };

    function refresh() {
      var total = 0, maks = 0, filled = 0;
      radios.forEach(function (r) {
        if (r.checked) {
          total += parseFloat(r.value) || 0;
          filled++;
        }
      });
      var groups = form.querySelectorAll('.butir').length;
      var m = 0;
      radios.forEach(function (r) {
        if (r.name === radios[0].name || r.dataset.first === '1') { /* noop */ }
      });
      // hitung maksimal dari setiap grup (satu grup = satu name)
      var seen = {};
      radios.forEach(function (r) {
        if (!seen[r.name]) {
          seen[r.name] = true;
          m += parseFloat(r.getAttribute('data-maks')) || 0;
        }
      });
      var nilai = m > 0 ? Math.round((total / m) * 10000) / 100 : 0;
      var p = predikatOf(nilai);
      if (out.total) out.total.textContent = total;
      if (out.maks) out.maks.textContent = m;
      if (out.nilai) out.nilai.textContent = nilai.toFixed(2).replace('.', ',');
      if (out.pred) {
        out.pred.textContent = p + ' — ' + LBL[p];
        out.pred.className = 'pill pill-' + p;
      }
      if (out.bar) { out.bar.style.width = Math.max(0, Math.min(100, nilai)) + '%'; out.bar.className = 'lv' + p; }
      if (out.terisi) out.terisi.textContent = filled + ' / ' + groups;

      form.querySelectorAll('.skala label').forEach(function (lb) {
        var inp = lb.querySelector('input');
        lb.classList.toggle('sel', !!(inp && inp.checked));
      });
    }

    form.addEventListener('change', function (ev) {
      if (ev.target.matches('input[type=radio][data-maks]')) refresh();
    });
    form.addEventListener('submit', function (ev) {
      var empty = [];
      var names = {};
      radios.forEach(function (r) {
        if (!names[r.name]) { names[r.name] = r.dataset.label || r.name; }
      });
      Object.keys(names).forEach(function (nm) {
        if (!form.querySelector('input[name="' + nm + '"]:checked')) empty.push(names[nm]);
      });
      if (empty.length) {
        ev.preventDefault();
        alert('Masih ada ' + empty.length + ' butir yang belum diberi skor:\n- ' + empty.slice(0, 8).join('\n- ') + (empty.length > 8 ? '\n- ...' : ''));
        var first = form.querySelector('input[name="' + Object.keys(names)[0] + '"]');
        if (first) first.closest('.butir').scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    refresh();
  }

  /* ---------- Saring tabel cepat ---------- */
  function initTableSearch() {
    var inp = document.getElementById('tblSearch');
    if (!inp) return;
    var tbl = document.getElementById(inp.dataset.target || '');
    if (!tbl) return;
    inp.addEventListener('input', function () {
      var v = inp.value.toLowerCase().trim();
      var rows = tbl.querySelectorAll('tbody tr');
      var shown = 0;
      rows.forEach(function (tr) {
        if (tr.dataset.nofilter === '1') return;
        var hit = tr.textContent.toLowerCase().indexOf(v) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) shown++;
      });
      var c = document.getElementById('tblCount');
      if (c) c.textContent = shown;
    });
  }

  /* ---------- CARI NAMA SEKOLAH pada daftar Peringkat Sekolah (dasbor Super Admin) ----------
   * Permintaan pemilik: kotak cari di kepala kartu "Peringkat Sekolah" supaya pencarian nama
   * sekolah cepat pada daftar yang panjang (88+ lembaga).
   *
   * Penyaringan berjalan SAAT DIKETIK (tanpa memuat ulang halaman). Yang dikerjakan:
   *   1. menyembunyikan/menampilkan baris menurut `data-cari` (nama sekolah + NPSN + kepala
   *      sekolah, sudah huruf kecil dari server) — BUKAN textContent seluruh baris, supaya
   *      angka/predikat tidak ikut dicocokkan dengan kata kunci;
   *   2. MENOMORI ULANG kolom "#" (tanpa ini nomornya melompat: 1, 2, 5, 9 …);
   *   3. menampilkan jumlah hasil + tombol "Tampilkan semua" & tombol ✕ untuk menghapus kata kunci;
   *   4. menampilkan baris "tidak ada yang cocok" bila hasilnya nol.
   * Beberapa kata kunci dipisah spasi harus SEMUA cocok (mis. "mts bahrul").
   * Tanpa JavaScript kotak cari tidak berfungsi, tetapi tabel tetap tampil utuh.
   */
  function initCariSekolah() {
    var inp = document.getElementById('cariSekolah');
    if (!inp) return;
    var tbl = document.getElementById(inp.dataset.target || 'tblPeringkat');
    if (!tbl) return;
    var tbody = tbl.querySelector('tbody');
    if (!tbody) return;
    var kosong = document.getElementById('cariKosong');
    var bersih = document.getElementById('cariSekolahBersih');
    var info = document.getElementById('cariInfo');
    var infoJumlah = document.getElementById('cariJumlah');
    var tombolSemua = document.getElementById('cariTampilSemua');

    // Teks yang dicari = SEL NAMA SEKOLAH (kolom ke-2: nama + baris kecil NPSN/kepala sekolah).
    // SENGAJA tidak memakai textContent seluruh baris: angka guru/supervisi/nilai/predikat ikut
    // tercocokkan kalau begitu (mis. mengetik "7" akan menampilkan sekolah dengan nilai 7x,xx).
    // Teks dihitung SEKALI di sini (bukan tiap ketikan) supaya penyaringan tetap ringan pada
    // daftar yang panjang. Baris `#cariKosong` (keadaan kosong) tidak ikut disaring.
    var baris = [].slice.call(tbody.querySelectorAll('tr')).filter(function (tr) {
      return tr.id !== 'cariKosong';
    });
    baris.forEach(function (tr) {
      var sel = tr.cells && tr.cells.length > 1 ? tr.cells[1] : tr;
      var teks = (sel.textContent || '').toLowerCase().replace(/\s+/g, ' ').trim();
      tr.setAttribute('data-cari', teks);
    });

    function terapkan() {
      var kunci = (inp.value || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
      var tampil = 0;
      baris.forEach(function (tr) {
        var teks = tr.getAttribute('data-cari') || '';
        var cocok = kunci.every(function (k) { return teks.indexOf(k) !== -1; });
        tr.hidden = !cocok;
        if (cocok) {
          tampil++;
          // Nomor urut dirapikan agar tetap 1..n setelah penyaringan.
          var sel = tr.querySelector('td b');
          if (sel) sel.textContent = String(tampil);
        }
      });
      if (kosong) kosong.hidden = tampil > 0 || kunci.length === 0;
      if (bersih) bersih.hidden = kunci.length === 0;
      if (info) info.hidden = kunci.length === 0;
      if (infoJumlah) {
        infoJumlah.textContent = tampil === 1
          ? 'Ditemukan 1 sekolah/madrasah.'
          : 'Ditemukan ' + tampil + ' sekolah/madrasah.';
      }
    }

    function bersihkan() {
      inp.value = '';
      terapkan();
      inp.focus();
    }

    inp.addEventListener('input', terapkan);
    inp.addEventListener('search', terapkan);
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); bersihkan(); }
    });
    if (bersih) bersih.addEventListener('click', bersihkan);
    if (tombolSemua) tombolSemua.addEventListener('click', bersihkan);
    terapkan();   // jaga-jaga bila peramban memulihkan isi kotak cari setelah muat ulang
  }

  /* ---------- Foto profil: pratinjau & pemeriksaan ukuran ---------- */
  function initFoto() {
    var inp = document.getElementById('foto');
    if (!inp) return;
    var wrap = document.getElementById('fotoPreviewWrap');
    var prev = document.getElementById('fotoPreview');
    var info = document.getElementById('fotoUkuran');
    var hint = document.getElementById('fotoHint');
    var btn = document.getElementById('btnFoto');
    var form = document.getElementById('formFoto');
    var status = document.getElementById('fotoStatus');
    var MAKS = 15 * 1024 * 1024;
    var OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    function mb(b) { return (b / 1048576).toFixed(b < 1048576 ? 2 : 1).replace('.', ',') + ' MB'; }

    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) return;
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (OK.indexOf(ext) === -1) {
        hint.innerHTML = '<b style="color:#b91c1c">Format .' + ext + ' tidak didukung.</b> Pakai JPG, PNG, GIF, WEBP, atau SVG.';
        wrap.hidden = true; btn.disabled = true; return;
      }
      if (f.size > MAKS) {
        hint.innerHTML = '<b style="color:#b91c1c">Terlalu besar (' + mb(f.size) + ').</b> Maksimal 15 MB — perkecil dulu fotonya.';
        wrap.hidden = true; btn.disabled = true; return;
      }
      btn.disabled = false;
      hint.innerHTML = 'Berkas siap diunggah: <b>' + f.name.replace(/[<>&"]/g, '') + '</b>';
      if (info) info.textContent = mb(f.size);
      if (ext === 'svg') {
        if (wrap) wrap.hidden = true;
        return;
      }
      var fr = new FileReader();
      fr.onload = function () {
        prev.innerHTML = '<img src="' + fr.result + '" alt="Pratinjau foto">';
        if (wrap) wrap.hidden = false;
      };
      fr.readAsDataURL(f);
    });

    if (form) {
      form.addEventListener('submit', function () {
        if (btn) { btn.disabled = true; btn.textContent = 'Mengunggah...'; }
        if (status) status.textContent = 'Mohon tunggu, foto sedang diunggah.';
      });
    }
  }

  /* ---------- Logo sekolah: pratinjau & pemeriksaan ukuran ---------- */
  function initLogo() {
    var inp = document.getElementById('logo');
    if (!inp) return;
    var wrap = document.getElementById('logoPreviewWrap');
    var prev = document.getElementById('logoPreview');
    var info = document.getElementById('logoUkuran');
    var hint = document.getElementById('logoHint');
    var btn = document.getElementById('btnLogo');
    var form = document.getElementById('formLogo');
    var status = document.getElementById('logoStatus');
    var MAKS = 15 * 1024 * 1024;
    var OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    function mb(b) { return (b / 1048576).toFixed(b < 1048576 ? 2 : 1).replace('.', ',') + ' MB'; }

    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) return;
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (OK.indexOf(ext) === -1) {
        hint.innerHTML = '<b style="color:#b91c1c">Format .' + ext + ' tidak didukung.</b> Pakai PNG, JPG, GIF, WEBP, atau SVG.';
        if (wrap) wrap.hidden = true; btn.disabled = true; return;
      }
      if (f.size > MAKS) {
        hint.innerHTML = '<b style="color:#b91c1c">Terlalu besar (' + mb(f.size) + ').</b> Maksimal 15 MB — perkecil dulu berkasnya.';
        if (wrap) wrap.hidden = true; btn.disabled = true; return;
      }
      btn.disabled = false;
      hint.innerHTML = 'Berkas siap diunggah: <b>' + f.name.replace(/[<>&"]/g, '') + '</b>';
      if (info) info.textContent = mb(f.size);
      if (ext === 'svg') {
        if (wrap) wrap.hidden = true;
        return;
      }
      var fr = new FileReader();
      fr.onload = function () {
        prev.innerHTML = '<img src="' + fr.result + '" alt="Pratinjau logo">';
        if (wrap) wrap.hidden = false;
      };
      fr.readAsDataURL(f);
    });

    if (form) {
      form.addEventListener('submit', function () {
        if (btn) { btn.disabled = true; }
        if (status) status.textContent = 'Mohon tunggu, logo sedang diunggah.';
      });
    }
  }

  /* ---------- Hitung mundur MASA AKTIF aplikasi (Status & Aktivasi) ----------
     Permintaan pemilik: masa aktif 1 tahun (365 hari) dengan hitungan mundur. Nilai awal sudah
     dicetak PHP (tetap terbaca walau skrip gagal dimuat); skrip ini hanya memperbaruinya tiap detik.
     Acuannya WAKTU SERVER (data-berakhir & data-sekarang), bukan jam perangkat pengguna. */
  function initHitungMundur() {
    // Semua blok hitung mundur yang ada di halaman (masa aktif 365 hari DAN masa uji coba 15 hari
    // bagi sekolah yang belum diaktivasi) diperbarui dengan cara yang sama, tiap 1 detik.
    var daftar = document.querySelectorAll('[data-hitung-mundur]');
    if (!daftar.length) return;
    var dasar = parseInt(daftar[0].getAttribute('data-sekarang'), 10);
    if (!isFinite(dasar) || dasar <= 0) return;
    var mulai = Date.now();
    var jam = [];
    function dua(n) { return (n < 10 ? '0' : '') + n; }
    Array.prototype.forEach.call(daftar, function (kotak) {
      var akhir = parseInt(kotak.getAttribute('data-berakhir'), 10);
      if (!isFinite(akhir) || akhir <= 0) return;
      jam.push({
        kotak: kotak,
        akhir: akhir,
        simpul: {
          hari: kotak.querySelector('[data-hm="hari"]'),
          jam: kotak.querySelector('[data-hm="jam"]'),
          menit: kotak.querySelector('[data-hm="menit"]'),
          detik: kotak.querySelector('[data-hm="detik"]')
        },
        ket: kotak.querySelector('.hitung-mundur-ket'),
        // Kalimat yang dipakai saat waktunya benar-benar habis (bisa dibedakan per blok).
        ketHabis: kotak.getAttribute('data-habis-ket') || 'Masa aktif baru saja berakhir — memuat ulang halaman...',
        sudahSelesai: false
      });
    });
    if (!jam.length) return;
    function gambar() {
      var lewat = Math.floor((Date.now() - mulai) / 1000);
      jam.forEach(function (b) {
        var sisa = b.akhir - (dasar + lewat);
        if (sisa < 0) sisa = 0;
        var h = Math.floor(sisa / 86400);
        var j = Math.floor((sisa % 86400) / 3600);
        var m = Math.floor((sisa % 3600) / 60);
        var d = sisa % 60;
        if (b.simpul.hari) b.simpul.hari.textContent = String(h);
        if (b.simpul.jam) b.simpul.jam.textContent = dua(j);
        if (b.simpul.menit) b.simpul.menit.textContent = dua(m);
        if (b.simpul.detik) b.simpul.detik.textContent = dua(d);
        if (b.ket) b.ket.textContent = 'Sisa ' + h + ' hari ' + dua(j) + ':' + dua(m) + ':' + dua(d);
        if (sisa <= 0 && !b.sudahSelesai) {
          b.sudahSelesai = true;
          b.kotak.classList.add('habis');
          if (b.ket) b.ket.textContent = b.ketHabis;
          window.setTimeout(function () { window.location.reload(); }, 2500);
        }
      });
    }
    gambar();
    window.setInterval(gambar, 1000);
  }

  /* ---------- Isi "Tentang Aplikasi" pada halaman masuk (tentang.php) ----------
     Hanya PELENGKAP: nama langsung dipratinjau sambil diketik dan berkas foto diperiksa
     ekstensi/ukurannya sebelum dikirim. Unggahan sendiri memakai form multipart biasa,
     jadi tetap berjalan walau skrip ini gagal dimuat. */
  function initTentang() {
    var form = document.getElementById('formTentang');
    if (!form) return;
    var MAKS = 15 * 1024 * 1024;
    var OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    var btn = document.getElementById('btnTentang');
    var status = document.getElementById('tentangStatus');

    function mb(b) { return (b / 1048576).toFixed(b < 1048576 ? 2 : 1).replace('.', ',') + ' MB'; }

    // Nama pada tiap tempat foto langsung tercetak pada pratinjaunya.
    Array.prototype.forEach.call(document.querySelectorAll('[data-pratinjau-nama]'), function (inp) {
      var tgt = document.getElementById(inp.getAttribute('data-pratinjau-nama'));
      if (!tgt) return;
      inp.addEventListener('input', function () {
        var v = inp.value.trim();
        tgt.innerHTML = v === '' ? '<span class="mut">(nama belum diisi)</span>' : '';
        if (v !== '') tgt.textContent = v;
      });
    });

    // Pratinjau + pemeriksaan berkas sebelum dikirim.
    Array.prototype.forEach.call(document.querySelectorAll('[data-pratinjau-foto]'), function (inp) {
      var bingkai = document.getElementById(inp.getAttribute('data-pratinjau-foto'));
      var hint = document.getElementById('hintFoto' + (inp.id.split('_').pop() || ''));
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) return;
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (OK.indexOf(ext) === -1) {
          if (hint) hint.innerHTML = '<b style="color:#b91c1c">Format .' + ext + ' tidak didukung.</b> Pakai PNG, JPG, GIF, WEBP, atau SVG.';
          if (btn) btn.disabled = true;
          return;
        }
        if (f.size > MAKS) {
          if (hint) hint.innerHTML = '<b style="color:#b91c1c">Terlalu besar (' + mb(f.size) + ').</b> Maksimal 15 MB.';
          if (btn) btn.disabled = true;
          return;
        }
        if (btn) btn.disabled = false;
        if (hint) hint.innerHTML = 'Berkas siap diunggah: <b>' + f.name.replace(/[<>&"]/g, '') + '</b> (' + mb(f.size) + ')';
        if (!bingkai || ext === 'svg') return;
        var fr = new FileReader();
        fr.onload = function () {
          bingkai.innerHTML = '<img src="' + fr.result + '" alt="Pratinjau foto">';
        };
        fr.readAsDataURL(f);
      });
    });

    form.addEventListener('submit', function () {
      if (btn) btn.disabled = true;
      if (status) status.textContent = 'Mohon tunggu, isian sedang disimpan…';
    });
  }

  /* ---------- Kirim Dokumen ke Pengawas (dokumen_kirim.php) ----------
     Hanya PELENGKAP: pratinjau nama/ukuran berkas yang dipilih + pemeriksaan ekstensi & ukuran
     sebelum form multipart dikirim. Unggahan tetap berjalan walau skrip ini gagal dimuat. */
  function initDokumen() {
    var form = document.getElementById('formKirimDokumen');
    if (!form) return;
    var inp = document.getElementById('berkas');
    var hint = document.getElementById('berkasHint');
    var btn = document.getElementById('btnKirimDokumen');
    var status = document.getElementById('dokumenStatus');
    if (!inp) return;
    var OK = ['pdf', 'docx', 'xlsx', 'pptx', 'csv', 'txt', 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    var MAKS = parseInt(form.getAttribute('data-maks') || '0', 10) || (15 * 1024 * 1024);
    function mb(b) { return (b / 1048576).toFixed(b < 1048576 ? 2 : 1).replace('.', ',') + ' MB'; }
    var pilihan;
    function siapkan() {
      pilihan = form.querySelector('select[name="jenis_id"]');
      if (pilihan && !pilihan.getAttribute('data-asli')) {
        pilihan.setAttribute('data-asli', pilihan.innerHTML);
      }
    }
    siapkan();
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f) return;
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (OK.indexOf(ext) === -1) {
        hint.innerHTML = '<b style="color:#b91c1c">Format .' + ext + ' tidak didukung.</b> Pakai PDF, DOCX, XLSX, PPTX, CSV, TXT, ZIP, atau gambar.';
        if (btn) btn.disabled = true;
        return;
      }
      if (f.size > MAKS) {
        hint.innerHTML = '<b style="color:#b91c1c">Terlalu besar (' + mb(f.size) + ').</b> Maksimal ' + mb(MAKS) + '.';
        if (btn) btn.disabled = true;
        return;
      }
      if (btn) btn.disabled = false;
      hint.innerHTML = 'Berkas siap dikirim: <b>' + f.name.replace(/[<>&"]/g, '') + '</b> (' + mb(f.size) + ')';
    });
    form.addEventListener('submit', function () {
      if (btn) btn.disabled = true;
      if (status) status.textContent = 'Mohon tunggu, dokumen sedang dikirim…';
    });
  }

  /* ---------- Bukti foto pengamatan pembelajaran ----------
     Unggahan memakai FORM BIASA (multipart) — JavaScript hanya menyiapkan pratinjau,
     memeriksa berkas lebih awal, dan menonaktifkan tombol saat mengirim. Jadi unggahan
     tetap berjalan walau skrip ini gagal dimuat. */
  function initFotoPengamatan() {
    var form = document.getElementById('formFotoPengamatan');
    if (!form) return;
    var inp = document.getElementById('fotoPengamatan');
    var btn = document.getElementById('btnFotoPengamatan');
    var prev = document.getElementById('fotoPengamatanPreview');
    var wrap = document.getElementById('fotoPengamatanPreviewWrap');
    var hint = document.getElementById('fotoPengamatanHint');
    var status = document.getElementById('fotoPengamatanStatus');
    var MAKS_BYTES = 15 * 1024 * 1024;   // per foto (FOTO_MAKS_BYTE)
    var MAKS_TOTAL = 18 * 1024 * 1024;   // total sekali kirim (aman di bawah post_max_size 21 MB)
    var OK = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    var sisa = inp ? parseInt(inp.getAttribute('data-sisa') || '1', 10) : 1;
    var sisaHint = hint ? hint.innerHTML : '';

    function mb(b) { return (b / 1048576).toFixed(b < 1048576 ? 2 : 1).replace('.', ',') + ' MB'; }
    function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[<>&"]/g, ''); }
    function salah(pesan) {
      if (hint) hint.innerHTML = '<b style="color:#b91c1c">' + esc(pesan) + '</b>';
      if (wrap) wrap.hidden = true;
      if (btn) btn.disabled = true;
    }

    if (inp && btn) {
      inp.addEventListener('change', function () {
        var files = Array.prototype.slice.call(inp.files || []);
        if (!files.length) return;

        if (files.length > sisa) {
          salah('Anda memilih ' + files.length + ' berkas, sedangkan sisa slot hanya ' + sisa +
                ' foto. Kurangi berkas yang dipilih.');
          return;
        }
        var total = 0;
        for (var i = 0; i < files.length; i++) {
          var f = files[i];
          var ext = (f.name.split('.').pop() || '').toLowerCase();
          if (OK.indexOf(ext) === -1) {
            salah('Format .' + ext + ' tidak didukung (' + f.name + '). Pakai JPG, PNG, GIF, WEBP, atau SVG.');
            return;
          }
          if (f.size > MAKS_BYTES) {
            salah('Berkas ' + f.name + ' terlalu besar (' + mb(f.size) + '). Maksimal 15 MB per foto.');
            return;
          }
          total += f.size;
        }
        if (total > MAKS_TOTAL) {
          salah('Total ukuran ' + mb(total) + ' melebihi batas sekali kirim. Unggah satu per satu atau perkecil fotonya.');
          return;
        }

        btn.disabled = false;
        if (hint) {
          hint.innerHTML = sisaHint + ' <b>' + files.length + ' berkas siap diunggah</b> (total ' + mb(total) + ').';
        }
        if (status) status.textContent = '';

        if (prev) prev.innerHTML = '';
        if (wrap) wrap.hidden = files.length === 0;
        files.forEach(function (f, idx) {
          var ext = (f.name.split('.').pop() || '').toLowerCase();
          if (ext === 'svg') {
            var b = document.createElement('div');
            b.className = 'foto-pilih';
            b.innerHTML = '<span class="xs mut">SVG: ' + esc(f.name) + '</span>';
            if (prev) prev.appendChild(b);
            return;
          }
          var fr = new FileReader();
          fr.onload = function () {
            var kotak = document.createElement('figure');
            kotak.className = 'foto-pilih';
            kotak.innerHTML = '<img src="' + fr.result + '" alt="Pratinjau berkas ' + (idx + 1) + '">' +
              '<figcaption>' + esc(f.name) + '<br>' + mb(f.size) + '</figcaption>';
            if (prev) prev.appendChild(kotak);
          };
          fr.readAsDataURL(f);
        });
      });
    }

    form.addEventListener('submit', function () {
      if (btn) { btn.disabled = true; btn.textContent = 'Mengunggah...'; }
      if (status) status.textContent = 'Mohon tunggu, foto sedang diunggah ke penyimpanan.';
    });
  }


  /* ---------- Cetak ---------- */
  function initPrint() {
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-print]');
      if (b) { ev.preventDefault(); window.print(); }
    });
  }

  /* ---------- Popup aktivasi (permintaan pemilik) ----------
     Halaman aktivasi.php mencetak <dialog id="popupAktivasi"> saat sebuah tindakan melewati batas,
     dengan atribut `open` supaya tetap terlihat walau skrip gagal dimuat. Di sini popup itu
     dinaikkan menjadi MODAL (latar gelap + Esc), dan tombol Tutup mengembalikan pengguna ke
     halaman asal bila alamatnya dikirim. */
  function initPopupAktivasi() {
    var dlg = document.getElementById('popupAktivasi');
    if (!dlg) return;

    if (typeof dlg.showModal === 'function' && !dlg.open) {
      try { dlg.showModal(); } catch (e) { /* biarkan tampil sebagai blok biasa */ }
    }

    var kembali = dlg.getAttribute('data-kembali') || '';
    function tutup() {
      if (typeof dlg.close === 'function' && dlg.open) { dlg.close(); }
      else { dlg.removeAttribute('open'); }
      if (kembali && kembali !== 'aktivasi.php') { window.location.href = kembali; }
    }

    dlg.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-popup-tutup]')) { ev.preventDefault(); tutup(); return; }
      // Klik di area latar (di luar isi popup) juga menutup.
      var isi = dlg.querySelector('.popup-isi');
      if (isi && !isi.contains(ev.target)) { tutup(); }
    });
    dlg.addEventListener('close', function () {
      if (kembali && kembali !== 'aktivasi.php') { window.location.href = kembali; }
    });
  }

  /* ---------- Popup PERINGATAN masa aktif akan berakhir (permintaan pemilik) ----------
     Server mencetak <dialog id="popupIngatAktivasi"> HANYA ketika sisa masa aktif/masa uji coba
     tinggal 1..7 hari (aktivasi_peringatan()). Di sini popup itu dinaikkan menjadi modal dan
     penutupannya diingat selama sesi peramban (sessionStorage) supaya tidak menutupi setiap
     halaman yang dibuka. Bila skrip gagal dimuat, dialog tetap tampil sebagai blok biasa. */
  function initIngatAktivasi() {
    var dlg = document.getElementById('popupIngatAktivasi');
    if (!dlg) return;

    var kunci = 'vsIngatAktivasi:' + (dlg.getAttribute('data-kunci') || '');
    var sudah = false;
    try { sudah = window.sessionStorage.getItem(kunci) === '1'; } catch (e) { sudah = false; }
    if (sudah) {
      // Sudah ditutup pada sesi ini → jangan tampilkan lagi.
      dlg.removeAttribute('open');
      if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
      return;
    }

    function ingat() {
      try { window.sessionStorage.setItem(kunci, '1'); } catch (e) { /* mode privat: abaikan */ }
    }
    function tutup() {
      ingat();
      if (typeof dlg.close === 'function' && dlg.open) { dlg.close(); }
      else { dlg.removeAttribute('open'); }
    }

    // Dialog dicetak dengan atribut `open` (cadangan tanpa JS). Lepas atribut itu lalu naikkan
    // menjadi MODAL supaya latar gelapnya muncul — showModal() menolak dialog yang sudah terbuka.
    dlg.removeAttribute('open');
    if (typeof dlg.showModal === 'function') {
      try { dlg.showModal(); } catch (e) { /* biarkan tampil sebagai blok biasa */ }
    } else {
      dlg.setAttribute('open', '');
    }

    dlg.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-popup-tutup]')) { ev.preventDefault(); tutup(); return; }
      var isi = dlg.querySelector('.popup-isi');
      if (isi && !isi.contains(ev.target)) { tutup(); }   // klik latar gelap
    });
    dlg.addEventListener('close', ingat);
  }

  /* ---------- Salin kode aktivasi ke papan klip ---------- */
  function initSalinKode() {
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-salin]');
      if (!b) return;
      ev.preventDefault();
      var inp = document.querySelector(b.getAttribute('data-salin'));
      if (!inp) return;
      // Sumber teks boleh berupa isian (<input>/<textarea>) ATAU elemen teks biasa
      // (mis. <b id="rekeningTeks"> dan paragraf pesan WA) — jangan pernah memakai isian
      // tersembunyi untuk itu: isian 100% lebar bisa membuat halaman meluber mendatar.
      var teks = (inp.value || inp.textContent || '').trim();
      var label = b.innerHTML;
      var selesai = function () {
        b.innerHTML = 'Tersalin';
        setTimeout(function () { b.innerHTML = label; }, 1600);
      };
      // Cadangan bila API clipboard tidak tersedia: pilih isi elemennya lebih dulu.
      var pilih = function () {
        try {
          if (typeof inp.select === 'function') { inp.select(); return; }
          var r = document.createRange();
          r.selectNodeContents(inp);
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(r);
        } catch (e) { /* abaikan: penyalinan tetap dicoba */ }
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(teks).then(selesai, function () {
          pilih(); document.execCommand('copy'); selesai();
        });
      } else {
        pilih(); document.execCommand('copy'); selesai();
      }
    });
  }

  /* ---------- Obrolan (chat) — satu ruang per sekolah/madrasah binaan ----------
     Permintaan pemilik: "Tambahkan fitur obrolan seperti aplikasi e-Guru hanya saja yang bisa
     mengobrol hanya pengawas bina dan binaannya yang sementara online. Tidak bisa mengobrol dengan
     yang bukan binaannya." Ruangnya dipisah per sekolah: akun sekolah berkomunikasi dengan pengawas
     pembinanya, Super Admin boleh masuk semua ruang. Penjagaan "bukan binaannya" ada di SERVER;
     skrip ini hanya pelengkap supaya pesan baru muncul otomatis tiap beberapa detik.

     PENTING: seluruh teks pesan/nama disisipkan lewat `textContent` (BUKAN innerHTML) karena
     isinya berasal dari pengguna lain; server juga meng-escape. Jangan diubah. */
  function initChat() {
    var kotak = document.getElementById('chatKotak');
    if (!kotak) { return; }

    var tombol = document.getElementById('chatTombol');
    var panel = document.getElementById('chatPanel');
    var isi = document.getElementById('chatIsi');
    var form = document.getElementById('chatForm');
    var isian = document.getElementById('chatIsian');
    var sisa = document.getElementById('chatSisa');
    var lencana = document.getElementById('chatLencana');
    var status = document.getElementById('chatStatus');
    var daftarRuang = document.getElementById('chatRuang');
    var onlineNama = document.getElementById('chatOnlineNama');

    var URL_CHAT = kotak.getAttribute('data-url') || 'chat.php';
    var JEDA = parseInt(kotak.getAttribute('data-jeda'), 10) || 5000;
    var MAKS = parseInt(kotak.getAttribute('data-maks'), 10) || 500;
    var ruang = parseInt(kotak.getAttribute('data-ruang'), 10) || 0;
    var terakhir = parseInt(kotak.getAttribute('data-terakhir'), 10) || 0;
    var CSRF = (form && form.querySelector('input[name=csrf]') ? form.querySelector('input[name=csrf]').value : '') || '';
    var terbuka = false;
    var timer = null;

    function jamSekarang() {
      var d = new Date();
      return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    function tampilkanLencana(n) {
      if (!lencana) { return; }
      if (n > 0) {
        lencana.textContent = n > 99 ? '99+' : String(n);
        lencana.hidden = false;
      } else {
        lencana.hidden = true;
      }
    }

    function keBawah() {
      if (isi) { isi.scrollTop = isi.scrollHeight; }
    }

    /** Tambahkan satu pesan ke daftar (semua teks lewat textContent). */
    function tambahPesan(p, punyaSaya) {
      if (!isi) { return; }
      var kosong = document.getElementById('chatKosong');
      if (kosong) { kosong.remove(); }

      var bungkus = document.createElement('div');
      bungkus.className = 'chat-pesan' + (punyaSaya ? ' saya' : '');
      bungkus.setAttribute('data-id', String(p.id));

      if (!punyaSaya) {
        var dari = document.createElement('span');
        dari.className = 'chat-dari';
        dari.textContent = (p.nama || '') + ' \u00b7 ' + (p.peran || '');
        bungkus.appendChild(dari);
      }
      var gelembung = document.createElement('span');
      gelembung.className = 'chat-gelembung';
      gelembung.textContent = p.isi || '';
      bungkus.appendChild(gelembung);

      var jam = document.createElement('span');
      jam.className = 'chat-jam';
      jam.textContent = p.jam || jamSekarang();
      bungkus.appendChild(jam);

      var galat = isi.querySelector('.chat-galat');
      if (galat) { isi.insertBefore(bungkus, galat); } else { isi.appendChild(bungkus); }
      keBawah();
    }

    function catatGalat(teks) {
      if (!isi) { return; }
      var el = isi.querySelector('.chat-galat');
      if (!el) {
        el = document.createElement('div');
        el.className = 'chat-galat';
        isi.appendChild(el);
      }
      el.textContent = teks;
      keBawah();
    }

    function hapusGalat() {
      var el = isi && isi.querySelector('.chat-galat');
      if (el) { el.remove(); }
    }

    /** Ganti seluruh isi daftar pesan (dipakai saat pindah ruang). */
    function gambarUlang(daftar) {
      if (!isi) { return; }
      isi.innerHTML = '';
      if (!daftar || !daftar.length) {
        var kosong = document.createElement('p');
        kosong.className = 'chat-kosong';
        kosong.id = 'chatKosong';
        kosong.textContent = 'Belum ada obrolan di ruang ini. Sapa pengawas bina atau binaan Anda yang sedang online.';
        isi.appendChild(kosong);
        return;
      }
      for (var i = 0; i < daftar.length; i++) {
        tambahPesan(daftar[i], !!daftar[i].saya);
      }
    }

    /** Perbarui pemilih ruang + keterangan "sedang online" dari balasan server. */
    function perbaruiRuang(d) {
      if (d.ruang) { ruang = parseInt(d.ruang.id, 10) || ruang; }
      if (status) {
        status.innerHTML = 'Ruang <b>' + (d.ruang && d.ruang.nama ? d.ruang.nama : '') + '</b> \u00b7 <b>'
          + (typeof d.online === 'number' ? d.online : 0) + '</b> online';
      }
      if (daftarRuang && d.ruang_list) {
        daftarRuang.innerHTML = '';
        for (var i = 0; i < d.ruang_list.length; i++) {
          var r = d.ruang_list[i];
          var opt = document.createElement('option');
          opt.value = String(r.id);
          opt.textContent = r.nama + (r.binaan ? ' (binaan)' : '') + (r.belum > 0 ? ' \u2014 ' + r.belum + ' baru' : '');
          if (parseInt(r.id, 10) === ruang) { opt.selected = true; }
          daftarRuang.appendChild(opt);
        }
      }
      if (onlineNama) {
        var nama = d.online_nama || [];
        if (!nama.length) {
          onlineNama.hidden = true;
        } else {
          onlineNama.hidden = false;
          onlineNama.textContent = 'Sedang online: ' + nama.slice(0, 6).join(', ')
            + (nama.length > 6 ? ' dan ' + (nama.length - 6) + ' lainnya' : '') + '.';
        }
      }
    }

    /** Ambil pesan baru dari server. `baca` = tandai sudah dibaca (hanya saat panel terbuka). */
    function tarik(baca) {
      var url = URL_CHAT + '?aksi=ambil&sejak=' + encodeURIComponent(terakhir)
        + '&sekolah=' + encodeURIComponent(ruang) + (baca ? '&baca=1' : '');
      return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.ok) { return; }
          var daftar = d.pesan || [];
          for (var i = 0; i < daftar.length; i++) {
            tambahPesan(daftar[i], !!daftar[i].saya);
            if (daftar[i].id > terakhir) { terakhir = daftar[i].id; }
          }
          perbaruiRuang(d);
          if (baca) { tampilkanLencana(d.belum_total || 0); }
          else { tampilkanLencana(d.belum_total || 0); }
        })
        .catch(function () { /* diamkan: dicoba lagi pada jeda berikutnya */ });
    }

    /** Pindah ruang obrolan (hanya ruang yang boleh dibuka pengguna — dijaga server). */
    function pindahRuang(id) {
      fetch(URL_CHAT + '?aksi=ruang&sekolah=' + encodeURIComponent(id) + (terbuka ? '&baca=1' : ''), {
        credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (d) {
          if (!d || !d.ok) { return; }
          hapusGalat();
          terakhir = 0;
          gambarUlang(d.pesan || []);
          for (var i = 0; i < (d.pesan || []).length; i++) {
            if (d.pesan[i].id > terakhir) { terakhir = d.pesan[i].id; }
          }
          perbaruiRuang(d);
          tampilkanLencana(d.belum_total || 0);
        })
        .catch(function () { catatGalat('Ruang obrolan gagal dibuka. Periksa sambungan lalu coba lagi.'); });
    }

    function mulai() {
      if (timer) { return; }
      timer = window.setInterval(function () {
        if (document.hidden) { return; }   // hemat: tab tidak terlihat
        tarik(terbuka);
      }, JEDA);
    }
    function hentikan() {
      if (timer) { window.clearInterval(timer); timer = null; }
    }

    function buka() {
      if (!panel) { return; }
      terbuka = true;
      panel.hidden = false;
      if (tombol) { tombol.setAttribute('aria-expanded', 'true'); }
      tarik(true);                    // tandai yang tampil sudah dibaca
      mulai();
      window.setTimeout(function () { if (isian) { isian.focus(); } }, 30);
      keBawah();
    }
    function tutup() {
      if (!panel) { return; }
      terbuka = false;
      panel.hidden = true;
      if (tombol) { tombol.setAttribute('aria-expanded', 'false'); }
      tarik(false);                   // lencana kembali dihitung tanpa menandai dibaca
    }

    if (tombol) {
      tombol.addEventListener('click', function () { if (terbuka) { tutup(); } else { buka(); } });
    }
    var tutupBtn = document.getElementById('chatTutup');
    if (tutupBtn) { tutupBtn.addEventListener('click', tutup); }
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && terbuka) { tutup(); }
    });
    if (daftarRuang) {
      daftarRuang.addEventListener('change', function () {
        pindahRuang(parseInt(daftarRuang.value, 10) || 0);
      });
    }

    /** Hitungan karakter yang tersisa (bukan pemblokir: server tetap yang menentukan). */
    function perbaruiSisa() {
      if (!sisa || !isian) { return; }
      sisa.textContent = (MAKS - isian.value.length) + ' karakter';
    }

    if (isian) {
      isian.addEventListener('input', perbaruiSisa);
      // Enter mengirim, Shift+Enter menambah baris baru.
      isian.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
          ev.preventDefault();
          if (form) { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
        }
      });
    }

    if (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!isian) { return; }
        var teks = isian.value.trim();
        if (teks === '') { return; }
        var tombolKirim = document.getElementById('chatKirim');
        if (tombolKirim) { tombolKirim.disabled = true; }

        var data = new FormData();
        data.append('csrf', CSRF);
        data.append('aksi', 'kirim');
        data.append('sekolah', String(ruang));
        data.append('isi', teks);

        fetch(URL_CHAT, {
          method: 'POST', body: data, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch' }
        })
          .then(function (r) { return r.json().catch(function () { return null; }); })
          .then(function (d) {
            if (d && d.ok && d.pesan && d.pesan.length) {
              isian.value = '';
              perbaruiSisa();
              hapusGalat();
              tambahPesan(d.pesan[0], true);
              if (d.pesan[0].id > terakhir) { terakhir = d.pesan[0].id; }
              perbaruiRuang(d);
              tampilkanLencana(d.belum_total || 0);
            } else {
              catatGalat((d && d.pesan_galat) ? d.pesan_galat : 'Pesan gagal terkirim. Coba lagi.');
            }
          })
          .catch(function () { catatGalat('Pesan gagal terkirim. Periksa sambungan lalu coba lagi.'); })
          .then(function () { if (tombolKirim) { tombolKirim.disabled = false; } });
      });
    }

    // Mulai: ambil sekali (agar lencana akurat) lalu berjalan otomatis pada jeda yang ditentukan.
    tarik(false);
    mulai();
    // Saat tab kembali terlihat, langsung tarik supaya tidak menunggu jeda berikutnya.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { tarik(terbuka); }
    });
  }

  /* ---------- Grafik (Chart.js) ---------- */
  /* Dua warna pertama palet = AKSEN TEMA TAMPILAN yang sedang berlaku (dipilih Super Admin), dibaca
     dari variabel CSS `--accent` / `--accent2` pada <body>. Bila variabelnya tidak terbaca (mis. tema
     bawaan sebelum CSS dimuat), dipakai nilai indigo/violet sebagai cadangan. */
  function warnaTema(nama, cadangan) {
    try {
      var v = getComputedStyle(document.body).getPropertyValue(nama);
      v = (v || '').trim();
      return v !== '' ? v : cadangan;
    } catch (e) { return cadangan; }
  }
  var PALETTE = [warnaTema('--accent', '#4F46E5'), warnaTema('--accent2', '#8B5CF6'),
                 '#0ea5e9', '#ec4899', '#f59e0b', '#10b981', '#6366f1', '#14b8a6'];
  var PRED_COLOR = { A: '#10b981', B: '#3b82f6', C: '#f59e0b', D: '#dc2626' };

  function baseOpts(extra) {
    return Object.assign({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { font: { family: 'Inter', size: 11 }, color: '#635F82', boxWidth: 12, usePointStyle: true, padding: 14 } },
        tooltip: {
          backgroundColor: 'rgba(31,27,58,.92)', titleFont: { family: 'Inter', size: 12 }, bodyFont: { family: 'Inter', size: 12 },
          padding: 10, cornerRadius: 10, displayColors: true
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } },
        y: { beginAtZero: true, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } }
      }
    }, extra || {});
  }

  window.VS = {
    predikatColor: PRED_COLOR,
    palette: PALETTE,
    baseOpts: baseOpts,
    chart: function (id, cfg) {
      var el = document.getElementById(id);
      if (!el || typeof Chart === 'undefined') return null;
      var c = new Chart(el.getContext('2d'), cfg);
      el.__chart = c;
      return c;
    },
    bar: function (id, labels, data, horizontal, colors) {
      return window.VS.chart(id, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Nilai rata-rata',
            data: data,
            backgroundColor: colors || (horizontal ? PALETTE[0] + 'cc' : PALETTE[0] + 'cc'),
            borderRadius: 8,
            maxBarThickness: horizontal ? 26 : 46
          }]
        },
        options: baseOpts({
          indexAxis: horizontal ? 'y' : 'x',
          plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 }
          },
          scales: horizontal
            ? { x: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } }, y: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } }
            : { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } }, x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } }
        })
      });
    },
    line: function (id, labels, data) {
      return window.VS.chart(id, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [{
            label: 'Rata-rata nilai',
            data: data,
            borderColor: '#4F46E5',
            backgroundColor: 'rgba(79,70,229,.14)',
            fill: true,
            tension: .35,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#4F46E5',
            pointBorderWidth: 2,
            pointRadius: 4
          }]
        },
        options: baseOpts({
          plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 } },
          scales: { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } }, x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } }
        })
      });
    },
    donut: function (id, labels, data, colors) {
      return window.VS.chart(id, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors || PALETTE, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
        options: baseOpts({
          cutout: '62%',
          plugins: {
            legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 11 }, color: '#635F82', boxWidth: 10, usePointStyle: true, padding: 12 } },
            tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 }
          },
          scales: {}
        })
      });
    },
    radar: function (id, labels, data) {
      return window.VS.chart(id, {
        type: 'radar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Capaian', data: data,
            borderColor: '#8B5CF6', backgroundColor: 'rgba(139,92,246,.22)',
            pointBackgroundColor: '#8B5CF6', borderWidth: 2
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 } },
          scales: {
            r: {
              beginAtZero: true, max: 100,
              grid: { color: 'rgba(99,95,130,.2)' },
              angleLines: { color: 'rgba(99,95,130,.2)' },
              pointLabels: { font: { family: 'Inter', size: 11 }, color: '#635F82' },
              ticks: { display: false, stepSize: 25 }
            }
          }
        }
      });
    }
  };

  /* ---------- Jenis lembaga: NSM hanya untuk MADRASAH (halaman pendaftaran) ----------
     Permintaan pemilik: "Pada halaman pendaftaran tambahkan juga pilihan jenis: Sekolah Umum
     (SD/SMP/SMA/SMK) dan Madrasah (MI/MTS/MA/MAK). Sehingga jika yang dipilih sekolah umum kolom
     NSM tidak perlu ditampilkan". Blok #blokNsm sudah diberi atribut `hidden` oleh SERVER saat
     halaman dirender ulang dengan jenis umum, jadi tanpa skrip ini pun keadaannya sudah benar —
     skrip ini hanya mengikuti perubahan pilihan di peramban. */
  function initJenisNsm() {
    var sel = document.getElementById('jenis_sekolah') || document.getElementById('jenis');
    var blok = document.getElementById('blokNsm');
    if (!sel || !blok) return;
    function sync() {
      var umum = sel.value === 'umum';
      blok.hidden = umum;
      // Nilai NSM yang tertinggal dibersihkan saat pilihan berpindah ke Sekolah Umum.
      if (umum) {
        var nsm = document.getElementById('nsm');
        if (nsm) nsm.value = '';
      }
    }
    sel.addEventListener('change', sync);
    sync();
  }

  /* ---------- HALAMAN DEPAN (LANDING PAGE): navigasi HP + slider testimoni ----------
     Permintaan pemilik: halaman depan menjadi landing page (beranda, fitur, keunggulan, testimoni,
     tentang, kontak) dengan panel masuk yang tetap dipertahankan.
     Skrip ini hanya PELENGKAP: tanpa JavaScript pun seluruh isi halaman tetap terbaca
     (slider beranda & testimoni memakai overflow-x + scroll-snap, dan navigasi tetap dapat digulir). */
  /* ==========================================================================================
     KARTU 3D (fitur & keunggulan pada halaman depan) — permintaan pemilik:
     "kartu-kartu fitur dan unggulan buat tampilannya lebih 3 dimensi dan ketika diklik buat
      efeknya/animasinya seperti kartu-kartu di dalam aplikasi portal operator."
     Skrip ini hanya PELENGKAP (tanpa JavaScript kartu tetap tampil normal):
       - mengisi --lp-rx/--lp-ry (kemiringan 3D) serta --lp-sx/--lp-sy (posisi sorotan) saat
         kursor bergerak di atas kartu, lalu mengembalikannya datar saat kursor keluar;
       - menambahkan kelas .lp-masuk (animasi muncul berurutan) lalu melepasnya setelah selesai,
         supaya animasi klik (.lp-klik) tidak bentrok dengan animasi masuk;
       - menambahkan .lp-klik sesaat setiap kartu diklik → pantulan singkat seperti kartu Portal
         Operator.
     Semua efek dihormati: prefers-reduced-motion & perangkat tanpa hover (sentuh) → tanpa miring.
     ========================================================================================== */
  function initKartu3D() {
    var kartu = document.querySelectorAll('.lp-kartu.lp-timbul');
    if (!kartu.length) return;

    var gerakSedikit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var bisaHover = !window.matchMedia || window.matchMedia('(hover: hover)').matches;

    // (a) animasi masuk berurutan — dilepas setelah selesai agar tidak menimpa efek klik.
    Array.prototype.forEach.call(kartu, function (k) {
      if (gerakSedikit) return;
      k.classList.add('lp-masuk');
      k.addEventListener('animationend', function lepas(ev) {
        if (ev.animationName !== 'lpKartuMasuk') return;
        k.classList.remove('lp-masuk');
        k.removeEventListener('animationend', lepas);
      });
    });

    // (b) kemiringan 3D + sorotan mengikuti kursor (hanya perangkat ber-hover).
    //     Pendengar dipasang PER KARTU: `pointermove` mengikuti kursor selama di atas kartu dan
    //     `pointerleave` (bukan `pointerout`) meluruskan kartu hanya ketika kursor benar-benar
    //     keluar — `pointerout` juga menyala saat berpindah antar-anak di dalam kartu sehingga
    //     pernah membuat kemiringan hilang di tengah jalan.
    if (bisaHover && !gerakSedikit) {
      Array.prototype.forEach.call(kartu, function (k) {
        k.addEventListener('pointermove', function (ev) {
          var kotak = k.getBoundingClientRect();
          if (!kotak.width || !kotak.height) return;
          var px = (ev.clientX - kotak.left) / kotak.width;   // 0..1 (kiri → kanan)
          var py = (ev.clientY - kotak.top) / kotak.height;   // 0..1 (atas → bawah)
          k.style.setProperty('--lp-sx', Math.round(px * 100) + '%');
          k.style.setProperty('--lp-sy', Math.round(py * 100) + '%');
          // Kemiringan DIBATASI 8° supaya tidak berlebihan/meluber.
          k.style.setProperty('--lp-ry', ((px - 0.5) * 8).toFixed(2) + 'deg');
          k.style.setProperty('--lp-rx', ((0.5 - py) * 8).toFixed(2) + 'deg');
        }, { passive: true });

        k.addEventListener('pointerleave', function () {
          k.style.removeProperty('--lp-rx');
          k.style.removeProperty('--lp-ry');
        }, { passive: true });
      });
    }

    // (c) efek klik: pantulan singkat (bisa diulang berkali-kali).
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || !t.closest) return;
      var k = t.closest('.lp-kartu.lp-timbul');
      if (!k || gerakSedikit) return;
      k.classList.remove('lp-klik');
      void k.offsetWidth;                  // paksa animasi bisa dijalankan ulang
      k.classList.add('lp-klik');
      window.setTimeout(function () { k.classList.remove('lp-klik'); }, 520);
    });
  }

  function initLanding() {
    var navToggle = document.getElementById('lpNavToggle');
    var navLink = document.getElementById('lpNavLink');
    if (navToggle && navLink) {
      navToggle.addEventListener('click', function () {
        navLink.classList.toggle('lp-nav-on');
      });
      navLink.addEventListener('click', function (ev) {
        if (ev.target.closest('a')) navLink.classList.remove('lp-nav-on');
      });
    }

    // Dua slider memakai MESIN yang sama: slider beranda (gambar + judul + keterangan) dan
    // slider testimoni. Bedanya hanya kelas penanda dan wadah tombol/titiknya.
    pasangSlider({
      slider: document.getElementById('lpSlider'),
      slide: '.lp-slide',
      // Wadah yang benar-benar digulir pada slider beranda adalah jalur slidenya (tombol panah &
      // titik berada DI LUAR jalur itu supaya tidak ikut tergulir).
      gulir: '.lp-slider-track',
      nav: document.getElementById('lpSliderNav'),
      titik: document.getElementById('lpSliderTitik'),
      panah: '.lp-slider-panah',
      label: 'Slide'
    });
    pasangSlider({
      slider: document.getElementById('testiSlider'),
      slide: '.lp-testi-slide',
      nav: document.getElementById('testiNav'),
      titik: document.getElementById('testiTitik'),
      panah: '.lp-testi-panah',
      label: 'Testimoni'
    });
  }

  /**
   * Mesin slider sederhana: geser satu layar sekali, tombol panah + titik penunjuk, dan geser
   * otomatis yang hanya DITUNDA satu siklus saat pengguna menyorot/menyentuh (bukan dimatikan).
   * Menghormati prefers-reduced-motion (tanpa geser otomatis).
   */
  function pasangSlider(opt) {
    var slider = opt.slider;
    if (!slider) return;
    var slides = slider.querySelectorAll(opt.slide);
    var nav = opt.nav;
    var titikWadah = opt.titik;
    // `gulir` = elemen yang benar-benar punya overflow-x (bila tidak diberikan, slider itu sendiri).
    var gulir = opt.gulir ? slider.querySelector(opt.gulir) : slider;
    if (!gulir || slides.length < 2 || !nav || !titikWadah) return;

    var jeda = parseInt(slider.getAttribute('data-otomatis'), 10) || 6000;
    var gerakMinimal = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var titik = [];
    var jam = null;

    function lebarSlide() {
      var a = slides[0].getBoundingClientRect();
      var b = slides.length > 1 ? slides[1].getBoundingClientRect() : null;
      return b ? (b.left - a.left) : a.width;
    }
    function perLayar() {
      var l = lebarSlide();
      return l > 0 ? Math.max(1, Math.round(gulir.clientWidth / l)) : 1;
    }
    function maksIndeks() { return Math.max(0, slides.length - perLayar()); }
    function indeksKini() {
      var l = lebarSlide();
      var i = l > 0 ? Math.round(gulir.scrollLeft / l) : 0;
      return Math.max(0, Math.min(i, maksIndeks()));
    }
    function tandai(i) {
      for (var j = 0; j < titik.length; j++) titik[j].classList.toggle('on', j === i);
    }
    function keSlide(i) {
      var mk = maksIndeks();
      if (i < 0) i = mk;
      if (i > mk) i = 0;
      gulir.scrollTo({ left: lebarSlide() * i, behavior: 'smooth' });
      tandai(i);
    }
    function bangunTitik() {
      titikWadah.innerHTML = '';
      titik = [];
      for (var i = 0; i <= maksIndeks(); i++) {
        (function (idx) {
          var b = document.createElement('button');
          b.type = 'button';
          b.setAttribute('aria-label', opt.label + ' ' + (idx + 1));
          b.addEventListener('click', function () { keSlide(idx); jadwalkan(jeda); });
          titikWadah.appendChild(b);
          titik.push(b);
        })(i);
      }
      tandai(Math.min(indeksKini(), maksIndeks()));
    }
    function hentikan() { if (jam) { window.clearTimeout(jam); jam = null; } }
    function jadwalkan(t) {
      hentikan();
      if (gerakMinimal) return;
      jam = window.setTimeout(function () {
        keSlide(indeksKini() + 1);
        jadwalkan(jeda);
      }, t);
    }

    nav.addEventListener('click', function (ev) {
      var btn = ev.target.closest(opt.panah);
      if (!btn) return;
      keSlide(indeksKini() + (parseInt(btn.getAttribute('data-arah'), 10) || 1));
      jadwalkan(jeda);
    });
    gulir.addEventListener('scroll', function () { tandai(indeksKini()); });
    window.addEventListener('resize', function () { window.setTimeout(bangunTitik, 150); });
    bangunTitik();

    if (!gerakMinimal) {
      jadwalkan(jeda);
      ['mouseenter', 'focusin', 'touchstart', 'pointerdown'].forEach(function (ev) {
        slider.addEventListener(ev, function () { jadwalkan(jeda); }, { passive: true });
      });
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) { hentikan(); } else { jadwalkan(jeda); }
      });
    }
  }

  /**
   * GAMBAR HALAMAN DEPAN DILINDUNGI (v1.32.0).
   *
   * Permintaan pemilik: gambar slider/testimoni/foto pengembang pada halaman depan tidak bisa
   * diklik kanan, diseret, atau dibuka alamat sumbernya. Alamat sumbernya sendiri sudah tidak
   * pernah dicetak di HTML (semua lewat `gambar.php`), sedangkan fungsi ini lapisan kedua di sisi
   * peramban: membatalkan menu klik-kanan & seret-lepas pada gambar ber-`data-lindung` dan pada
   * wadahnya (`data-lindung-wadah`).
   */
  function initLindungGambar() {
    var gambar = document.querySelectorAll('img[data-lindung]');
    if (!gambar.length) return;

    for (var i = 0; i < gambar.length; i++) {
      var im = gambar[i];
      im.setAttribute('draggable', 'false');
      im.addEventListener('dragstart', function (e) { e.preventDefault(); });
    }

    function terlindung(t) {
      if (!t || !t.closest) return false;
      return !!(t.closest('[data-lindung-wadah]') || t.closest('img[data-lindung]'));
    }

    document.addEventListener('contextmenu', function (e) {
      if (!terlindung(e.target)) return;
      e.preventDefault();
      return false;
    });

    document.addEventListener('dragstart', function (e) {
      if (terlindung(e.target)) e.preventDefault();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initNav();
    initLanding();
    initKartu3D();
    initLindungGambar();
    initConfirm();
    initSkorForm();
    initTableSearch();
    initCariSekolah();
    initPrint();
    initFoto();
    initLogo();
    initTentang();
    initDokumen();
    initHitungMundur();
    initFotoPengamatan();
    initPopupAktivasi();
    initIngatAktivasi();
    initSalinKode();
    initJenisNsm();
    initChat();
  });
})();
