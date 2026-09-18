<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$g = $id ? one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$id, $sid]) : null;
if ($id && !$g) {
    flash('Data guru tidak ditemukan.', 'err');
    redirect('guru.php');
}

$form = [
    'nama'    => $g['nama'] ?? '',
    'nip'     => $g['nip'] ?? '',
    'mapel'   => $g['mapel'] ?? '',
    'jabatan' => $g['jabatan'] ?? '',
    'kelas'   => $g['kelas'] ?? '',
    'telepon' => $g['telepon'] ?? '',
    'aktif'   => $g ? (int) $g['aktif'] : 1,
];
$akun = $id ? one('SELECT * FROM users WHERE guru_id = ? LIMIT 1', [$id]) : null;
$buatAkun = (string) ($_POST['buat_akun'] ?? ($akun ? '0' : '1'));
$username = (string) ($_POST['username'] ?? ($akun['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    foreach (['nama', 'nip', 'mapel', 'jabatan', 'kelas', 'telepon'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['aktif'] = isset($_POST['aktif']) ? 1 : 0;

    if ($form['nama'] === '') {
        $err[] = 'Nama guru wajib diisi.';
    }
    // NIP/NUPTK boleh kosong (atau diisi "-" yang diperlakukan sebagai kosong).
    $form['nip'] = nip_bersih($form['nip']);
    if ($form['nip'] !== '' && one('SELECT id FROM guru WHERE nip = ? AND id <> ? AND sekolah_id = ?', [$form['nip'], $id, $sid])) {
        $err[] = 'NIP ' . $form['nip'] . ' sudah dipakai guru lain.';
    }

    if ($buatAkun === '1') {
        if ($username === '') {
            // Nama pengguna: pakai NIP bila ada, atau turunan nama guru bila NIP kosong.
            $username = $form['nip'] !== '' ? $form['nip'] : username_dari_nama($form['nama']);
        }
        $username = trim($username);
        if (one('SELECT id FROM users WHERE username = ? AND (guru_id IS NULL OR guru_id <> ?)', [$username, $id])) {
            $err[] = 'Nama pengguna "' . $username . '" sudah dipakai.';
        }
        // Sandi boleh dibiarkan kosong: aplikasi memakai sandi cadangan
        // (NIP guru bila ada, atau 'guru123' bila NIP belum ada).
        if ($password !== '' && strlen($password) < 6) {
            $err[] = 'Kata sandi minimal 6 karakter (atau biarkan kosong untuk memakai sandi otomatis).';
        }
    }

    if (!$err) {
        $now = date('Y-m-d H:i:s');
        db()->exec('BEGIN IMMEDIATE');
        try {
            if ($g) {
                q('UPDATE guru SET nama=?, nip=?, mapel=?, jabatan=?, kelas=?, telepon=?, aktif=? WHERE id=? AND sekolah_id=?',
                    [$form['nama'], $form['nip'], $form['mapel'], $form['jabatan'], $form['kelas'], $form['telepon'], $form['aktif'], $id, $sid]);
                $gid = $id;
            } else {
                q('INSERT INTO guru (sekolah_id, nama, nip, mapel, jabatan, kelas, telepon, aktif, demo, dibuat) VALUES (?,?,?,?,?,?,?,?,0,?)',
                    [$sid, $form['nama'], $form['nip'], $form['mapel'], $form['jabatan'], $form['kelas'], $form['telepon'], $form['aktif'], $now]);
                $gid = (int) db()->lastInsertId();
            }

            if ($buatAkun === '1') {
                if ($akun) {
                    if ($password !== '') {
                        q('UPDATE users SET username=?, nama=?, password_hash=? WHERE id=?',
                            [$username, $form['nama'], password_hash($password, PASSWORD_DEFAULT), (int) $akun['id']]);
                    } else {
                        q('UPDATE users SET username=?, nama=? WHERE id=?', [$username, $form['nama'], (int) $akun['id']]);
                    }
                } else {
                    $pass = $password !== '' ? $password : guru_sandi_awal($form);
                    q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                        [$username, password_hash($pass, PASSWORD_DEFAULT), $form['nama'], 'guru', $sid, $gid, $now]);
                }
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        flash('Data guru ' . $form['nama'] . ' tersimpan.' . ($buatAkun === '1' && !$akun ? ' Akun login: ' . $username : ''));
        redirect('guru.php');
    }
}

$riwayat = $id ? all('SELECT s.*, i.nama AS instrumen FROM supervisi s JOIN instrumen i ON i.id = s.instrumen_id
                      WHERE s.guru_id = ? AND s.sekolah_id = ? ORDER BY s.tanggal DESC LIMIT 12', [$id, $sid]) : [];

page_top($g ? 'Edit Data Guru' : 'Tambah Data Guru', 'guru');
?>
<?php foreach ($err as $m): ?>
  <div class="alert alert-err"><?= ikon('star', 16) ?><span><?= e($m) ?></span></div>
<?php endforeach; ?>

<form method="post" class="grid g21" style="align-items:start">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">

  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head"><div><h2>Identitas Guru</h2><p>Data ini dipakai pada laporan supervisi.</p></div></div>
      <div class="grid g2">
        <div>
          <label class="f" for="nama">Nama Lengkap &amp; Gelar *</label>
          <input type="text" id="nama" name="nama" value="<?= e((string) $form['nama']) ?>" required placeholder="Contoh: Siti Aminah, S.Pd.">
        </div>
        <div>
          <label class="f" for="nip">NIP / NUPTK <span class="mut" style="font-weight:500">(boleh dikosongkan)</span></label>
          <input type="text" id="nip" name="nip" value="<?= e((string) $form['nip']) ?>" placeholder="18 digit, atau kosongkan / tulis -">
          <div class="hint">Bila guru belum punya NIP/NUPTK, kolom ini boleh dibiarkan kosong atau diisi tanda <b>-</b>.
            Nama pengguna akunnya otomatis memakai nama guru, dan kata sandi awalnya <b>guru123</b>.</div>
        </div>
        <div>
          <label class="f" for="mapel">Mata Pelajaran</label>
          <input type="text" id="mapel" name="mapel" value="<?= e((string) $form['mapel']) ?>" placeholder="Contoh: Matematika">
        </div>
        <div>
          <label class="f" for="jabatan">Jabatan / Golongan</label>
          <input type="text" id="jabatan" name="jabatan" value="<?= e((string) $form['jabatan']) ?>" placeholder="Contoh: Guru Muda">
        </div>
        <div>
          <label class="f" for="kelas">Kelas / Wali Kelas</label>
          <input type="text" id="kelas" name="kelas" value="<?= e((string) $form['kelas']) ?>" placeholder="Contoh: Wali kelas VIII-A">
        </div>
        <div>
          <label class="f" for="telepon">No. HP / WhatsApp</label>
          <input type="text" id="telepon" name="telepon" value="<?= e((string) $form['telepon']) ?>" placeholder="08xxxxxxxxxx">
        </div>
      </div>
      <div class="divider"></div>
      <label class="f" style="display:flex;align-items:center;gap:9px;font-weight:600">
        <input type="checkbox" name="aktif" value="1" <?= (int) $form['aktif'] === 1 ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
        Guru masih aktif (masuk daftar penilaian)
      </label>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Akun Login Guru</h2><p>Guru dapat melihat sendiri hasil supervisinya.</p></div></div>
      <label class="f" style="display:flex;align-items:center;gap:9px;font-weight:600;margin-bottom:14px">
        <input type="checkbox" id="buat_akun" name="buat_akun" value="1" <?= $buatAkun === '1' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
        Berikan akun login untuk guru ini
      </label>
      <div id="blokAkun" class="grid g2">
        <div>
          <label class="f" for="username">Nama Pengguna</label>
          <input type="text" id="username" name="username" value="<?= e($username) ?>" placeholder="NIP atau nama">
          <div class="hint">Boleh dikosongkan, otomatis memakai NIP.</div>
        </div>
        <div>
          <label class="f" for="password"><?= $akun ? 'Kata Sandi Baru' : 'Kata Sandi' ?></label>
          <input type="text" id="password" name="password" value="" placeholder="<?= $akun ? 'biarkan kosong bila tidak diubah' : 'kosongkan = otomatis' ?>">
          <div class="hint">
            <?= $akun
                ? 'Isi hanya jika ingin mengganti kata sandi.'
                : 'Boleh dibiarkan kosong: aplikasi otomatis memakai NIP guru, atau <b>guru123</b> bila guru belum punya NIP.' ?>
          </div>
        </div>
      </div>
      <?php if ($akun): ?>
        <div class="alert alert-ok mt16" style="margin-bottom:0"><?= ikon('check', 16) ?><span>Akun aktif: <b><?= e((string) $akun['username']) ?></b> <?= (int) $akun['login_terakhir'] ? '&middot; terakhir masuk ' . e((string) $akun['login_terakhir']) : '' ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <div class="card">
      <h3 class="mb16">Simpan</h3>
      <div class="form-actions" style="flex-direction:column;gap:10px;align-items:stretch">
        <button class="btn btn-primary full" type="submit"><?= ikon('check', 16) ?> Simpan Data Guru</button>
        <a class="btn btn-ghost full" href="guru.php">Batal</a>
      </div>
    </div>

    <?php if ($id): ?>
    <div class="card">
      <h3 class="mb16">Riwayat Supervisi</h3>
      <?php if ($riwayat): ?>
        <div class="grid" style="gap:10px">
          <?php foreach ($riwayat as $r): ?>
            <div class="flex between" style="background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:9px 11px">
              <div style="min-width:0">
                <b class="sm"><?= e((string) $r['instrumen']) ?></b>
                <div class="xs mut"><?= e(tanggal_id((string) $r['tanggal'])) ?> &middot; <?= $r['status'] === 'final' ? 'Final' : 'Draf' ?></div>
              </div>
              <span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= r1($r['nilai']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="sm mut" style="margin:0">Belum ada supervisi untuk guru ini.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
(function () {
  var cb = document.getElementById('buat_akun');
  var blok = document.getElementById('blokAkun');
  if (!cb || !blok) return;
  function sync() { blok.style.display = cb.checked ? '' : 'none'; }
  cb.addEventListener('change', sync);
  sync();
})();
</script>
<?php
page_bottom();
