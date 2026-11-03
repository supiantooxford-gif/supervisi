<?php
/**
 * HALAMAN DEPAN (LANDING PAGE) + PANEL MASUK.
 *
 * Permintaan pemilik: "Buat halaman depan aplikasi menjadi tampilan web landing page yang
 * professional dan menarik yang menampilkan beranda, fitur aplikasi, keunggulan, testimoni,
 * tentang aplikasi, kontak. panel login tetap dipertahankan. Untuk mengisi halaman landing page
 * di isi lewat akun super admin."
 *
 * Isi halaman (beranda, judul tiap bagian, statistik, kontak, daftar fitur/keunggulan/testimoni)
 * diambil dari basis data dan DIISI lewat menu Super Admin "Halaman Depan (Landing)"
 * (berkas `landing.php`). Nilai bawaan ada di `inc/landing.php`.
 * Blok "Tentang Aplikasi" (dua foto 3x4 + nama + keterangan) tetap memakai menu
 * "Tentang Aplikasi (Halaman Masuk)" (`tentang.php`) — satu sumber data, tidak diduplikasi.
 *
 * Halaman ini juga tetap menjadi tempat MASUK (login) dan menerima PESAN KONTAK dari pengunjung
 * (masuk ke akun Super Admin, tab "Pesan Masuk" pada menu Halaman Depan (Landing)).
 *
 * ISTILAH: halaman ini bersifat PUBLIK, jadi seluruh tulisan yang tampil memakai
 * "sekolah/madrasah" — halaman ini melayani sekolah umum maupun madrasah sekaligus.
 * Catatan: teks $keteranganGagal (disimpan pada Log Aktivitas, bukan tampilan halaman ini) sengaja
 * tetap memakai istilah pendek "sekolah …" agar riwayat log & uji log tetap konsisten.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

if (current_user()) {
    redirect('index.php');
}

$err = '';
$user = '';
$pesanGalat = [];
$nilaiPesan = ['nama' => '', 'kontak' => '', 'pesan' => ''];

// Pengaman sederhana formulir kontak (pengunjung tanpa akun).
if (empty($_SESSION['landing_captcha'])) {
    $_SESSION['landing_captcha'] = ['a' => random_int(2, 9), 'b' => random_int(2, 9)];
}
$cap = $_SESSION['landing_captcha'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    // ------------------------------------------------------------------ kirim pesan kontak
    if ($aksi === 'pesan') {
        $nilaiPesan['nama']   = landing_potong((string) ($_POST['nama'] ?? ''), LANDING_NAMA_MAKS);
        $nilaiPesan['kontak'] = landing_potong((string) ($_POST['kontak'] ?? ''), 120);
        $nilaiPesan['pesan']  = landing_potong((string) ($_POST['pesan'] ?? ''), LANDING_PESAN_MAKS);
        $jawab = trim((string) ($_POST['captcha'] ?? ''));

        if ((int) ($_SESSION['landing_pesan_jumlah'] ?? 0) >= LANDING_MAKS_SESI) {
            $pesanGalat[] = 'Pengiriman pesan dari sesi ini sudah mencapai batas (' . LANDING_MAKS_SESI . ' kali).';
        }
        if ($nilaiPesan['nama'] === '') { $pesanGalat[] = 'Nama wajib diisi.'; }
        if ($nilaiPesan['kontak'] === '') { $pesanGalat[] = 'Email atau nomor WhatsApp wajib diisi.'; }
        if (strlen($nilaiPesan['pesan']) < 5) { $pesanGalat[] = 'Tuliskan pesan Anda (minimal 5 karakter).'; }
        if ($jawab === '' || (int) $jawab !== ((int) $cap['a'] + (int) $cap['b'])) {
            $pesanGalat[] = 'Jawaban pengaman (captcha) salah.';
        }

        if (!$pesanGalat) {
            try {
                q('INSERT INTO landing_pesan (nama, kontak, pesan, ip, dibaca, dibuat) VALUES (?,?,?,?,0,?)', [
                    $nilaiPesan['nama'], $nilaiPesan['kontak'], $nilaiPesan['pesan'],
                    log_ip(), date('Y-m-d H:i:s'),
                ]);
                $_SESSION['landing_pesan_jumlah'] = (int) ($_SESSION['landing_pesan_jumlah'] ?? 0) + 1;
                unset($_SESSION['landing_captcha']);
                flash('Terima kasih, ' . $nilaiPesan['nama'] . '. Pesan Anda sudah terkirim dan akan dibalas Admin aplikasi.');
                redirect('login.php?terkirim=1#kontak');
            } catch (Throwable $ex) {
                $pesanGalat[] = 'Pesan gagal disimpan. Coba lagi sebentar lagi.';
            }
        }
        if ($pesanGalat) {
            $_SESSION['landing_captcha'] = ['a' => random_int(2, 9), 'b' => random_int(2, 9)];
            $cap = $_SESSION['landing_captcha'];
        }
    } else {
        // -------------------------------------------------------------- masuk aplikasi
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
                $err = 'Akun ini belum ditautkan ke sekolah/madrasah mana pun. Hubungi Super Admin.';
                $keteranganGagal = 'akun belum ditautkan ke sekolah';
            } elseif ($sekolahUser && ($sekolahUser['verifikasi'] ?? 'disetujui') === 'menunggu') {
                $err = 'Pendaftaran sekolah/madrasah ' . $sekolahUser['nama'] . ' masih MENUNGGU PERSETUJUAN Super Admin. '
                     . 'Anda dapat masuk setelah pendaftaran disetujui.';
                $keteranganGagal = 'sekolah masih menunggu persetujuan';
            } elseif ($sekolahUser && ($sekolahUser['verifikasi'] ?? 'disetujui') === 'ditolak') {
                $err = 'Pendaftaran sekolah/madrasah ' . $sekolahUser['nama'] . ' belum disetujui'
                     . (!empty($sekolahUser['alasan_tolak']) ? ': ' . $sekolahUser['alasan_tolak'] : '.')
                     . ' Silakan hubungi Super Admin.';
                $keteranganGagal = 'pendaftaran sekolah ditolak';
            } elseif ($sekolahUser && (int) $sekolahUser['aktif'] !== 1) {
                $err = 'Sekolah/Madrasah ' . $sekolahUser['nama'] . ' sedang dinonaktifkan. Hubungi Super Admin.';
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
}

/**
 * Isi halaman depan. Seluruh pembacaan basis data dibungkus try/catch supaya halaman masuk
 * tetap tampil walau basis data belum siap (halaman ini publik).
 */
try {
    $slider    = landing_list('slider');
    $fitur     = landing_list('fitur');
    $unggulan  = landing_list('unggulan');
    $testimoni = landing_list('testimoni');
    $statistik = landing_stat_list();
    $waTautan  = landing_wa_tautan('Assalamualaikum, saya ingin bertanya tentang aplikasi ' . APP_NAME . '.');
    $tentang   = tentang_isi();
} catch (Throwable $e) {
    $slider = $fitur = $unggulan = $testimoni = $statistik = [];
    $waTautan = '';
    $tentang = [
        'slot'        => [1 => ['foto' => '', 'nama' => ''], 2 => ['foto' => '', 'nama' => '']],
        'keterangan'  => '',
        'ada'         => false,
        'jumlah_foto' => 0,
    ];
}

// Deskripsi halaman (meta description): keterangan slide pertama, atau keterangan "Tentang Aplikasi"
// bila slider belum diisi. Tidak pernah kosong supaya halaman publik tetap punya deskripsi.
$deskripsiHalaman = '';
if ($slider) {
    $deskripsiHalaman = trim((string) $slider[0]['keterangan']);
}
if ($deskripsiHalaman === '') {
    $deskripsiHalaman = trim((string) ($tentang['keterangan'] ?? ''));
}
if ($deskripsiHalaman === '') {
    $deskripsiHalaman = APP_NAME . ' - sistem informasi supervisi akademik guru untuk sekolah/madrasah.';
}

$flashes = flash_take();
$menuLanding = [
    ['#beranda', 'Beranda'],
    ['#fitur', 'Fitur Aplikasi'],
    ['#keunggulan', 'Keunggulan'],
    ['#testimoni', 'Testimoni'],
    ['#tentang', 'Tentang Aplikasi'],
    ['#kontak', 'Kontak'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk &middot; <?= e(APP_NAME) ?> &middot; Sistem Informasi Supervisi Akademik Guru</title>
<meta name="description" content="<?= e($deskripsiHalaman) ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127891;</text></svg>">
</head>
<body class="lp <?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<!-- ================================================ BILAH NAVIGASI -->
<header class="lp-nav" id="lpNav">
  <div class="lp-wrap lp-nav-isi">
    <a class="lp-brand" href="#beranda">
      <span class="lp-logo"><?= ikon('school', 26) ?></span>
      <span class="lp-brand-teks">
        <b><?= e(APP_NAME) ?></b>
        <small>Sistem informasi supervisi akademik guru</small>
      </span>
    </a>
    <button class="lp-nav-toggle" type="button" id="lpNavToggle" aria-label="Buka menu"><?= ikon('slider', 20) ?></button>
    <nav class="lp-nav-link" id="lpNavLink">
      <?php foreach ($menuLanding as $m): ?>
        <a href="<?= e($m[0]) ?>"><?= e($m[1]) ?></a>
      <?php endforeach; ?>
      <a class="btn btn-primary btn-sm" href="#masuk"><?= ikon('lock', 15) ?> Masuk</a>
    </nav>
  </div>
</header>

<main>
  <!-- ================================================ BERANDA -->
  <section class="lp-hero" id="beranda">
    <div class="lp-wrap lp-hero-grid<?= $slider ? '' : ' lp-hero-solo' ?>">
      <div class="lp-hero-teks">
        <?php if (trim(landing_teks('label')) !== ''): ?>
          <span class="lp-label"><?= e(landing_teks('label')) ?></span>
        <?php endif; ?>

        <?php
        // SLIDER BERANDA (permintaan pemilik, v1.17.0): area judul/pengantar/deskripsi/tombol yang
        // lama DIGANTI slider foto/gambar berisi Judul Slider + foto + keterangan.
        // Isinya dikelola Super Admin pada menu "Halaman Depan (Landing)" → tab "Slider Beranda".
        // Tanpa JavaScript pun tetap bekerja: daftar slide memakai overflow-x + scroll-snap.
        ?>
        <?php if ($slider): ?>
          <div class="lp-slider" id="lpSlider" data-otomatis="6000">
            <div class="lp-slider-track">
              <?php foreach ($slider as $i => $sl): ?>
                <?php $gambar = landing_slider_gambar($sl); ?>
                <figure class="lp-slide">
                  <div class="lp-slide-gambar">
                    <?php if ($gambar !== ''): ?>
                      <img src="<?= e($gambar) ?>" alt="<?= e(trim((string) $sl['judul']) !== '' ? (string) $sl['judul'] : 'Gambar slider ' . ($i + 1)) ?>"<?= $i === 0 ? '' : ' loading="lazy"' ?>>
                    <?php else: ?>
                      <span class="lp-slide-kosong">
                        <?= ikon('school', 46) ?>
                        <small>Foto slider belum diunggah</small>
                      </span>
                    <?php endif; ?>
                  </div>
                  <figcaption class="lp-slide-teks">
                    <?php if (trim((string) $sl['judul']) !== ''): ?>
                      <?php if ($i === 0): ?>
                        <h1 class="lp-slide-judul"><?= e((string) $sl['judul']) ?></h1>
                      <?php else: ?>
                        <h2 class="lp-slide-judul"><?= e((string) $sl['judul']) ?></h2>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if (trim((string) $sl['keterangan']) !== ''): ?>
                      <p class="lp-slide-ket"><?= nl2br(e((string) $sl['keterangan'])) ?></p>
                    <?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>

            <?php if (count($slider) > 1): ?>
              <div class="lp-slider-nav" id="lpSliderNav">
                <button type="button" class="lp-slider-panah" data-arah="-1" aria-label="Slide sebelumnya">&lsaquo;</button>
                <span class="lp-slider-titik" id="lpSliderTitik"></span>
                <button type="button" class="lp-slider-panah" data-arah="1" aria-label="Slide berikutnya">&rsaquo;</button>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ------------------------------- PANEL MASUK (tetap dipertahankan) -->
      <div class="lp-hero-side">
        <div class="card lp-login portal-login" id="masuk">
          <div class="card-head">
            <div>
              <h2><?= ikon('lock', 16) ?> Masuk Aplikasi</h2>
              <p>Gunakan akun yang diberikan pengelola sekolah/madrasah Anda.</p>
            </div>
          </div>

          <?php if ($err !== ''): ?>
            <div class="alert alert-err"><?= ikon('alert', 16) ?><span><?= e($err) ?></span></div>
          <?php endif; ?>
          <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= $f['tipe'] === 'ok' ? 'ok' : ($f['tipe'] === 'warn' ? 'warn' : 'err') ?>">
              <span><?= e($f['pesan']) ?></span>
            </div>
          <?php endforeach; ?>

          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div>
              <label class="f" for="username">Nama Pengguna</label>
              <input type="text" id="username" name="username" value="<?= e($user) ?>" required autofocus
                     placeholder="nama pengguna akun Anda">
            </div>
            <div class="mt16">
              <label class="f" for="password">Kata Sandi</label>
              <input type="password" id="password" name="password" required>
            </div>
            <button class="btn btn-primary full mt16" type="submit"><?= ikon('lock', 16) ?> Masuk</button>
          </form>

          <div class="divider"></div>
          <p class="sm mut" style="margin:0 0 10px">
            Sekolah/Madrasah Anda belum terdaftar? Ajukan pendaftaran, lalu aplikasi dapat dipakai setelah
            pendaftaran disetujui Admin.
          </p>
          <a class="btn btn-ghost full" href="daftar.php"><?= ikon('school', 16) ?> Daftarkan Sekolah/Madrasah</a>

          <div class="divider"></div>
          <div class="portal-bantuan">
            <b>Butuh bantuan?</b>
            <span>Lupa kata sandi, akun belum bisa masuk, atau pendaftaran belum disetujui — hubungi
              administrator <?= e(APP_NAME) ?> atau pengelola sekolah/madrasah Anda.</span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($statistik): ?>
      <div class="lp-wrap">
        <div class="lp-stat">
          <?php foreach ($statistik as $st): ?>
            <div class="lp-stat-item">
              <?php if ($st['angka'] !== ''): ?><b><?= e($st['angka']) ?></b><?php endif; ?>
              <?php if ($st['label'] !== ''): ?><span><?= e($st['label']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- ================================================ FITUR APLIKASI -->
  <?php if ($fitur): ?>
    <section class="lp-seksi" id="fitur">
      <div class="lp-wrap">
        <div class="lp-seksi-head">
          <h2><?= e(landing_teks('fitur_judul')) ?></h2>
          <?php if (trim(landing_teks('fitur_sub')) !== ''): ?><p><?= e(landing_teks('fitur_sub')) ?></p><?php endif; ?>
        </div>
        <div class="lp-grid lp-grid-3">
          <?php foreach ($fitur as $f): ?>
            <article class="lp-kartu lp-timbul">
              <span class="lp-ikon"><?= landing_ikon(landing_ikon_sah((string) $f['ikon']), 24) ?></span>
              <h3><?= e((string) $f['judul']) ?></h3>
              <?php if (trim((string) $f['teks']) !== ''): ?><p><?= nl2br(e((string) $f['teks'])) ?></p><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================================================ KEUNGGULAN -->
  <?php if ($unggulan): ?>
    <section class="lp-seksi lp-alt" id="keunggulan">
      <div class="lp-wrap">
        <div class="lp-seksi-head">
          <h2><?= e(landing_teks('unggulan_judul')) ?></h2>
          <?php if (trim(landing_teks('unggulan_sub')) !== ''): ?><p><?= e(landing_teks('unggulan_sub')) ?></p><?php endif; ?>
        </div>
        <div class="lp-grid lp-grid-3">
          <?php foreach ($unggulan as $f): ?>
            <article class="lp-kartu lp-timbul lp-unggulan">
              <span class="lp-ikon lp-ikon-emas"><?= landing_ikon(landing_ikon_sah((string) $f['ikon']), 24) ?></span>
              <h3><?= e((string) $f['judul']) ?></h3>
              <?php if (trim((string) $f['teks']) !== ''): ?><p><?= nl2br(e((string) $f['teks'])) ?></p><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================================================ TESTIMONI -->
  <?php if ($testimoni): ?>
    <section class="lp-seksi" id="testimoni">
      <div class="lp-wrap">
        <div class="lp-seksi-head">
          <?php if (trim(landing_teks('testimoni_judul')) !== ''): ?>
            <span class="lp-label"><?= e(landing_teks('testimoni_judul')) ?></span>
          <?php endif; ?>
          <?php if (trim(landing_teks('testimoni_judul_besar')) !== ''): ?>
            <h2><?= e(landing_teks('testimoni_judul_besar')) ?></h2>
          <?php endif; ?>
          <?php if (trim(landing_teks('testimoni_sub')) !== ''): ?><p><?= e(landing_teks('testimoni_sub')) ?></p><?php endif; ?>
        </div>

        <div class="lp-testi-slider" id="testiSlider" data-otomatis="6000">
          <div class="lp-testi-track">
            <?php foreach ($testimoni as $t): ?>
              <figure class="lp-testi-slide">
                <span class="lp-kutip">&ldquo;</span>
                <?php if (trim((string) $t['isi']) !== ''): ?>
                  <blockquote><?= nl2br(e((string) $t['isi'])) ?></blockquote>
                <?php endif; ?>
                <figcaption class="lp-testi-sumber">
                  <?php $foto = trim((string) ($t['foto'] ?? '')); ?>
                  <?php if ($foto !== ''): ?>
                    <img class="lp-testi-foto" src="<?= e($foto) ?>" alt="Foto <?= e((string) $t['nama']) ?>">
                  <?php else: ?>
                    <span class="lp-testi-foto lp-testi-inisial"><?= e(strtoupper(substr(trim((string) $t['nama']), 0, 1))) ?></span>
                  <?php endif; ?>
                  <span class="lp-testi-teks">
                    <b><?= e((string) $t['nama']) ?></b>
                    <?php if (trim((string) $t['jabatan']) !== ''): ?><small><?= e((string) $t['jabatan']) ?></small><?php endif; ?>
                  </span>
                </figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (count($testimoni) > 1): ?>
          <div class="lp-testi-nav" id="testiNav">
            <button type="button" class="lp-testi-panah" data-arah="-1" aria-label="Testimoni sebelumnya">&lsaquo;</button>
            <span class="lp-testi-titik" id="testiTitik"></span>
            <button type="button" class="lp-testi-panah" data-arah="1" aria-label="Testimoni berikutnya">&rsaquo;</button>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ================================================ TENTANG APLIKASI -->
  <section class="lp-seksi lp-alt" id="tentang">
    <div class="lp-wrap">
      <div class="lp-seksi-head">
        <h2><?= e(landing_teks('tentang_judul')) ?></h2>
        <?php if (trim(landing_teks('tentang_sub')) !== ''): ?><p><?= e(landing_teks('tentang_sub')) ?></p><?php endif; ?>
      </div>
      <div class="card lp-tentang-kartu">
        <?php if (!empty($tentang['ada'])): ?>
          <div class="portal-tentang" id="tentangAplikasi" style="margin:0;padding-bottom:16px">
            <div class="tentang-baris">
              <?php if ($tentang['jumlah_foto'] > 0 || $tentang['slot'][1]['nama'] !== '' || $tentang['slot'][2]['nama'] !== ''): ?>
                <div class="tentang-foto-grid">
                  <?php foreach ([1, 2] as $n): ?>
                    <?php $sl = $tentang['slot'][$n]; ?>
                    <?php if ($sl['foto'] === '' && $sl['nama'] === '') { continue; } ?>
                    <figure class="tentang-slot">
                      <div class="tentang-bingkai">
                        <?php if ($sl['foto'] !== ''): ?>
                          <img src="<?= e($sl['foto']) ?>" alt="<?= e($sl['nama'] !== '' ? $sl['nama'] : 'Foto ' . $n) ?>" loading="lazy">
                        <?php else: ?>
                          <span class="tentang-kosong"><?= ikon('user', 26) ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ($sl['nama'] !== ''): ?>
                        <figcaption class="tentang-nama"><?= e($sl['nama']) ?></figcaption>
                      <?php endif; ?>
                    </figure>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($tentang['keterangan'] !== ''): ?>
                <p class="tentang-ket"><?= nl2br(e($tentang['keterangan'])) ?></p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ================================================ KONTAK -->
  <section class="lp-seksi" id="kontak">
    <div class="lp-wrap">
      <div class="lp-seksi-head">
        <h2><?= e(landing_teks('kontak_judul')) ?></h2>
        <?php if (trim(landing_teks('kontak_sub')) !== ''): ?><p><?= e(landing_teks('kontak_sub')) ?></p><?php endif; ?>
      </div>
      <div class="lp-grid lp-kontak-grid">
        <div class="card lp-kontak">
          <div class="card-head">
            <div>
              <h3><?= ikon('telepon', 16) ?> Informasi Kontak</h3>
              <p>Alamat, telepon, dan jam layanan Admin aplikasi.</p>
            </div>
          </div>
          <?php if (trim(landing_teks('kontak_ajakan')) !== ''): ?>
            <p><?= nl2br(e(landing_teks('kontak_ajakan'))) ?></p>
          <?php endif; ?>
          <ul class="lp-kontak-list">
            <?php if (trim(landing_teks('kontak_alamat')) !== ''): ?>
              <li><span class="lp-ikon lp-ikon-kecil"><?= landing_ikon('lokasi', 18) ?></span>
                <span><small>Alamat</small><?= nl2br(e(landing_teks('kontak_alamat'))) ?></span></li>
            <?php endif; ?>
            <?php if (trim(landing_teks('kontak_telepon')) !== ''): ?>
              <li><span class="lp-ikon lp-ikon-kecil"><?= landing_ikon('telepon', 18) ?></span>
                <span><small>Telepon / WhatsApp</small><?= e(landing_teks('kontak_telepon')) ?></span></li>
            <?php endif; ?>
            <?php if (trim(landing_teks('kontak_email')) !== ''): ?>
              <li><span class="lp-ikon lp-ikon-kecil"><?= landing_ikon('email', 18) ?></span>
                <span><small>Email</small><a href="mailto:<?= e(landing_teks('kontak_email')) ?>"><?= e(landing_teks('kontak_email')) ?></a></span></li>
            <?php endif; ?>
            <?php if (trim(landing_teks('kontak_jam')) !== ''): ?>
              <li><span class="lp-ikon lp-ikon-kecil"><?= landing_ikon('clock', 18) ?></span>
                <span><small>Jam Layanan</small><?= e(landing_teks('kontak_jam')) ?></span></li>
            <?php endif; ?>
          </ul>
          <div class="lp-kontak-aksi">
            <?php if ($waTautan !== ''): ?>
              <a class="btn btn-primary" href="<?= e($waTautan) ?>" target="_blank" rel="noopener noreferrer">
                <?= ikon('message', 16) ?> Hubungi lewat WhatsApp</a>
            <?php endif; ?>
            <?php if (trim(landing_teks('kontak_maps')) !== ''): ?>
              <a class="btn btn-ghost" href="<?= e(landing_teks('kontak_maps')) ?>" target="_blank" rel="noopener noreferrer">
                <?= landing_ikon('lokasi', 16) ?> Lihat Lokasi</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="daftar.php"><?= ikon('school', 16) ?> Daftarkan Sekolah/Madrasah</a>
          </div>
        </div>

        <div class="card lp-kontak" id="cardPesanKontak">
          <div class="card-head">
            <div>
              <h3><?= ikon('message', 16) ?> Kirim Pesan</h3>
              <p>Pesan Anda tercatat pada akun Admin aplikasi.</p>
            </div>
          </div>
          <?php if (!$pesanGalat && !empty($_GET['terkirim'])): ?>
            <div class="alert alert-ok"><?= ikon('check', 16) ?><span>Terima kasih, pesan Anda sudah terkirim.</span></div>
          <?php endif; ?>
          <?php if ($pesanGalat): ?>
            <div class="alert alert-err">
              <?= ikon('alert', 16) ?>
              <ul class="poin-list" style="margin:0">
                <?php foreach ($pesanGalat as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="pesan">
            <div>
              <label class="f" for="namaPesan">Nama</label>
              <input type="text" id="namaPesan" name="nama" value="<?= e($nilaiPesan['nama']) ?>"
                     maxlength="<?= (int) LANDING_NAMA_MAKS ?>" required placeholder="nama Anda">
            </div>
            <div class="mt16">
              <label class="f" for="kontakPesan">Email / No. WhatsApp</label>
              <input type="text" id="kontakPesan" name="kontak" value="<?= e($nilaiPesan['kontak']) ?>"
                     maxlength="120" required placeholder="agar Admin dapat membalas">
            </div>
            <div class="mt16">
              <label class="f" for="isiPesan">Pesan</label>
              <textarea id="isiPesan" name="pesan" rows="4" maxlength="<?= (int) LANDING_PESAN_MAKS ?>"
                        required placeholder="Tulis pertanyaan atau pesan Anda."><?= e($nilaiPesan['pesan']) ?></textarea>
            </div>
            <div class="mt16">
              <label class="f" for="captchaPesan">Pengaman: <?= (int) $cap['a'] ?> + <?= (int) $cap['b'] ?> = ?</label>
              <input type="text" id="captchaPesan" name="captcha" required placeholder="jawaban berupa angka">
            </div>
            <button class="btn btn-primary full mt16" type="submit"><?= ikon('send', 16) ?> Kirim Pesan</button>
          </form>
        </div>
      </div>
    </div>
  </section>
</main>

<!-- ================================================ KAKI HALAMAN -->
<?php
// Permintaan pemilik (v1.17.1): tulisan nama aplikasi + subjudul DAN deretan tautan bagian pada
// kaki halaman DIHAPUS — yang tersisa hanya baris versi + baris pengembang, diletakkan di TENGAH.
// Tautan bagian tetap tersedia pada bilah navigasi di atas halaman.
?>
<footer class="lp-foot portal-foot">
  <div class="lp-wrap lp-foot-isi">
    <div class="lp-foot-ket">
      <div><?= e(APP_NAME) ?> Versi <?= e(APP_VERSION) ?> &middot; Sistem Informasi Supervisi Akademik Guru</div>
      <div class="portal-pengembang">Dikembangkan oleh <?= e(APP_AUTHOR) ?> (<?= e(APP_AUTHOR_JABATAN) ?>)</div>
    </div>
  </div>
</footer>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
