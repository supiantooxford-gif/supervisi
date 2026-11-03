<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();

$ringkasan = null;
$galat = [];

// AKTIVASI: impor data guru hanya tersedia setelah sekolah diaktivasi (pilihan pemilik:
// "impor guru & buat akun massal ditutup" pada mode terbatas).
if (aktivasi_terbatas()) {
    aktivasi_halang('impor', 'guru.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $raw = (string) ($_POST['payload'] ?? '');
    $data = json_decode($raw, true);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $perbarui = !empty($data['perbarui']);
    $buatAkun = !empty($data['buat_akun']);

    $tambah = 0; $ubah = 0; $lewat = 0; $akunBaru = 0;
    if (!$rows) {
        $galat[] = 'Tidak ada baris data yang dikirim. Pastikan file sudah dipilih dan kolom sudah dipetakan.';
    } else {
        $now = date('Y-m-d H:i:s');
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($rows as $i => $r) {
                $nama = trim((string) ($r['nama'] ?? ''));
                $nip = nip_bersih($r['nip'] ?? ''); // "-" atau kosong = belum punya NIP
                $mapel = trim((string) ($r['mapel'] ?? ''));
                $jabatan = trim((string) ($r['jabatan'] ?? ''));
                $kelas = trim((string) ($r['kelas'] ?? ''));
                $telepon = trim((string) ($r['telepon'] ?? ''));
                $baris = (int) ($r['_baris'] ?? ($i + 1));

                if ($nama === '') {
                    $lewat++;
                    if (count($galat) < 8) {
                        $galat[] = 'Baris ' . $baris . ': nama guru kosong, dilewati.';
                    }
                    continue;
                }
                if (strlen($nama) > 160) {
                    $nama = substr($nama, 0, 160);
                }

                // Pencocokan: utamakan NIP; bila NIP kosong, cocokkan berdasarkan nama
                // (beberapa guru boleh sama-sama belum punya NIP tanpa dianggap duplikat).
                $ada = $nip !== ''
                    ? one('SELECT * FROM guru WHERE nip = ? AND sekolah_id = ?', [$nip, $sid])
                    : one('SELECT * FROM guru WHERE lower(nama) = lower(?) AND sekolah_id = ?', [$nama, $sid]);
                if ($ada) {
                    if (!$perbarui) {
                        $lewat++;
                        continue;
                    }
                    q('UPDATE guru SET nama=?, mapel=CASE WHEN ? <> "" THEN ? ELSE mapel END, jabatan=CASE WHEN ? <> "" THEN ? ELSE jabatan END,
                         kelas=CASE WHEN ? <> "" THEN ? ELSE kelas END, telepon=CASE WHEN ? <> "" THEN ? ELSE telepon END, aktif=1 WHERE id=? AND sekolah_id=?',
                        [$nama, $mapel, $mapel, $jabatan, $jabatan, $kelas, $kelas, $telepon, $telepon, (int) $ada['id'], $sid]);
                    $gid = (int) $ada['id'];
                    $ubah++;
                } else {
                    q('INSERT INTO guru (sekolah_id, nama, nip, mapel, jabatan, kelas, telepon, aktif, demo, dibuat) VALUES (?,?,?,?,?,?,?,1,0,?)',
                        [$sid, $nama, $nip, $mapel, $jabatan, $kelas, $telepon, $now]);
                    $gid = (int) db()->lastInsertId();
                    $tambah++;
                }

                if ($buatAkun) {
                    $sudah = one('SELECT id FROM users WHERE guru_id = ?', [$gid]);
                    if (!$sudah) {
                        // Nama pengguna & sandi awal mengikuti helper bersama (NIP boleh kosong).
                        $guruBaru = ['nip' => $nip, 'nama' => $nama];
                        $username = guru_username_unik($guruBaru);
                        q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                            [$username, password_hash(guru_sandi_awal($guruBaru), PASSWORD_DEFAULT), $nama, 'guru', $sid, $gid, $now]);
                        $akunBaru++;
                    }
                }
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        $ringkasan = ['tambah' => $tambah, 'ubah' => $ubah, 'lewat' => $lewat, 'akun' => $akunBaru, 'total' => count($rows)];
    }
}

$totalGuru = (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$sid], 0);

page_top('Impor Data Guru', 'guru_import');
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>Impor Data Guru dari Excel / CSV</h2>
      <p>Unggah file, cocokkan kolomnya, lihat pratinjau, lalu simpan. File tidak disimpan di server.</p>
    </div>
    <a class="btn btn-ghost" href="guru.php"><?= ikon('users', 16) ?> Data Guru (<?= $totalGuru ?>)</a>
  </div>

  <?php if ($ringkasan): ?>
    <div class="alert alert-ok">
      <?= ikon('check', 16) ?>
      <span>Impor selesai dari <?= (int) $ringkasan['total'] ?> baris: <b><?= (int) $ringkasan['tambah'] ?></b> guru baru,
      <b><?= (int) $ringkasan['ubah'] ?></b> diperbarui, <b><?= (int) $ringkasan['lewat'] ?></b> dilewati<?= $ringkasan['akun'] > 0 ? ', <b>' . (int) $ringkasan['akun'] . '</b> akun login dibuat (kata sandi awal = NIP)' : '' ?>.</span>
    </div>
  <?php endif; ?>
  <?php if ($galat): ?>
    <div class="alert alert-warn">
      <?= ikon('star', 16) ?>
      <span><b>Catatan:</b><br><?php foreach ($galat as $g): ?>&bull; <?= e($g) ?><br><?php endforeach; ?></span>
    </div>
  <?php endif; ?>

  <div class="grid g3" style="gap:16px">
    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no">1</div>
        <div>
          <div class="butir-komponen">Pilih file</div>
          <div class="butir-indikator">Format .xlsx, .xls, atau .csv. Baris pertama boleh judul kolom, boleh juga tidak.</div>
        </div>
      </div>
      <div class="mt16">
        <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,text/csv" style="padding:9px">
      </div>
      <div class="hint" id="fileInfo">Belum ada file dipilih.</div>
      <div class="mt8">
        <select id="sheetSelect" style="display:none"></select>
      </div>
    </div>

    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no">2</div>
        <div>
          <div class="butir-komponen">Cocokkan kolom</div>
          <div class="butir-indikator">Pilih kolom yang berisi data guru. Kolom yang tidak dipakai boleh dibiarkan kosong.</div>
        </div>
      </div>
      <div class="mt16 grid" style="gap:10px">
        <div>
          <label class="f" for="headerRow">Baris judul kolom (header)</label>
          <select id="headerRow"></select>
        </div>
        <div id="mapArea" class="grid g2" style="gap:10px"></div>
      </div>
    </div>

    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no">3</div>
        <div>
          <div class="butir-komponen">Pilihan &amp; simpan</div>
          <div class="butir-indikator">Atur cara penanganan data yang sudah ada di aplikasi.</div>
        </div>
      </div>
      <div class="mt16 grid" style="gap:10px">
        <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600">
          <input type="checkbox" id="opsiPerbarui" checked style="width:16px;height:16px;accent-color:var(--accent)">
          Perbarui data guru yang NIP-nya sudah ada
        </label>
        <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600">
          <input type="checkbox" id="opsiAkun" style="width:16px;height:16px;accent-color:var(--accent)">
          Sekaligus buatkan akun login guru (kata sandi awal = NIP)
        </label>
        <button class="btn btn-primary" id="btnImpor" type="button" disabled><?= ikon('upload', 16) ?> Impor Sekarang</button>
        <button class="btn btn-ghost" id="btnContoh" type="button">Unduh contoh format CSV</button>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Pratinjau Data</h2><p>Menampilkan maksimal 25 baris pertama sebagai pemeriksaan sebelum disimpan.</p></div>
    <span class="chip" id="prevInfo">Belum ada data</span>
  </div>
  <div id="prevArea">
    <div class="empty"><?= ikon('upload', 34) ?><h3>Belum ada file</h3><p>Pilih file Excel/CSV untuk melihat pratinjau datanya di sini.</p></div>
  </div>
</div>

<form method="post" id="formImpor" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="payload" id="payloadInput">
</form>

<script>window.VS_TH = {a: 90, b: 80, c: 70};</script>
<script src="assets/vendor/xlsx.full.min.js"></script>
<script>
(function () {
  var FIELDS = [
    { k: 'nama',    l: 'Nama Guru *',   keys: ['nama', 'name', 'guru', 'namaguru'] },
    { k: 'nip',     l: 'NIP / NUPTK',   keys: ['nip', 'nuptk', 'idguru', 'noinduk'] },
    { k: 'mapel',   l: 'Mata Pelajaran', keys: ['mapel', 'matapelajaran', 'pelajaran', 'mata'] },
    { k: 'jabatan', l: 'Jabatan',       keys: ['jabatan', 'golongan', 'pangkat'] },
    { k: 'kelas',   l: 'Kelas',         keys: ['kelas', 'wali', 'walikelas'] },
    { k: 'telepon', l: 'No. HP',        keys: ['telepon', 'hp', 'wa', 'whatsapp', 'nohp', 'telp', 'handphone'] }
  ];
  var wb = null, rows = [], header = [], map = {};
  var fInput = document.getElementById('fileInput');
  var sSelect = document.getElementById('sheetSelect');
  var hSelect = document.getElementById('headerRow');
  var mapArea = document.getElementById('mapArea');
  var prevArea = document.getElementById('prevArea');
  var prevInfo = document.getElementById('prevInfo');
  var fileInfo = document.getElementById('fileInfo');
  var btnImpor = document.getElementById('btnImpor');

  function norm(s) { return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]+/g, ''); }

  function colLetter(i) {
    var s = '';
    i = i + 1;
    while (i > 0) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - m) / 26); }
    return s;
  }

  fInput.addEventListener('change', function (ev) {
    var f = ev.target.files && ev.target.files[0];
    if (!f) return;
    var fr = new FileReader();
    fr.onload = function () {
      try {
        wb = XLSX.read(new Uint8Array(fr.result), { type: 'array' });
      } catch (e) {
        fileInfo.textContent = 'Gagal membaca file: ' + e.message;
        return;
      }
      fileInfo.innerHTML = '<b>' + f.name + '</b> &middot; ' + Math.round(f.size / 1024) + ' KB &middot; ' + wb.SheetNames.length + ' sheet';
      sSelect.innerHTML = '';
      wb.SheetNames.forEach(function (n) {
        var o = document.createElement('option');
        o.value = n; o.textContent = 'Sheet: ' + n;
        sSelect.appendChild(o);
      });
      sSelect.style.display = wb.SheetNames.length > 1 ? '' : 'none';
      loadSheet(wb.SheetNames[0]);
    };
    fr.readAsArrayBuffer(f);
  });

  sSelect.addEventListener('change', function () { loadSheet(sSelect.value); });

  function loadSheet(name) {
    var ws = wb.Sheets[name];
    rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '', blankrows: false });
    if (!rows.length) {
      prevArea.innerHTML = '<div class="empty"><h3>Sheet kosong</h3><p>Tidak ada data pada sheet ini.</p></div>';
      btnImpor.disabled = true;
      return;
    }
    var guess = 0;
    for (var i = 0; i < Math.min(rows.length, 12); i++) {
      var filled = rows[i].filter(function (c) { return String(c).trim() !== ''; }).length;
      if (filled >= 2) { guess = i; break; }
    }
    hSelect.innerHTML = '';
    for (var j = 0; j < Math.min(rows.length, 15); j++) {
      var o = document.createElement('option');
      o.value = j;
      o.textContent = 'Baris ' + (j + 1) + ': ' + (rows[j].slice(0, 6).join(' | ').substring(0, 80) || '(kosong)');
      hSelect.appendChild(o);
    }
    hSelect.value = guess;
    render();
  }

  hSelect.addEventListener('change', function () { map = {}; render(); });

  function render() {
    header = rows[Number(hSelect.value)] || [];
    var maxCol = 0;
    rows.forEach(function (r) { if (r.length > maxCol) maxCol = r.length; });
    if (!Object.keys(map).length) {
      FIELDS.forEach(function (fd) {
        map[fd.k] = '';
        header.forEach(function (h, ci) {
          var nh = norm(h);
          if (!nh) return;
          fd.keys.forEach(function (k) {
            if (!map[fd.k] && (nh === k || nh.indexOf(k) === 0)) { map[fd.k] = String(ci); }
          });
        });
      });
    }
    mapArea.innerHTML = '';
    FIELDS.forEach(function (fd) {
      var wrap = document.createElement('div');
      var lab = document.createElement('label');
      lab.className = 'f'; lab.textContent = fd.l;
      var sel = document.createElement('select');
      var o0 = document.createElement('option');
      o0.value = ''; o0.textContent = '— tidak dipakai —';
      sel.appendChild(o0);
      for (var c = 0; c < maxCol; c++) {
        var o = document.createElement('option');
        o.value = String(c);
        o.textContent = 'Kolom ' + colLetter(c) + (header[c] ? ': ' + String(header[c]).substring(0, 26) : '');
        sel.appendChild(o);
      }
      sel.value = map[fd.k] || '';
      sel.addEventListener('change', function () { map[fd.k] = sel.value; renderPreview(); });
      wrap.appendChild(lab); wrap.appendChild(sel);
      mapArea.appendChild(wrap);
    });
    renderPreview();
  }

  function valueOf(row, key) {
    var c = map[key];
    if (c === '' || c === undefined) return '';
    return String(row[Number(c)] == null ? '' : row[Number(c)]).trim();
  }

  function bodyRows() {
    return rows.slice(Number(hSelect.value) + 1);
  }

  function renderPreview() {
    var body = bodyRows();
    var data = [];
    body.forEach(function (r, i) {
      var o = { nama: valueOf(r, 'nama'), nip: valueOf(r, 'nip'), mapel: valueOf(r, 'mapel'), jabatan: valueOf(r, 'jabatan'), kelas: valueOf(r, 'kelas'), telepon: valueOf(r, 'telepon'), _baris: i + Number(hSelect.value) + 2 };
      if (o.nama === '' && o.nip === '' && o.mapel === '') return;
      data.push(o);
    });
    prevInfo.textContent = data.length + ' baris siap diimpor';
    btnImpor.disabled = data.length === 0;
    if (!data.length) {
      prevArea.innerHTML = '<div class="empty"><h3>Belum ada baris data</h3><p>Periksa baris judul kolom dan pemetaan kolom Nama Guru.</p></div>';
      return;
    }
    var html = '<div class="table-wrap"><table class="tbl"><thead><tr><th>#</th><th>Nama</th><th>NIP</th><th>Mapel</th><th>Jabatan</th><th>Kelas</th><th>No. HP</th><th class="ctr">Status</th></tr></thead><tbody>';
    data.slice(0, 25).forEach(function (o, i) {
      var ok = o.nama !== '';
      html += '<tr><td>' + (i + 1) + '</td><td><b>' + esc(o.nama || '-') + '</b></td><td>' + esc(o.nip || '-') + '</td><td>' + esc(o.mapel || '-') + '</td><td>' + esc(o.jabatan || '-') + '</td><td>' + esc(o.kelas || '-') + '</td><td>' + esc(o.telepon || '-') + '</td><td class="ctr">' +
        (ok ? '<span class="pill pill-ok">Siap</span>' : '<span class="pill pill-D">Nama kosong</span>') + '</td></tr>';
    });
    html += '</tbody></table></div>';
    if (data.length > 25) {
      html += '<p class="xs mut mt8">Menampilkan 25 dari ' + data.length + ' baris. Semua baris akan diimpor.</p>';
    }
    prevArea.innerHTML = html;
    prevArea.__data = data;
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  btnImpor.addEventListener('click', function () {
    var data = prevArea.__data || [];
    if (!data.length) return;
    var payload = {
      rows: data,
      perbarui: document.getElementById('opsiPerbarui').checked,
      buat_akun: document.getElementById('opsiAkun').checked
    };
    document.getElementById('payloadInput').value = JSON.stringify(payload);
    document.getElementById('formImpor').submit();
  });

  document.getElementById('btnContoh').addEventListener('click', function () {
    var csv = 'Nama,NIP,Mapel,Jabatan,Kelas,No HP\nDra. Siti Aminah,197804122006041002,Bahasa Indonesia,Guru Madya,X-1,081200000001\nBudi Santoso S.Pd.,198203152008011003,Matematika,Guru Muda,VIII-A,081200000002\n';
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'contoh-data-guru.csv';
    a.click();
  });
})();
</script>
<?php
page_bottom();
