<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];
$admin = is_admin();
if ($u['role'] === 'pengawas') {
    flash('Sebagai pengawas, Anda melihat data melalui menu Laporan & Rekap (hanya lihat).', 'warn');
    redirect('laporan.php');
}

$instList = instrumen_list();
if (!$instList) {
    exit('Belum ada instrumen supervisi pada sekolah ini.');
}
$kode = (string) ($_GET['kode'] ?? $instList[0]['kode']);
$ins = one('SELECT * FROM instrumen WHERE kode = ?', [$kode]);
if (!$ins) {
    redirect('supervisi.php?kode=' . urlencode((string) $instList[0]['kode']));
}
$iid = (int) $ins['id'];

// ---------- Aksi ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    if (!$admin) {
        flash('Hanya administrator/kepala sekolah yang boleh mengubah data supervisi.', 'err');
        redirect('supervisi.php?kode=' . urlencode($kode));
    }
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $s = $id ? one('SELECT * FROM supervisi WHERE id = ? AND instrumen_id = ? AND sekolah_id = ?', [$id, $iid, $sid]) : null;
    if (!$s) {
        flash('Data supervisi tidak ditemukan.', 'err');
        redirect('supervisi.php?kode=' . urlencode($kode));
    }
    if ($aksi === 'final') {
        q('UPDATE supervisi SET status = "final", diubah = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
        flash('Supervisi ditandai final.');
    } elseif ($aksi === 'draft') {
        q('UPDATE supervisi SET status = "draft", diubah = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
        flash('Supervisi dikembalikan menjadi draf.', 'warn');
    } elseif ($aksi === 'hapus') {
        q('DELETE FROM supervisi WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        flash('Data supervisi dihapus.');
    }
    redirect('supervisi.php?kode=' . urlencode($kode));
}

// ---------- Filter ----------
$cari = trim((string) ($_GET['cari'] ?? ''));
$fStatus = (string) ($_GET['status'] ?? '');
$fPred = (string) ($_GET['predikat'] ?? '');

$w = ['s.instrumen_id = ?', 's.sekolah_id = ?'];
$p = [$iid, $sid];
if (!$admin) {
    $w[] = 's.guru_id = ?';
    $p[] = (int) ($u['guru_id'] ?? 0);
}
if ($cari !== '') {
    $w[] = '(g.nama LIKE ? OR g.mapel LIKE ? OR s.supervisor LIKE ? OR s.periode LIKE ?)';
    $like = '%' . $cari . '%';
    array_push($p, $like, $like, $like, $like);
}
if ($fStatus !== '' && in_array($fStatus, ['final', 'draft'], true)) {
    $w[] = 's.status = ?';
    $p[] = $fStatus;
}
if ($fPred !== '' && in_array($fPred, ['A', 'B', 'C', 'D'], true)) {
    $w[] = 's.predikat = ?';
    $p[] = $fPred;
}
$where = implode(' AND ', $w);

$rows = all("SELECT s.*, g.nama AS guru, g.mapel, g.nip
    FROM supervisi s JOIN guru g ON g.id = s.guru_id
    WHERE $where ORDER BY s.tanggal DESC, s.id DESC", $p);

$stat = one("SELECT COUNT(*) jml, AVG(s.nilai) rata, MAX(s.nilai) maks, MIN(s.nilai) min
    FROM supervisi s JOIN guru g ON g.id = s.guru_id WHERE $where AND s.status = 'final'", $p);
$jmlFinal = (int) ($stat['jml'] ?? 0);
$rata = $stat['rata'] !== null ? (float) $stat['rata'] : 0.0;

$jmlGuru = (int) val('SELECT COUNT(*) FROM guru WHERE aktif = 1 AND sekolah_id = ?', [$sid], 0);
$jmlGuruDinilai = (int) val("SELECT COUNT(DISTINCT s.guru_id) FROM supervisi s WHERE s.instrumen_id = ? AND s.sekolah_id = ? AND s.status = 'final'", [$iid, $sid], 0);
$cakupan = $jmlGuru > 0 ? (int) round($jmlGuruDinilai / $jmlGuru * 100) : 0;

$distPred = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach (all("SELECT predikat, COUNT(*) c FROM supervisi s JOIN guru g ON g.id = s.guru_id WHERE $where AND s.status = 'final' GROUP BY predikat", $p) as $r) {
    if (isset($distPred[$r['predikat']])) {
        $distPred[$r['predikat']] = (int) $r['c'];
    }
}

// rata-rata per butir (untuk melihat butir terlemah)
$perButir = all("SELECT b.komponen, b.urutan, AVG(d.skor) rata, b.skor_maks
    FROM detail d JOIN butir b ON b.id = d.butir_id JOIN supervisi s ON s.id = d.supervisi_id
    WHERE s.instrumen_id = ? AND s.sekolah_id = ? AND s.status = 'final'
    GROUP BY b.id ORDER BY rata ASC LIMIT 6", [$iid, $sid]);

page_top((string) $ins['nama'], $kode);
?>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= e((string) $ins['nama']) ?></h2>
      <p><?= e((string) $ins['deskripsi']) ?></p>
    </div>
    <div class="flex">
      <span class="chip"><?= (int) $ins['jml_butir'] ?> butir aktif &middot; skala 1-4</span>
      <?php if (is_super()): ?>
        <a class="btn btn-ghost" href="instrumen_butir.php?kode=<?= e($kode) ?>" title="Butir penilaian bersifat global (berlaku semua sekolah)"><?= ikon('edit', 16) ?> Kelola Butir (Global)</a>
      <?php endif; ?>
      <?php if ($admin || is_super()): ?>
        <a class="btn btn-ghost" href="cetak_butir.php?kode=<?= e($kode) ?>" title="Cetak daftar butir aktif menu ini"><?= ikon('print', 16) ?> Cetak Daftar Butir</a>
      <?php endif; ?>
      <?php if ($admin): ?>
        <a class="btn btn-primary" href="supervisi_form.php?kode=<?= e($kode) ?>"><?= ikon('plus', 16) ?> Penilaian Baru</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid g4">
    <div class="card stat card-tight">
      <div class="stat-ic s"><?= ikon('check', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $jmlFinal ?></div><div class="stat-lbl">Supervisi final</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic g"><?= ikon('chart', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= r1($rata) ?></div><div class="stat-lbl">Rata-rata nilai</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic p"><?= ikon('users', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $cakupan ?>%</div><div class="stat-lbl">Cakupan guru (<?= $jmlGuruDinilai ?>/<?= $jmlGuru ?>)</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic o"><?= ikon('star', 18) ?></div>
      <div>
        <div class="stat-num" style="font-size:1.3rem"><?= $stat['maks'] !== null ? r1($stat['maks']) : '0,00' ?></div>
        <div class="stat-lbl">Nilai tertinggi<?= $stat['min'] !== null ? ' &middot; terendah ' . r1($stat['min']) : '' ?></div>
      </div>
    </div>
  </div>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><div><h2>Nilai per Guru</h2><p>Rata-rata nilai final pada menu supervisi ini.</p></div></div>
    <?php if ($jmlFinal > 0): ?>
      <div class="chart-box tall"><canvas id="chGuru"></canvas></div>
    <?php else: ?>
      <div class="empty"><?= ikon('chart', 32) ?><h3>Belum ada nilai final</h3><p>Grafik muncul setelah ada supervisi berstatus final.</p></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Predikat</h2><p>Sebaran predikat menu ini.</p></div></div>
    <?php if ($jmlFinal > 0): ?>
      <div class="chart-box tall"><canvas id="chPred"></canvas></div>
    <?php else: ?>
      <div class="empty"><p class="sm mut">Belum ada data.</p></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($perButir): ?>
<div class="card">
  <div class="card-head"><div><h2>Butir dengan Capaian Terendah</h2><p>Enam butir yang paling perlu pembenahan pada menu ini.</p></div></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th style="width:44px">#</th><th>Komponen</th><th style="width:190px">Rata-rata skor</th></tr></thead>
      <tbody>
      <?php $n = 0; foreach ($perButir as $b): $n++; $pct = ((float) $b['skor_maks']) > 0 ? ((float) $b['rata'] / (float) $b['skor_maks'] * 100) : 0; ?>
        <tr>
          <td><?= $n ?></td>
          <td><?= e((string) $b['komponen']) ?></td>
          <td>
            <div class="flex between" style="gap:8px"><b class="sm"><?= r1($b['rata']) ?></b><span class="xs mut"><?= r1($pct) ?>%</span></div>
            <div class="bar-mini mt8"><i class="lv<?= e(predikat_dari($pct)) ?>" style="width:<?= min(100, max(0, $pct)) ?>%"></i></div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h2>Daftar Supervisi</h2><p><span id="tblCount"><?= count($rows) ?></span> data ditemukan</p></div>
    <form class="filterbar" method="get">
      <input type="hidden" name="kode" value="<?= e($kode) ?>">
      <div>
        <label class="f" for="cari">Cari</label>
        <input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama guru / mapel...">
      </div>
      <div>
        <label class="f" for="status">Status</label>
        <select id="status" name="status">
          <option value="">Semua</option>
          <option value="final" <?= $fStatus === 'final' ? 'selected' : '' ?>>Final</option>
          <option value="draft" <?= $fStatus === 'draft' ? 'selected' : '' ?>>Draf</option>
        </select>
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
      <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Terapkan</button>
      <?php if ($cari !== '' || $fStatus !== '' || $fPred !== ''): ?>
        <a class="btn btn-ghost" href="supervisi.php?kode=<?= e($kode) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">
      <?= ikon('doc', 34) ?>
      <h3>Belum ada data supervisi</h3>
      <p><?= $admin ? 'Mulai lakukan penilaian untuk menu ini.' : 'Belum ada hasil supervisi untuk Anda pada menu ini.' ?></p>
      <?php if ($admin): ?>
        <a class="btn btn-primary" href="supervisi_form.php?kode=<?= e($kode) ?>"><?= ikon('plus', 16) ?> Penilaian Baru</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl" id="tblSupervisi">
        <thead>
          <tr>
            <th>Tanggal</th><th>Guru</th><th>Supervisor</th>
            <th class="ctr">Skor</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th class="ctr">Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?><span class="tbl-sub"><?= e((string) ($r['periode'] ?: '-')) ?></span></td>
            <td><b><?= e((string) $r['guru']) ?></b><span class="tbl-sub"><?= e((string) ($r['mapel'] ?: '-')) ?></span></td>
            <td><?= e((string) ($r['supervisor'] ?: '-')) ?></td>
            <td class="ctr nowrap"><?= r1($r['total_skor']) ?> / <?= r1($r['skor_maks']) ?></td>
            <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
            <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?> &middot; <?= e(predikat_label((string) $r['predikat'])) ?></span></td>
            <td class="ctr"><span class="pill <?= $r['status'] === 'final' ? 'pill-ok' : 'pill-warn' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draf' ?></span></td>
            <td>
              <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>"><?= ikon('eye', 15) ?></a>
                <?php if ($admin): ?>
                  <a class="btn btn-ghost btn-sm" href="supervisi_form.php?kode=<?= e($kode) ?>&id=<?= (int) $r['id'] ?>"><?= ikon('edit', 15) ?></a>
                  <form method="post" data-confirm="Hapus supervisi untuk <?= e((string) $r['guru']) ?> tanggal <?= e(tanggal_id((string) $r['tanggal'])) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 15) ?></button>
                  </form>
                  <?php if ($r['status'] === 'draft'): ?>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="aksi" value="final"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-ok btn-sm" type="submit"><?= ikon('check', 15) ?> Final</button>
                    </form>
                  <?php else: ?>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="aksi" value="draft"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit">Draf</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script src="assets/vendor/chart.umd.min.js"></script>
<script>
window.VS_TH = <?= json_encode(thresholds(), JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', function () {
  <?php if ($jmlFinal > 0): ?>
  var guru = <?= json_encode(array_map(fn($r) => ['n' => $r['guru'], 'v' => round((float) $r['nilai'], 2)], array_values(array_filter($rows, fn($r) => $r['status'] === 'final'))), JSON_UNESCAPED_UNICODE) ?>;
  guru.sort(function (a, b) { return b.v - a.v; });
  VS.bar('chGuru', guru.map(function (d) { return d.n; }), guru.map(function (d) { return d.v; }), true,
    guru.map(function (d) { var p = d.v >= 90 ? 'A' : d.v >= 80 ? 'B' : d.v >= 70 ? 'C' : 'D'; return VS.predikatColor[p] + 'cc'; }));

  var dist = <?= json_encode(array_values(array_filter([
      ['l' => 'Amat Baik (A)', 'v' => $distPred['A'], 'c' => '#10b981'],
      ['l' => 'Baik (B)', 'v' => $distPred['B'], 'c' => '#3b82f6'],
      ['l' => 'Cukup (C)', 'v' => $distPred['C'], 'c' => '#f59e0b'],
      ['l' => 'Perlu Pembinaan (D)', 'v' => $distPred['D'], 'c' => '#dc2626'],
  ], fn($d) => $d['v'] > 0)), JSON_UNESCAPED_UNICODE) ?>;
  VS.donut('chPred', dist.map(function (d) { return d.l; }), dist.map(function (d) { return d.v; }), dist.map(function (d) { return d.c; }));
  <?php endif; ?>
});
</script>
<?php
page_bottom();
