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

  /* ---------- Cetak ---------- */
  function initPrint() {
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-print]');
      if (b) { ev.preventDefault(); window.print(); }
    });
  }

  /* ---------- Grafik (Chart.js) ---------- */
  var PALETTE = ['#4F46E5', '#8B5CF6', '#0ea5e9', '#ec4899', '#f59e0b', '#10b981', '#6366f1', '#14b8a6'];
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

  document.addEventListener('DOMContentLoaded', function () {
    initNav();
    initConfirm();
    initSkorForm();
    initTableSearch();
    initPrint();
    initFoto();
    initLogo();
  });
})();
