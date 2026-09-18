<?php
/**
 * Kelola akun Pengawas Sekolah (khusus Super Admin).
 *
 * Pengawas bersifat lintas sekolah namun TERBATAS: hanya sekolah yang ditetapkan
 * di sini (tabel pengawas_sekolah) yang boleh dilihat rekapnya, dan hanya untuk melihat.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Akun Pengawas (semua sekolah): memakai istilah generik "Sekolah" (bukan Madrasah)
// karena mengelola sekolah umum dan madrasah sekaligus.
$GLOBALS['__vs_istilah_nonaktif'] = true;

$u = require_super();
$aku = (int) $u['id'];

/** Ganti daftar sekolah binaan seorang pengawas sesuai centang di form. */
function simpan_binaan(int $userId, array $sekolahIds): int
{
    $bersih = [];
    foreach ($sekolahIds as $sid) {
        $sid = (int) $sid;
        if ($sid > 0 && one('SELECT id FROM sekolah WHERE id = ? AND verifikasi = "disetujui" AND aktif = 1', [$sid])) {
            $bersih[$sid] = true;
        }
    }
    $lama = array_map('intval', array_column(all('SELECT sekolah_id FROM pengawas_sekolah WHERE user_id = ?', [$userId]), 'sekolah_id'));
    $tambah = array_diff(array_keys($bersih), $lama);
    $hapus = array_diff($lama, array_keys($bersih));

    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach ($tambah as $sid) {
            q('INSERT OR IGNORE INTO pengawas_sekolah (user_id, sekolah_id, dibuat) VALUES (?, ?, ?)',
                [$userId, (int) $sid, date('Y-m-d H:i:s')]);
        }
        foreach ($hapus as $sid) {
            q('DELETE FROM pengawas_sekolah WHERE user_id = ? AND sekolah_id = ?', [$userId, (int) $sid]);
        }
        db()->exec('COMMIT');
    } catch (Throwable $ex) {
        db()->exec('ROLLBACK');
        throw $ex;
    }
    return count($bersih);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $p = $id ? one('SELECT * FROM users WHERE id = ? AND role = "pengawas"', [$id]) : null;

    // ---- tambah akun pengawas ----
    if ($aksi === 'tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $binaan = is_array($_POST['sekolah'] ?? null) ? $_POST['sekolah'] : [];
        $err = [];
        if ($nama === '') {
            $err[] = 'Nama pengawas wajib diisi.';
        }
        if ($username === '' || strlen($password) < 6) {
            $err[] = 'Nama pengguna wajib diisi dan kata sandi minimal 6 karakter.';
        } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
            $err[] = 'Nama pengguna "' . $username . '" sudah dipakai. Nama pengguna harus unik se-aplikasi.';
        }
        if ($err) {
            foreach ($err as $m) {
                flash($m, 'err');
            }
            redirect('pengawas.php');
        }
        db()->exec('BEGIN IMMEDIATE');
        try {
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,NULL,NULL,1,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $nama, 'pengawas', date('Y-m-d H:i:s')]);
            $baru = (int) db()->lastInsertId();
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        $n = simpan_binaan($baru, $binaan);
        flash('Akun pengawas "' . $nama . '" dibuat (nama pengguna: ' . $username . ') dengan ' . $n . ' sekolah binaan.');
        redirect('pengawas.php');
    }

    if (!$p) {
        flash('Akun pengawas tidak ditemukan.', 'err');
        redirect('pengawas.php');
    }

    // ---- ubah identitas & sandi ----
    if ($aksi === 'ubah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($nama === '' || $username === '') {
            flash('Nama dan nama pengguna wajib diisi.', 'err');
            redirect('pengawas.php');
        }
        if (one('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) {
            flash('Nama pengguna "' . $username . '" sudah dipakai akun lain.', 'err');
            redirect('pengawas.php');
        }
        if ($password !== '' && strlen($password) < 6) {
            flash('Kata sandi baru minimal 6 karakter.', 'err');
            redirect('pengawas.php');
        }
        if ($password !== '') {
            q('UPDATE users SET nama = ?, username = ?, password_hash = ? WHERE id = ?',
                [$nama, $username, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            q('UPDATE users SET nama = ?, username = ? WHERE id = ?', [$nama, $username, $id]);
        }
        flash('Akun pengawas "' . $nama . '" diperbarui' . ($password !== '' ? ' beserta kata sandinya' : '') . '.');
        redirect('pengawas.php');
    }

    // ---- tetapkan binaan ----
    if ($aksi === 'binaan') {
        $binaan = is_array($_POST['sekolah'] ?? null) ? $_POST['sekolah'] : [];
        $n = simpan_binaan($id, $binaan);
        flash('Sekolah binaan ' . $p['nama'] . ' diperbarui menjadi ' . $n . ' sekolah.', 'warn');
        redirect('pengawas.php');
    }

    if ($aksi === 'toggle') {
        q('UPDATE users SET aktif = ? WHERE id = ?', [(int) $p['aktif'] === 1 ? 0 : 1, $id]);
        flash('Status akun ' . $p['nama'] . ' diperbarui.', 'warn');
        redirect('pengawas.php');
    }

    if ($aksi === 'hapus') {
        q('DELETE FROM users WHERE id = ? AND role = "pengawas"', [$id]);
        flash('Akun pengawas ' . $p['nama'] . ' dihapus.', 'warn');
        redirect('pengawas.php');
    }

    flash('Aksi tidak dikenali.', 'err');
    redirect('pengawas.php');
}

// Hanya sekolah yang disetujui DAN aktif yang bisa dipilih sebagai binaan.
$sekolahList = all('SELECT * FROM sekolah WHERE verifikasi = "disetujui" AND aktif = 1 ORDER BY nama');
$rows = all('SELECT p.*,
    (SELECT COUNT(*) FROM pengawas_sekolah ps WHERE ps.user_id = p.id) AS jml_binaan
    FROM users p WHERE p.role = "pengawas" ORDER BY p.nama');

$binaanMap = [];
foreach (all('SELECT ps.user_id, s.id, s.nama, s.aktif FROM pengawas_sekolah ps JOIN sekolah s ON s.id = ps.sekolah_id ORDER BY s.nama') as $r) {
    $binaanMap[(int) $r['user_id']][] = ['id' => (int) $r['id'], 'nama' => $r['nama'], 'aktif' => (int) $r['aktif']];
}

$ringkasBinaan = [];
foreach ($binaanMap as $uid => $list) {
    $ringkasBinaan[$uid] = ['sekolah' => 0, 'guru' => 0, 'supervisi' => 0, 'nilai' => 0.0, 'n' => 0];
    foreach ($list as $s) {
        $st = sekolah_stat((int) $s['id']);
        $ringkasBinaan[$uid]['sekolah']++;
        $ringkasBinaan[$uid]['guru'] += $st['guru'];
        $ringkasBinaan[$uid]['supervisi'] += $st['supervisi'];
        if ($st['rata'] > 0) {
            $ringkasBinaan[$uid]['nilai'] += $st['rata'];
            $ringkasBinaan[$uid]['n']++;
        }
    }
    $ringkasBinaan[$uid]['rata'] = $ringkasBinaan[$uid]['n'] > 0 ? round($ringkasBinaan[$uid]['nilai'] / $ringkasBinaan[$uid]['n'], 2) : 0.0;
}

page_top('Akun Pengawas Sekolah', 'pengawas');
?>
<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('eye', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($rows) ?></div><div class="stat-lbl">Akun pengawas</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('building', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($sekolahList) ?></div><div class="stat-lbl">Sekolah terdaftar</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('check', 20) ?></div>
    <div>
      <div class="stat-num" style="font-size:1.35rem"><?= count(array_filter($rows, fn($r) => (int) $r['aktif'] === 1)) ?></div>
      <div class="stat-lbl">Akun aktif</div>
    </div>
  </div>
  <div class="card stat">
    <div class="stat-ic o"><?= ikon('star', 20) ?></div>
    <div>
      <div class="stat-num" style="font-size:1.35rem"><?= (int) val('SELECT COUNT(*) FROM pengawas_sekolah', [], 0) ?></div>
      <div class="stat-lbl">Total penugasan binaan</div>
    </div>
  </div>
</div>

<div class="alert alert-ok">
  <?= ikon('eye', 16) ?>
  <span>Pengawas adalah akun <b>hanya-lihat</b>: setelah masuk, ia hanya dapat membuka <b>rekap supervisi sekolah binaannya</b>
    dan mengunduhnya (CSV/cetak). Ia tidak dapat menilai, mengubah data guru, atau membuka menu pengaturan.</span>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Daftar Akun Pengawas</h2><p>Buka panel pada tiap akun untuk mengubah identitas, sandi, dan sekolah binaannya.</p></div>
    <span class="chip"><?= count($rows) ?> akun</span>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">
      <?= ikon('eye', 34) ?>
      <h3>Belum ada akun pengawas</h3>
      <p>Tambahkan akun pengawas pada formulir di bawah, lalu pilih sekolah mana saja yang menjadi binaannya.</p>
    </div>
  <?php else: ?>
    <div class="grid" style="gap:16px">
      <?php foreach ($rows as $p): $binaan = $binaanMap[(int) $p['id']] ?? []; $rb = $ringkasBinaan[(int) $p['id']] ?? null; ?>
        <div class="butir" style="margin:0;<?= (int) $p['aktif'] === 0 ? 'opacity:.7' : '' ?>">
          <div class="butir-head" style="align-items:flex-start">
            <div class="stat-ic" style="width:40px;height:40px;border-radius:12px"><?= ikon('eye', 19) ?></div>
            <div style="flex:1;min-width:0">
              <div class="butir-komponen">
                <?= e((string) $p['nama']) ?>
                <?php if ((int) $p['aktif'] === 0): ?><span class="pill pill-gray" style="margin-left:6px;vertical-align:middle">nonaktif</span><?php endif; ?>
                <?php if (empty($binaan)): ?><span class="pill pill-warn" style="margin-left:6px;vertical-align:middle">belum ada binaan</span><?php endif; ?>
              </div>
              <div class="butir-indikator">
                Nama pengguna: <b><?= e((string) $p['username']) ?></b> &middot;
                terakhir masuk: <?= e((string) ($p['login_terakhir'] ?: 'belum pernah')) ?>
                <?php if ($rb && $rb['sekolah'] > 0): ?>
                  <br><?= (int) $rb['sekolah'] ?> sekolah binaan &middot; <?= (int) $rb['guru'] ?> guru &middot;
                  <?= (int) $rb['supervisi'] ?> supervisi final &middot; rata-rata <?= r1($rb['rata']) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex" style="gap:6px">
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $p['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <form method="post" data-confirm="Hapus akun pengawas <?= e((string) $p['nama']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 14) ?></button>
              </form>
            </div>
          </div>

          <div class="mt16">
            <?php if ($binaan): ?>
              <div class="chip-list">
                <?php foreach ($binaan as $b): ?>
                  <span class="chip <?= $b['aktif'] === 1 ? '' : 'pill-gray' ?>"><?= ikon('school', 13) ?> <?= e((string) $b['nama']) ?><?= $b['aktif'] === 1 ? '' : ' (nonaktif)' ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="sm mut" style="margin:0">Belum ada sekolah binaan. Pengawas ini belum bisa melihat data apa pun — tetapkan binaannya di bawah.</p>
            <?php endif; ?>
          </div>

          <details class="editbox">
            <summary><?= ikon('edit', 14) ?> Ubah akun &amp; sekolah binaan</summary>
            <div class="grid g2 mt16" style="gap:16px;align-items:start">
              <form method="post" class="grid" style="gap:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <h3 class="mb0">Identitas &amp; sandi</h3>
                <div><label class="f">Nama Pengawas</label><input type="text" name="nama" value="<?= e((string) $p['nama']) ?>" required></div>
                <div><label class="f">Nama Pengguna</label><input type="text" name="username" value="<?= e((string) $p['username']) ?>" required></div>
                <div>
                  <label class="f">Kata Sandi Baru</label>
                  <input type="text" name="password" placeholder="biarkan kosong bila tidak diubah">
                  <div class="hint">Isi hanya bila ingin mengganti kata sandi pengawas.</div>
                </div>
                <div class="flex"><button class="btn btn-primary btn-sm" type="submit"><?= ikon('check', 14) ?> Simpan identitas</button></div>
              </form>

              <form method="post" class="grid" style="gap:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="binaan"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <h3 class="mb0">Sekolah binaan</h3>
                <div class="grid" style="gap:8px;max-height:230px;overflow:auto;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:12px">
                  <?php if (!$sekolahList): ?>
                    <span class="sm mut">Belum ada sekolah terdaftar.</span>
                  <?php endif; ?>
                  <?php $punya = array_map(fn($b) => (int) $b['id'], $binaan); ?>
                  <?php foreach ($sekolahList as $s): ?>
                    <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600;margin:0">
                      <input type="checkbox" name="sekolah[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $punya, true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
                      <?= e((string) $s['nama']) ?><?= (int) $s['aktif'] === 0 ? ' (nonaktif)' : '' ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="hint">Centang sekolah yang menjadi binaannya. Perubahan langsung berlaku pada akun tersebut.</div>
                <div class="flex"><button class="btn btn-primary btn-sm" type="submit"><?= ikon('check', 14) ?> Simpan sekolah binaan</button></div>
              </form>
            </div>
          </details>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Tambah Akun Pengawas</h2><p>Buat akun untuk pengawas sekolah/pengawas binaan, lalu tentukan sekolah binaannya.</p></div>
  </div>
  <form method="post" class="grid g2" style="align-items:start">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="tambah">
    <div class="grid" style="gap:12px">
      <?php $prefill = count($rows) === 0; // akun pertama: isikan nilai yang wajar agar cepat dibuat ?>
      <div><label class="f" for="nama">Nama Pengawas *</label><input type="text" id="nama" name="nama" required value="<?= $prefill ? 'Pengawas Sekolah' : '' ?>" placeholder="nama &amp; gelar"></div>
      <div class="grid g2" style="gap:12px">
        <div><label class="f" for="username">Nama Pengguna *</label><input type="text" id="username" name="username" required value="<?= $prefill ? 'pengawas' : '' ?>" placeholder="tanpa spasi"></div>
        <div><label class="f" for="password">Kata Sandi *</label><input type="text" id="password" name="password" required value="<?= $prefill ? 'pengawas123' : '' ?>" placeholder="minimal 6 karakter"></div>
      </div>
      <div class="hint">Kata sandi ini yang dipakai pengawas untuk masuk. Beritahukan kepadanya setelah dibuat.</div>
    </div>

    <div class="grid" style="gap:12px">
      <label class="f" style="margin:0">Sekolah binaan</label>
      <div class="grid" style="gap:8px;max-height:200px;overflow:auto;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:12px">
        <?php foreach ($sekolahList as $s): ?>
          <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600;margin:0">
            <input type="checkbox" name="sekolah[]" value="<?= (int) $s['id'] ?>" style="width:16px;height:16px;accent-color:var(--accent)">
            <?= e((string) $s['nama']) ?><?= (int) $s['aktif'] === 0 ? ' (nonaktif)' : '' ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Akun Pengawas</button>
    </div>
  </form>
</div>
<?php
page_bottom();
