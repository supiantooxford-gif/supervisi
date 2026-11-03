<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();
$aku = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $target = $id ? one('SELECT * FROM users WHERE id = ? AND sekolah_id = ?', [$id, $sid]) : null;

    if ($aksi === 'tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? 'admin') === 'guru' ? 'guru' : 'admin';
        $guruId = (int) ($_POST['guru_id'] ?? 0);
        if ($nama === '' || $username === '' || strlen($password) < 6) {
            flash('Nama, nama pengguna, dan kata sandi (min. 6 karakter) wajib diisi.', 'err');
        } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
            flash('Nama pengguna "' . $username . '" sudah dipakai. Nama pengguna harus unik se-aplikasi.', 'err');
        } else {
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $nama, $role, $sid, ($role === 'guru' && $guruId > 0) ? $guruId : null, date('Y-m-d H:i:s')]);
            flash('Pengguna ' . $nama . ' ditambahkan ke sekolah ini.');
        }
        redirect('pengguna.php');
    }

    if ($aksi === 'massal') {
        // AKTIVASI: pembuatan akun massal hanya tersedia setelah sekolah diaktivasi.
        if (aktivasi_terbatas()) {
            aktivasi_halang('massal', 'pengguna.php');
        }
        $belum = all('SELECT g.* FROM guru g WHERE g.aktif = 1 AND g.sekolah_id = ? AND NOT EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id)', [$sid]);
        $n = 0;
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($belum as $g) {
                // NIP boleh kosong: nama pengguna & sandi awal mengikuti helper bersama.
                $username = guru_username_unik($g);
                q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                    [$username, password_hash(guru_sandi_awal($g), PASSWORD_DEFAULT), $g['nama'], 'guru', $sid, (int) $g['id'], date('Y-m-d H:i:s')]);
                $n++;
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        flash($n > 0 ? $n . ' akun guru dibuat. Kata sandi awal memakai NIP masing-masing guru.' : 'Semua guru aktif di sekolah ini sudah memiliki akun.', $n > 0 ? 'ok' : 'warn');
        redirect('pengguna.php');
    }

    if (!$target) {
        flash('Pengguna tidak ditemukan pada sekolah ini.', 'err');
        redirect('pengguna.php');
    }

    if ($aksi === 'reset') {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 6) {
            flash('Kata sandi baru minimal 6 karakter.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('Kata sandi ' . $target['nama'] . ' berhasil diganti.');
        }
    } elseif ($aksi === 'toggle') {
        if ($id === $aku) {
            flash('Akun Anda sendiri tidak dapat dinonaktifkan.', 'err');
        } else {
            q('UPDATE users SET aktif = ? WHERE id = ?', [(int) $target['aktif'] === 1 ? 0 : 1, $id]);
            flash('Status akun ' . $target['nama'] . ' diperbarui.', 'warn');
        }
    } elseif ($aksi === 'hapus') {
        if ($id === $aku) {
            flash('Akun Anda sendiri tidak dapat dihapus.', 'err');
        } elseif ($target['role'] === 'admin' && (int) val('SELECT COUNT(*) FROM users WHERE role = "admin" AND aktif = 1 AND sekolah_id = ?', [$sid], 0) <= 1) {
            flash('Minimal harus ada satu akun admin aktif pada sekolah ini.', 'err');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Akun ' . $target['nama'] . ' dihapus.');
        }
    }

    redirect('pengguna.php');
}

$users = all('SELECT u.*, g.nama AS guru_nama FROM users u LEFT JOIN guru g ON g.id = u.guru_id
              WHERE u.sekolah_id = ? ORDER BY u.role, u.nama', [$sid]);
$guruList = all('SELECT id, nama FROM guru WHERE sekolah_id = ? ORDER BY nama', [$sid]);
$jmlTanpaAkun = (int) val('SELECT COUNT(*) FROM guru g WHERE g.aktif = 1 AND g.sekolah_id = ? AND NOT EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id)', [$sid], 0);

page_top('Pengguna & Akun Sekolah', 'pengguna');
?>
<div class="grid g3">
  <div class="card stat card-tight">
    <div class="stat-ic"><?= ikon('users', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= count($users) ?></div><div class="stat-lbl">Total pengguna sekolah ini</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic s"><?= ikon('key', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= (int) val('SELECT COUNT(*) FROM users WHERE role = "guru" AND sekolah_id = ?', [$sid], 0) ?></div><div class="stat-lbl">Akun guru</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic o"><?= ikon('star', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= $jmlTanpaAkun ?></div><div class="stat-lbl">Guru aktif tanpa akun</div></div>
  </div>
</div>

<div class="alert alert-ok">
  <?= ikon('building', 16) ?>
  <span>Daftar ini hanya memuat pengguna sekolah <b><?= e(tetap_kata(setting('nama_sekolah', ''))) ?></b>.
    Akun guru &amp; admin sekolah lain dikelola terpisah oleh masing-masing sekolah.</span>
</div>

<?php if ($jmlTanpaAkun > 0): ?>
<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Buatkan akun massal</h3>
      <p class="sm mut" style="margin:4px 0 0">Buat akun untuk <?= $jmlTanpaAkun ?> guru aktif sekolah ini yang belum punya akun. Kata sandi awal memakai NIP guru.</p>
    </div>
    <form method="post" data-confirm="Buat akun login untuk <?= $jmlTanpaAkun ?> guru sekaligus?">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="massal">
      <?php if (aktivasi_terbatas()): ?>
        <?php // AKTIVASI: pada mode terbatas tombolnya mengarah ke popup "Aplikasi Belum Diaktifasi". ?>
        <a class="btn btn-ghost" href="aktivasi.php?batas=massal&amp;kembali=pengguna.php"><?= ikon('lock', 16) ?> Dibatasi — belum diaktifasi</a>
      <?php else: ?>
        <button class="btn btn-primary" type="submit"><?= ikon('key', 16) ?> Buat <?= $jmlTanpaAkun ?> akun guru</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="grid g12">
  <div class="card">
    <div class="card-head"><div><h2>Tambah Pengguna</h2><p>Akun kepala sekolah atau akun guru pada sekolah ini.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="tambah">
      <div>
        <label class="f" for="nama">Nama Lengkap *</label>
        <input type="text" id="nama" name="nama" required>
      </div>
      <div>
        <label class="f" for="role">Peran</label>
        <select id="role" name="role">
          <option value="admin">Administrator / Kepala Sekolah (sekolah ini)</option>
          <option value="guru">Guru (hanya melihat hasil sendiri)</option>
        </select>
      </div>
      <div id="blokGuru" style="display:none">
        <label class="f" for="guru_id">Tautkan ke Data Guru</label>
        <select id="guru_id" name="guru_id">
          <option value="0">— tidak ditautkan —</option>
          <?php foreach ($guruList as $g): ?>
            <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="f" for="username">Nama Pengguna *</label>
        <input type="text" id="username" name="username" required placeholder="tanpa spasi, unik se-aplikasi">
      </div>
      <div>
        <label class="f" for="password">Kata Sandi *</label>
        <input type="text" id="password" name="password" required placeholder="minimal 6 karakter">
      </div>
      <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Pengguna</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h2>Daftar Pengguna</h2><p><span id="tblCount"><?= count($users) ?></span> akun di sekolah ini</p></div>
      <div style="min-width:220px">
        <input type="search" id="tblSearch" data-target="tblUser" placeholder="Cari pengguna...">
      </div>
    </div>
    <div class="table-wrap">
      <table class="tbl" id="tblUser">
        <thead><tr><th>Nama</th><th>Nama Pengguna</th><th>Peran</th><th>Terakhir masuk</th><th class="ctr">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $x): ?>
          <tr>
            <td>
              <span class="mini-avatar">
                <?= avatar_html((string) $x['nama'], foto_user($x), 34) ?>
                <span>
                  <b><?= e((string) $x['nama']) ?></b>
                  <?php if ($x['guru_nama']): ?><span class="tbl-sub">Guru: <?= e((string) $x['guru_nama']) ?></span><?php endif; ?>
                </span>
              </span>
            </td>
            <td><?= e((string) $x['username']) ?></td>
            <td><span class="pill <?= $x['role'] === 'admin' ? 'pill-B' : 'pill-gray' ?>"><?= $x['role'] === 'admin' ? 'Admin sekolah' : 'Guru' ?></span></td>
            <td class="xs mut"><?= e((string) ($x['login_terakhir'] ?: 'belum pernah')) ?></td>
            <td class="ctr"><span class="pill <?= (int) $x['aktif'] === 1 ? 'pill-ok' : 'pill-gray' ?>"><?= (int) $x['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td>
              <div class="row-actions">
                <form method="post" class="flex" style="gap:6px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                  <input type="text" name="password" placeholder="sandi baru" style="width:120px;padding:6px 9px;font-size:.78rem">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('key', 14) ?></button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit" <?= (int) $x['id'] === $aku ? 'disabled' : '' ?>><?= (int) $x['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
                <form method="post" data-confirm="Hapus akun <?= e((string) $x['nama']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" <?= (int) $x['id'] === $aku ? 'disabled' : '' ?>><?= ikon('trash', 14) ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var role = document.getElementById('role');
  var blok = document.getElementById('blokGuru');
  if (!role || !blok) return;
  function sync() { blok.style.display = role.value === 'guru' ? '' : 'none'; }
  role.addEventListener('change', sync);
  sync();
})();
</script>
<?php
page_bottom();
