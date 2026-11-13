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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $p = $id ? one('SELECT * FROM users WHERE id = ? AND role = "pengawas"', [$id]) : null;

    // ---- gabungkan akun bernama sama (permintaan pemilik) ----
    if ($aksi === 'gabung') {
        $hasil = pengawas_gabung_nama_sama();
        if ($hasil['akun_dihapus'] > 0) {
            $nama = array_map(static fn(array $g): string => $g['nama'], $hasil['grup']);
            app_setting_set('pengawas_gabung_v1', date('Y-m-d H:i:s') . '|' . (int) $hasil['akun_dihapus']);
            flash($hasil['akun_dihapus'] . ' akun pengawas bernama sama digabung menjadi satu ('
                . $hasil['binaan_dipindah'] . ' sekolah binaan dipindahkan)'
                . ($nama ? ': ' . implode(', ', $nama) : '') . '.', 'warn');
        } else {
            flash('Tidak ada akun pengawas bernama sama yang perlu digabung.');
        }
        redirect('pengawas.php');
    }

    // ---- tambah akun pengawas ----
    if ($aksi === 'tambah') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $nip = nip_bersih($_POST['nip'] ?? '');
        $binaan = is_array($_POST['sekolah'] ?? null) ? $_POST['sekolah'] : [];
        $err = [];
        if ($nama === '') {
            $err[] = 'Nama pengawas wajib diisi.';
        }
        // SATU ORANG = SATU AKUN: nama pengawas yang sudah terdaftar tidak boleh dibuat ulang
        // (permintaan pemilik). Sekolah binaannya ditambahkan pada akun yang sudah ada itu.
        $kembar = $nama !== '' ? pengawas_akun_nama_sama($nama) : null;
        if ($kembar) {
            $err[] = 'Akun pengawas bernama "' . $kembar['nama'] . '" sudah ada (nama pengguna: '
                . $kembar['username'] . '). Satu pengawas cukup satu akun — tambahkan sekolah binaannya '
                . 'pada akun itu (tombol "Ubah akun & sekolah binaan").';
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
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, nip, asal, dibuat) VALUES (?,?,?,?,NULL,NULL,1,?,?,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $nama, 'pengawas',
                 $nip, 'manual', date('Y-m-d H:i:s')]);
            $baru = (int) db()->lastInsertId();
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        $simpan = pengawas_binaan_simpan($baru, $binaan);
        flash('Akun pengawas "' . $nama . '" dibuat (nama pengguna: ' . $username . ') dengan '
            . $simpan['jumlah'] . ' sekolah binaan.');
        if ($simpan['ditolak']) {
            flash('Tidak dapat dijadikan binaan (sudah dibina pengawas lain): '
                . implode('; ', $simpan['ditolak']) . '.', 'err');
        }
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
        // Nama pengawas kembar tidak boleh dibuat lewat "ubah" (satu orang = satu akun). Bila memang
        // dua akun itu orang yang sama, gunakan tombol "Gabungkan akun bernama sama".
        $kembar = pengawas_akun_nama_sama($nama, $id);
        if ($kembar) {
            flash('Sudah ada akun pengawas bernama "' . $kembar['nama'] . '" (nama pengguna: '
                . $kembar['username'] . '). Gunakan tombol "Gabungkan akun bernama sama" bila keduanya '
                . 'orang yang sama, atau bedakan namanya.', 'err');
            redirect('pengawas.php');
        }
        if ($password !== '' && strlen($password) < 6) {
            flash('Kata sandi baru minimal 6 karakter.', 'err');
            redirect('pengawas.php');
        }
        // NIP hanya diperbarui bila medannya benar-benar dikirim (form lama tetap aman).
        $nipKirim = array_key_exists('nip', $_POST);
        $nip = $nipKirim ? nip_bersih($_POST['nip'] ?? '') : '';
        if ($password !== '') {
            q('UPDATE users SET nama = ?, username = ?, password_hash = ? WHERE id = ?',
                [$nama, $username, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            q('UPDATE users SET nama = ?, username = ? WHERE id = ?', [$nama, $username, $id]);
        }
        if ($nipKirim) {
            q('UPDATE users SET nip = ? WHERE id = ?', [$nip !== '' ? $nip : null, $id]);
        }
        flash('Akun pengawas "' . $nama . '" diperbarui' . ($password !== '' ? ' beserta kata sandinya' : '') . '.');
        redirect('pengawas.php');
    }

    // ---- tetapkan binaan ----
    if ($aksi === 'binaan') {
        $binaan = is_array($_POST['sekolah'] ?? null) ? $_POST['sekolah'] : [];
        $simpan = pengawas_binaan_simpan($id, $binaan);
        flash('Sekolah binaan ' . $p['nama'] . ' diperbarui menjadi ' . $simpan['jumlah'] . ' sekolah.', 'warn');
        if ($simpan['ditolak']) {
            flash('Tidak dapat dijadikan binaan (sudah dibina pengawas lain): '
                . implode('; ', $simpan['ditolak']) . '. Lepas dulu binaan itu dari pengawasnya.', 'err');
        }
        if ($simpan['dilepas']) {
            flash('Binaan yang dilepas: ' . implode(', ', $simpan['dilepas']) . '.', 'warn');
        }
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

// ============================== PERSIAPAN TAMPILAN ==============================
// (1) Sekali saja setelah pembaruan dipasang: gabungkan akun pengawas yang NAMANYA SAMA menjadi satu
//     (permintaan pemilik). Idempoten — penanda `pengawas_gabung_v1` mencegah pengulangan.
$gabungSekali = pengawas_gabung_sekali();
if ($gabungSekali['akun_dihapus'] > 0) {
    $nmGabung = array_map(static fn(array $g): string => $g['nama'], $gabungSekali['grup']);
    flash($gabungSekali['akun_dihapus'] . ' akun pengawas bernama sama digabung menjadi satu ('
        . $gabungSekali['binaan_dipindah'] . ' sekolah binaan dipindahkan)'
        . ($nmGabung ? ': ' . implode(', ', $nmGabung) : '') . '.', 'warn');
}

// (2) Hanya sekolah yang disetujui DAN aktif yang bisa dipilih sebagai binaan.
$sekolahList = all('SELECT * FROM sekolah WHERE verifikasi = "disetujui" AND aktif = 1 ORDER BY nama');
$rows = all('SELECT p.*,
    (SELECT COUNT(*) FROM pengawas_sekolah ps WHERE ps.user_id = p.id) AS jml_binaan
    FROM users p WHERE p.role = "pengawas" ORDER BY p.nama');

// SATU SEKOLAH = SATU PENGAWAS: peta sekolah yang sudah dibina pengawas LAIN dipakai untuk
// menonaktifkan kotak centangnya pada tiap formulir (penjagaan sebenarnya tetap di server).
$binaanPeta = [];   // sekolah_id => pengawas (selain akun yang sedang dibuka) — diisi di bawah
$duplikatGrup = pengawas_duplikat_grup();
$binaanGanda = pengawas_binaan_ganda();

$binaanMap = [];
$pemilikSekolah = [];   // sekolah_id => ['user_id' => int, 'nama' => string pengawas]
foreach (all('SELECT ps.user_id, s.id, s.nama, s.aktif, u.nama AS pengawas FROM pengawas_sekolah ps JOIN sekolah s ON s.id = ps.sekolah_id JOIN users u ON u.id = ps.user_id ORDER BY s.nama') as $r) {
    $binaanMap[(int) $r['user_id']][] = ['id' => (int) $r['id'], 'nama' => $r['nama'], 'aktif' => (int) $r['aktif']];
    if (!isset($pemilikSekolah[(int) $r['id']])) {
        $pemilikSekolah[(int) $r['id']] = ['user_id' => (int) $r['user_id'], 'nama' => (string) $r['pengawas']];
    }
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
  <?php $jmlDitinjau = pengawas_menunggu_jumlah(); ?>
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

<?php if ($jmlDitinjau > 0): ?>
  <div class="alert alert-warn">
    <?= ikon('star', 16) ?>
    <span><b><?= $jmlDitinjau ?> akun pengawas</b> diajukan lewat halaman pendaftaran sekolah dan belum ditinjau.
      Akun itu <b>berstatus nonaktif</b> sehingga belum bisa dipakai masuk. Periksa identitas &amp; sekolah
      binaannya, tetapkan <b>kata sandi barunya</b>, lalu tekan <b>Aktifkan</b> bila memang benar.</span>
  </div>
<?php endif; ?>

<?php
// ATURAN "SATU SEKOLAH = SATU PENGAWAS" & "NAMA SAMA = SATU AKUN" (permintaan pemilik).
// Kartu di bawah menjelaskan aturannya sekaligus menjadi jalan keluar bila masih ada data lama.
$jmlDuplikat = 0;
foreach ($duplikatGrup as $g) {
    $jmlDuplikat += count($g['akun']) - 1;
}
?>
<div class="alert alert-info" id="infoSatuPengawas">
  <?= ikon('shield', 16) ?>
  <span><b>Satu sekolah/madrasah hanya dibina satu pengawas.</b> Sekolah yang sudah menjadi binaan
    seorang pengawas tidak bisa dicentang untuk pengawas lain (kotaknya terkunci dan diberi keterangan
    nama pengawasnya). Untuk memindahkan binaan, lepas dulu dari pengawas lama, lalu centang pada
    pengawas baru &mdash; data sekolah tidak terpengaruh sama sekali.</span>
</div>

<?php if ($jmlDuplikat > 0): ?>
  <div class="card" id="cardGabungPengawas">
    <div class="card-head">
      <div>
        <h2>Akun Pengawas Bernama Sama</h2>
        <p>Satu pengawas cukup satu akun. Akun bernama sama digabung menjadi satu &mdash; seluruh sekolah
          binaannya disatukan pada akun yang paling lama dibuat.</p>
      </div>
      <span class="chip pill-warn"><?= (int) $jmlDuplikat ?> akun berlebih</span>
    </div>
    <div class="table-wrap">
      <table class="tbl" id="tblGabungPengawas">
        <thead><tr><th>Nama Pengawas</th><th>Akun yang digabung</th><th class="ctr">Sekolah binaan</th><th>Dipertahankan</th></tr></thead>
        <tbody>
        <?php foreach ($duplikatGrup as $g): ?>
          <tr>
            <td><b><?= e($g['nama']) ?></b></td>
            <td>
              <?php foreach ($g['akun'] as $ak): ?>
                <span class="chip"><?= e((string) $ak['username']) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="ctr">
              <?php
              $totGabung = 0;
              foreach ($g['akun'] as $ak) {
                  $totGabung += (int) val('SELECT COUNT(*) FROM pengawas_sekolah WHERE user_id = ?', [(int) $ak['id']], 0);
              }
              echo (int) $totGabung;
              ?>
            </td>
            <td class="sm mut"><?= e((string) $g['akun'][0]['username']) ?> (akun terlama)</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="mt16" data-confirm="Gabungkan <?= (int) $jmlDuplikat ?> akun pengawas bernama sama? Sekolah binaan &amp; komentarnya dipindahkan ke akun terlama, lalu akun gandanya dihapus.">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="gabung">
      <button class="btn btn-primary" type="submit"><?= ikon('check', 16) ?> Gabungkan Sekarang</button>
      <span class="xs mut" style="margin-left:8px">Data sekolah, supervisi, dan komentar tidak dihapus &mdash; hanya dipindahkan ke satu akun.</span>
    </form>
  </div>
<?php endif; ?>

<?php if ($binaanGanda): ?>
  <div class="alert alert-err" id="alertBinaanGanda">
    <?= ikon('alert', 16) ?>
    <span><b>Sekolah berikut masih dibina lebih dari satu pengawas</b> (data lama):
      <?php
      $teksGanda = [];
      foreach ($binaanGanda as $sg) {
          $teksGanda[] = e($sg['nama']) . ' (' . e(implode(' &amp; ', $sg['pengawas'])) . ')';
      }
      echo implode('; ', $teksGanda);
      ?>. Lepas dari salah satu pengawas agar aturan satu sekolah satu pengawas berlaku.</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h2>Daftar Akun Pengawas</h2><p>Buka panel pada tiap akun untuk mengubah identitas, sandi, dan sekolah binaannya.</p></div>
    <div class="flex">
      <!-- CETAK & UNDUH WORD (v1.29.0 — permintaan pemilik: kotak merah di area ini). Hasilnya:
           tabel No, Pengawas Bina, Sekolah/Madrasah Binaan (bernomor), Masa Aktif. -->
      <?php if ($rows): ?>
        <a class="btn btn-ghost btn-sm" id="btnCetakPengawas" href="cetak_pengawas.php" target="_blank"
           rel="noopener"><?= ikon('print', 14) ?> Cetak</a>
        <a class="btn btn-ghost btn-sm" id="btnWordPengawas" href="pengawas_docx.php"><?= ikon('doc', 14) ?> Unduh Word</a>
      <?php endif; ?>
      <?php if ($jmlDitinjau > 0): ?>
        <span class="chip pill-warn" id="chipDitinjau"><?= (int) $jmlDitinjau ?> menunggu ditinjau</span>
      <?php endif; ?>
      <span class="chip"><?= count($rows) ?> akun</span>
    </div>
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
                <?php if ((int) $p['aktif'] === 0 && (string) ($p['asal'] ?? '') === 'pendaftaran'): ?>
                  <span class="pill pill-warn" style="margin-left:6px;vertical-align:middle">menunggu ditinjau</span>
                <?php endif; ?>
                <?php if (empty($binaan)): ?><span class="pill pill-warn" style="margin-left:6px;vertical-align:middle">belum ada binaan</span><?php endif; ?>
              </div>
              <div class="butir-indikator">
                Nama pengguna: <b><?= e((string) $p['username']) ?></b><?php if (trim((string) ($p['nip'] ?? '')) !== ''): ?>
                  &middot; NIP: <b><?= e((string) $p['nip']) ?></b><?php endif; ?> &middot;
                terakhir masuk: <?= e((string) ($p['login_terakhir'] ?: 'belum pernah')) ?>
                <?php if ((string) ($p['asal'] ?? '') === 'pendaftaran'): ?>
                  <br><span class="pill pill-gray">dari pendaftaran sekolah</span>
                  <?php if ((int) $p['aktif'] === 0): ?> diajukan oleh sekolah yang mendaftar — tinjau dulu sebelum diaktifkan.<?php endif; ?>
                <?php endif; ?>
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
                <div><label class="f">NIP</label><input type="text" name="nip" value="<?= e((string) ($p['nip'] ?? '')) ?>"></div>
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
                  <?php foreach ($sekolahList as $s):
                      $sidSekolah = (int) $s['id'];
                      // Sekolah yang sudah dibina pengawas LAIN → kotak dikunci (aturan satu sekolah satu pengawas).
                      $pemilik = $pemilikSekolah[$sidSekolah] ?? null;
                      $terkunci = $pemilik !== null && $pemilik['user_id'] !== (int) $p['id'];
                      ?>
                    <label class="f<?= $terkunci ? ' pengawas-terkunci' : '' ?>" style="display:flex;gap:9px;align-items:center;font-weight:600;margin:0<?= $terkunci ? ';opacity:.65' : '' ?>">
                      <input type="checkbox" name="sekolah[]" value="<?= $sidSekolah ?>" <?= in_array($sidSekolah, $punya, true) ? 'checked' : '' ?> <?= $terkunci ? 'disabled' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
                      <span><?= e((string) $s['nama']) ?><?= (int) $s['aktif'] === 0 ? ' (nonaktif)' : '' ?>
                        <?php if ($terkunci): ?><span class="tbl-sub">sudah dibina <?= e($pemilik['nama']) ?></span><?php endif; ?>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="hint">Centang sekolah yang menjadi binaannya. Perubahan langsung berlaku pada akun tersebut.
                  <b>Satu sekolah/madrasah hanya untuk satu pengawas</b> &mdash; sekolah yang sudah dibina pengawas lain
                  tidak dapat dicentang di sini.</div>
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
      <div><label class="f" for="nip">NIP</label><input type="text" id="nip" name="nip" placeholder="boleh dikosongkan"></div>
      <div class="grid g2" style="gap:12px">
        <div><label class="f" for="username">Nama Pengguna *</label><input type="text" id="username" name="username" required value="<?= $prefill ? 'pengawas' : '' ?>" placeholder="tanpa spasi"></div>
        <div><label class="f" for="password">Kata Sandi *</label><input type="text" id="password" name="password" required value="<?= $prefill ? 'pengawas123' : '' ?>" placeholder="minimal 6 karakter"></div>
      </div>
      <div class="hint">Kata sandi ini yang dipakai pengawas untuk masuk. Beritahukan kepadanya setelah dibuat.</div>
    </div>

    <div class="grid" style="gap:12px">
      <label class="f" style="margin:0">Sekolah binaan</label>
      <div class="grid" style="gap:8px;max-height:200px;overflow:auto;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:12px">
        <?php foreach ($sekolahList as $s):
            $pemilik = $pemilikSekolah[(int) $s['id']] ?? null;
            ?>
          <label class="f" style="display:flex;gap:9px;align-items:center;font-weight:600;margin:0<?= $pemilik ? ';opacity:.65' : '' ?>">
            <input type="checkbox" name="sekolah[]" value="<?= (int) $s['id'] ?>" <?= $pemilik ? 'disabled' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
            <span><?= e((string) $s['nama']) ?><?= (int) $s['aktif'] === 0 ? ' (nonaktif)' : '' ?>
              <?php if ($pemilik): ?><span class="tbl-sub">sudah dibina <?= e($pemilik['nama']) ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Sekolah yang sudah dibina pengawas lain tidak dapat dicentang &mdash; satu sekolah/madrasah
        hanya untuk satu pengawas. Nama pengawas yang sudah terdaftar juga tidak bisa dibuat ulang; tambahkan
        sekolah binaannya pada akun yang sudah ada.</div>
      <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Akun Pengawas</button>
    </div>
  </form>
</div>
<?php
page_bottom();
