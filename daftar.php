<?php
/**
 * Pendaftaran sekolah/madrasah (halaman PUBLIK, dapat diakses tanpa masuk).
 *
 * Sekolah/madrasah yang mendaftar dicatat dengan status verifikasi "menunggu" dan akun adminnya
 * belum dapat dipakai sampai Admin menyetujui di menu Pendaftaran Sekolah.
 *
 * ISTILAH (permintaan pemilik): seluruh tulisan yang TAMPIL memakai istilah "sekolah/madrasah"
 * (halaman ini melayani sekolah umum maupun madrasah) dan persetujuan disebut "Admin"
 * (bukan "Super Admin"). Nama field/kolom (mis. name="kepala_sekolah") TIDAK diubah.
 * Menu & butir penilaian bawaan baru dibuat saat pendaftaran disetujui.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$err = [];
$berhasil = false;
$form = [
    'nama' => '', 'npsn' => '', 'alamat' => '', 'kepala_sekolah' => '', 'nip_kepala_sekolah' => '',
    'tahun_ajaran' => date('Y') . '/' . (date('Y') + 1), 'semester' => 'Ganjil',
    'kontak_nama' => '', 'kontak_telepon' => '', 'kontak_email' => '',
    'admin_nama' => '', 'admin_username' => '',
];

/** Captcha sederhana (penjumlahan) untuk menahan pengiriman otomatis. */
function captcha_baru(): array
{
    $a = random_int(3, 12);
    $b = random_int(2, 9);
    $_SESSION['daftar_captcha'] = $a + $b;
    return [$a, $b];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    foreach (array_keys($form) as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    $jawab = trim((string) ($_POST['captcha'] ?? ''));
    $setuju = !empty($_POST['setuju']);

    // Validasi
    if ($form['nama'] === '') {
        $err[] = 'Nama sekolah/madrasah wajib diisi.';
    }
    if ($form['kontak_nama'] === '') {
        $err[] = 'Nama penanggung jawab (yang dapat dihubungi) wajib diisi.';
    }
    if ($form['kontak_telepon'] === '' && $form['kontak_email'] === '') {
        $err[] = 'Isi minimal satu kontak yang dapat dihubungi: nomor HP/WhatsApp atau email.';
    }
    if ($form['kontak_email'] !== '' && !filter_var($form['kontak_email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Alamat email belum valid.';
    }
    if ($form['tahun_ajaran'] === '') {
        $form['tahun_ajaran'] = date('Y') . '/' . (date('Y') + 1);
    }
    if ($form['admin_nama'] === '') {
        $err[] = 'Nama admin/kepala sekolah/madrasah (pemilik akun) wajib diisi.';
    }
    $username = strtolower(preg_replace('/\s+/', '', $form['admin_username']));
    if ($username === '' || strlen($username) < 4) {
        $err[] = 'Nama pengguna minimal 4 karakter tanpa spasi.';
    } elseif (!preg_match('/^[a-z0-9._-]+$/', $username)) {
        $err[] = 'Nama pengguna hanya boleh huruf kecil, angka, titik, garis bawah, atau tanda hubung.';
    } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
        $err[] = 'Nama pengguna "' . $username . '" sudah dipakai oleh sekolah/madrasah atau akun lain. Pilih yang lain.';
    }
    if (strlen($password) < 8) {
        $err[] = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $password2) {
        $err[] = 'Ulangi kata sandi dengan sama.';
    }
    if ((int) ($_SESSION['daftar_captcha'] ?? -1) !== (int) $jawab) {
        $err[] = 'Jawaban pertanyaan pengaman salah. Coba lagi.';
    }
    if (!$setuju) {
        $err[] = 'Centang pernyataan bahwa data yang diisi benar.';
    }
    // Batasi jumlah pendaftaran per sesi agar tidak disalahgunakan
    $sudahKirim = (int) ($_SESSION['daftar_kirim'] ?? 0);
    if ($sudahKirim >= 3) {
        $err[] = 'Anda sudah mengirim beberapa pendaftaran dari perangkat ini. Hubungi Admin bila belum mendapat kabar.';
    }

    if (!$err) {
        db()->exec('BEGIN IMMEDIATE');
        try {
            q('INSERT INTO sekolah (nama, npsn, alamat, kepala_sekolah, nip_kepala_sekolah, tahun_ajaran, semester,
                    batas_a, batas_b, batas_c, catatan, aktif, verifikasi, kontak_nama, kontak_telepon, kontak_email, tanggal_daftar, dibuat)
               VALUES (?,?,?,?,?,?,?,90,80,70,?,0,"menunggu",?,?,?,?,?)', [
                $form['nama'],
                $form['npsn'] !== '' ? $form['npsn'] : '-',
                $form['alamat'] !== '' ? $form['alamat'] : '-',
                $form['kepala_sekolah'] !== '' ? $form['kepala_sekolah'] : '-',
                $form['nip_kepala_sekolah'] !== '' ? $form['nip_kepala_sekolah'] : '-',
                $form['tahun_ajaran'],
                $form['semester'] === 'Genap' ? 'Genap' : 'Ganjil',
                'Pendaftaran mandiri dari halaman pendaftaran.',
                $form['kontak_nama'],
                $form['kontak_telepon'],
                $form['kontak_email'],
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);
            $sidBaru = (int) db()->lastInsertId();

            // sekolah_id harus ikut sebagai parameter (6 nilai untuk 6 placeholder).
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,NULL,1,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $form['admin_nama'], 'admin', $sidBaru, date('Y-m-d H:i:s')]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        $_SESSION['daftar_kirim'] = $sudahKirim + 1;
        unset($_SESSION['daftar_captcha']);
        $berhasil = true;
        $form['admin_username'] = $username;
    } else {
        // Captcha baru setiap kali gagal
        [$ca, $cb] = captcha_baru();
    }
}

if (!$berhasil && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    [$ca, $cb] = captcha_baru();
} elseif (!$berhasil) {
    [$ca, $cb] = captcha_baru();
}

$siapa = current_user();
$sudahMasuk = $siapa !== null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pendaftaran Sekolah/Madrasah &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127891;</text></svg>">
<style>
  .daftar-wrap{min-height:100vh;padding:30px 18px 44px}
  .daftar-inner{max-width:880px;margin:0 auto}
  .daftar-head{display:flex;align-items:center;gap:14px;margin-bottom:22px}
  .daftar-logo{width:54px;height:54px;border-radius:17px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent2));box-shadow:0 12px 26px -12px rgba(79,70,229,.8);flex:0 0 auto}
  .daftar-head h1{font-size:1.35rem;margin:0}
  .daftar-head p{margin:4px 0 0;color:var(--muted);font-size:.84rem}
  .daftar-foot{margin-top:20px;text-align:center;font-size:.76rem;color:var(--muted)}
  .langkah{display:flex;gap:12px;align-items:flex-start;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:11px 13px}
  .langkah b{display:block;font-size:.86rem}
  .langkah span{font-size:.79rem;color:var(--muted)}
  .langkah i{width:26px;height:26px;border-radius:9px;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:.78rem;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent2));flex:0 0 auto}
</style>
</head>
<body>
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="daftar-wrap">
  <div class="daftar-inner">

    <div class="daftar-head">
      <div class="daftar-logo"><?= ikon('school', 26) ?></div>
      <div>
        <h1>Pendaftaran Sekolah/Madrasah</h1>
        <p><?= e(APP_NAME) ?> &middot; sistem informasi supervisi akademik guru</p>
      </div>
    </div>

    <?php if ($berhasil): ?>
      <div class="card">
        <div class="empty" style="padding:28px 18px">
          <?= ikon('check', 40) ?>
          <h3>Pendaftaran terkirim</h3>
          <p>Terima kasih. Pendaftaran sekolah/madrasah <b><?= e($form['nama']) ?></b> sudah kami terima dan
            sekarang <b>menunggu persetujuan Admin</b>.</p>
        </div>

        <div class="grid g2" style="gap:14px">
          <div class="langkah">
            <i>1</i>
            <div>
              <b>Data sedang diperiksa</b>
              <span>Admin memeriksa kelengkapan data sekolah/madrasah Anda.</span>
            </div>
          </div>
          <div class="langkah">
            <i>2</i>
            <div>
              <b>Setelah disetujui, langsung bisa masuk</b>
              <span>Gunakan akun di bawah ini pada halaman masuk. Aplikasi sudah siap dengan 4 menu supervisi.</span>
            </div>
          </div>
        </div>

        <div class="divider"></div>
        <div class="grid g2" style="gap:14px">
          <div><label class="f">Nama Pengguna</label><div class="b"><?= e($form['admin_username']) ?></div></div>
          <div><label class="f">Kata Sandi</label><div class="b">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; <span class="xs mut">(yang Anda isi tadi)</span></div></div>
        </div>
        <p class="hint">Simpan nama pengguna dan kata sandi Anda. Bila lupa, minta Admin mengatur ulang.</p>

        <div class="divider"></div>
        <p class="sm mut" style="margin:0">Bila dalam beberapa hari belum ada kabar, hubungi Admin
          <?= e(APP_NAME) ?><?= APP_AUTHOR !== '' ? ' (dikembangkan oleh ' . e(APP_AUTHOR) . ')' : '' ?>.</p>
      </div>

      <div class="flex mt16" style="justify-content:center">
        <a class="btn btn-primary" href="login.php"><?= ikon('key', 16) ?> Ke Halaman Masuk</a>
      </div>
    <?php else: ?>
      <?php if ($sudahMasuk): ?>
        <div class="alert alert-ok">
          <?= ikon('check', 16) ?>
          <span>Anda sedang masuk sebagai <b><?= e((string) ($siapa['nama'] ?? '')) ?></b>.
            <a href="index.php">Buka aplikasi</a> atau lanjutkan mengisi formulir ini.</span>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="alert alert-err">
          <?= ikon('star', 16) ?>
          <span><b>Periksa kembali isian Anda:</b><br><?php foreach ($err as $m): ?>&bull; <?= e($m) ?><br><?php endforeach; ?></span>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-head">
          <div>
            <h2>Formulir Pendaftaran</h2>
            <p>Isi data sekolah/madrasah dan akun admin yang akan mengelola supervisi. Aplikasi aktif setelah disetujui Admin.</p>
          </div>
          <span class="chip">Gratis melalui persetujuan</span>
        </div>

        <div class="grid g3 mb16" style="gap:12px">
          <div class="langkah"><i>1</i><div><b>Isi formulir</b><span>Data sekolah/madrasah + akun admin</span></div></div>
          <div class="langkah"><i>2</i><div><b>Diperiksa Admin</b><span>Persetujuan penggunaan</span></div></div>
          <div class="langkah"><i>3</i><div><b>Masuk &amp; mulai supervisi</b><span>Menu &amp; butir penilaian sudah disiapkan</span></div></div>
        </div>

        <form method="post" class="grid" style="gap:18px" autocomplete="off">
          <?= csrf_field() ?>

          <div>
            <div class="section-title mb8"><?= ikon('school', 14) ?> Data Sekolah/Madrasah</div>
            <div class="grid g2">
              <div>
                <label class="f" for="nama">Nama Sekolah/Madrasah *</label>
                <input type="text" id="nama" name="nama" value="<?= e($form['nama']) ?>" required placeholder="mis. MA. Sirojuth Tholibiin Mootilango">
              </div>
              <div>
                <label class="f" for="npsn">NPSN</label>
                <input type="text" id="npsn" name="npsn" value="<?= e($form['npsn']) ?>" placeholder="8 digit">
              </div>
              <div style="grid-column:1/-1">
                <label class="f" for="alamat">Alamat Sekolah/Madrasah</label>
                <input type="text" id="alamat" name="alamat" value="<?= e($form['alamat']) ?>" placeholder="Jl. ... , Desa ..., Kec. ..., Kab. ...">
              </div>
              <div>
                <label class="f" for="kepala_sekolah">Kepala Sekolah/Madrasah</label>
                <input type="text" id="kepala_sekolah" name="kepala_sekolah" value="<?= e($form['kepala_sekolah']) ?>" placeholder="nama &amp; gelar">
              </div>
              <div>
                <label class="f" for="nip_kepala_sekolah">NIP Kepala Sekolah/Madrasah</label>
                <input type="text" id="nip_kepala_sekolah" name="nip_kepala_sekolah" value="<?= e($form['nip_kepala_sekolah']) ?>">
              </div>
              <div>
                <label class="f" for="tahun_ajaran">Tahun Ajaran</label>
                <input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e($form['tahun_ajaran']) ?>" placeholder="2026/2027">
              </div>
              <div>
                <label class="f" for="semester">Semester</label>
                <select id="semester" name="semester">
                  <option value="Ganjil" <?= $form['semester'] === 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                  <option value="Genap" <?= $form['semester'] === 'Genap' ? 'selected' : '' ?>>Genap</option>
                </select>
              </div>
            </div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div>
            <div class="section-title mb8"><?= ikon('user', 14) ?> Penanggung Jawab / Kontak</div>
            <div class="grid g3">
              <div>
                <label class="f" for="kontak_nama">Nama Penanggung Jawab *</label>
                <input type="text" id="kontak_nama" name="kontak_nama" value="<?= e($form['kontak_nama']) ?>" required placeholder="mis. kepala sekolah/madrasah / operator">
              </div>
              <div>
                <label class="f" for="kontak_telepon">No. HP / WhatsApp</label>
                <input type="text" id="kontak_telepon" name="kontak_telepon" value="<?= e($form['kontak_telepon']) ?>" placeholder="08xxxxxxxxxx">
              </div>
              <div>
                <label class="f" for="kontak_email">Email</label>
                <input type="text" id="kontak_email" name="kontak_email" value="<?= e($form['kontak_email']) ?>" placeholder="nama@email.com">
              </div>
            </div>
            <div class="hint">Isi minimal salah satu (HP/WhatsApp atau email) agar Admin dapat menghubungi Anda.</div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div>
            <div class="section-title mb8"><?= ikon('key', 14) ?> Akun Admin Sekolah/Madrasah</div>
            <div class="grid g2">
              <div>
                <label class="f" for="admin_nama">Nama Admin *</label>
                <input type="text" id="admin_nama" name="admin_nama" value="<?= e($form['admin_nama']) ?>" required placeholder="nama kepala sekolah/madrasah / pengawas / operator">
              </div>
              <div>
                <label class="f" for="admin_username">Nama Pengguna *</label>
                <input type="text" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" required placeholder="huruf kecil/angka, tanpa spasi">
                <div class="hint">Dipakai untuk masuk. Harus belum dipakai akun lain.</div>
              </div>
              <div>
                <label class="f" for="password">Kata Sandi *</label>
                <input type="password" id="password" name="password" required placeholder="minimal 8 karakter">
              </div>
              <div>
                <label class="f" for="password2">Ulangi Kata Sandi *</label>
                <input type="password" id="password2" name="password2" required>
              </div>
            </div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div class="grid g2" style="gap:14px;align-items:end">
            <div>
              <label class="f" for="captcha">Pertanyaan Pengaman *</label>
              <div class="flex" style="gap:10px;align-items:center">
                <span class="chip" id="soalCaptcha" style="font-weight:800"><?= (int) $ca ?> + <?= (int) $cb ?> = ?</span>
                <input type="text" id="captcha" name="captcha" required inputmode="numeric" placeholder="jawaban" style="max-width:150px">
              </div>
            </div>
            <div>
              <label class="f" style="display:flex;gap:9px;align-items:flex-start;font-weight:600">
                <input type="checkbox" name="setuju" value="1" style="width:16px;height:16px;margin-top:2px;accent-color:var(--accent)" required>
                <span>Saya menyatakan data yang diisi benar dan menyetujui aplikasi ini digunakan untuk supervisi sekolah/madrasah kami.</span>
              </label>
            </div>
          </div>

          <div class="flex">
            <button class="btn btn-primary" type="submit"><?= ikon('check', 16) ?> Kirim Pendaftaran</button>
            <a class="btn btn-ghost" href="login.php">Saya sudah punya akun</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="daftar-foot">
      <div><?= e(APP_NAME) ?> Versi <?= e(APP_VERSION) ?></div>
      <div class="mt8" style="font-weight:600;color:var(--ink)">Dikembangkan oleh <?= e(APP_AUTHOR) ?> (<?= e(APP_AUTHOR_JABATAN) ?>)</div>
    </div>
  </div>
</div>
</body>
</html>
