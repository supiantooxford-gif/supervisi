<?php
/**
 * Lembar cetak Jadwal Supervisi (A4 landscape) — SATU tabel untuk semua tanggal.
 *
 * Opsi filter: ?dari=YYYY-MM-DD&sampai=YYYY-MM-DD (bila kosong, seluruh jadwal dicetak).
 * Tanda tangan: hanya kepala sekolah, nama & NIP dari PENGATURAN sekolah,
 * dan posisinya di sebelah KANAN lembar (permintaan pemilik).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Tampung keluaran agar istilah Sekolah/Madrasah bisa disesuaikan sebelum dikirim.
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();

$u = require_login();
$pengawas = $u['role'] === 'pengawas';

// Guru tidak memakai halaman jadwal (tetap ditolak, sama seperti sebelumnya).
if ($u['role'] === 'guru') {
    http_response_code(403);
    exit('Lembar cetak jadwal hanya untuk admin sekolah/madrasah dan pengawas.');
}

// Pengawas boleh mencetak jadwal sekolah/madrasah binaannya (lewat ?sekolah=ID), hanya melihat.
if ($pengawas && (int) ($_GET['sekolah'] ?? 0) > 0) {
    $minta = (int) $_GET['sekolah'];
    if (!pengawas_punya((int) $u['id'], $minta)) {
        http_response_code(403);
        exit('Madrasah/sekolah itu bukan sekolah binaan Anda.');
    }
    if (sekolah_id() !== $minta) {
        set_sekolah_context($minta);
        redirect('cetak_jadwal.php' . (isset($_GET['auto']) ? '?auto=1' : ''));
    }
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

/** Tanggal valid (YYYY-MM-DD) atau null. */
function tgl_ok($v): ?string
{
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
}

$fDari = tgl_ok($_GET['dari'] ?? null);
$fSampai = tgl_ok($_GET['sampai'] ?? null);
if ($fDari !== null && $fSampai !== null && $fDari > $fSampai) {
    [$fDari, $fSampai] = [$fSampai, $fDari];
}

$w = ['sekolah_id = ?'];
$p = [$sid];
if ($fDari !== null) {
    $w[] = 'tanggal >= ?';
    $p[] = $fDari;
}
if ($fSampai !== null) {
    $w[] = 'tanggal <= ?';
    $p[] = $fSampai;
}

$rows = all('SELECT * FROM jadwal WHERE ' . implode(' AND ', $w) . ' ORDER BY tanggal, urutan, id', $p);
if (!$rows) {
    flash('Belum ada jadwal untuk dicetak pada rentang yang dipilih.', 'err');
    redirect('jadwal.php');
}

// Nama kepala sekolah dari pengaturan (NIP boleh kosong / "-").
$kepalaSekolah = trim(setting('kepala_sekolah', ''));
if ($kepalaSekolah === '') {
    $kepalaSekolah = '-';
}
$nipKepala = trim(setting('nip_kepala_sekolah', ''));
$autoPrint = isset($_GET['auto']);

if ($fDari !== null && $fSampai !== null) {
    $periode = tanggal_id($fDari) . ' s/d ' . tanggal_id($fSampai);
} elseif ($fDari !== null) {
    $periode = 'mulai ' . tanggal_id($fDari);
} elseif ($fSampai !== null) {
    $periode = 'sampai ' . tanggal_id($fSampai);
} else {
    $periode = 'Seluruh jadwal yang tercatat';
}

$hari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
         'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
$jmlHari = count(array_unique(array_column($rows, 'tanggal')));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jadwal Supervisi — <?= e(tetap_kata((string) $sekolah['nama'])) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:1100px;margin:0 auto}
  .toolbar{max-width:1100px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .sheet-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table.sheet.jadwal th,table.sheet.jadwal td{padding:7px 8px}
  /* Tanda tangan: di sisi KANAN lembar (permintaan pemilik). */
  .sheet-foot{justify-content:flex-end;padding-right:6mm}
  .sheet-sign-kanan{flex:0 0 auto;min-width:250px;text-align:center}
  @media (max-width:820px){
    .print-sheet{padding:18px 14px}
    table.sheet.jadwal{min-width:720px}
    .sheet-meta{grid-template-columns:minmax(0,1fr)}
    .sheet-foot{justify-content:center;padding-right:0}
  }
  @media print{
    body{background:#fff;padding:0}
    .toolbar,.no-print{display:none !important}
    .sheet-wrap{max-width:none}
    .print-sheet{box-shadow:none;border:none;padding:0;border-radius:0}
    .sheet-scroll{overflow:visible}
    table.sheet.jadwal{min-width:0}
    .sheet-foot{justify-content:flex-end;padding-right:6mm}
  }
  @page{size:A4 landscape;margin:12mm}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="jadwal.php">Kembali</a>
  <a class="btn btn-primary" href="cetak_jadwal.php?<?= e(http_build_query(array_filter(['dari' => $fDari, 'sampai' => $fSampai, 'auto' => 1]))) ?>"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <?php $logoKop = logo_sekolah(); ?>
    <div class="sheet-head<?= $logoKop !== '' ? ' kop-logo' : '' ?>">
      <?php if ($logoKop !== ''): ?>
        <div class="kop-gambar"><img src="<?= e($logoKop) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>"></div>
      <?php endif; ?>
      <div class="kop-teks">
      <h2>Jadwal Supervisi</h2>
      <p><?= e(tetap_kata((string) $sekolah['nama'])) ?></p>
      <?php foreach (kop_baris_identitas() as $bk): ?>
        <p style="font-size:.78rem"><?= e(tetap_kata($bk)) ?></p>
      <?php endforeach; ?>
      </div>
    </div>

    <div class="sheet-meta">
      <div><b>Periode</b>: <?= e($periode) ?></div>
      <div><b>Tahun Ajaran</b>: <?= e(setting('tahun_ajaran', '-')) ?> &minus; Semester <?= e(setting('semester', '-')) ?></div>
      <div><b>Jumlah Guru</b>: <?= count($rows) ?> baris jadwal<?= $jmlHari > 1 ? ' (tersebar pada ' . (int) $jmlHari . ' tanggal)' : '' ?></div>
      <div><b>Jenis Kegiatan</b>: Supervisi Akademik</div>
    </div>

    <div class="sheet-scroll">
      <table class="sheet jadwal">
        <thead>
          <tr>
            <th class="ctr" style="width:30px">No</th>
            <th style="width:15%">Hari / Tanggal</th>
            <th style="width:20%">Nama Guru</th>
            <th style="width:15%">Mata Pelajaran</th>
            <th class="ctr" style="width:8%">Kelas</th>
            <th class="ctr" style="width:8%">Jam ke-</th>
            <th style="width:16%">Supervisor</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
        <?php $n = 0; foreach ($rows as $r): $n++; ?>
          <tr>
            <td class="ctr"><?= $n ?></td>
            <td>
              <?= e($hari[date('l', strtotime((string) $r['tanggal']))] ?? '') ?>,
              <?= e(tanggal_id((string) $r['tanggal'])) ?>
            </td>
            <td><?= e((string) $r['nama_guru']) ?></td>
            <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
            <td class="ctr"><?= e((string) ($r['kelas'] ?: '-')) ?></td>
            <td class="ctr"><?= e((string) ($r['jam_ke'] ?: '-')) ?></td>
            <td><?= e((string) ($r['supervisor'] ?: '-')) ?></td>
            <td><?= e((string) ($r['keterangan'] ?: '-')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="sm" style="margin-top:14px;color:#635F82">
      Jadwal dapat berubah sesuai kondisi sekolah. Guru yang dijadwalkan mohon menyiapkan perangkat
      pembelajaran yang diperlukan dan berkoordinasi dengan kepala sekolah.
    </p>

    <!-- Tanda tangan: hanya kepala sekolah, diletakkan di sisi KANAN lembar.
         Baris "tempat, tanggal" (nama Kecamatan + tanggal) diletakkan di ATAS nama kepala
         (permintaan pemilik) — pada lembar yang hanya ditandatangani kepala, letaknya menyesuaikan. -->
    <div class="sheet-foot">
      <div class="sheet-sign sheet-sign-kanan">
        <div class="ttd-tempat"><?= e(ttd_tempat_tanggal()) ?></div>
        <div><?= ttd_mengetahui_html() ?></div>
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
