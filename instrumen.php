<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Menu & butir penilaian bersifat GLOBAL: hanya Super Admin yang mengelolanya,
// dan tidak memerlukan konteks sekolah.
$u = require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $ins = $id ? one('SELECT * FROM instrumen WHERE id = ?', [$id]) : null;

    if ($aksi === 'tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $kode = strtolower(trim((string) ($_POST['kode'] ?? '')));
        $kode = preg_replace('/[^a-z0-9_]+/', '_', $kode);
        $deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
        $ikon = (string) ($_POST['ikon'] ?? 'doc');
        if ($nama === '' || $kode === '') {
            flash('Nama dan kode menu wajib diisi.', 'err');
        } elseif (one('SELECT id FROM instrumen WHERE kode = ?', [$kode])) {
            flash('Kode menu "' . $kode . '" sudah dipakai.', 'err');
        } else {
            $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) + 1 FROM instrumen', [], 1);
            q('INSERT INTO instrumen (kode, nama, deskripsi, ikon, urutan, aktif) VALUES (?,?,?,?,?,1)',
                [$kode, $nama, $deskripsi, $ikon, $urutan]);
            flash('Menu supervisi "' . $nama . '" ditambahkan. Silakan isi butir penilaiannya.');
        }
        redirect('instrumen.php');
    }

    if (!$ins) {
        flash('Instrumen tidak ditemukan.', 'err');
        redirect('instrumen.php');
    }

    if ($aksi === 'ubah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
        $ikon = (string) ($_POST['ikon'] ?? 'doc');
        if ($nama === '') {
            flash('Nama menu wajib diisi.', 'err');
        } else {
            q('UPDATE instrumen SET nama=?, deskripsi=?, ikon=? WHERE id=?', [$nama, $deskripsi, $ikon, $id]);
            flash('Instrumen diperbarui.');
        }
    } elseif ($aksi === 'aktif' || $aksi === 'nonaktif') {
        q('UPDATE instrumen SET aktif = ? WHERE id = ?', [$aksi === 'aktif' ? 1 : 0, $id]);
        flash('Status instrumen diperbarui.', 'warn');
    } elseif ($aksi === 'hapus') {
        $jml = (int) val('SELECT COUNT(*) FROM supervisi WHERE instrumen_id = ?', [$id], 0);
        if ($jml > 0) {
            flash('Instrumen ini masih memiliki ' . $jml . ' data supervisi, tidak dapat dihapus. Nonaktifkan saja.', 'err');
        } else {
            // Menu GLOBAL: butir & menunya dihapus sekali, berlaku untuk semua sekolah.
            q('DELETE FROM butir WHERE instrumen_id = ?', [$id]);
            q('DELETE FROM instrumen WHERE id = ?', [$id]);
            flash('Instrumen "' . $ins['nama'] . '" dihapus.');
        }
    } elseif ($aksi === 'naik' || $aksi === 'turun') {
        $arah = $aksi === 'naik' ? '<' : '>';
        $urut = $aksi === 'naik' ? 'DESC' : 'ASC';
        $tetangga = one("SELECT * FROM instrumen WHERE urutan $arah ? ORDER BY urutan $urut LIMIT 1", [(int) $ins['urutan']]);
        if ($tetangga) {
            db()->exec('BEGIN IMMEDIATE');
            try {
                q('UPDATE instrumen SET urutan = ? WHERE id = ?', [(int) $tetangga['urutan'], (int) $ins['id']]);
                q('UPDATE instrumen SET urutan = ? WHERE id = ?', [(int) $ins['urutan'], (int) $tetangga['id']]);
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw $ex;
            }
        }
    }
    redirect('instrumen.php');
}

$rows = all('SELECT i.*,
    (SELECT COUNT(*) FROM butir b WHERE b.instrumen_id = i.id AND b.aktif = 1) jml_butir,
    (SELECT COUNT(*) FROM butir b WHERE b.instrumen_id = i.id AND b.aktif = 0) jml_nonaktif,
    (SELECT COUNT(*) FROM supervisi s WHERE s.instrumen_id = i.id) jml_supervisi
    FROM instrumen i ORDER BY i.urutan, i.id');

$ikonPilihan = ['folder' => 'Folder/Administrasi', 'doc' => 'Dokumen/Modul', 'eye' => 'Mata/Observasi', 'chart' => 'Grafik/Penilaian', 'check' => 'Centang', 'star' => 'Bintang', 'users' => 'Guru', 'report' => 'Laporan'];

page_top('Instrumen Penilaian', 'instrumen');
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>Menu &amp; Instrumen Supervisi</h2>
      <p>Atur nama menu, urutan tampil, serta butir penilaian pada setiap menu.
        Daftar ini <b>berlaku untuk semua sekolah/madrasah</b> — perubahan di sini langsung dipakai
        seluruh sekolah, dan hanya Super Admin yang dapat mengubahnya.</p>
    </div>
    <div class="flex">
      <span class="chip"><?= count($rows) ?> menu</span>
      <span class="chip"><?= jumlah_butir_global(false) ?> butir (<?= jumlah_butir_global() ?> aktif)</span>
      <a class="btn btn-ghost" href="cetak_butir.php"><?= ikon('print', 15) ?> Cetak Daftar Butir</a>
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th style="width:44px">Urut</th><th>Menu Supervisi</th><th class="ctr">Butir</th><th class="ctr">Data</th><th class="ctr">Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i): ?>
        <tr>
          <td>
            <div class="flex" style="gap:4px">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="naik"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">▲</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="turun"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">▼</button></form>
            </div>
          </td>
          <td>
            <div class="flex" style="gap:10px;align-items:center">
              <span class="stat-ic" style="width:34px;height:34px;border-radius:10px"><?= ikon((string) $i['ikon'], 16) ?></span>
              <div>
                <form method="post" class="flex" style="gap:6px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                  <input type="text" name="nama" value="<?= e((string) $i['nama']) ?>" style="width:250px;padding:6px 9px;font-size:.82rem">
                  <input type="text" name="deskripsi" value="<?= e((string) $i['deskripsi']) ?>" style="width:270px;padding:6px 9px;font-size:.82rem" placeholder="Deskripsi singkat">
                  <select name="ikon" style="width:170px;padding:6px 9px;font-size:.82rem">
                    <?php foreach ($ikonPilihan as $k => $lbl): ?>
                      <option value="<?= e($k) ?>" <?= $i['ikon'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-ghost btn-sm" type="submit">Simpan</button>
                </form>
                <div class="xs mut mt8">kode: <?= e((string) $i['kode']) ?></div>
              </div>
            </div>
          </td>
          <td class="ctr">
            <b><?= (int) $i['jml_butir'] ?></b>
            <?php if ((int) $i['jml_nonaktif'] > 0): ?><span class="tbl-sub"><span class="pill pill-gray"><?= (int) $i['jml_nonaktif'] ?> nonaktif</span></span><?php endif; ?>
          </td>
          <td class="ctr"><?= (int) $i['jml_supervisi'] ?></td>
          <td class="ctr"><span class="pill <?= (int) $i['aktif'] === 1 ? 'pill-ok' : 'pill-gray' ?>"><?= (int) $i['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td>
            <div class="row-actions">
              <a class="btn btn-primary btn-sm" href="instrumen_butir.php?kode=<?= e((string) $i['kode']) ?>"><?= ikon('check', 14) ?> Kelola Butir</a>
              <a class="btn btn-ghost btn-sm" href="cetak_butir.php?kode=<?= e((string) $i['kode']) ?>" title="Cetak daftar butir aktif menu ini"><?= ikon('print', 14) ?></a>
              <a class="btn btn-ghost btn-sm" href="supervisi.php?kode=<?= e((string) $i['kode']) ?>"><?= ikon('eye', 14) ?></a>
              <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="<?= (int) $i['aktif'] === 1 ? 'nonaktif' : 'aktif' ?>">
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $i['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-confirm="Hapus menu <?= e((string) $i['nama']) ?> beserta butirnya?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 14) ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h2>Tambah Menu Supervisi Baru</h2><p>Bila sekolah memakai jenis supervisi tambahan, misalnya supervisi laboratorium atau bimbingan konseling.</p></div></div>
  <form method="post" class="grid g4">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="tambah">
    <div>
      <label class="f" for="nama">Nama Menu *</label>
      <input type="text" id="nama" name="nama" required placeholder="Supervisi Laboratorium">
    </div>
    <div>
      <label class="f" for="kode">Kode *</label>
      <input type="text" id="kode" name="kode" required placeholder="laboratorium">
    </div>
    <div>
      <label class="f" for="deskripsi">Deskripsi</label>
      <input type="text" id="deskripsi" name="deskripsi" placeholder="Penjelasan singkat">
    </div>
    <div>
      <label class="f" for="ikon">Ikon</label>
      <select id="ikon" name="ikon">
        <?php foreach ($ikonPilihan as $k => $lbl): ?>
          <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="grid-column:1/-1">
      <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Menu</button>
    </div>
  </form>
</div>
<?php
page_bottom();
