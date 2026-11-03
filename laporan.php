<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$pengawas = is_pengawas();

// Pengawas hanya untuk sekolah binaannya. Tautan ?sekolah=ID dari daftar binaan
// akan dijadikan konteks agar header & seluruh angka konsisten.
if ($pengawas && (int) ($_GET['sekolah'] ?? 0) > 0) {
    $minta = (int) $_GET['sekolah'];
    if (!pengawas_punya((int) $u['id'], $minta)) {
        http_response_code(403);
        exit('Sekolah itu bukan sekolah binaan Anda.');
    }
    if ((int) val('SELECT COUNT(*) FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"', [$minta], 0) === 0) {
        http_response_code(403);
        exit('Sekolah itu sudah dinonaktifkan sehingga rekapitulasinya tidak dapat dibuka. Hubungi Super Admin.');
    }
    if (sekolah_id() !== $minta) {
        set_sekolah_context($minta);
        redirect('laporan.php');
    }
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];
$admin = is_admin();

// ---------------------------------------------------------------- KOMENTAR / TINDAK LANJUT PENGAWAS
// Permintaan pemilik: pengawas mengomentari seluruh hasil rekapan supervisi setiap madrasah, dan
// komentarnya "terkirim" ke akun kepala madrasah untuk dilihat. Pilihan pemilik: SATU komentar per
// madrasah (boleh diperbarui pengawas) dan kepala madrasah HANYA membaca (tanpa balasan).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Peran diperiksa SEBELUM CSRF: pengguna yang tidak berhak memang tidak pernah melihat
    // formulirnya, jadi jawabannya harus 403 (bukan 419) — pola yang sama dengan aktivasi.php.
    if (!$pengawas) {
        http_response_code(403);
        exit('Hanya pengawas sekolah/madrasah yang dapat mengirim komentar tindak lanjut.');
    }
    csrf_guard();
    $aksiK = (string) ($_POST['aksi'] ?? '');
    if ($aksiK === 'komentar_simpan') {
        $isi = komentar_potong((string) ($_POST['komentar'] ?? ''), (int) KOMENTAR_MAKS);
        if ($isi === '') {
            // Mengosongkan isi = menghapus komentar (supaya pengawas dapat membatalkan komentarnya).
            if (komentar_pengawas_hapus($sid, (int) $u['id'])) {
                flash('Komentar tindak lanjut untuk ' . $sekolah['nama'] . ' dihapus.', 'warn');
            } else {
                flash('Belum ada komentar yang dapat dihapus untuk sekolah/madrasah ini.', 'warn');
            }
        } else {
            komentar_pengawas_simpan($sid, (int) $u['id'], $isi);
            flash('Komentar tindak lanjut untuk ' . $sekolah['nama'] . ' terkirim ke akun kepala sekolah/madrasah.');
        }
        redirect('laporan.php#cardKomentar');
    }
    redirect('laporan.php');
}
$bolehFilterGuru = $admin || $pengawas;

$instList = instrumen_list(false);

$fGuru = (int) ($_GET['guru'] ?? 0);
$fIns = (string) ($_GET['instrumen'] ?? '');
$fDari = (string) ($_GET['dari'] ?? '');
$fSampai = (string) ($_GET['sampai'] ?? '');
$fPred = (string) ($_GET['predikat'] ?? '');
$fStatus = (string) ($_GET['status'] ?? 'final');

if ($u['role'] === 'guru') {
    $fGuru = (int) ($u['guru_id'] ?? 0);
}

$w = ['s.sekolah_id = ?'];
$p = [$sid];
if ($fGuru > 0) {
    $w[] = 's.guru_id = ?';
    $p[] = $fGuru;
}
if ($fIns !== '' && one('SELECT id FROM instrumen WHERE kode = ?', [$fIns])) {
    $w[] = 'i.kode = ?';
    $p[] = $fIns;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDari)) {
    $w[] = 's.tanggal >= ?';
    $p[] = $fDari;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fSampai)) {
    $w[] = 's.tanggal <= ?';
    $p[] = $fSampai;
}
if (in_array($fPred, ['A', 'B', 'C', 'D'], true)) {
    $w[] = 's.predikat = ?';
    $p[] = $fPred;
}
if ($fStatus === 'final' || $fStatus === 'draft') {
    $w[] = 's.status = ?';
    $p[] = $fStatus;
}
$where = implode(' AND ', $w);

$rows = all("SELECT s.*, g.nama AS guru, g.nip, g.mapel, g.jabatan, i.nama AS instrumen, i.kode, i.ikon
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE $where ORDER BY s.tanggal DESC, g.nama", $p);

$stat = one("SELECT COUNT(*) jml, AVG(s.nilai) rata, MAX(s.nilai) maks, MIN(s.nilai) min
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id WHERE $where", $p);

// Matriks guru x instrumen
$matriks = [];
foreach (all("SELECT s.guru_id, g.nama AS guru, i.kode, i.nama AS instrumen, AVG(s.nilai) rata, COUNT(*) jml
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE $where GROUP BY s.guru_id, i.kode ORDER BY g.nama", $p) as $r) {
    $gid = (int) $r['guru_id'];
    if (!isset($matriks[$gid])) {
        $matriks[$gid] = ['nama' => $r['guru'], 'nilai' => [], 'rata' => []];
    }
    $matriks[$gid]['rata'][$r['kode']] = (float) $r['rata'];
    $matriks[$gid]['nilai'][] = (float) $r['rata'];
}
ksort($matriks);
foreach ($matriks as $gid => $m) {
    $matriks[$gid]['avg'] = $m['nilai'] ? round(array_sum($m['nilai']) / count($m['nilai']), 2) : 0;
}

$perInstrumen = all("SELECT i.kode, i.nama, AVG(s.nilai) rata, COUNT(s.id) jml
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE $where GROUP BY i.id ORDER BY i.urutan", $p);

$distPred = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach (all("SELECT s.predikat, COUNT(*) c FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id WHERE $where GROUP BY s.predikat", $p) as $r) {
    if (isset($distPred[$r['predikat']])) {
        $distPred[$r['predikat']] = (int) $r['c'];
    }
}

$perBulan = all("SELECT substr(s.tanggal,1,7) bln, AVG(s.nilai) rata, COUNT(*) jml
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE $where GROUP BY bln ORDER BY bln", $p);

$predColors = ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'];
$distArr = [];
foreach (['A', 'B', 'C', 'D'] as $pr) {
    if ($distPred[$pr] > 0) {
        $distArr[] = ['l' => $pr . ' — ' . predikat_label($pr), 'v' => $distPred[$pr], 'c' => $predColors[$pr]];
    }
}

$guruList = $bolehFilterGuru ? all('SELECT id, nama, mapel FROM guru WHERE sekolah_id = ? ORDER BY nama', [$sid]) : [];
$qs = $_GET;
$exportUrl = 'laporan_export.php?' . http_build_query($qs);

// Komentar pengawas untuk madrasah ini (kepala madrasah membacanya; pengawas melihat & menyunting
// komentarnya sendiri). Guru tidak melihat kartu ini.
$komentarSaya = $pengawas ? komentar_pengawas_saya($sid, (int) $u['id']) : null;
$komentarList = (!$pengawas && $u['role'] === 'guru') ? [] : komentar_pengawas_list($sid);

page_top('Laporan & Rekap Supervisi', 'laporan');
?>
<div class="card no-print">
  <div class="card-head">
    <div>
      <h2>Filter Laporan</h2>
      <p>Pilih rentang dan cakupan data yang ingin ditampilkan.<?= $pengawas ? ' Sebagai pengawas, Anda hanya dapat melihat dan mengunduh rekap.' : '' ?></p>
    </div>
    <div class="flex">
      <button class="btn btn-ghost" type="button" data-print><?= ikon('print', 16) ?> Cetak</button>
      <a class="btn btn-primary" href="<?= e($exportUrl) ?>"><?= ikon('doc', 16) ?> Unduh Rekap (CSV)</a>
    </div>
  </div>
  <form class="filterbar" method="get">
    <?php if ($admin): ?>
      <div>
        <label class="f" for="guru">Guru</label>
        <select id="guru" name="guru">
          <option value="0">Semua guru</option>
          <?php foreach ($guruList as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= $fGuru === (int) $g['id'] ? 'selected' : '' ?>><?= e((string) $g['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label class="f" for="instrumen">Menu Supervisi</label>
      <select id="instrumen" name="instrumen">
        <option value="">Semua menu</option>
        <?php foreach ($instList as $i): ?>
          <option value="<?= e((string) $i['kode']) ?>" <?= $fIns === $i['kode'] ? 'selected' : '' ?>><?= e((string) $i['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="dari">Dari tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e($fDari) ?>">
    </div>
    <div>
      <label class="f" for="sampai">Sampai tanggal</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($fSampai) ?>">
    </div>
    <div>
      <label class="f" for="predikat">Predikat</label>
      <select id="predikat" name="predikat">
        <option value="">Semua</option>
        <?php foreach (['A', 'B', 'C', 'D'] as $pr): ?>
          <option value="<?= $pr ?>" <?= $fPred === $pr ? 'selected' : '' ?>><?= $pr ?> &middot; <?= e(predikat_label($pr)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="status">Status</label>
      <select id="status" name="status">
        <option value="final" <?= $fStatus === 'final' ? 'selected' : '' ?>>Final saja</option>
        <option value="draft" <?= $fStatus === 'draft' ? 'selected' : '' ?>>Draf saja</option>
        <option value="" <?= $fStatus === '' ? 'selected' : '' ?>>Semua status</option>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Tampilkan</button>
    <a class="btn btn-ghost" href="laporan.php">Reset</a>
  </form>
</div>

<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('check', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= (int) ($stat['jml'] ?? 0) ?></div><div class="stat-lbl">Supervisi tercatat</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('chart', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= r1((float) ($stat['rata'] ?? 0)) ?></div><div class="stat-lbl">Rata-rata nilai</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('star', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $stat['maks'] !== null ? r1($stat['maks']) : '0,00' ?></div><div class="stat-lbl">Nilai tertinggi</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic o"><?= ikon('users', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($matriks) ?></div><div class="stat-lbl">Guru tercakup</div><div class="stat-hint"><?= $stat['min'] !== null ? 'terendah ' . r1($stat['min']) : '' ?></div></div>
  </div>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><div><h2>Rata-rata per Menu Supervisi</h2><p>Sesuai filter yang dipilih.</p></div></div>
    <?php if ($perInstrumen): ?><div class="chart-box tall"><canvas id="chIns"></canvas></div>
    <?php else: ?><div class="empty"><p class="sm mut">Belum ada data pada filter ini.</p></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Distribusi Predikat</h2><p>Komposisi mutu hasil supervisi.</p></div></div>
    <?php if ($distArr): ?><div class="chart-box tall"><canvas id="chPred"></canvas></div>
    <?php else: ?><div class="empty"><p class="sm mut">Belum ada data.</p></div><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h2>Perkembangan per Bulan</h2><p>Tren rata-rata nilai supervisi.</p></div></div>
  <?php if ($perBulan): ?><div class="chart-box"><canvas id="chBulan"></canvas></div>
  <?php else: ?><div class="empty"><p class="sm mut">Belum ada data.</p></div><?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Rekap Nilai per Guru</h2><p>Rata-rata nilai pada tiap menu supervisi beserta predikat akhirnya.</p></div>
    <span class="chip"><?= count($matriks) ?> guru</span>
  </div>
  <?php if ($matriks): ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Guru</th>
            <?php foreach ($instList as $i): ?>
              <th class="ctr"><?= e(str_replace('Supervisi ', '', (string) $i['nama'])) ?></th>
            <?php endforeach; ?>
            <th class="ctr">Rata-rata</th><th class="ctr">Predikat</th><th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($matriks as $gid => $m): $avg = $m['avg']; ?>
          <tr>
            <td><b><?= e((string) $m['nama']) ?></b></td>
            <?php foreach ($instList as $i): $v = $m['rata'][$i['kode']] ?? null; ?>
              <td class="ctr">
                <?php if ($v !== null): ?>
                  <b><?= r1($v) ?></b><span class="tbl-sub"><span class="pill pill-<?= e(predikat_dari((float) $v)) ?>"><?= e(predikat_dari((float) $v)) ?></span></span>
                <?php else: ?>
                  <span class="mut xs">—</span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="ctr">
              <b><?= r1($avg) ?></b>
              <div class="bar-mini mt8"><i class="lv<?= e(predikat_dari($avg)) ?>" style="width:<?= min(100, $avg) ?>%"></i></div>
            </td>
            <td class="ctr"><span class="pill pill-<?= e(predikat_dari($avg)) ?>"><?= e(predikat_dari($avg)) ?> &middot; <?= e(predikat_label(predikat_dari($avg))) ?></span></td>
            <td class="right no-print"><a class="btn btn-ghost btn-sm" href="laporan.php?guru=<?= (int) $gid ?><?= $fIns !== '' ? '&instrumen=' . e($fIns) : '' ?>">Detail</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty"><?= ikon('report', 34) ?><h3>Belum ada data</h3><p>Tidak ada supervisi yang cocok dengan filter.</p></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Rincian Supervisi</h2><p><span id="tblCount"><?= count($rows) ?></span> baris data.</p></div>
    <div style="min-width:230px" class="no-print"><input type="search" id="tblSearch" data-target="tblRinci" placeholder="Cari nama/kegiatan..."></div>
  </div>
  <?php if ($rows): ?>
    <div class="table-wrap">
      <table class="tbl" id="tblRinci">
        <thead><tr><th>Tanggal</th><th>Guru</th><th>Menu Supervisi</th><th>Supervisor</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th class="ctr">Status</th><th class="no-print"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
            <td><b><?= e((string) $r['guru']) ?></b><span class="tbl-sub"><?= e((string) ($r['mapel'] ?: '-')) ?></span></td>
            <td><?= e((string) $r['instrumen']) ?></td>
            <td><?= e((string) ($r['supervisor'] ?: '-')) ?></td>
            <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
            <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?></span></td>
            <td class="ctr"><span class="pill <?= $r['status'] === 'final' ? 'pill-ok' : 'pill-warn' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draf' ?></span></td>
            <td class="right no-print">
              <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>"><?= ikon('eye', 14) ?></a>
                <a class="btn btn-ghost btn-sm" href="cetak.php?id=<?= (int) $r['id'] ?>" target="_blank"><?= ikon('print', 14) ?></a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty"><p class="sm mut">Tidak ada data pada filter ini.</p></div>
  <?php endif; ?>
</div>

<script src="assets/vendor/chart.umd.min.js"></script>
<script>
window.VS_TH = <?= json_encode(thresholds(), JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', function () {
  <?php if ($perInstrumen): ?>
  var ins = <?= json_encode(array_map(fn($i) => ['n' => str_replace('Supervisi ', '', (string) $i['nama']), 'v' => round((float) $i['rata'], 2)], array_values($perInstrumen)), JSON_UNESCAPED_UNICODE) ?>;
  VS.bar('chIns', ins.map(function (d) { return d.n; }), ins.map(function (d) { return d.v; }), false);
  <?php endif; ?>
  <?php if ($distArr): ?>
  var dist = <?= json_encode($distArr, JSON_UNESCAPED_UNICODE) ?>;
  VS.donut('chPred', dist.map(function (d) { return d.l; }), dist.map(function (d) { return d.v; }), dist.map(function (d) { return d.c; }));
  <?php endif; ?>
  <?php if ($perBulan): ?>
  VS.line('chBulan', <?= json_encode(array_column($perBulan, 'bln'), JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode(array_map(fn($r) => round((float) $r['rata'], 2), $perBulan)) ?>);
  <?php endif; ?>
});
</script>
<?php if ($pengawas || ($u['role'] === 'admin' || is_super())): ?>
  <?php // ---- KARTU KOMENTAR / TINDAK LANJUT PENGAWAS (permintaan pemilik) ----
        // Pengawas: menulis/memperbarui komentar untuk SELURUH rekapan madrasah ini.
        // Kepala sekolah/madrasah: membaca komentar yang dikirim pengawas (tanpa balasan). ?>
  <div class="card" id="cardKomentar">
    <div class="card-head">
      <div>
        <h2><?= ikon('message', 18) ?> Komentar &amp; Tindak Lanjut Pengawas</h2>
        <p><?= $pengawas
          ? 'Komentar ini untuk <b>seluruh hasil rekapan</b> ' . e(tetap_kata((string) $sekolah['nama']))
            . ' dan langsung terkirim ke akun kepala sekolah/madrasah.'
          : 'Komentar/tindak lanjut dari pengawas untuk rekapan sekolah/madrasah ini.' ?></p>
      </div>
      <span class="chip"><?= count($komentarList) ?> komentar</span>
    </div>

    <?php if ($pengawas): ?>
      <form method="post" class="komentar-form">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="komentar_simpan">
        <label class="f" for="komentar">Komentar / Tindak Lanjut untuk <?= e(tetap_kata((string) $sekolah['nama'])) ?></label>
        <textarea id="komentar" name="komentar" rows="5" maxlength="<?= (int) KOMENTAR_MAKS ?>"
                  placeholder="mis. Secara umum supervisi sudah baik. Tindak lanjut: perbaiki RPP pada indikator ... dan lengkapi bukti fisik."><?= e((string) ($komentarSaya['isi'] ?? '')) ?></textarea>
        <div class="hint">
          Komentar yang <b>dikosongkan lalu disimpan</b> akan menghapus komentar Anda untuk sekolah ini.
          Satu komentar berlaku untuk seluruh rekapan madrasah ini dan tidak dapat dibalas oleh kepala
          sekolah/madrasah (sesuai permintaan Anda).
        </div>
        <div class="form-actions">
          <?php // Label panjang ini tidak boleh membuat halaman meluber di HP 390px
                // (kelas .btn-komentar mengizinkan teksnya membungkus — lihat assets/style.css). ?>
          <button class="btn btn-primary btn-komentar" type="submit"><?= ikon('send', 16) ?> Kirim Komentar ke Kepala Sekolah/Madrasah</button>
          <span class="xs mut"><?php
            if ($komentarSaya) {
              echo 'Terakhir diperbarui ' . e(waktu_id_polos((string) ($komentarSaya['diubah'] ?: $komentarSaya['dibuat'])));
            } else {
              echo 'Belum ada komentar dari Anda untuk sekolah ini.';
            }
          ?></span>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($komentarList): ?>
      <div class="komentar-list mt16">
        <?php foreach ($komentarList as $k): ?>
          <div class="komentar-item">
            <div class="komentar-head">
              <b><?= e((string) ($k['pengawas'] ?: 'Pengawas')) ?></b>
              <span class="pill pill-gray">Pengawas</span>
              <span class="xs mut"><?= e(waktu_id_polos((string) ($k['diubah'] ?: $k['dibuat']))) ?></span>
              <?php if ($pengawas && (int) $k['pengawas_id'] === (int) $u['id']): ?>
                <span class="pill pill-ok" style="margin-left:auto">Komentar Anda</span>
              <?php endif; ?>
            </div>
            <p class="komentar-isi"><?= nl2br(e((string) $k['isi'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$pengawas): ?>
        <p class="hint" style="margin-top:12px">Komentar ini dikirim pengawas untuk rekapan
          <b><?= e(tetap_kata((string) $sekolah['nama'])) ?></b> dan menjadi bahan tindak lanjut.
          Komentar juga tampil pada dasbor akun kepala sekolah/madrasah.</p>
      <?php endif; ?>
    <?php elseif (!$pengawas): ?>
      <div class="alert alert-warn" style="margin:0">
        <?= ikon('info', 16) ?>
        <span>Belum ada komentar/tindak lanjut dari pengawas untuk sekolah/madrasah ini.</span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
page_bottom();
