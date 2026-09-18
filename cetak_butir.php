<?php
/**
 * Lembar cetak DAFTAR BUTIR PENILAIAN — satu menu supervisi per lembar (A4 portrait).
 *
 * Butir penilaian bersifat GLOBAL (satu daftar untuk semua sekolah/madrasah), jadi yang
 * berubah antar sekolah hanyalah KOP dan TANDA TANGAN-nya: dokumen dicetak memakai konteks
 * sekolah yang sedang aktif (Super Admin masuk ke konteks sekolah lewat halaman Kelola
 * Sekolah, atau memilih sekolah pada halaman pemilih di bawah).
 *
 * Hanya butir AKTIF yang dicetak (butir nonaktif tidak dipakai pada penilaian baru).
 * Tanda tangan: hanya kepala sekolah; nama & NIP dari PENGATURAN sekolah, sisi KANAN lembar.
 *
 * Akses: Super Admin + admin/kepala sekolah. Guru & pengawas ditolak (403).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();

// Guru & pengawas tidak memakai lembar ini (hanya pengelola butir/penilaian).
if ($u['role'] === 'guru') {
    http_response_code(403);
    exit('Lembar daftar butir penilaian hanya untuk kepala sekolah dan Super Admin.');
}
if ($u['role'] === 'pengawas') {
    http_response_code(403);
    exit('Lembar daftar butir penilaian hanya untuk kepala sekolah dan Super Admin.');
}

// ---------- Menu supervisi yang akan dicetak ----------
$kode = trim((string) ($_GET['kode'] ?? ''));
$ins = $kode !== '' ? one('SELECT * FROM instrumen WHERE kode = ?', [$kode]) : null;
if (!$ins) {
    $ins = one('SELECT * FROM instrumen WHERE aktif = 1 ORDER BY urutan, id LIMIT 1');
}
if (!$ins) {
    flash('Belum ada menu supervisi yang bisa dicetak.', 'err');
    redirect(is_super() ? 'instrumen.php' : 'index.php');
}
$kodeMenu = (string) $ins['kode'];
$menuAktif = all('SELECT * FROM instrumen WHERE aktif = 1 ORDER BY urutan, id');
// Bila yang dicetak adalah menu nonaktif (?kode= menu lama), tetap tampilkan sebagai pilihan aktif.
$adaDiDaftar = false;
foreach ($menuAktif as $m) {
    if ((string) $m['kode'] === $kodeMenu) {
        $adaDiDaftar = true;
        break;
    }
}
if (!$adaDiDaftar) {
    $menuAktif[] = $ins;
}

// ---------- Super Admin: dokumen memuat kop & tanda tangan satu sekolah ----------
if (is_super()) {
    $minta = (int) ($_GET['sekolah'] ?? 0);
    if ($minta > 0 && $minta !== sekolah_id()) {
        $target = one('SELECT * FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"', [$minta]);
        if (!$target) {
            flash('Sekolah/madrasah itu tidak ditemukan atau sudah dinonaktifkan.', 'err');
            redirect('cetak_butir.php?kode=' . urlencode($kodeMenu));
        }
        set_sekolah_context($minta);
        redirect('cetak_butir.php?kode=' . urlencode($kodeMenu) . '&auto=1');
    }

    // Belum ada konteks sekolah: tawarkan pilihan sekolah/madrasah dulu.
    if (!current_sekolah()) {
        // Halaman ini menampilkan sekolah umum DAN madrasah sekaligus → istilah generik.
        $GLOBALS['__vs_istilah_nonaktif'] = true;
        $daftar = all('SELECT * FROM sekolah WHERE aktif = 1 AND verifikasi = "disetujui" ORDER BY nama');
        $butirAktifMenu = (int) val('SELECT COUNT(*) FROM butir WHERE instrumen_id = ? AND aktif = 1', [(int) $ins['id']], 0);

        page_top('Cetak Daftar Butir', 'instrumen');
        ?>
        <div class="card">
          <div class="card-head">
            <div>
              <h2>Cetak Daftar Butir — <?= e((string) $ins['nama']) ?></h2>
              <p>Daftar butir berlaku untuk semua sekolah, sedangkan kop dan tanda tangan mengikuti
                sekolah/madrasah yang dipilih. Pilih dulu sekolah yang lembar cetaknya ingin dibuat,
                lalu Anda dapat langsung mencetak (<?= $butirAktifMenu ?> butir aktif pada menu ini).</p>
            </div>
            <div class="flex">
              <span class="chip"><?= count($daftar) ?> sekolah aktif</span>
              <a class="btn btn-ghost" href="instrumen.php">Kembali</a>
            </div>
          </div>

          <?php if (!$daftar): ?>
            <div class="empty"><?= ikon('building', 34) ?><h3>Belum ada sekolah aktif</h3>
              <p>Tambahkan sekolah lebih dulu pada halaman Kelola Sekolah.</p>
              <a class="btn btn-primary" href="sekolah.php">Kelola Sekolah</a></div>
          <?php else: ?>
            <div class="grid g3" style="gap:18px">
              <?php foreach ($daftar as $s): ?>
                <div class="card card-tight" style="margin:0">
                  <div class="flex between" style="gap:10px;align-items:flex-start">
                    <b><?= e((string) $s['nama']) ?></b>
                    <span class="pill pill-gray"><?= (string) $s['jenis'] === 'madrasah' ? 'Madrasah' : 'Sekolah' ?></span>
                  </div>
                  <p class="sm mut" style="margin:6px 0 0">
                    <?= e((string) ($s['alamat'] ?: '-')) ?> &middot; NPSN <?= e((string) ($s['npsn'] ?: '-')) ?>
                  </p>
                  <a class="btn btn-primary btn-sm mt16" href="cetak_butir.php?kode=<?= e(urlencode($kodeMenu)) ?>&amp;sekolah=<?= (int) $s['id'] ?>">
                    <?= ikon('print', 15) ?> Cetak Daftar Butir
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
        page_bottom();
        exit;
    }
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

// ---------- Butir AKTIF menu ini ----------
$rows = all('SELECT * FROM butir WHERE instrumen_id = ? AND aktif = 1 ORDER BY urutan, id', [(int) $ins['id']]);
if (!$rows) {
    flash('Menu "' . $ins['nama'] . '" belum memiliki butir aktif untuk dicetak.', 'err');
    redirect(is_super() ? 'instrumen_butir.php?kode=' . urlencode($kodeMenu) : 'supervisi.php?kode=' . urlencode($kodeMenu));
}

$totalMaks = 0;
foreach ($rows as $r) {
    $totalMaks += (int) $r['skor_maks'];
}

// Nama kepala sekolah dari pengaturan (NIP boleh kosong / "-").
$kepalaSekolah = trim(setting('kepala_sekolah', ''));
if ($kepalaSekolah === '') {
    $kepalaSekolah = '-';
}
$nipKepala = trim(setting('nip_kepala_sekolah', ''));
$autoPrint = isset($_GET['auto']);
$kembali = is_super() ? 'instrumen_butir.php?kode=' . urlencode($kodeMenu) : 'supervisi.php?kode=' . urlencode($kodeMenu);

// Tampung keluaran agar istilah Sekolah/Madrasah bisa disesuaikan sebelum dikirim.
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar Butir Penilaian — <?= e(tetap_kata((string) $sekolah['nama'])) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:820px;margin:0 auto}
  .toolbar{max-width:820px;margin:0 auto 18px;display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap}
  .toolbar .pilih-menu{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;flex:1}
  .toolbar .pilih-menu a.btn{white-space:nowrap}
  .sheet-head .kop-teks .sheet-menu{font-size:.9rem;color:var(--ink);margin-top:8px}
  table.sheet.butir td,table.sheet.butir th{padding:8px 9px}
  table.sheet.butir tfoot td{background:#f6f3ff;font-weight:700}
  /* Tanda tangan: hanya kepala sekolah, di sisi KANAN lembar. */
  .sheet-foot{justify-content:flex-end;padding-right:6mm}
  .sheet-sign-kanan{flex:0 0 auto;min-width:250px;text-align:center}
  @media (max-width:820px){
    .print-sheet{padding:18px 14px}
    table.sheet.butir{min-width:0}
    .sheet-meta{grid-template-columns:minmax(0,1fr)}
    .sheet-foot{justify-content:center;padding-right:0}
    .toolbar{justify-content:center}
  }
  @media print{
    body{background:#fff;padding:0}
    .toolbar,.no-print{display:none !important}
    .sheet-wrap{max-width:none}
    .print-sheet{box-shadow:none;border:none;padding:0;border-radius:0}
    .sheet-foot{justify-content:flex-end;padding-right:6mm}
  }
  @page{size:A4 portrait;margin:14mm}
</style>
</head>
<body>
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="<?= e($kembali) ?>">Kembali</a>
  <div class="pilih-menu">
    <?php foreach ($menuAktif as $m): ?>
      <?php $on = (string) $m['kode'] === $kodeMenu; ?>
      <a class="btn <?= $on ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
         href="cetak_butir.php?kode=<?= e(urlencode((string) $m['kode'])) ?>"
         title="Cetak daftar butir menu <?= e((string) $m['nama']) ?>"><?= e((string) $m['nama']) ?></a>
    <?php endforeach; ?>
  </div>
  <a class="btn btn-primary" href="cetak_butir.php?kode=<?= e(urlencode($kodeMenu)) ?>&auto=1"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <?php $logoKop = logo_sekolah(); ?>
    <div class="sheet-head<?= $logoKop !== '' ? ' kop-logo' : '' ?>">
      <?php if ($logoKop !== ''): ?>
        <div class="kop-gambar"><img src="<?= e($logoKop) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>"></div>
      <?php endif; ?>
      <div class="kop-teks">
        <h2>Daftar Butir Penilaian</h2>
        <p><?= e(tetap_kata((string) $sekolah['nama'])) ?></p>
        <p style="font-size:.78rem"><?= e((string) ($sekolah['alamat'] ?: '-')) ?> &middot; NPSN <?= e((string) ($sekolah['npsn'] ?: '-')) ?></p>
        <p class="sheet-menu"><b>Menu Supervisi:</b> <?= e((string) $ins['nama']) ?></p>
      </div>
    </div>

    <div class="sheet-meta">
      <div><b>Menu Supervisi</b>: <?= e((string) $ins['nama']) ?><?= (string) $ins['kode'] !== '' ? ' (' . e((string) $ins['kode']) . ')' : '' ?></div>
      <div><b>Jumlah Butir</b>: <?= count($rows) ?> butir aktif</div>
      <div><b>Total Skor Maksimal</b>: <?= $totalMaks ?></div>
      <div><b>Tahun Ajaran</b>: <?= e(setting('tahun_ajaran', '-')) ?> &minus; Semester <?= e(setting('semester', '-')) ?></div>
    </div>

    <table class="sheet butir">
      <thead>
        <tr>
          <th class="ctr" style="width:36px">No</th>
          <th style="width:38%">Komponen / Aspek Penilaian</th>
          <th>Indikator Penilaian</th>
          <th class="ctr" style="width:74px">Skor Maks</th>
        </tr>
      </thead>
      <tbody>
      <?php $n = 0; foreach ($rows as $b): $n++; ?>
        <tr>
          <td class="ctr"><?= $n ?></td>
          <td><?= e((string) $b['komponen']) ?></td>
          <td><?= $b['indikator'] ? e((string) $b['indikator']) : '<span class="mut">-</span>' ?></td>
          <td class="ctr"><?= (int) $b['skor_maks'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="ctr" colspan="3">Jumlah — <?= count($rows) ?> butir aktif</td>
          <td class="ctr"><?= $totalMaks ?></td>
        </tr>
      </tfoot>
    </table>

    <p class="sm" style="margin-top:14px;color:#635F82">
      Daftar butir ini bersifat global dan dipakai bersama oleh seluruh satuan pendidikan yang memakai
      aplikasi ini. Butir nonaktif tidak dicetak karena tidak lagi muncul pada penilaian baru.
      Nilai akhir dihitung dari jumlah skor perolehan dibagi total skor maksimal dikali 100.
    </p>

    <!-- Tanda tangan: hanya kepala sekolah, diletakkan di sisi KANAN lembar. -->
    <div class="sheet-foot">
      <div class="sheet-sign sheet-sign-kanan">
        <div>Mengetahui,<br>Kepala Sekolah</div>
        <div class="space"></div>
        <u><?= e($kepalaSekolah) ?></u><br>
        <span class="sm">NIP. <?= e($nipKepala !== '' ? $nipKepala : '-') ?></span>
      </div>
    </div>

    <p class="xs mut center" style="margin-top:20px">
      Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada <?= e(tanggal_id(date('Y-m-d'))) ?>.
    </p>
  </div>
</div>

<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
<?php endif; ?>
</script>
</body>
</html>
<?php terjemahkan_selesai(); ?>
