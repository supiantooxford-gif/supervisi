<?php
/**
 * Layout halaman (sidebar + header + footer), sadar konteks sekolah & peran.
 */
declare(strict_types=1);

function ikon(string $name, int $size = 18): string
{
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'doc'       => '<path d="M14 3v5h5"/><path d="M19 8.5V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z"/><path d="M9 13h6M9 17h4"/>',
        'eye'       => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"/><circle cx="12" cy="12" r="2.5"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'report'    => '<path d="M4 4h11l5 5v11H4z"/><path d="M8 14h8M8 17h5M8 11h3"/>',
        'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/><path d="M17 11a3 3 0 1 0 0-6"/><path d="M18 19.6c0-2.2-.8-3.8-2.2-4.8 3 .2 5.2 2.2 5.2 4.8"/>',
        'upload'    => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M17 6l2 2M15 8l2 2"/>',
        'slider'    => '<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="17" r="2"/>',
        'print'     => '<path d="M7 9V4h10v5"/><rect x="3" y="9" width="18" height="7" rx="2"/><path d="M7 16h10v4H7z"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'edit'      => '<path d="M4 20h4l10-10-4-4L4 16z"/><path d="M14 5l4 4"/>',
        'trash'     => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'logout'    => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M15 8l4 4-4 4M19 12H9"/>',
        'search'    => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
        'check'     => '<path d="M4 12.5l5 5L20 6.5"/>',
        'star'      => '<path d="M12 3l2.9 5.9 6.1.9-4.5 4.4 1.1 6.2L12 17.6 6.4 20.4l1.1-6.2L3 9.8l6.1-.9z"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5S20 17 20 21"/>',
        'school'    => '<path d="M12 3l9 4.5-9 4.5L3 7.5z"/><path d="M6 10v6c0 1.7 2.7 3 6 3s6-1.3 6-3v-6"/>',
        'building'  => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/><path d="M10 21v-3h4v3"/>',
        'switch'    => '<path d="M7 4L3 8l4 4"/><path d="M3 8h13a4 4 0 0 1 0 8H4"/>',
        'filter'    => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'lock'      => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 4.4-3.2 7.9-8 9-4.8-1.1-8-4.6-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'alert'     => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 10h6M9 14h6M9 18h4"/>',
        'book'      => '<path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h8M8 11h6"/>',
        'calendar2' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/><path d="M8 15h4"/>',
    ];
    $d = $p[$name] ?? $p['doc'];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function page_top(string $judul, string $menu = ''): void
{
    // Seluruh keluaran halaman ditampung agar istilah "Sekolah" dapat disesuaikan
    // menjadi "Madrasah" (lihat terjemahkan_html() + terjemahkan_selesai()).
    ob_start();
    $GLOBALS['__vs_buf_level'] = ob_get_level();

    $u = current_user();
    $super = is_super();
    $pengawas = $u !== null && $u['role'] === 'pengawas';
    if ($pengawas) {
        // Seluruh tampilan/menu Pengawas memakai istilah "Sekolah/Madrasah"
        // (pengawas membina sekolah umum dan madrasah). Dokumen cetak tidak terpengaruh
        // karena halaman cetak tidak memakai layout ini.
        $GLOBALS['__vs_istilah_pengawas'] = true;
    }
    $admin = is_admin();
    $sekolah = current_sekolah();
    $binaanSaya = $pengawas ? sekolah_binaan((int) $u['id'], false) : [];
    $inst = $sekolah ? instrumen_list() : [];
    $flashes = flash_take();
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $here = basename($script);
    $kodeAktif = $_GET['kode'] ?? '';
    $semuaSekolah = $super && (isset($_GET['semua']) || !$sekolah);
    if ($sekolah) {
        $subJudul = tetap_kata((string) $sekolah['nama']) . ' &middot; Tahun Ajaran ' . ($sekolah['tahun_ajaran'] ?: '-') . ' &middot; Semester ' . ($sekolah['semester'] ?: '-');
    } elseif ($pengawas) {
        $subJudul = 'Pengawas Sekolah &middot; ' . count($binaanSaya) . ' sekolah binaan';
    } elseif ($super) {
        $subJudul = 'Super Admin &middot; ' . jumlah_sekolah() . ' sekolah terdaftar';
    } else {
        $subJudul = '';
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127891;</text></svg>">
</head>
<body>
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<button class="nav-toggle" id="navToggle" aria-label="Buka menu"><?= ikon('slider', 20) ?></button>
<div class="scrim" id="scrim"></div>

<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-logo"><?= ikon($sekolah ? 'school' : 'building', 22) ?></div>
    <div>
      <div class="brand-name"><?= e(APP_NAME) ?></div>
      <div class="brand-sub"><?= e($sekolah ? tetap_kata((string) $sekolah['nama']) : ($pengawas ? 'Pengawas Sekolah' : 'Super Admin — semua sekolah')) ?></div>
    </div>
  </div>

  <nav class="nav">
    <?php if ($super): ?>
      <div class="nav-sec">Super Admin</div>
      <a class="nav-item <?= $here === 'index.php' && $semuaSekolah ? 'on' : '' ?>" href="index.php">
        <?= ikon('chart') ?><span>Ringkasan Semua Sekolah</span>
      </a>
      <a class="nav-item <?= $here === 'sekolah.php' ? 'on' : '' ?>" href="sekolah.php">
        <?= ikon('building') ?><span>Kelola Sekolah</span>
      </a>
      <a class="nav-item <?= $here === 'pengawas.php' ? 'on' : '' ?>" href="pengawas.php">
        <?= ikon('eye') ?><span>Akun Pengawas</span>
      </a>
      <?php $jmlMenunggu = jumlah_pendaftaran_menunggu(); ?>
      <a class="nav-item <?= $here === 'pendaftaran.php' ? 'on' : '' ?>" href="pendaftaran.php">
        <?= ikon('star') ?><span>Pendaftaran Sekolah<?= $jmlMenunggu > 0 ? ' (' . (int) $jmlMenunggu . ')' : '' ?></span>
        <?php if ($jmlMenunggu > 0): ?><span class="pill pill-warn" style="margin-left:auto">baru</span><?php endif; ?>
      </a>
      <a class="nav-item <?= in_array($here, ['instrumen.php', 'instrumen_butir.php'], true) ? 'on' : '' ?>" href="instrumen.php">
        <?= ikon('check') ?><span>Butir Penilaian (Global)</span>
      </a>
      <?php $jmlOnline = log_jumlah_online(); ?>
      <a class="nav-item <?= in_array($here, ['log.php'], true) ? 'on' : '' ?>" href="log.php">
        <?= ikon('eye') ?><span>Log Aktivitas</span>
        <?php if ($jmlOnline > 0): ?><span class="pill pill-ok" style="margin-left:auto" title="Pengguna yang sedang online"><?= (int) $jmlOnline ?> online</span><?php endif; ?>
      </a>
    <?php endif; ?>

    <?php if ($pengawas): ?>
      <div class="nav-sec">Rekap Supervisi</div>
      <a class="nav-item <?= $here === 'index.php' && !$sekolah ? 'on' : '' ?>" href="index.php">
        <?= ikon('building') ?><span>Sekolah Binaan (<?= count($binaanSaya) ?>)</span>
      </a>
      <?php if ($sekolah): ?>
        <a class="nav-item <?= in_array($here, ['laporan.php', 'supervisi_detail.php', 'cetak.php'], true) ? 'on' : '' ?>" href="laporan.php">
          <?= ikon('report') ?><span>Rekap <?= e(istilah_sekolah('Sekolah')) ?> Ini</span>
        </a>
        <a class="nav-item <?= in_array($here, ['jadwal.php', 'cetak_jadwal.php'], true) ? 'on' : '' ?>" href="jadwal.php">
          <?= ikon('calendar') ?><span>Jadwal Supervisi</span>
        </a>
        <a class="nav-item" href="laporan_export.php">
          <?= ikon('doc') ?><span>Unduh Rekap (CSV)</span>
        </a>
      <?php endif; ?>
    <?php elseif ($sekolah && ($super || $u['role'] === 'admin')): ?>
      <div class="nav-sec"><?= $super ? 'Sekolah yang Dikelola' : 'Menu Supervisi' ?></div>
      <?php if ($super): ?>
        <a class="nav-item <?= $here === 'index.php' && !$semuaSekolah ? 'on' : '' ?>" href="index.php?sekolah=1">
          <?= ikon('dashboard') ?><span>Dashboard Sekolah Ini</span>
        </a>
      <?php else: ?>
        <a class="nav-item <?= $here === 'index.php' ? 'on' : '' ?>" href="index.php">
          <?= ikon('dashboard') ?><span>Dashboard</span>
        </a>
      <?php endif; ?>

      <?php foreach ($inst as $i): ?>
        <a class="nav-item <?= ($here === 'supervisi.php' || $here === 'supervisi_form.php') && $kodeAktif === $i['kode'] ? 'on' : '' ?>"
           href="supervisi.php?kode=<?= e((string) $i['kode']) ?>">
          <?= ikon((string) $i['ikon']) ?><span><?= e((string) $i['nama']) ?></span>
        </a>
      <?php endforeach; ?>

      <a class="nav-item <?= $here === 'laporan.php' ? 'on' : '' ?>" href="laporan.php">
        <?= ikon('report') ?><span>Laporan &amp; Rekap</span>
      </a>

      <div class="nav-sec">Data Master</div>
      <a class="nav-item <?= in_array($here, ['guru.php', 'guru_edit.php'], true) ? 'on' : '' ?>" href="guru.php">
        <?= ikon('users') ?><span>Data Guru</span>
      </a>
      <a class="nav-item <?= $here === 'guru_import.php' ? 'on' : '' ?>" href="guru_import.php">
        <?= ikon('upload') ?><span>Impor Guru</span>
      </a>
      <a class="nav-item <?= $here === 'pengguna.php' ? 'on' : '' ?>" href="pengguna.php">
        <?= ikon('key') ?><span>Pengguna &amp; Akun</span>
      </a>
      <a class="nav-item <?= in_array($here, ['jadwal.php', 'cetak_jadwal.php'], true) ? 'on' : '' ?>" href="jadwal.php">
        <?= ikon('calendar') ?><span>Jadwal Supervisi</span>
      </a>
      <a class="nav-item <?= $here === 'pengaturan.php' ? 'on' : '' ?>" href="pengaturan.php">
        <?= ikon('slider') ?><span>Profil &amp; Pengaturan</span>
      </a>
    <?php elseif ($u && $u['role'] === 'guru'): ?>
      <div class="nav-sec">Menu</div>
      <a class="nav-item <?= $here === 'index.php' ? 'on' : '' ?>" href="index.php">
        <?= ikon('dashboard') ?><span>Dashboard Saya</span>
      </a>
      <a class="nav-item <?= $here === 'laporan.php' ? 'on' : '' ?>" href="laporan.php">
        <?= ikon('report') ?><span>Laporan Saya</span>
      </a>
    <?php endif; ?>

    <div class="nav-sec">Akun</div>
    <a class="nav-item <?= $here === 'akun.php' ? 'on' : '' ?>" href="akun.php">
      <?= ikon('user') ?><span>Akun Saya</span>
    </a>
  </nav>

  <div class="sidebar-foot">
    <div class="who">
      <?= avatar_html((string) ($u['nama'] ?? ''), foto_user($u), 36) ?>
      <div class="who-txt">
        <strong><?= e((string) ($u['nama'] ?? '')) ?></strong>
        <small><?= $super ? 'Super Admin' : ($pengawas ? 'Pengawas Sekolah' : ($u['role'] === 'admin' ? 'Administrator / Kepala Sekolah' : 'Guru')) ?></small>
      </div>
    </div>
    <a class="btn btn-ghost btn-sm full" href="logout.php"><?= ikon('logout', 16) ?> Keluar</a>
  </div>
</aside>

<main class="main">
  <header class="topbar">
    <div>
      <h1><?= e($judul) ?></h1>
      <p class="topbar-sub"><?= $subJudul ?></p>
    </div>
    <div class="topbar-actions">
      <?php if ($super): ?>
        <span class="pill pill-B"><?= ikon('star', 13) ?> Super Admin</span>
        <?php if ($sekolah): ?>
          <a class="btn btn-ghost btn-sm" href="sekolah.php"><?= ikon('switch', 15) ?> Ganti Sekolah</a>
        <?php endif; ?>
      <?php elseif ($pengawas): ?>
        <span class="pill pill-warn"><?= ikon('eye', 13) ?> Pengawas &middot; hanya lihat</span>
        <a class="btn btn-ghost btn-sm" href="index.php"><?= ikon('switch', 15) ?> Ganti Sekolah Binaan</a>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="akun.php"><?= ikon('user', 16) ?> <?= e((string) ($u['nama'] ?? '')) ?></a>
      <?php if ($admin): ?>
        <a class="btn btn-primary btn-sm" href="supervisi_form.php?kode=pengamatan"><?= ikon('plus', 16) ?> Supervisi Baru</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($pengawas && $sekolah): ?>
    <div class="alert alert-ok" style="margin-bottom:18px">
      <?= ikon('eye', 16) ?>
      <span>Rekap <b><?= e(tetap_kata((string) $sekolah['nama'])) ?></b> (sekolah binaan Anda). Halaman ini hanya untuk melihat dan mengunduh.</span>
      <div class="flex" style="margin-left:auto;gap:8px">
        <a class="btn btn-ghost btn-sm" href="jadwal.php"><?= ikon('calendar', 14) ?> Jadwal</a>
        <a class="btn btn-ghost btn-sm" href="laporan_export.php"><?= ikon('doc', 14) ?> Unduh CSV</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($super && $sekolah): ?>
    <div class="alert alert-ok" style="margin-bottom:18px">
      <?= ikon('building', 16) ?>
      <span>Anda sedang <b>mengelola <?= e(tetap_kata((string) $sekolah['nama'])) ?></b>. Seluruh data yang tampil hanya milik sekolah ini.</span>
      <form method="post" action="sekolah.php" style="margin-left:auto">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="keluar">
        <button class="btn btn-ghost btn-sm" type="submit">Kembali ke Super Admin</button>
      </form>
    </div>
  <?php endif; ?>

  <?php foreach ($flashes as $f): ?>
    <div class="alert <?= $f['tipe'] === 'ok' ? 'alert-ok' : ($f['tipe'] === 'warn' ? 'alert-warn' : 'alert-err') ?>">
      <?= ikon($f['tipe'] === 'ok' ? 'check' : 'star', 16) ?><span><?= e($f['pesan']) ?></span>
    </div>
  <?php endforeach; ?>

  <div class="content">
<?php
}

function page_bottom(): void
{
    $sekolah = current_sekolah();
    ?>
  </div>
  <footer class="foot">
    <span><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &middot; <?= e($sekolah ? tetap_kata((string) $sekolah['nama']) : 'Super Admin') ?></span>
    <span>Dikembangkan oleh <?= e(APP_AUTHOR) ?> (<?= e(APP_AUTHOR_JABATAN) ?>)</span>
  </footer>
</main>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
<?php
    terjemahkan_selesai();
}

/** Tutup penampung keluaran halaman dan terapkan penyesuaian istilah Sekolah/Madrasah. */
function terjemahkan_selesai(): void
{
    $level = (int) ($GLOBALS['__vs_buf_level'] ?? 0);
    if ($level > 0 && ob_get_level() === $level) {
        echo terjemahkan_html((string) ob_get_clean());
        $GLOBALS['__vs_buf_level'] = 0;
    }
}

/**
 * Kartu BUKTI FOTO PENGAMATAN PEMBELAJARAN: galeri + form unggah + tombol hapus.
 * Hanya dipakai untuk menu `pengamatan` (lihat supervisi_foto_berlaku()).
 *
 * PENTING (jangan diubah tanpa membaca ini):
 *  1. Kartu ini memakai <form> BIASA (multipart, dikirim langsung oleh peramban) — BUKAN
 *     unggahan lewat fetch/JavaScript. Dengan begitu unggahan tetap berjalan walau skrip
 *     gagal dimuat; pratinjau & pemeriksaan ukuran di assets/app.js hanya pelengkap.
 *  2. Karena itu kartu ini WAJIB diletakkan DI LUAR form lain: HTML tidak mengizinkan form
 *     bersarang — peramban akan membuang form di dalam form sehingga tombolnya diam-diam
 *     mengirim form yang salah (inilah penyebab "unggah tidak jalan" pada versi pertama).
 *  3. Isian berkas bernama `foto[]` dan boleh memilih 2 foto SEKALIGUS (permintaan pemilik);
 *     batas 2 foto tetap ditegakkan di server (upload_supervisi_foto.php).
 *
 * @param int    $supId   id supervisi yang fotonya dikelola
 * @param array  $foto    baris tabel supervisi_foto (hasil supervisi_foto())
 * @param string $tempat  halaman pemanggil: 'form' (form penilaian) atau 'detail'
 */
function kartu_foto_pengamatan(int $supId, array $foto, string $tempat = 'form'): string
{
    $maks = (int) SUPERVISI_FOTO_MAKS;
    $jml = count($foto);
    $sisa = max(0, $maks - $jml);
    $tempat = $tempat === 'detail' ? 'detail' : 'form';
    ob_start();
    ?>
<div class="card" id="cardFotoPengamatan">
  <div class="card-head">
    <div>
      <h2>Bukti Foto Pengamatan Pembelajaran</h2>
      <p>Dokumentasi guru saat mengajar di kelas. Maksimal <?= $maks ?> foto &mdash; dapat dipilih sekaligus.</p>
    </div>
    <span class="pill <?= $jml >= $maks ? 'pill-ok' : 'pill-gray' ?>"><?= $jml ?> dari <?= $maks ?> foto</span>
  </div>

  <?php if ($jml > 0): ?>
    <div class="foto-bukti-grid" id="fotoBuktiGrid">
      <?php foreach ($foto as $ix => $f): ?>
        <figure class="foto-bukti">
          <img src="<?= e((string) $f['url']) ?>" alt="Bukti foto pengamatan <?= $ix + 1 ?>" loading="lazy">
          <figcaption>
            <span class="xs mut">Foto <?= $ix + 1 ?> dari <?= $maks ?></span>
            <form method="post" action="upload_supervisi_foto.php"
                  data-confirm="Hapus foto bukti pengamatan ini?">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="supervisi_id" value="<?= $supId ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <input type="hidden" name="kembali" value="<?= e($tempat) ?>">
              <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 14) ?> Hapus</button>
            </form>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($sisa > 0): ?>
    <form method="post" action="upload_supervisi_foto.php" enctype="multipart/form-data"
          class="foto-unggah-form" id="formFotoPengamatan">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="unggah">
      <input type="hidden" name="supervisi_id" value="<?= $supId ?>">
      <input type="hidden" name="kembali" value="<?= e($tempat) ?>">
      <div>
        <label class="f" for="fotoPengamatan">Pilih berkas foto<?= $sisa > 1 ? ' (bisa ' . $sisa . ' sekaligus)' : '' ?></label>
        <input type="file" id="fotoPengamatan" name="foto[]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml"
               multiple data-sisa="<?= $sisa ?>" data-maks="<?= $maks ?>">
        <div class="hint" id="fotoPengamatanHint">
          Format JPG, PNG, GIF, WEBP, atau SVG &middot; maksimal 15 MB per foto &middot;
          sisa <?= $sisa ?> slot foto (boleh memilih <?= $sisa > 1 ? $sisa . ' berkas sekaligus' : 'satu berkas' ?>).
        </div>
      </div>
      <div class="foto-preview-wrap" id="fotoPengamatanPreviewWrap" hidden>
        <div class="foto-pilih-daftar" id="fotoPengamatanPreview"></div>
        <div class="xs mut center">Pratinjau berkas yang dipilih</div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit" id="btnFotoPengamatan"><?= ikon('upload', 16) ?> Unggah Foto</button>
        <span class="xs mut" id="fotoPengamatanStatus"></span>
      </div>
    </form>
  <?php else: ?>
    <div class="alert alert-warn" style="margin:0">
      <?= ikon('info', 16) ?><span>Bukti foto sudah penuh (<?= $maks ?> dari <?= $maks ?> foto). Hapus salah satu foto bila ingin menggantinya.</span>
    </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}
