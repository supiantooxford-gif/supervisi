<?php
/**
 * Halaman masuk Supervisi Guru — berbentuk PORTAL.
 *
 * Tata letak: kepala portal (nama aplikasi + tautan pendaftaran), lalu dua kolom —
 * kolom kiri berisi penjelasan aplikasi (tentang, menu supervisi yang BENAR-BENAR ada
 * di aplikasi ini, peran pengguna, cara mulai, dan keamanan), kolom kanan berisi formulir masuk.
 *
 * Halaman ini bersifat PUBLIK (belum ada konteks sekolah), jadi istilah yang dipakai tetap
 * generik "Sekolah". Angka statistik sekolah/guru/hasil supervisi sengaja TIDAK ditampilkan
 * (permintaan pemilik) — portal hanya menjelaskan fungsi aplikasi.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

if (current_user()) {
    redirect('index.php');
}

$err = '';
$user = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if ($user === '' || $pass === '') {
        $err = 'Nama pengguna dan kata sandi wajib diisi.';
    } else {
        $u = one('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$user]);
        $sekolahUser = ($u && !in_array($u['role'], ['super', 'pengawas'], true) && (int) ($u['sekolah_id'] ?? 0) > 0)
            ? one('SELECT * FROM sekolah WHERE id = ?', [(int) $u['sekolah_id']])
            : null;
        // Percobaan masuk yang gagal dicatat pada Log Aktivitas (memantau percobaan mencurigakan).
        $keteranganGagal = '';
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            $err = 'Nama pengguna atau kata sandi salah.';
            $keteranganGagal = $u ? 'kata sandi salah' : 'nama pengguna tidak terdaftar';
        } elseif ((int) $u['aktif'] !== 1) {
            $err = 'Akun ini sedang dinonaktifkan. Hubungi administrator.';
            $keteranganGagal = 'akun dinonaktifkan';
        } elseif (!in_array($u['role'], ['super', 'pengawas'], true) && !$sekolahUser) {
            $err = 'Akun ini belum ditautkan ke sekolah mana pun. Hubungi Super Admin.';
            $keteranganGagal = 'akun belum ditautkan ke sekolah';
        } elseif ($sekolahUser && ($sekolahUser['verifikasi'] ?? 'disetujui') === 'menunggu') {
            $err = 'Pendaftaran sekolah ' . $sekolahUser['nama'] . ' masih MENUNGGU PERSETUJUAN Super Admin. '
                 . 'Anda dapat masuk setelah pendaftaran disetujui.';
            $keteranganGagal = 'sekolah masih menunggu persetujuan';
        } elseif ($sekolahUser && ($sekolahUser['verifikasi'] ?? 'disetujui') === 'ditolak') {
            $err = 'Pendaftaran sekolah ' . $sekolahUser['nama'] . ' belum disetujui'
                 . (!empty($sekolahUser['alasan_tolak']) ? ': ' . $sekolahUser['alasan_tolak'] : '.')
                 . ' Silakan hubungi Super Admin.';
            $keteranganGagal = 'pendaftaran sekolah ditolak';
        } elseif ($sekolahUser && (int) $sekolahUser['aktif'] !== 1) {
            $err = 'Sekolah ' . $sekolahUser['nama'] . ' sedang dinonaktifkan. Hubungi Super Admin.';
            $keteranganGagal = 'sekolah dinonaktifkan';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            // Konteks sekolah dimulai bersih: admin/guru otomatis memakai sekolahnya,
            // super admin mulai dari tampilan lintas sekolah.
            unset($_SESSION['ctx_sekolah_id']);
            q('UPDATE users SET login_terakhir = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $u['id']]);
            // Catat sebagai pengguna online + simpan riwayat "masuk".
            $u['sekolah_nama'] = $sekolahUser ? (string) $sekolahUser['nama'] : '';
            log_sesi_mulai($u);
            $dest = $_SESSION['redirect_after'] ?? '';
            unset($_SESSION['redirect_after']);
            if (is_string($dest) && $dest !== '' && strpos($dest, '://') === false) {
                header('Location: ' . $dest);
                exit;
            }
            redirect('index.php');
        }
        if ($keteranganGagal !== '') {
            log_aktivitas_catat('gagal', $u, $user, $keteranganGagal);
            // Halaman login bersifat publik: batasi jumlah riwayat gagal agar tabelnya tidak membengkak.
            log_batasi_gagal();
        }
    }
}

/**
 * Menu supervisi yang benar-benar ada di aplikasi (menu GLOBAL, dikelola Super Admin).
 * Bila basis data belum siap, portal tetap tampil tanpa daftar menu (halaman masuk tidak boleh gagal).
 */
$menuSupervisi = [];
try {
    foreach (instrumen_list() as $m) {
        $menuSupervisi[] = [
            'nama'      => (string) $m['nama'],
            'deskripsi' => trim((string) $m['deskripsi']),
            'ikon'      => (string) ($m['ikon'] ?: 'doc'),
        ];
    }
} catch (Throwable $e) {
    $menuSupervisi = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127891;</text></svg>">
</head>
<body>
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="portal-wrap">
  <div class="portal-inner">

    <!-- ============================ KEPALA PORTAL ============================ -->
    <header class="portal-head">
      <div class="portal-brand">
        <div class="portal-logo"><?= ikon('school', 30) ?></div>
        <div>
          <h1><?= e(APP_NAME) ?> <span class="portal-tagline">Supervisi Akademik</span></h1>
          <p>Sistem informasi supervisi akademik guru — melayani banyak sekolah sekaligus</p>
        </div>
      </div>
      <div class="portal-head-kanan">
        <a class="btn btn-ghost btn-sm" href="daftar.php"><?= ikon('school', 15) ?> Daftarkan Sekolah</a>
      </div>
    </header>

    <div class="portal-grid">

      <!-- ============================ KOLOM KIRI: PENJELASAN ============================ -->
      <div class="portal-info">

        <section class="card portal-hero">
          <div class="section-title mb8"><?= ikon('star', 14) ?> Tentang Aplikasi</div>
          <p class="portal-lead">
            <b><?= e(APP_NAME) ?></b> adalah aplikasi <b>supervisi akademik guru</b> untuk sekolah dan madrasah.
            Penilaian dilakukan memakai butir penilaian yang tersusun dalam menu supervisi, setiap butir diberi
            skor 1-4, lalu <b>nilai akhir dan predikat dihitung otomatis</b> mengikuti ambang yang ditetapkan sekolah.
          </p>
          <p class="portal-lead">
            Hasilnya dirangkum pada laporan dan rekap (matriks guru &times; menu beserta grafik), dilengkapi
            <b>jadwal supervisi</b> dan <b>dokumen siap cetak</b>: lembar hasil supervisi, data guru, jadwal,
            serta daftar butir penilaian — semuanya memakai kop sekolah dan tanda tangan kepala sekolah.
          </p>
          <p class="portal-lead mut">
            Aplikasi ini <b>multi-sekolah</b>: satu pemasangan dapat dipakai banyak sekolah/madrasah sekaligus,
            dengan data setiap sekolah yang terpisah dan akun berjenjang sesuai tugas masing-masing.
          </p>
        </section>

        <section class="card">
          <div class="card-head">
            <div>
              <h2><?= ikon('check', 16) ?> Menu Supervisi</h2>
              <p>Tiap menu berisi butir penilaian beserta indikatornya. Menu berikut diambil langsung dari
                daftar menu yang aktif pada aplikasi ini.</p>
            </div>
            <?php if ($menuSupervisi): ?>
              <span class="chip"><?= count($menuSupervisi) ?> menu</span>
            <?php endif; ?>
          </div>

          <?php if ($menuSupervisi): ?>
            <div class="portal-menu">
              <?php foreach ($menuSupervisi as $m): ?>
                <div class="portal-menu-item">
                  <span class="portal-menu-ico"><?= ikon($m['ikon'], 16) ?></span>
                  <div>
                    <b><?= e($m['nama']) ?></b>
                    <span><?= $m['deskripsi'] !== '' ? e($m['deskripsi']) : 'Butir penilaian beserta indikatornya.' ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="sm mut" style="margin:0">Daftar menu belum tersedia. Setelah Super Admin menyiapkan
              menu supervisi, daftarnya akan tampil di bagian ini.</p>
          <?php endif; ?>

          <p class="hint mt16">
            Selain menu supervisi di atas, tersedia menu pendukung: <b>Laporan &amp; Rekap</b>,
            <b>Data Guru</b> (dapat diimpor dari Excel/CSV), <b>Jadwal Supervisi</b>, <b>Pengguna &amp; Akun</b>,
            serta <b>Profil &amp; Pengaturan</b> sekolah. Butir penilaian dikelola Super Admin sebagai
            daftar global sehingga dipakai bersama semua sekolah.
          </p>
        </section>

        <section class="card">
          <div class="card-head">
            <div><h2><?= ikon('shield', 16) ?> Cara Mulai &amp; Keamanan</h2>
              <p>Langkah singkat memakai aplikasi untuk sekolah/madrasah baru.</p></div>
          </div>
          <ol class="portal-alur">
            <li><b>Daftarkan sekolah/madrasah</b> melalui halaman pendaftaran — nama pengguna dan kata sandi
              pengelola dibuat pada saat mendaftar.</li>
            <li><b>Super Admin menyetujui</b> pendaftaran; setelah disetujui akun pengelola dapat langsung masuk.</li>
            <li><b>Lengkapi data</b> guru beserta akunnya, lalu susun jadwal supervisi.</li>
            <li><b>Lakukan penilaian</b> per guru pada setiap menu supervisi dan cetak dokumennya bila diperlukan.</li>
          </ol>
          <ul class="portal-cek">
            <li><b>Data setiap sekolah terpisah</b> — akun hanya melihat data sekolahnya sendiri.</li>
            <li><b>Pengawas</b> hanya dapat melihat dan mengunduh rekap sekolah binaannya.</li>
            <li><b>Kata sandi tersimpan teracak</b> dan setiap formulir dilindungi token keamanan (CSRF).</li>
            <li><b>Dokumen cetak</b> memakai kop sekolah serta nama kepala sekolah dari pengaturan sekolah.</li>
          </ul>
        </section>

      </div>

      <!-- ============================ KOLOM KANAN: FORM MASUK ============================ -->
      <div class="portal-side">
        <div class="card portal-login">
          <div class="card-head">
            <div>
              <h2><?= ikon('lock', 16) ?> Masuk Aplikasi</h2>
              <p>Gunakan akun yang diberikan pengelola sekolah Anda.</p>
            </div>
          </div>

          <?php if ($err !== ''): ?>
            <div class="alert alert-err"><?= ikon('alert', 16) ?><span><?= e($err) ?></span></div>
          <?php endif; ?>

          <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>
            <div>
              <label class="f" for="username">Nama Pengguna</label>
              <input type="text" id="username" name="username" value="<?= e($user) ?>" required autofocus
                     placeholder="nama pengguna akun Anda">
            </div>
            <div>
              <label class="f" for="password">Kata Sandi</label>
              <input type="password" id="password" name="password" required>
            </div>
            <button class="btn btn-primary full" type="submit"><?= ikon('lock', 16) ?> Masuk</button>
          </form>

          <div class="divider"></div>
          <p class="sm mut" style="margin:0 0 10px">
            Sekolah Anda belum terdaftar? Ajukan pendaftaran, lalu aplikasi dapat dipakai setelah
            pendaftaran disetujui Super Admin.
          </p>
          <a class="btn btn-ghost full" href="daftar.php"><?= ikon('school', 16) ?> Daftarkan Sekolah</a>

          <div class="divider"></div>
          <div class="portal-bantuan">
            <b>Butuh bantuan?</b>
            <span>Lupa kata sandi, akun belum bisa masuk, atau pendaftaran belum disetujui — hubungi
              administrator <?= e(APP_NAME) ?> atau pengelola sekolah Anda.</span>
          </div>
        </div>
      </div>

    </div>

    <footer class="portal-foot">
      <span><?= e(APP_NAME) ?> Versi <?= e(APP_VERSION) ?> &middot; Sistem Informasi Supervisi Akademik Guru</span>
      <span class="portal-pengembang">Dikembangkan oleh <?= e(APP_AUTHOR) ?> (<?= e(APP_AUTHOR_JABATAN) ?>)</span>
    </footer>

  </div>
</div>
</body>
</html>
