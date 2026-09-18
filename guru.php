<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $g = $id ? one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$id, $sid]) : null;
    if (!$g) {
        flash('Data guru tidak ditemukan.', 'err');
        redirect('guru.php');
    }
    if ($aksi === 'hapus') {
        q('DELETE FROM guru WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        flash('Data guru ' . $g['nama'] . ' beserta riwayat supervisinya dihapus.');
    } elseif ($aksi === 'aktif' || $aksi === 'nonaktif') {
        q('UPDATE guru SET aktif = ? WHERE id = ? AND sekolah_id = ?', [$aksi === 'aktif' ? 1 : 0, $id, $sid]);
        flash('Status guru ' . $g['nama'] . ' diperbarui.', 'warn');
    } elseif ($aksi === 'akun') {
        $sudah = one('SELECT id FROM users WHERE guru_id = ?', [$id]);
        if ($sudah) {
            flash('Guru ' . $g['nama'] . ' sudah memiliki akun.', 'warn');
        } else {
            // NIP boleh kosong: nama pengguna memakai NIP bila ada, atau nama guru.
            $username = guru_username_unik($g);
            $pass = guru_sandi_awal($g);
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                [$username, password_hash($pass, PASSWORD_DEFAULT), $g['nama'], 'guru', $sid, $id, date('Y-m-d H:i:s')]);
            flash('Akun login dibuat untuk ' . $g['nama'] . ' — nama pengguna: ' . $username . ', kata sandi awal: ' . $pass . '. Minta guru segera menggantinya.');
        }
    }
    redirect('guru.php');
}

$cari = trim((string) ($_GET['cari'] ?? ''));
$fAktif = (string) ($_GET['status'] ?? '');
$fAkun = (string) ($_GET['akun'] ?? '');
if (isset($_GET['belum'])) {
    $fAkun = 'belum';
}

$w = ['g.sekolah_id = ?'];
$p = [$sid];
if ($cari !== '') {
    $w[] = '(g.nama LIKE ? OR g.nip LIKE ? OR g.mapel LIKE ? OR g.jabatan LIKE ?)';
    $like = '%' . $cari . '%';
    array_push($p, $like, $like, $like, $like);
}
if ($fAktif !== '' && in_array($fAktif, ['1', '0'], true)) {
    $w[] = 'g.aktif = ' . (int) $fAktif;
}
if ($fAkun === 'punya') {
    $w[] = 'EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id)';
} elseif ($fAkun === 'belum') {
    $w[] = 'NOT EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id)';
}
$where = implode(' AND ', $w);

$rows = all("SELECT g.*,
    (SELECT COUNT(*) FROM supervisi s WHERE s.guru_id = g.id AND s.status = 'final') AS jml_supervisi,
    (SELECT AVG(s.nilai) FROM supervisi s WHERE s.guru_id = g.id AND s.status = 'final') AS rata,
    (SELECT username FROM users u WHERE u.guru_id = g.id LIMIT 1) AS username,
    (SELECT foto FROM users u WHERE u.guru_id = g.id AND u.foto IS NOT NULL AND u.foto <> '' LIMIT 1) AS foto
    FROM guru g WHERE $where ORDER BY g.nama", $p);

$totalGuru = (int) val('SELECT COUNT(*) FROM guru WHERE aktif = 1 AND sekolah_id = ?', [$sid], 0);
$totalAkun = (int) val('SELECT COUNT(*) FROM users WHERE role = "guru" AND sekolah_id = ?', [$sid], 0);
$totalBelum = (int) val('SELECT COUNT(*) FROM guru g WHERE g.aktif = 1 AND g.sekolah_id = ? AND NOT EXISTS (SELECT 1 FROM supervisi s WHERE s.guru_id = g.id AND s.status = "final")', [$sid], 0);

page_top('Data Guru', 'guru');
?>
<div class="grid g4">
  <div class="card stat card-tight">
    <div class="stat-ic"><?= ikon('users', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= $totalGuru ?></div><div class="stat-lbl">Guru aktif</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic s"><?= ikon('key', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= $totalAkun ?></div><div class="stat-lbl">Punya akun login</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic o"><?= ikon('star', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= $totalBelum ?></div><div class="stat-lbl">Belum disupervisi</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic p"><?= ikon('folder', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= count($rows) ?></div><div class="stat-lbl">Baris ditampilkan</div></div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Daftar Guru</h2><p><span id="tblCount"><?= count($rows) ?></span> data ditemukan</p></div>
    <div class="flex">
      <a class="btn btn-primary" href="guru_edit.php"><?= ikon('plus', 16) ?> Tambah Guru</a>
      <a class="btn btn-ghost" href="guru_import.php"><?= ikon('upload', 16) ?> Impor Excel/CSV</a>
      <a class="btn btn-ghost" href="cetak_guru.php" title="Cetak daftar guru beserta NIP/NIK dan akun loginnya"><?= ikon('print', 16) ?> Cetak Data Guru</a>
    </div>
  </div>

  <form class="filterbar mb16" method="get">
    <div>
      <label class="f" for="cari">Cari</label>
      <input type="search" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="Nama / NIP / mapel...">
    </div>
    <div>
      <label class="f" for="status">Status</label>
      <select id="status" name="status">
        <option value="">Semua</option>
        <option value="1" <?= $fAktif === '1' ? 'selected' : '' ?>>Aktif</option>
        <option value="0" <?= $fAktif === '0' ? 'selected' : '' ?>>Nonaktif</option>
      </select>
    </div>
    <div>
      <label class="f" for="akun">Akun login</label>
      <select id="akun" name="akun">
        <option value="">Semua</option>
        <option value="punya" <?= $fAkun === 'punya' ? 'selected' : '' ?>>Sudah punya akun</option>
        <option value="belum" <?= $fAkun === 'belum' ? 'selected' : '' ?>>Belum punya akun</option>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Terapkan</button>
    <?php if ($cari !== '' || $fAktif !== '' || $fAkun !== ''): ?>
      <a class="btn btn-ghost" href="guru.php">Reset</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <?= ikon('users', 34) ?>
      <h3>Belum ada data guru</h3>
      <p>Tambahkan satu per satu atau impor sekaligus dari file Excel/CSV.</p>
      <div class="flex" style="justify-content:center">
        <a class="btn btn-primary" href="guru_edit.php"><?= ikon('plus', 16) ?> Tambah Guru</a>
        <a class="btn btn-ghost" href="guru_import.php"><?= ikon('upload', 16) ?> Impor Excel/CSV</a>
        <a class="btn btn-ghost" href="cetak_guru.php"><?= ikon('print', 16) ?> Cetak Data Guru</a>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl" id="tblGuru">
        <thead>
          <tr><th>Nama</th><th>NIP</th><th>Mapel</th><th class="ctr">Supervisi</th><th class="ctr">Rata-rata</th><th class="ctr">Akun</th><th class="ctr">Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $g): $rata = $g['rata'] !== null ? (float) $g['rata'] : null; ?>
          <tr>
            <td>
              <span class="mini-avatar">
                <?= avatar_html((string) $g['nama'], $g['foto'] !== null ? (string) $g['foto'] : null, 34) ?>
                <span>
                  <b><?= e((string) $g['nama']) ?></b>
                  <span class="tbl-sub"><?= e((string) ($g['jabatan'] ?: '-')) ?></span>
                </span>
              </span>
            </td>
            <td class="nowrap"><?= e((string) ($g['nip'] ?: '-')) ?></td>
            <td><?= e((string) ($g['mapel'] ?: '-')) ?></td>
            <td class="ctr"><?= (int) $g['jml_supervisi'] ?></td>
            <td class="ctr">
              <?php if ($rata !== null): ?>
                <b><?= r1($rata) ?></b> <span class="pill pill-<?= e(predikat_dari($rata)) ?>"><?= e(predikat_dari($rata)) ?></span>
              <?php else: ?>
                <span class="mut xs">belum ada</span>
              <?php endif; ?>
            </td>
            <td class="ctr">
              <?php if ($g['username']): ?>
                <span class="pill pill-ok"><?= e((string) $g['username']) ?></span>
              <?php else: ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="aksi" value="akun"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" title="Buatkan akun login"><?= ikon('key', 14) ?> Buat akun</button>
                </form>
              <?php endif; ?>
            </td>
            <td class="ctr"><span class="pill <?= (int) $g['aktif'] === 1 ? 'pill-ok' : 'pill-gray' ?>"><?= (int) $g['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td>
              <div class="row-actions">
                <a class="btn btn-ghost btn-sm" href="guru_edit.php?id=<?= (int) $g['id'] ?>"><?= ikon('edit', 15) ?></a>
                <a class="btn btn-ghost btn-sm" href="supervisi_form.php?kode=administrasi&guru=<?= (int) $g['id'] ?>" title="Nilai supervisi"><?= ikon('check', 15) ?></a>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="<?= (int) $g['aktif'] === 1 ? 'nonaktif' : 'aktif' ?>">
                  <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $g['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" data-confirm="Hapus guru <?= e((string) $g['nama']) ?>? Seluruh riwayat supervisinya juga akan terhapus.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 15) ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
page_bottom();
