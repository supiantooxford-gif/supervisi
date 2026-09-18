<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Kelola Sekolah (semua sekolah): memakai istilah generik "Sekolah" (bukan Madrasah)
// karena mengelola sekolah umum dan madrasah sekaligus.
$GLOBALS['__vs_istilah_nonaktif'] = true;

$u = require_super();

/** Validasi ambang predikat: A > B > C, rentang 0-100. */
function ambang_valid(float $a, float $b, float $c): bool
{
    return $a > $b && $b > $c && $c >= 0 && $a <= 100;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $s = $id ? one('SELECT * FROM sekolah WHERE id = ?', [$id]) : null;

    // ---- pindah konteks ----
    if ($aksi === 'masuk') {
        if (!$s) {
            flash('Sekolah tidak ditemukan.', 'err');
            redirect('sekolah.php');
        }
        if ((int) $s['aktif'] !== 1) {
            flash('Sekolah ini sedang dinonaktifkan. Aktifkan dulu bila ingin mengelolanya.', 'warn');
            redirect('sekolah.php');
        }
        set_sekolah_context($id);
        flash('Sekarang mengelola ' . $s['nama'] . '.');
        redirect('index.php?sekolah=1');
    }
    if ($aksi === 'keluar') {
        set_sekolah_context(null);
        flash('Kembali ke mode Super Admin.');
        redirect('index.php');
    }

    // ---- tambah sekolah baru ----
    if ($aksi === 'tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['admin_username'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $namaAdmin = trim((string) ($_POST['admin_nama'] ?? ''));
        $a = (float) ($_POST['batas_a'] ?? 90);
        $b = (float) ($_POST['batas_b'] ?? 80);
        $c = (float) ($_POST['batas_c'] ?? 70);

        $err = [];
        if ($nama === '') {
            $err[] = 'Nama sekolah wajib diisi.';
        }
        if ($username === '' || strlen($password) < 6) {
            $err[] = 'Nama pengguna admin dan kata sandi (minimal 6 karakter) wajib diisi.';
        } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
            $err[] = 'Nama pengguna "' . $username . '" sudah dipakai. Nama pengguna harus unik se-aplikasi.';
        }
        if (!ambang_valid($a, $b, $c)) {
            $err[] = 'Ambang predikat tidak valid (harus A > B > C, rentang 0-100).';
        }
        if ($err) {
            foreach ($err as $m) {
                flash($m, 'err');
            }
            redirect('sekolah.php');
        }

        db()->exec('BEGIN IMMEDIATE');
        try {
            $jenisBaru = (array_key_exists('jenis_sekolah', $_POST) && $_POST['jenis_sekolah'] === 'madrasah') ? 'madrasah' : 'umum';
            q('INSERT INTO sekolah (nama, npsn, alamat, kepala_sekolah, nip_kepala_sekolah, tahun_ajaran, semester, batas_a, batas_b, batas_c, catatan, aktif, jenis, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)', [
                $nama,
                trim((string) ($_POST['npsn'] ?? '')) ?: '-',
                trim((string) ($_POST['alamat'] ?? '')) ?: '-',
                trim((string) ($_POST['kepala_sekolah'] ?? '')) ?: '-',
                trim((string) ($_POST['nip_kepala_sekolah'] ?? '')) ?: '-',
                trim((string) ($_POST['tahun_ajaran'] ?? '')) ?: date('Y') . '/' . (date('Y') + 1),
                (($_POST['semester'] ?? 'Ganjil') === 'Genap') ? 'Genap' : 'Ganjil',
                $a, $b, $c,
                trim((string) ($_POST['catatan'] ?? '')) ?: null,
                $jenisBaru,
                date('Y-m-d H:i:s'),
            ]);
            $sidBaru = (int) db()->lastInsertId();

            // Menu & butir penilaian GLOBAL (dikelola Super Admin) — tidak ada salinan per sekolah.

            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,NULL,1,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), ($namaAdmin !== '' ? $namaAdmin : 'Administrator ' . $nama), 'admin', $sidBaru, date('Y-m-d H:i:s')]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        flash('Sekolah "' . $nama . '" ditambahkan. Akun admin: ' . $username . '. '
            . 'Menu & butir penilaian memakai daftar GLOBAL (' . jumlah_butir_global() . ' butir aktif) yang sama untuk semua sekolah.');
        redirect('sekolah.php');
    }

    if (!$s) {
        flash('Sekolah tidak ditemukan.', 'err');
        redirect('sekolah.php');
    }

    // ---- ubah profil sekolah ----
    if ($aksi === 'ubah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $a = (float) ($_POST['batas_a'] ?? 90);
        $b = (float) ($_POST['batas_b'] ?? 80);
        $c = (float) ($_POST['batas_c'] ?? 70);
        if ($nama === '') {
            flash('Nama sekolah wajib diisi.', 'err');
        } elseif (!ambang_valid($a, $b, $c)) {
            flash('Ambang predikat tidak valid (harus A > B > C, rentang 0-100).', 'err');
        } else {
            $hitungUlang = !empty($_POST['hitung_ulang']);
            db()->exec('BEGIN IMMEDIATE');
            try {
                // Jenis hanya diubah bila field dikirim; kalau tidak, nilai lama dipertahankan.
                $jenisUbah = array_key_exists('jenis_sekolah', $_POST)
                    ? ($_POST['jenis_sekolah'] === 'madrasah' ? 'madrasah' : 'umum')
                    : (string) ($s['jenis'] ?? 'umum');
                q('UPDATE sekolah SET nama=?, npsn=?, alamat=?, kepala_sekolah=?, nip_kepala_sekolah=?, tahun_ajaran=?, semester=?, batas_a=?, batas_b=?, batas_c=?, catatan=?, jenis=? WHERE id=?', [
                    $nama,
                    trim((string) ($_POST['npsn'] ?? '')) ?: '-',
                    trim((string) ($_POST['alamat'] ?? '')) ?: '-',
                    trim((string) ($_POST['kepala_sekolah'] ?? '')) ?: '-',
                    trim((string) ($_POST['nip_kepala_sekolah'] ?? '')) ?: '-',
                    trim((string) ($_POST['tahun_ajaran'] ?? '')),
                    (($_POST['semester'] ?? 'Ganjil') === 'Genap') ? 'Genap' : 'Ganjil',
                    $a, $b, $c,
                    trim((string) ($_POST['catatan'] ?? '')) ?: null,
                    $jenisUbah,
                    $id,
                ]);
                if ($hitungUlang) {
                    foreach (all('SELECT id, nilai FROM supervisi WHERE sekolah_id = ?', [$id]) as $row) {
                        q('UPDATE supervisi SET predikat = ? WHERE id = ?', [predikat_dari((float) $row['nilai'], ['batas_a' => $a, 'batas_b' => $b, 'batas_c' => $c]), (int) $row['id']]);
                    }
                }
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw $ex;
            }
            flash('Profil sekolah "' . $nama . '" diperbarui.' . ($hitungUlang ? ' Predikat seluruh hasil supervisi dihitung ulang.' : ''));
        }
        redirect('sekolah.php');
    }

    // ---- status aktif ----
    if ($aksi === 'aktif' || $aksi === 'nonaktif') {
        $baru = $aksi === 'aktif' ? 1 : 0;
        $d = sekolah_dampak($id);
        q('UPDATE sekolah SET aktif = ? WHERE id = ?', [$baru, $id]);
        if ($baru === 1) {
            // Akun admin diaktifkan lagi supaya sekolah bisa langsung dipakai.
            q('UPDATE users SET aktif = 1 WHERE sekolah_id = ? AND role = "admin"', [$id]);
        }
        if ($baru === 0 && sekolah_id() === $id) {
            set_sekolah_context(null);
        }
        flash($baru === 1
            ? 'Sekolah "' . $s['nama'] . '" diaktifkan kembali. ' . $d['akun'] . ' akun (termasuk akun admin) dapat masuk lagi; seluruh data lamanya tetap utuh.'
            : 'Sekolah "' . $s['nama'] . '" dinonaktifkan. Akses masuk seluruh akunnya (' . $d['akun'] . ' akun, ' . $d['guru'] . ' data guru) dihentikan, '
              . 'tetapi datanya TIDAK dihapus — bisa diaktifkan kembali kapan saja.',
            $baru === 1 ? 'ok' : 'warn');
        redirect('sekolah.php');
    }

    // ---- akun admin sekolah ----
    if ($aksi === 'admin') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $namaAdmin = trim((string) ($_POST['nama'] ?? ''));
        $ada = one('SELECT * FROM users WHERE sekolah_id = ? AND role = "admin" ORDER BY id LIMIT 1', [$id]);
        if ($username === '' || (strlen($password) < 6 && !$ada)) {
            flash('Nama pengguna wajib diisi dan kata sandi minimal 6 karakter.', 'err');
            redirect('sekolah.php');
        }
        if (one('SELECT id FROM users WHERE username = ? AND (sekolah_id IS NULL OR sekolah_id <> ? OR role <> "admin")', [$username, $id])) {
            flash('Nama pengguna "' . $username . '" sudah dipakai akun lain.', 'err');
            redirect('sekolah.php');
        }
        if ($ada) {
            if ($password !== '') {
                q('UPDATE users SET username=?, password_hash=?, nama=?, aktif=1 WHERE id=?',
                    [$username, password_hash($password, PASSWORD_DEFAULT), ($namaAdmin !== '' ? $namaAdmin : $ada['nama']), (int) $ada['id']]);
            } else {
                q('UPDATE users SET username=?, nama=?, aktif=1 WHERE id=?',
                    [$username, ($namaAdmin !== '' ? $namaAdmin : $ada['nama']), (int) $ada['id']]);
            }
            flash('Akun admin sekolah "' . $s['nama'] . '" diperbarui (nama pengguna: ' . $username . ').');
        } else {
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, aktif, dibuat) VALUES (?,?,?,?,?,1,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), ($namaAdmin !== '' ? $namaAdmin : 'Administrator ' . $s['nama']), 'admin', $id, date('Y-m-d H:i:s')]);
            flash('Akun admin baru dibuat untuk "' . $s['nama'] . '" — nama pengguna: ' . $username . '.');
        }
        redirect('sekolah.php');
    }

    // ---- hapus sekolah ----
    if ($aksi === 'hapus') {
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));
        // Ringkasan dampak WAJIB dihitung sebelum data dihapus, agar pesan/laporan
        // menampilkan jumlah yang benar (bukan nol setelah baris terhapus).
        $st = sekolah_stat($id);
        $d = sekolah_dampak($id);
        if ($konfirmasi !== 'HAPUS') {
            flash('Ketik HAPUS pada kolom konfirmasi untuk menghapus sekolah "' . $s['nama'] . '".', 'err');
            redirect('sekolah.php');
        }
        if ((int) val('SELECT COUNT(*) FROM sekolah', [], 0) <= 1) {
            flash('Tidak dapat menghapus satu-satunya sekolah yang ada.', 'err');
            redirect('sekolah.php');
        }
        db()->exec('BEGIN IMMEDIATE');
        try {
            // Tabel anak (guru/supervisi/butir/detail) ikut terhapus lewat ON DELETE CASCADE.
            q('DELETE FROM pengawas_sekolah WHERE sekolah_id = ?', [$id]);
            q('DELETE FROM users WHERE sekolah_id = ? AND role <> "super"', [$id]);
            q('DELETE FROM supervisi WHERE sekolah_id = ?', [$id]);
            q('DELETE FROM guru WHERE sekolah_id = ?', [$id]);
            // Menu & butir penilaian bersifat GLOBAL — tidak ikut terhapus saat sekolah dihapus.
            q('DELETE FROM sekolah WHERE id = ?', [$id]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        if (sekolah_id() === $id) {
            set_sekolah_context(null);
        }
        flash('Sekolah "' . $s['nama'] . '" DIHAPUS PERMANEN: ' . $d['guru'] . ' data guru, ' . $d['akun'] . ' akun, '
            . $d['supervisi'] . ' supervisi, dan ' . $d['penilaian'] . ' baris penilaian ikut terhapus.', 'warn');
        redirect('sekolah.php');
    }

    flash('Aksi tidak dikenali.', 'err');
    redirect('sekolah.php');
}

$rows = all('SELECT s.*,
    (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = s.id AND g.aktif = 1) guru,
    (SELECT COUNT(*) FROM users u WHERE u.sekolah_id = s.id) users,
    (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") supervisi,
    (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "draft") draft,
    (SELECT AVG(v.nilai) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") rata,
    (SELECT MAX(v.tanggal) FROM supervisi v WHERE v.sekolah_id = s.id) terakhir,
    (SELECT COUNT(DISTINCT v.guru_id) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") guru_dinilai,
    (SELECT COUNT(*) FROM instrumen i WHERE i.aktif = 1) menu,
    (SELECT COUNT(*) FROM butir b WHERE b.aktif = 1) butir,
    (SELECT username FROM users u WHERE u.sekolah_id = s.id AND u.role = "admin" ORDER BY u.id LIMIT 1) admin_username,
    (SELECT nama FROM users u WHERE u.sekolah_id = s.id AND u.role = "admin" ORDER BY u.id LIMIT 1) admin_nama
    FROM sekolah s WHERE s.verifikasi = "disetujui" ORDER BY s.nama');

$aktifList = [];
$nonaktifList = [];
foreach ($rows as $r) {
    if ((int) $r['aktif'] === 1) {
        $aktifList[] = $r;
    } else {
        $nonaktifList[] = $r;
    }
}

$totGuru = 0;
$totSuper = 0;
$totSekolah = count($rows);
foreach ($rows as $r) {
    $totGuru += (int) $r['guru'];
    $totSuper += (int) $r['supervisi'];
}
$aktifCtx = sekolah_id();

page_top('Kelola Sekolah', 'sekolah');
?>
<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('building', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $totSekolah ?></div><div class="stat-lbl">Sekolah terdaftar</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('users', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $totGuru ?></div><div class="stat-lbl">Guru aktif (seluruh sekolah)</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('check', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $totSuper ?></div><div class="stat-lbl">Supervisi final</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic p"><?= ikon('key', 20) ?></div>
    <div>
      <div class="stat-num" style="font-size:1.35rem"><?= count($aktifList) ?></div>
      <div class="stat-lbl">Sekolah aktif</div>
      <?php if ($nonaktifList): ?><div class="stat-hint"><?= count($nonaktifList) ?> sekolah nonaktif</div><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($nonaktifList): ?>
  <div class="alert alert-warn">
    <?= ikon('star', 16) ?>
    <span>Ada <b><?= count($nonaktifList) ?> sekolah nonaktif</b> (tidak dipakai lagi). Datanya masih tersimpan — Anda dapat
      <b>mengaktifkan kembali</b> atau <b>menghapus permanen</b> dari bagian <a href="#nonaktif">Sekolah Nonaktif</a> di bawah.</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div>
      <h2>Sekolah Aktif</h2>
      <p>Data tiap sekolah terpisah penuh. Klik <b>Kelola</b> untuk masuk ke datanya, atau nonaktifkan/hapus bila sekolah sudah tidak memakai aplikasi.</p>
    </div>
    <div class="flex">
      <span class="chip"><?= count($aktifList) ?> aktif</span>
      <?php if ($nonaktifList): ?>
        <a class="btn btn-ghost btn-sm" href="#nonaktif"><?= ikon('eye', 15) ?> Sekolah nonaktif (<?= count($nonaktifList) ?>)</a>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="pengawas.php"><?= ikon('eye', 15) ?> Akun Pengawas</a>
      <a class="btn btn-ghost btn-sm" href="pendaftaran.php"><?= ikon('star', 15) ?> Pendaftaran Sekolah<?= jumlah_pendaftaran_menunggu() > 0 ? ' (' . (int) jumlah_pendaftaran_menunggu() . ')' : '' ?></a>
    </div>
  </div>

  <?php if (!$aktifList): ?>
    <div class="empty"><?= ikon('building', 32) ?><h3>Belum ada sekolah aktif</h3><p>Tambahkan sekolah baru di bagian bawah halaman ini.</p></div>
  <?php endif; ?>

  <div class="grid" style="gap:16px">
    <?php foreach ($aktifList as $s):
        $rata = (float) ($s['rata'] ?? 0);
        $pred = $rata > 0 ? predikat_dari($rata, $s) : null;
        $cakupan = (int) $s['guru'] > 0 ? (int) round((int) $s['guru_dinilai'] / (int) $s['guru'] * 100) : 0;
        $diKelola = $aktifCtx === (int) $s['id'];
        $dampak = sekolah_dampak((int) $s['id']);
        ?>
      <div class="butir" style="margin:0;<?= (int) $s['aktif'] === 0 ? 'opacity:.7' : '' ?>;<?= $diKelola ? 'border-color:var(--accent);background:linear-gradient(135deg,rgba(79,70,229,.06),rgba(139,92,246,.06))' : '' ?>">
        <div class="butir-head" style="align-items:flex-start">
          <div class="stat-ic" style="width:40px;height:40px;border-radius:12px"><?= ikon('school', 19) ?></div>
          <div style="flex:1;min-width:0">
            <div class="butir-komponen">
              <?= e((string) $s['nama']) ?>
              <?php if ($diKelola): ?><span class="pill pill-B" style="margin-left:6px;vertical-align:middle">sedang dikelola</span><?php endif; ?>
              <?php if ((int) $s['aktif'] === 0): ?><span class="pill pill-gray" style="margin-left:6px;vertical-align:middle">nonaktif</span><?php endif; ?>
              <span class="pill <?= ($s['jenis'] ?? 'umum') === 'madrasah' ? 'pill-B' : 'pill-gray' ?>" style="margin-left:6px;vertical-align:middle"><?= ($s['jenis'] ?? 'umum') === 'madrasah' ? 'Madrasah' : 'Sekolah Umum' ?></span>
            </div>
            <div class="butir-indikator">
              NPSN <?= e((string) ($s['npsn'] ?: '-')) ?> &middot; <?= e((string) ($s['alamat'] ?: '-')) ?><br>
              Kepala sekolah: <?= e((string) ($s['kepala_sekolah'] ?: '-')) ?> &middot; T.A. <?= e((string) ($s['tahun_ajaran'] ?: '-')) ?> <?= e((string) ($s['semester'] ?: '')) ?>
            </div>
          </div>
          <div class="flex" style="gap:6px">
            <?php if (!$diKelola): ?>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="masuk"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit" <?= (int) $s['aktif'] === 0 ? 'disabled' : '' ?>><?= ikon('switch', 14) ?> Kelola</button>
              </form>
            <?php else: ?>
              <form method="post" action="sekolah.php">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="keluar">
                <button class="btn btn-ghost btn-sm" type="submit">Kembali ke Super Admin</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="grid g4 mt16" style="gap:12px">
          <div class="nilai-chip" style="min-width:0"><b><?= (int) $s['guru'] ?></b><span>Guru aktif</span></div>
          <div class="nilai-chip" style="min-width:0"><b><?= (int) $s['supervisi'] ?><?php if ((int) $s['draft'] > 0): ?><span class="xs mut" style="font-weight:400">+<?= (int) $s['draft'] ?> draf</span><?php endif; ?></b><span>Supervisi final</span></div>
          <div class="nilai-chip" style="min-width:0"><b><?= $rata > 0 ? r1($rata) : '-' ?></b><span>Rata-rata nilai</span></div>
          <div class="nilai-chip" style="min-width:0"><b><?= $cakupan ?>%</b><span>Cakupan guru</span></div>
        </div>

        <?php if ($diKelola): ?>
          <div class="alert alert-ok mt16" style="margin-bottom:0">
            <?= ikon('switch', 15) ?><span>Anda sedang mengelola sekolah ini. Semua menu supervisi mengarah ke datanya.</span>
            <form method="post" action="sekolah.php" style="margin-left:auto">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="keluar">
              <button class="btn btn-ghost btn-sm" type="submit">Kembali ke Super Admin</button>
            </form>
          </div>
        <?php else: ?>
          <div class="flex mt16" style="gap:8px;flex-wrap:wrap">
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="masuk"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit"><?= ikon('switch', 14) ?> Kelola Data Sekolah</button>
            </form>
            <form method="post" data-confirm="Nonaktifkan sekolah <?= e((string) $s['nama']) ?>? Seluruh akunnya tidak bisa masuk, tetapi datanya tetap tersimpan dan bisa diaktifkan kembali.">
              <?= csrf_field() ?><input type="hidden" name="aksi" value="nonaktif"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('eye', 14) ?> Nonaktifkan (tidak dipakai lagi)</button>
            </form>
            <a class="btn btn-danger btn-sm" href="#hapus-<?= (int) $s['id'] ?>"><?= ikon('trash', 14) ?> Hapus permanen</a>
          </div>
        <?php endif; ?>

        <div class="flex between mt16" style="gap:10px;flex-wrap:wrap">
          <div class="xs mut">
            <?= (int) $s['menu'] ?> menu &middot; <?= (int) $s['butir'] ?> butir &middot; <?= (int) $s['users'] ?> akun &middot;
            akun admin: <b><?= e((string) ($s['admin_username'] ?: 'belum ada')) ?></b> &middot;
            supervisi terakhir: <?= $s['terakhir'] ? e(tanggal_id((string) $s['terakhir'])) : 'belum ada' ?>
            <?php if ($pred): ?>&middot; predikat <span class="pill pill-<?= e($pred) ?>"><?= e($pred) ?></span><?php endif; ?>
          </div>
        </div>

        <details class="editbox">
          <summary><?= ikon('edit', 14) ?> Ubah profil sekolah / akun admin</summary>
          <div class="grid g2 mt16" style="gap:16px;align-items:start">
            <form method="post" class="grid" style="gap:10px">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <h3 class="mb0">Profil &amp; ambang predikat</h3>
              <div>
                <label class="f">Nama Sekolah *</label>
                <input type="text" name="nama" value="<?= e((string) $s['nama']) ?>" required>
              </div>
              <div class="grid g2" style="gap:10px">
                <div><label class="f">NPSN</label><input type="text" name="npsn" value="<?= e((string) $s['npsn']) ?>"></div>
                <div><label class="f">Tahun Ajaran</label><input type="text" name="tahun_ajaran" value="<?= e((string) $s['tahun_ajaran']) ?>"></div>
              </div>
              <div><label class="f">Alamat</label><input type="text" name="alamat" value="<?= e((string) $s['alamat']) ?>"></div>
              <div class="grid g2" style="gap:10px">
                <div><label class="f">Kepala Sekolah</label><input type="text" name="kepala_sekolah" value="<?= e((string) $s['kepala_sekolah']) ?>"></div>
                <div><label class="f">NIP Kepala Sekolah</label><input type="text" name="nip_kepala_sekolah" value="<?= e((string) $s['nip_kepala_sekolah']) ?>"></div>
              </div>
              <div class="grid g2" style="gap:10px">
                <div>
                  <label class="f">Jenis Sekolah</label>
                  <select name="jenis_sekolah">
                    <option value="umum" <?= ($s['jenis'] ?? 'umum') !== 'madrasah' ? 'selected' : '' ?>>Sekolah Umum (SD/SMP/SMA/SMK)</option>
                    <option value="madrasah" <?= ($s['jenis'] ?? 'umum') === 'madrasah' ? 'selected' : '' ?>>Madrasah (MI/MTs/MA)</option>
                  </select>
                  <div class="hint">
                    Bila <b>Madrasah</b>, istilah "Sekolah" di aplikasi &amp; cetakan menjadi "Madrasah".
                    Pilihan ini ikut tersimpan saat menekan <b>Simpan profil</b> di bawah.
                    (Sekolah juga dapat mengubahnya sendiri di menu <b>Profil &amp; Pengaturan</b>.)
                  </div>
                </div>
                <div>
                  <label class="f">Semester</label>
                  <select name="semester">
                    <option value="Ganjil" <?= $s['semester'] === 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                    <option value="Genap" <?= $s['semester'] === 'Genap' ? 'selected' : '' ?>>Genap</option>
                  </select>
                </div>
                <div>
                  <label class="f">Catatan (internal)</label>
                  <input type="text" name="catatan" value="<?= e((string) $s['catatan']) ?>" placeholder="mis. sekolah binaan">
                </div>
              </div>
              <div class="grid g3" style="gap:10px">
                <div><label class="f">A &ge;</label><input type="number" name="batas_a" value="<?= (int) $s['batas_a'] ?>" min="0" max="100" step="0.5"></div>
                <div><label class="f">B &ge;</label><input type="number" name="batas_b" value="<?= (int) $s['batas_b'] ?>" min="0" max="100" step="0.5"></div>
                <div><label class="f">C &ge;</label><input type="number" name="batas_c" value="<?= (int) $s['batas_c'] ?>" min="0" max="100" step="0.5"></div>
              </div>
              <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600">
                <input type="checkbox" name="hitung_ulang" value="1" style="width:16px;height:16px;accent-color:var(--accent)">
                Hitung ulang predikat seluruh hasil supervisi sekolah ini
              </label>
              <div class="flex">
                <button class="btn btn-primary btn-sm" type="submit"><?= ikon('check', 14) ?> Simpan profil</button>
              </div>
            </form>

            <div class="grid" style="gap:16px">
              <form method="post" class="grid" style="gap:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="admin"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <h3 class="mb0"><?= $s['admin_username'] ? 'Akun admin sekolah' : 'Buatkan akun admin sekolah' ?></h3>
                <div><label class="f">Nama Admin</label><input type="text" name="nama" value="<?= e((string) ($s['admin_nama'] ?: '')) ?>" placeholder="mis. Kepala Sekolah"></div>
                <div><label class="f">Nama Pengguna *</label><input type="text" name="username" value="<?= e((string) ($s['admin_username'] ?: '')) ?>" required placeholder="unik se-aplikasi"></div>
                <div>
                  <label class="f"><?= $s['admin_username'] ? 'Kata Sandi Baru' : 'Kata Sandi *' ?></label>
                  <input type="text" name="password" placeholder="<?= $s['admin_username'] ? 'biarkan kosong bila tidak diubah' : 'minimal 6 karakter' ?>">
                </div>
                <div class="flex">
                  <button class="btn btn-primary btn-sm" type="submit"><?= ikon('key', 14) ?> <?= $s['admin_username'] ? 'Simpan akun' : 'Buat akun admin' ?></button>
                </div>
              </form>

              <div class="hint">
                Ingin menghentikan penggunaan aplikasi oleh sekolah ini? Gunakan <b>Nonaktifkan</b> atau
                <b>Hapus permanen</b> pada bagian <b>Zona Berbahaya</b> di bawah.
              </div>
            </div>
          </div>
        </details>

        <!-- Zona berbahaya: nonaktifkan / hapus permanen -->
        <details class="editbox" id="hapus-<?= (int) $s['id'] ?>">
          <summary style="color:var(--err)"><?= ikon('trash', 14) ?> Zona berbahaya: nonaktifkan atau hapus sekolah ini</summary>
          <div class="grid g2 mt16" style="gap:16px;align-items:start">
            <div class="butir" style="margin:0;border-color:rgba(217,119,6,.35)">
              <div class="butir-head">
                <div class="butir-no" style="background:linear-gradient(135deg,#f59e0b,#ea580c)">1</div>
                <div>
                  <div class="butir-komponen">Nonaktifkan (disarankan)</div>
                  <div class="butir-indikator">
                    Seluruh akun sekolah ini (<?= (int) $dampak['akun'] ?> akun, <?= (int) $dampak['guru'] ?> guru) tidak dapat masuk lagi.
                    <b>Data tetap utuh</b> — <?= (int) $dampak['supervisi'] ?> supervisi dan <?= (int) $dampak['penilaian'] ?> baris penilaian tetap tersimpan,
                    dan bisa diaktifkan kembali kapan saja bila sekolah memakainya lagi.
                  </div>
                </div>
              </div>
              <form method="post" class="mt16" data-confirm="Nonaktifkan sekolah <?= e((string) $s['nama']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="nonaktif"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-ghost" type="submit"><?= ikon('eye', 15) ?> Nonaktifkan sekolah</button>
              </form>
            </div>

            <div class="butir" style="margin:0;border-color:rgba(220,38,38,.4);background:linear-gradient(135deg,rgba(220,38,38,.05),rgba(255,255,255,.5))">
              <div class="butir-head">
                <div class="butir-no" style="background:linear-gradient(135deg,#dc2626,#b91c1c)">2</div>
                <div>
                  <div class="butir-komponen">Hapus permanen</div>
                  <div class="butir-indikator">
                    Menghapus <b>seluruh</b> data sekolah ini dan <b>tidak dapat dibatalkan</b>:
                    <br>&bull; <?= (int) $dampak['guru'] ?> data guru (<?= (int) $dampak['guru_aktif'] ?> aktif)
                    <br>&bull; <?= (int) $dampak['akun'] ?> akun pengguna (admin sekolah &amp; guru)<?= (int) $dampak['foto'] > 0 ? ', termasuk ' . (int) $dampak['foto'] . ' foto profil' : '' ?>
                    <br>&bull; <?= (int) $dampak['supervisi'] ?> supervisi (<?= (int) $dampak['supervisi_final'] ?> sudah final) dan <?= (int) $dampak['penilaian'] ?> baris penilaian
                    <br>&bull; <?= (int) $dampak['butir'] ?> butir penilaian beserta menunya
                    <?php if ((int) $dampak['pengawas'] > 0): ?><br>&bull; <?= (int) $dampak['pengawas'] ?> penugasan pengawas pada sekolah ini<?php endif; ?>
                    <?php if ($dampak['terakhir']): ?><br>Supervisi terakhir: <?= e(tanggal_id((string) $dampak['terakhir'])) ?><?php endif; ?>
                  </div>
                </div>
              </div>
              <form method="post" class="mt16" data-confirm="HAPUS PERMANEN sekolah <?= e((string) $s['nama']) ?> beserta SELURUH data guru, akun, dan supervisinya? Tindakan ini tidak dapat dibatalkan.">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <div class="grid g2" style="gap:10px">
                  <div>
                    <label class="f">Ketik HAPUS untuk konfirmasi</label>
                    <input type="text" name="konfirmasi" placeholder="HAPUS">
                  </div>
                  <div style="align-self:end">
                    <button class="btn btn-danger full" type="submit"><?= ikon('trash', 15) ?> Hapus permanen</button>
                  </div>
                </div>
              </form>
              <div class="hint">Bila ragu, pilih <b>Nonaktifkan</b> dulu — datanya tetap aman dan bisa diaktifkan kembali.</div>
            </div>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($nonaktifList): ?>
<div class="card" id="nonaktif">
  <div class="card-head">
    <div>
      <h2>Sekolah Nonaktif (<?= count($nonaktifList) ?>)</h2>
      <p>Sekolah yang sudah tidak menggunakan aplikasi. Datanya masih tersimpan — bisa diaktifkan kembali atau dihapus permanen.</p>
    </div>
    <span class="pill pill-gray"><?= count($nonaktifList) ?> sekolah</span>
  </div>

  <div class="grid" style="gap:16px">
    <?php foreach ($nonaktifList as $s):
        $dampak = sekolah_dampak((int) $s['id']);
        $rata = (float) ($s['rata'] ?? 0);
        ?>
      <div class="butir" style="margin:0;border-color:rgba(99,95,130,.3);background:rgba(255,255,255,.5)">
        <div class="butir-head" style="align-items:flex-start">
          <div class="stat-ic" style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#94a3b8,#64748b)"><?= ikon('eye', 19) ?></div>
          <div style="flex:1;min-width:0">
            <div class="butir-komponen">
              <?= e((string) $s['nama']) ?>
              <span class="pill pill-gray" style="margin-left:6px;vertical-align:middle">nonaktif</span>
            </div>
            <div class="butir-indikator">
              NPSN <?= e((string) ($s['npsn'] ?: '-')) ?> &middot; <?= e((string) ($s['alamat'] ?: '-')) ?><br>
              <?= (int) $dampak['guru'] ?> guru &middot; <?= (int) $dampak['akun'] ?> akun &middot;
              <?= (int) $dampak['supervisi'] ?> supervisi &middot;
              rata-rata <?= $rata > 0 ? r1($rata) : '-' ?> &middot;
              supervisi terakhir: <?= $dampak['terakhir'] ? e(tanggal_id((string) $dampak['terakhir'])) : 'belum ada' ?>
            </div>
          </div>
        </div>

        <div class="flex mt16" style="gap:8px;flex-wrap:wrap">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="aktif"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= ikon('check', 14) ?> Aktifkan kembali</button>
          </form>
          <a class="btn btn-ghost btn-sm" href="#hapus-<?= (int) $s['id'] ?>-nonaktif"><?= ikon('trash', 14) ?> Hapus permanen</a>
        </div>

        <details class="editbox" id="hapus-<?= (int) $s['id'] ?>-nonaktif">
          <summary style="color:var(--err)"><?= ikon('trash', 14) ?> Hapus permanen sekolah ini</summary>
          <form method="post" class="grid mt16" style="gap:10px" data-confirm="HAPUS PERMANEN sekolah <?= e((string) $s['nama']) ?> beserta SELURUH data guru, akun, dan supervisinya? Tindakan ini tidak dapat dibatalkan.">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div class="sm mut">
              Yang akan terhapus: <b><?= (int) $dampak['guru'] ?></b> data guru, <b><?= (int) $dampak['akun'] ?></b> akun,
              <b><?= (int) $dampak['supervisi'] ?></b> supervisi, <b><?= (int) $dampak['penilaian'] ?></b> baris penilaian,
              dan <b><?= (int) $dampak['butir'] ?></b> butir penilaian<?= (int) $dampak['pengawas'] > 0 ? ' serta ' . (int) $dampak['pengawas'] . ' penugasan pengawas' : '' ?>.
            </div>
            <div class="grid g2" style="gap:10px">
              <div>
                <label class="f">Ketik HAPUS untuk konfirmasi</label>
                <input type="text" name="konfirmasi" placeholder="HAPUS">
              </div>
              <div style="align-self:end">
                <button class="btn btn-danger full" type="submit"><?= ikon('trash', 15) ?> Hapus permanen</button>
              </div>
            </div>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h2>Tambah Sekolah Baru</h2><p>Sekolah baru langsung mendapat 4 menu supervisi dengan 54 butir penilaian bawaan yang bebas disesuaikan, plus satu akun admin.</p></div>
  </div>
  <form method="post" class="grid g3">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="tambah">
    <div><label class="f" for="nama">Nama Sekolah *</label><input type="text" id="nama" name="nama" required placeholder="mis. MA. Al Hidayah"></div>
    <div><label class="f" for="npsn">NPSN</label><input type="text" id="npsn" name="npsn" placeholder="8 digit"></div>
    <div><label class="f" for="tahun_ajaran">Tahun Ajaran</label><input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e(date('Y') . '/' . (date('Y') + 1)) ?>"></div>
    <div><label class="f" for="alamat">Alamat</label><input type="text" id="alamat" name="alamat" placeholder="Jl. ..."></div>
    <div><label class="f" for="kepala_sekolah">Kepala Sekolah</label><input type="text" id="kepala_sekolah" name="kepala_sekolah" placeholder="Nama &amp; gelar"></div>
    <div><label class="f" for="nip_kepala_sekolah">NIP Kepala Sekolah</label><input type="text" id="nip_kepala_sekolah" name="nip_kepala_sekolah"></div>
    <div><label class="f" for="semester">Semester</label>
      <select id="semester" name="semester"><option value="Ganjil">Ganjil</option><option value="Genap">Genap</option></select>
    </div>
    <div><label class="f" for="jenis_sekolah_tambah">Jenis Sekolah</label>
      <select id="jenis_sekolah_tambah" name="jenis_sekolah">
        <option value="umum">Sekolah Umum (SD/SMP/SMA/SMK)</option>
        <option value="madrasah">Madrasah (MI/MTs/MA)</option>
      </select>
    </div>
    <div><label class="f" for="batas_a">Predikat A &ge;</label><input type="number" id="batas_a" name="batas_a" value="90" min="0" max="100" step="0.5"></div>
    <div><label class="f" for="batas_b">Predikat B &ge;</label><input type="number" id="batas_b" name="batas_b" value="80" min="0" max="100" step="0.5"></div>
    <div><label class="f" for="batas_c">Predikat C &ge;</label><input type="number" id="batas_c" name="batas_c" value="70" min="0" max="100" step="0.5"></div>
    <div><label class="f" for="catatan">Catatan (opsional)</label><input type="text" id="catatan" name="catatan" placeholder="mis. sekolah binaan"></div>
    <div></div>
    <div class="full" style="grid-column:1/-1">
      <div class="divider"></div>
      <h3 class="mb8">Akun admin sekolah (untuk kepala sekolah / pengawas sekolah tersebut)</h3>
    </div>
    <div><label class="f" for="admin_nama">Nama Admin</label><input type="text" id="admin_nama" name="admin_nama" placeholder="nama kepala sekolah"></div>
    <div><label class="f" for="admin_username">Nama Pengguna Admin *</label><input type="text" id="admin_username" name="admin_username" required placeholder="tanpa spasi, unik se-aplikasi"></div>
    <div><label class="f" for="admin_password">Kata Sandi Admin *</label><input type="text" id="admin_password" name="admin_password" required placeholder="minimal 6 karakter"></div>
    <div style="grid-column:1/-1">
      <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Sekolah &amp; Akun Admin</button>
    </div>
  </form>
</div>
<?php
page_bottom();
