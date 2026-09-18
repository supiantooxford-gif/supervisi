<?php
/**
 * Jadwal Supervisi (Data Master).
 *
 * Satu tabel untuk SEMUA jadwal — tidak lagi dipisah per tanggal. Setiap baris membawa
 * tanggalnya sendiri, sehingga jadwal dari beberapa hari tampil berurutan (tanggal, lalu urutan)
 * dalam satu daftar dan dicetak pada satu lembar (lihat cetak_jadwal.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$pengawas = $u['role'] === 'pengawas';

// Guru tidak memakai halaman jadwal (tetap ditolak, sama seperti sebelumnya).
if ($u['role'] === 'guru') {
    http_response_code(403);
    exit('Halaman jadwal supervisi hanya untuk admin sekolah/madrasah dan pengawas.');
}

// Pengawas dapat MEMBUKA jadwal sekolah/madrasah binaannya lewat daftar binaan (?sekolah=ID).
// Tautan itu sekaligus memindahkan konteks ke sekolah tersebut, sama seperti menu Laporan.
if ($pengawas && (int) ($_GET['sekolah'] ?? 0) > 0) {
    $minta = (int) $_GET['sekolah'];
    if (!pengawas_punya((int) $u['id'], $minta)) {
        http_response_code(403);
        exit('Madrasah/sekolah itu bukan sekolah binaan Anda.');
    }
    if ((int) val('SELECT COUNT(*) FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"', [$minta], 0) === 0) {
        http_response_code(403);
        exit('Madrasah/sekolah itu sudah dinonaktifkan sehingga jadwalnya tidak dapat dibuka.');
    }
    if (sekolah_id() !== $minta) {
        set_sekolah_context($minta);
        redirect('jadwal.php');
    }
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];
$bolehUbah = is_admin();   // pengawas = hanya lihat

/** Tanggal valid (YYYY-MM-DD) atau null. */
function tanggal_valid($v): ?string
{
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
}

/** Bangun klausa WHERE untuk filter rentang tanggal + parameter-nya. */
function jadwal_filter(?string $dari, ?string $sampai): array
{
    $w = ['sekolah_id = ?'];
    $p = [sekolah_id()];
    if ($dari !== null) {
        $w[] = 'tanggal >= ?';
        $p[] = $dari;
    }
    if ($sampai !== null) {
        $w[] = 'tanggal <= ?';
        $p[] = $sampai;
    }
    return [implode(' AND ', $w), $p];
}

/** URL halaman dengan filter yang sedang aktif (untuk pengalihan setelah aksi). */
function jadwal_url(?string $dari, ?string $sampai, array $extra = []): string
{
    $q = [];
    if ($dari !== null) {
        $q['dari'] = $dari;
    }
    if ($sampai !== null) {
        $q['sampai'] = $sampai;
    }
    foreach ($extra as $k => $v) {
        $q[$k] = $v;
    }
    return 'jadwal.php' . ($q ? '?' . http_build_query($q) : '');
}

// ---------------------------------------------------------------- filter aktif
$fDari = tanggal_valid($_POST['f_dari'] ?? $_GET['dari'] ?? null);
$fSampai = tanggal_valid($_POST['f_sampai'] ?? $_GET['sampai'] ?? null);
if ($fDari !== null && $fSampai !== null && $fDari > $fSampai) {
    [$fDari, $fSampai] = [$fSampai, $fDari];
}

// Tautan lama (jadwal per tanggal) tetap diarahkan ke tampilan satu tabel.
if (isset($_GET['tanggal']) && ($t = tanggal_valid($_GET['tanggal'])) !== null) {
    redirect('jadwal.php?dari=' . urlencode($t) . '&sampai=' . urlencode($t));
}

$url = jadwal_url($fDari, $fSampai);
[$where, $params] = jadwal_filter($fDari, $fSampai);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pengawas hanya boleh melihat: seluruh aksi pengubahan ditolak.
    if (!$bolehUbah) {
        http_response_code(403);
        exit('Akun pengawas hanya dapat melihat jadwal, tidak mengubahnya.');
    }
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    // ---------------- tambah baris ----------------
    if ($aksi === 'tambah') {
        $tanggal = tanggal_valid($_POST['tanggal'] ?? '');
        if ($tanggal === null) {
            flash('Tanggal jadwal wajib diisi dengan format tanggal yang benar.', 'err');
            redirect($url);
        }
        $guruId = (int) ($_POST['guru_id'] ?? 0);
        $nama = trim((string) ($_POST['nama_guru'] ?? ''));
        $mapel = trim((string) ($_POST['mapel'] ?? ''));
        $kelas = trim((string) ($_POST['kelas'] ?? ''));
        if ($guruId > 0) {
            $g = one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$guruId, $sid]);
            if (!$g) {
                flash('Guru yang dipilih tidak ditemukan pada sekolah ini.', 'err');
                redirect($url);
            }
            if ($nama === '') {
                $nama = (string) $g['nama'];
            }
            if ($mapel === '') {
                $mapel = (string) ($g['mapel'] ?? '');
            }
            if ($kelas === '') {
                $kelas = (string) ($g['kelas'] ?? '');
            }
        }
        if ($nama === '') {
            flash('Nama guru wajib diisi (pilih dari daftar atau tulis manual).', 'err');
            redirect($url);
        }
        $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) + 1 FROM jadwal WHERE sekolah_id = ? AND tanggal = ?', [$sid, $tanggal], 1);
        q('INSERT INTO jadwal (sekolah_id, tanggal, urutan, guru_id, nama_guru, mapel, kelas, jam_ke, supervisor, keterangan, dibuat)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $sid, $tanggal, $urutan, $guruId > 0 ? $guruId : null, $nama, $mapel, $kelas,
            trim((string) ($_POST['jam_ke'] ?? '')),
            trim((string) ($_POST['supervisor'] ?? '')),
            trim((string) ($_POST['keterangan'] ?? '')),
            date('Y-m-d H:i:s'),
        ]);
        flash('Jadwal ' . $nama . ' (' . tanggal_id($tanggal) . ') ditambahkan.');
        redirect($url);
    }

    // ---------------- ubah baris ----------------
    if ($aksi === 'ubah') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = one('SELECT * FROM jadwal WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        if (!$row) {
            flash('Baris jadwal tidak ditemukan.', 'err');
            redirect($url);
        }
        $tanggal = tanggal_valid($_POST['tanggal'] ?? '');
        if ($tanggal === null) {
            flash('Tanggal jadwal wajib diisi dengan format tanggal yang benar.', 'err');
            redirect($url);
        }
        $nama = trim((string) ($_POST['nama_guru'] ?? ''));
        if ($nama === '') {
            flash('Nama guru wajib diisi.', 'err');
            redirect($url);
        }
        // Bila tanggal dipindah, baris diletakkan di akhir urutan tanggal baru.
        $urutan = (int) $row['urutan'];
        if ($tanggal !== (string) $row['tanggal']) {
            $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) + 1 FROM jadwal WHERE sekolah_id = ? AND tanggal = ?', [$sid, $tanggal], 1);
        }
        q('UPDATE jadwal SET tanggal=?, urutan=?, nama_guru=?, mapel=?, kelas=?, jam_ke=?, supervisor=?, keterangan=? WHERE id=? AND sekolah_id=?', [
            $tanggal, $urutan, $nama,
            trim((string) ($_POST['mapel'] ?? '')),
            trim((string) ($_POST['kelas'] ?? '')),
            trim((string) ($_POST['jam_ke'] ?? '')),
            trim((string) ($_POST['supervisor'] ?? '')),
            trim((string) ($_POST['keterangan'] ?? '')),
            $id, $sid,
        ]);
        flash('Jadwal ' . $nama . ' diperbarui.');
        redirect($url);
    }

    // ---------------- hapus satu baris ----------------
    if ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = one('SELECT * FROM jadwal WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        if (!$row) {
            flash('Baris jadwal tidak ditemukan.', 'err');
            redirect($url);
        }
        q('DELETE FROM jadwal WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        flash('Jadwal ' . $row['nama_guru'] . ' dihapus.', 'warn');
        redirect($url);
    }

    // ---------------- naik / turun (dalam tanggal yang sama) ----------------
    if ($aksi === 'naik' || $aksi === 'turun') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = one('SELECT * FROM jadwal WHERE id = ? AND sekolah_id = ?', [$id, $sid]);
        if ($row) {
            $arah = $aksi === 'naik' ? '<' : '>';
            $urut = $aksi === 'naik' ? 'DESC' : 'ASC';
            $tetangga = one("SELECT * FROM jadwal WHERE sekolah_id = ? AND tanggal = ? AND urutan $arah ? ORDER BY urutan $urut LIMIT 1",
                [$sid, (string) $row['tanggal'], (int) $row['urutan']]);
            if ($tetangga) {
                db()->exec('BEGIN IMMEDIATE');
                try {
                    q('UPDATE jadwal SET urutan = ? WHERE id = ?', [(int) $tetangga['urutan'], (int) $row['id']]);
                    q('UPDATE jadwal SET urutan = ? WHERE id = ?', [(int) $row['urutan'], (int) $tetangga['id']]);
                    db()->exec('COMMIT');
                } catch (Throwable $ex) {
                    db()->exec('ROLLBACK');
                    throw $ex;
                }
            }
        }
        redirect($url);
    }

    // ---------------- salin jadwal dari satu tanggal ke tanggal lain ----------------
    if ($aksi === 'salin') {
        $dari = tanggal_valid($_POST['dari'] ?? '');
        $ke = tanggal_valid($_POST['ke'] ?? '');
        if ($dari === null || $ke === null) {
            flash('Tanggal sumber dan tanggal tujuan wajib diisi.', 'err');
            redirect($url);
        }
        if ($dari === $ke) {
            flash('Tanggal tujuan sama dengan tanggal sumber.', 'err');
            redirect($url);
        }
        $rows = all('SELECT * FROM jadwal WHERE sekolah_id = ? AND tanggal = ? ORDER BY urutan, id', [$sid, $dari]);
        if (!$rows) {
            flash('Tidak ada jadwal pada tanggal sumber ' . tanggal_id($dari) . '.', 'err');
            redirect($url);
        }
        $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) FROM jadwal WHERE sekolah_id = ? AND tanggal = ?', [$sid, $ke], 0);
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($rows as $r) {
                $urutan++;
                q('INSERT INTO jadwal (sekolah_id, tanggal, urutan, guru_id, nama_guru, mapel, kelas, jam_ke, supervisor, keterangan, dibuat)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                    $sid, $ke, $urutan, $r['guru_id'], $r['nama_guru'], $r['mapel'], $r['kelas'],
                    $r['jam_ke'], $r['supervisor'], $r['keterangan'], date('Y-m-d H:i:s'),
                ]);
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        flash(count($rows) . ' baris jadwal disalin dari ' . tanggal_id($dari) . ' ke ' . tanggal_id($ke) . '.');
        redirect($url);
    }

    // ---------------- hapus sesuai filter / hapus semua ----------------
    if ($aksi === 'hapus_filter' || $aksi === 'hapus_semua') {
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));
        if ($konfirmasi !== 'HAPUS') {
            flash('Ketik HAPUS untuk mengonfirmasi penghapusan jadwal.', 'err');
            redirect($url);
        }
        if ($aksi === 'hapus_filter') {
            if ($fDari === null && $fSampai === null) {
                flash('Pilih rentang tanggal dulu, atau gunakan "Hapus semua jadwal" bila memang ingin menghapus semuanya.', 'err');
                redirect($url);
            }
            $n = (int) val('SELECT COUNT(*) FROM jadwal WHERE ' . $where, $params, 0);
            q('DELETE FROM jadwal WHERE ' . $where, $params);
            flash($n . ' baris jadwal pada rentang yang dipilih dihapus.', 'warn');
        } else {
            $n = (int) val('SELECT COUNT(*) FROM jadwal WHERE sekolah_id = ?', [$sid], 0);
            q('DELETE FROM jadwal WHERE sekolah_id = ?', [$sid]);
            flash($n . ' baris jadwal (seluruh tanggal) dihapus.', 'warn');
        }
        redirect('jadwal.php');
    }

    flash('Aksi tidak dikenali.', 'err');
    redirect($url);
}

// ---------------------------------------------------------------- data halaman
$rows = all('SELECT * FROM jadwal WHERE ' . $where . ' ORDER BY tanggal, urutan, id', $params);

$jmlSemua = (int) val('SELECT COUNT(*) FROM jadwal WHERE sekolah_id = ?', [$sid], 0);
$jmlHari = (int) val('SELECT COUNT(DISTINCT tanggal) FROM jadwal WHERE ' . $where, $params, 0);
$jmlGuru = (int) val('SELECT COUNT(DISTINCT COALESCE(guru_id, nama_guru)) FROM jadwal WHERE ' . $where, $params, 0);
$tanggalTersedia = array_column(all('SELECT DISTINCT tanggal FROM jadwal WHERE sekolah_id = ? ORDER BY tanggal', [$sid]), 'tanggal');

$guruList = all('SELECT id, nama, mapel, kelas FROM guru WHERE sekolah_id = ? AND aktif = 1 ORDER BY nama', [$sid]);

// usulan supervisor: kepala sekolah + akun pengawas + supervisor yang pernah dipakai
$saranSupervisor = [];
$kepsek = trim(setting('kepala_sekolah', ''));
if ($kepsek !== '' && $kepsek !== '-') {
    $saranSupervisor[] = $kepsek;
}
foreach (all('SELECT nama FROM users WHERE role = "pengawas" AND aktif = 1 ORDER BY nama') as $r) {
    $saranSupervisor[] = (string) $r['nama'];
}
foreach (all('SELECT DISTINCT supervisor FROM jadwal WHERE sekolah_id = ? AND supervisor <> "" ORDER BY supervisor', [$sid]) as $r) {
    $saranSupervisor[] = (string) $r['supervisor'];
}
$saranSupervisor = array_values(array_unique($saranSupervisor));

// baris yang sedang disunting (mode ubah)
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $editRow = one('SELECT * FROM jadwal WHERE id = ? AND sekolah_id = ?', [$editId, $sid]);
}

$cetakUrl = 'cetak_jadwal.php' . ($fDari !== null || $fSampai !== null
    ? '?' . http_build_query(array_filter(['dari' => $fDari, 'sampai' => $fSampai]))
    : '');

page_top('Jadwal Supervisi', 'jadwal');
?>
<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('calendar', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($rows) ?></div><div class="stat-lbl">Baris jadwal ditampilkan</div><div class="stat-hint"><?= $jmlSemua ?> total tersimpan</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('calendar', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $jmlHari ?></div><div class="stat-lbl">Hari tercakup</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('users', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $jmlGuru ?></div><div class="stat-lbl">Guru dijadwalkan</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic p"><?= ikon('school', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($guruList) ?></div><div class="stat-lbl">Guru aktif (siap dijadwalkan)</div></div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= $bolehUbah ? 'Daftar Jadwal Supervisi' : 'Jadwal Supervisi ' . e(istilah_sekolah('Sekolah')) ?></h2>
      <p><?= $bolehUbah
            ? 'Seluruh jadwal (semua tanggal) dalam satu tabel, diurutkan menurut tanggal. Gunakan filter bila hanya ingin melihat rentang tertentu.'
            : 'Jadwal supervisi ' . e(istilah_sekolah('Sekolah')) . ' binaan Anda. Halaman ini hanya untuk melihat dan mencetak.' ?></p>
    </div>
    <div class="flex">
      <a class="btn btn-primary" href="<?= e($cetakUrl) ?>" target="_blank"><?= ikon('print', 16) ?> Cetak
        <?= ($fDari !== null || $fSampai !== null) ? 'Sesuai Filter' : 'Semua Jadwal' ?></a>
    </div>
  </div>

  <form method="get" action="jadwal.php" class="filterbar mb16">
    <div>
      <label class="f" for="dari">Dari tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e((string) $fDari) ?>">
    </div>
    <div>
      <label class="f" for="sampai">Sampai tanggal</label>
      <input type="date" id="sampai" name="sampai" value="<?= e((string) $fSampai) ?>">
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Terapkan</button>
    <?php if ($fDari !== null || $fSampai !== null): ?>
      <a class="btn btn-ghost" href="jadwal.php">Tampilkan semua</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <?= ikon('calendar', 34) ?>
      <h3>Belum ada jadwal<?= ($fDari !== null || $fSampai !== null) ? ' pada rentang ini' : '' ?></h3>
      <p><?= $bolehUbah
            ? 'Tambahkan jadwal pada formulir di bawah — satu tabel untuk semua tanggal.'
            : 'Belum ada jadwal yang disusun untuk ' . e(istilah_sekolah('Sekolah')) . ' ini.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl" id="tblJadwal">
        <thead>
          <tr>
            <th class="ctr" style="width:44px">No</th>
            <th style="width:150px">Tanggal</th>
            <th>Nama Guru</th>
            <th>Mata Pelajaran</th>
            <th>Kelas</th>
            <th class="ctr">Jam ke-</th>
            <th>Supervisor</th>
            <th>Keterangan</th>
            <?php if ($bolehUbah): ?><th style="width:150px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($rows as $r): $n++; $sendiri = $editRow && (int) $editRow['id'] === (int) $r['id']; ?>
          <tr<?= $sendiri ? ' style="background:rgba(79,70,229,.07)"' : '' ?>>
            <td class="ctr"><b><?= $n ?></b></td>
            <td class="nowrap">
              <b><?= e(tanggal_id((string) $r['tanggal'])) ?></b>
              <span class="tbl-sub"><?= e((string) $r['tanggal']) ?></span>
            </td>
            <td><b><?= e((string) $r['nama_guru']) ?></b></td>
            <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
            <td><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="ctr"><?= e((string) ($r['jam_ke'] ?: '-')) ?></td>
            <td><?= e((string) ($r['supervisor'] ?: '-')) ?></td>
            <td class="sm"><?= e((string) ($r['keterangan'] ?: '-')) ?></td>
            <?php if ($bolehUbah): ?>
            <td class="no-print">
              <div class="row-actions">
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="naik"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>"><input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" title="Naikkan (dalam tanggal yang sama)">▲</button>
                </form>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="turun"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>"><input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" title="Turunkan (dalam tanggal yang sama)">▼</button>
                </form>
                <a class="btn btn-ghost btn-sm" href="<?= e(jadwal_url($fDari, $fSampai, ['edit' => (int) $r['id']])) ?>#form-jadwal"
                   title="Ubah baris ini"><?= ikon('edit', 14) ?></a>
                <form method="post" data-confirm="Hapus jadwal <?= e((string) $r['nama_guru']) ?> (<?= e(tanggal_id((string) $r['tanggal'])) ?>)?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>"><input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
                  <button class="btn btn-danger btn-sm" type="submit" title="Hapus"><?= ikon('trash', 14) ?></button>
                </form>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($bolehUbah): ?>
<div class="card" id="form-jadwal">
  <div class="card-head">
    <div>
      <h2><?= $editRow ? 'Ubah Jadwal: ' . e((string) $editRow['nama_guru']) : 'Tambah Jadwal' ?></h2>
      <p><?= $editRow
          ? 'Ubah isi baris jadwal, termasuk memindahkannya ke tanggal lain.'
          : 'Isi satu baris jadwal — termasuk tanggalnya. Pilih guru dari daftar agar mata pelajaran &amp; kelas terisi otomatis.' ?></p>
    </div>
    <?php if ($editRow): ?>
      <a class="btn btn-ghost btn-sm" href="<?= e($url) ?>#form-jadwal">Batal mengubah</a>
    <?php endif; ?>
  </div>

  <form method="post" class="grid g4">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="<?= $editRow ? 'ubah' : 'tambah' ?>">
    <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>">
    <input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
    <?php if ($editRow): ?>
      <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <?php endif; ?>

    <div>
      <label class="f" for="tanggal">Tanggal *</label>
      <input type="date" id="tanggal" name="tanggal" value="<?= e($editRow ? (string) $editRow['tanggal'] : date('Y-m-d')) ?>" required>
    </div>
    <div>
      <label class="f" for="guru_id">Pilih Guru</label>
      <select id="guru_id" name="guru_id">
        <option value="0">— tulis manual di bawah —</option>
        <?php foreach ($guruList as $g): ?>
          <option value="<?= (int) $g['id'] ?>" <?= $editRow && (int) ($editRow['guru_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= e((string) $g['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="nama_guru">Nama Guru *</label>
      <input type="text" id="nama_guru" name="nama_guru" value="<?= e((string) ($editRow['nama_guru'] ?? '')) ?>" placeholder="terisi otomatis bila memilih guru">
    </div>
    <div>
      <label class="f" for="mapel">Mata Pelajaran</label>
      <input type="text" id="mapel" name="mapel" value="<?= e((string) ($editRow['mapel'] ?? '')) ?>" placeholder="mis. Matematika">
    </div>
    <div>
      <label class="f" for="kelas">Kelas</label>
      <input type="text" id="kelas" name="kelas" value="<?= e((string) ($editRow['kelas'] ?? '')) ?>" placeholder="mis. VIII-A">
    </div>
    <div>
      <label class="f" for="jam_ke">Jam ke-</label>
      <input type="text" id="jam_ke" name="jam_ke" value="<?= e((string) ($editRow['jam_ke'] ?? '')) ?>" placeholder="mis. 3 atau 3-4">
    </div>
    <div>
      <label class="f" for="supervisor">Supervisor</label>
      <input type="text" id="supervisor" name="supervisor" list="saranSupervisor" value="<?= e((string) ($editRow['supervisor'] ?? '')) ?>" placeholder="nama kepala sekolah / pengawas">
      <datalist id="saranSupervisor">
        <?php foreach ($saranSupervisor as $s): ?><option value="<?= e($s) ?>"></option><?php endforeach; ?>
      </datalist>
    </div>
    <div>
      <label class="f" for="keterangan">Keterangan</label>
      <input type="text" id="keterangan" name="keterangan" value="<?= e((string) ($editRow['keterangan'] ?? '')) ?>" placeholder="mis. kelas VIII-A, ruang 2">
    </div>
    <div style="align-self:end;grid-column:1/-1">
      <button class="btn btn-primary" type="submit"><?= $editRow ? ikon('check', 16) . ' Simpan Perubahan' : ikon('plus', 16) . ' Tambahkan ke Jadwal' ?></button>
    </div>
  </form>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <div class="card-head"><div><h2>Salin Jadwal antar Tanggal</h2><p>Menyalin seluruh baris dari satu tanggal ke tanggal lain (praktis untuk jadwal berulang).</p></div></div>
    <?php if (count($tanggalTersedia) < 1): ?>
      <p class="sm mut" style="margin:0">Belum ada jadwal yang bisa disalin.</p>
    <?php else: ?>
      <form method="post" class="grid g3">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="salin">
        <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>">
        <input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
        <div>
          <label class="f" for="dari_salin">Salin dari tanggal</label>
          <select id="dari_salin" name="dari">
            <?php foreach ($tanggalTersedia as $t): ?>
              <option value="<?= e((string) $t) ?>"><?= e(tanggal_id((string) $t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="f" for="ke_salin">Ke tanggal</label>
          <input type="date" id="ke_salin" name="ke" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div style="align-self:end">
          <button class="btn btn-ghost full" type="submit"><?= ikon('upload', 15) ?> Salin</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Zona Berbahaya</h2><p>Menghapus baris jadwal. Wajib ketik <b>HAPUS</b> untuk konfirmasi.</p></div></div>
    <div class="grid g2" style="gap:14px;align-items:start">
      <form method="post" data-confirm="Hapus SEMUA baris jadwal pada rentang tanggal yang dipilih?">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="hapus_filter">
        <input type="hidden" name="f_dari" value="<?= e((string) $fDari) ?>">
        <input type="hidden" name="f_sampai" value="<?= e((string) $fSampai) ?>">
        <label class="f">Hapus sesuai filter<?= ($fDari !== null || $fSampai !== null) ? ' (' . count($rows) . ' baris)' : ' — filter belum dipilih' ?></label>
        <input type="text" name="konfirmasi" placeholder="ketik HAPUS" style="margin-bottom:8px">
        <button class="btn btn-danger full" type="submit" <?= ($fDari === null && $fSampai === null) ? 'disabled' : '' ?>><?= ikon('trash', 15) ?> Hapus yang tampil</button>
      </form>

      <form method="post" data-confirm="Hapus SELURUH jadwal sekolah ini (<?= $jmlSemua ?> baris, semua tanggal)?">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="hapus_semua">
        <label class="f">Hapus semua jadwal (<?= $jmlSemua ?> baris)</label>
        <input type="text" name="konfirmasi" placeholder="ketik HAPUS" style="margin-bottom:8px">
        <button class="btn btn-danger full" type="submit" <?= $jmlSemua === 0 ? 'disabled' : '' ?>><?= ikon('trash', 15) ?> Hapus semua jadwal</button>
      </form>
    </div>
  </div>
</div>
<?php else: ?>
  <div class="alert alert-ok">
    <?= ikon('eye', 16) ?>
    <span>Anda masuk sebagai <b>Pengawas</b>, jadi jadwal ini hanya dapat dilihat dan dicetak.
      Penyusunan atau perubahan jadwal dilakukan oleh admin <?= e(istilah_sekolah('Sekolah')) ?>.</span>
  </div>
<?php endif; ?>

<?php if ($bolehUbah): ?>
<script>
(function () {
  var data = <?= json_encode(array_map(fn($g) => [
      'id' => (int) $g['id'],
      'nama' => (string) $g['nama'],
      'mapel' => (string) ($g['mapel'] ?? ''),
      'kelas' => (string) ($g['kelas'] ?? ''),
  ], $guruList), JSON_UNESCAPED_UNICODE) ?>;
  var sel = document.getElementById('guru_id');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var id = parseInt(sel.value, 10) || 0;
    var g = null;
    for (var i = 0; i < data.length; i++) { if (data[i].id === id) { g = data[i]; break; } }
    if (!g) return;
    document.getElementById('nama_guru').value = g.nama;
    if (g.mapel) document.getElementById('mapel').value = g.mapel;
    if (g.kelas) document.getElementById('kelas').value = g.kelas;
  });
})();
</script>
<?php endif; ?>
<?php
page_bottom();
