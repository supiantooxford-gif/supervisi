<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$sid = sekolah_id();
$id = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'profil') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($nama === '') {
            flash('Nama tidak boleh kosong.', 'err');
        } else {
            q('UPDATE users SET nama = ? WHERE id = ?', [$nama, $id]);
            if ($u['role'] === 'guru' && (int) ($u['guru_id'] ?? 0) > 0) {
                // Nama tampilan akun guru disamakan agar konsisten pada laporan.
                q('UPDATE guru SET nama = ? WHERE id = ? AND sekolah_id = ?', [$nama, (int) $u['guru_id'], $sid]);
            }
            flash('Profil diperbarui.');
        }
    } elseif ($aksi === 'sandi') {
        $lama = (string) ($_POST['sandi_lama'] ?? '');
        $baru = (string) ($_POST['sandi_baru'] ?? '');
        $ulang = (string) ($_POST['sandi_ulang'] ?? '');
        $row = one('SELECT password_hash FROM users WHERE id = ?', [$id]);
        if (!$row || !password_verify($lama, $row['password_hash'])) {
            flash('Kata sandi lama tidak sesuai.', 'err');
        } elseif (strlen($baru) < 6) {
            flash('Kata sandi baru minimal 6 karakter.', 'err');
        } elseif ($baru !== $ulang) {
            flash('Ulangi kata sandi baru dengan sama.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), $id]);
            flash('Kata sandi berhasil diganti.');
        }
    }
    redirect('akun.php');
}

$guru = (int) ($u['guru_id'] ?? 0) > 0 ? one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [(int) $u['guru_id'], $sid]) : null;
// Ringkasan & riwayat milik guru ini pada SEKOLAH ini (id guru sudah unik per sekolah,
// pembatas sekolah_id ditambahkan supaya tidak pernah mengambil data sekolah lain).
$ringkas = $guru ? one('SELECT COUNT(*) jml, AVG(nilai) rata FROM supervisi WHERE guru_id = ? AND sekolah_id = ? AND status = "final"', [(int) $guru['id'], $sid]) : null;
$riwayat = $guru ? all('SELECT s.*, i.nama AS instrumen FROM supervisi s JOIN instrumen i ON i.id = s.instrumen_id
                        WHERE s.guru_id = ? AND s.sekolah_id = ? ORDER BY s.tanggal DESC LIMIT 8', [(int) $guru['id'], $sid]) : [];

$fotoSaya = foto_user($u);

page_top('Akun Saya', 'akun');
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>Foto Profil</h2>
      <p>Foto ini tampil pada menu samping, daftar pengguna, dan dokumen supervisi. Format JPG, PNG, GIF, WEBP, atau SVG &middot; maksimal 15 MB.</p>
    </div>
    <span class="chip"><?= $fotoSaya ? 'Foto terpasang' : 'Belum ada foto' ?></span>
  </div>

  <div class="foto-baris">
    <div class="foto-sisi">
      <?= avatar_html((string) $u['nama'], $fotoSaya, 132, 'avatar avatar-besar') ?>
      <div class="xs mut center"><?= e($u['role'] === 'super' ? 'Super Admin' : ($u['role'] === 'admin' ? 'Admin / Kepala Sekolah' : 'Guru')) ?></div>
    </div>

    <form method="post" action="upload_foto.php" enctype="multipart/form-data" class="foto-form" id="formFoto">
      <?= csrf_field() ?>
      <div class="foto-input-baris">
        <div style="flex:1;min-width:0">
          <label class="f" for="foto">Pilih berkas foto</label>
          <input type="file" id="foto" name="foto" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required>
          <div class="hint" id="fotoHint">Belum ada berkas dipilih. Foto persegi (1:1) memberi hasil terbaik.</div>
        </div>
        <div class="foto-preview-wrap" id="fotoPreviewWrap" hidden>
          <div class="foto-preview" id="fotoPreview"></div>
          <div class="xs mut center" id="fotoUkuran"></div>
        </div>
      </div>
      <div class="flex mt16">
        <button class="btn btn-primary" type="submit" id="btnFoto"><?= ikon('upload', 16) ?> <?= $fotoSaya ? 'Ganti Foto' : 'Unggah Foto' ?></button>
        <span class="xs mut" id="fotoStatus"></span>
      </div>
    </form>

    <?php if ($fotoSaya): ?>
      <form method="post" action="upload_foto.php" data-confirm="Hapus foto profil Anda? Anda akan kembali memakai avatar inisial nama.">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="hapus">
        <button class="btn btn-danger" type="submit"><?= ikon('trash', 16) ?> Hapus Foto</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <div class="card-head"><div><h2>Profil</h2><p>Nama ini tampil pada laporan supervisi.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="profil">
      <div>
        <label class="f" for="nama">Nama Lengkap</label>
        <input type="text" id="nama" name="nama" value="<?= e((string) $u['nama']) ?>" required>
      </div>
      <div class="grid g2" style="gap:12px">
        <div>
          <label class="f">Nama Pengguna</label>
          <input type="text" value="<?= e((string) $u['username']) ?>" disabled>
        </div>
        <div>
          <label class="f">Peran</label>
          <input type="text" value="<?= $u['role'] === 'super' ? 'Super Admin (lintas sekolah)' : ($u['role'] === 'pengawas' ? 'Pengawas Sekolah (hanya lihat rekap)' : ($u['role'] === 'admin' ? 'Administrator / Kepala Sekolah' : 'Guru')) ?>" disabled>
        </div>
      </div>
      <button class="btn btn-primary" type="submit"><?= ikon('check', 16) ?> Simpan Profil</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Ganti Kata Sandi</h2><p>Gunakan kata sandi yang tidak mudah ditebak.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="sandi">
      <div>
        <label class="f" for="sandi_lama">Kata Sandi Saat Ini</label>
        <input type="password" id="sandi_lama" name="sandi_lama" required>
      </div>
      <div class="grid g2" style="gap:12px">
        <div>
          <label class="f" for="sandi_baru">Kata Sandi Baru</label>
          <input type="password" id="sandi_baru" name="sandi_baru" required placeholder="minimal 6 karakter">
        </div>
        <div>
          <label class="f" for="sandi_ulang">Ulangi Kata Sandi Baru</label>
          <input type="password" id="sandi_ulang" name="sandi_ulang" required>
        </div>
      </div>
      <button class="btn btn-primary" type="submit"><?= ikon('key', 16) ?> Ganti Kata Sandi</button>
    </form>
    <?php if ((string) $u['username'] === 'admin'): ?>
      <div class="alert alert-warn mt16" style="margin-bottom:0">
        <?= ikon('star', 16) ?><span>Anda memakai nama pengguna bawaan <b>admin</b>. Sebaiknya ganti kata sandi bawaan sekarang.</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($guru): ?>
<div class="grid g3">
  <div class="card stat card-tight">
    <div class="stat-ic"><?= ikon('users', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= (int) ($ringkas['jml'] ?? 0) ?></div><div class="stat-lbl">Supervisi final</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic s"><?= ikon('chart', 18) ?></div>
    <div><div class="stat-num" style="font-size:1.3rem"><?= r1((float) ($ringkas['rata'] ?? 0)) ?></div><div class="stat-lbl">Rata-rata nilai</div></div>
  </div>
  <div class="card stat card-tight">
    <div class="stat-ic g"><?= ikon('star', 18) ?></div>
    <?php $pa = ($ringkas['rata'] ?? null) !== null ? predikat_dari((float) $ringkas['rata']) : '-'; ?>
    <div><div class="stat-num" style="font-size:1.3rem"><?= e($pa) ?></div><div class="stat-lbl"><?= $pa !== '-' ? e(predikat_label($pa)) : 'Belum ada nilai' ?></div></div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Data Kepegawaian</h2><p>Diperbarui oleh administrator/kepala sekolah.</p></div>
    <a class="btn btn-ghost btn-sm" href="laporan.php"><?= ikon('report', 15) ?> Laporan lengkap</a>
  </div>
  <div class="grid g4" style="gap:14px">
    <div><label class="f">NIP / NUPTK</label><div class="sm"><?= e((string) ($guru['nip'] ?: '-')) ?></div></div>
    <div><label class="f">Mata Pelajaran</label><div class="sm"><?= e((string) ($guru['mapel'] ?: '-')) ?></div></div>
    <div><label class="f">Jabatan</label><div class="sm"><?= e((string) ($guru['jabatan'] ?: '-')) ?></div></div>
    <div><label class="f">Kelas / Wali</label><div class="sm"><?= e((string) ($guru['kelas'] ?: '-')) ?></div></div>
  </div>
  <div class="divider"></div>
  <h3 class="mb8">Riwayat Supervisi Terakhir</h3>
  <?php if ($riwayat): ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Tanggal</th><th>Menu Supervisi</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($riwayat as $r): ?>
          <tr>
            <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
            <td><?= e((string) $r['instrumen']) ?></td>
            <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
            <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?></span></td>
            <td class="right"><a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>">Lihat</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="sm mut" style="margin:0">Belum ada hasil supervisi.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
page_bottom();
