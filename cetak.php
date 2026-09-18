<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Tampung keluaran agar istilah Sekolah/Madrasah bisa disesuaikan sebelum dikirim.
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();

$u = require_login();
$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];
$admin = is_admin();

$id = (int) ($_GET['id'] ?? 0);
$s = one('SELECT s.*, g.nama AS guru, g.nip, g.mapel, g.jabatan, g.kelas, i.nama AS instrumen, i.kode, i.deskripsi
          FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
          WHERE s.id = ? AND s.sekolah_id = ?', [$id, $sid]);
if (!$s) {
    http_response_code(404);
    exit('Data supervisi tidak ditemukan.');
}
if (!$admin && !is_pengawas() && (int) ($u['guru_id'] ?? 0) !== (int) $s['guru_id']) {
    http_response_code(403);
    exit('Anda hanya dapat mencetak hasil supervisi sendiri.');
}

// Butir bisa berupa butir GLOBAL (butir_id terisi) atau butir LAMA/arsip (butir_id NULL,
// nama & skor maksimalnya tersimpan di kolom *_arsip) — keduanya ditampilkan.
$detail = all('SELECT d.skor, d.catatan,
        COALESCE(b.komponen, d.komponen_arsip) AS komponen,
        COALESCE(b.indikator, NULL) AS indikator,
        COALESCE(b.skor_maks, d.maks_arsip, 4) AS skor_maks,
        COALESCE(b.urutan, d.urutan_arsip, 999) AS urutan,
        CASE WHEN d.butir_id IS NULL THEN 1 ELSE 0 END AS arsip
    FROM detail d LEFT JOIN butir b ON b.id = d.butir_id
    WHERE d.supervisi_id = ? ORDER BY urutan, d.id', [$id]);

$pred = (string) $s['predikat'];
$fotoGuru = foto_guru((int) $s['guru_id']);

// Nama & NIP kepala sekolah diambil dari PENGATURAN sekolah (menu Profil & Pengaturan),
// supaya lembar cetak selalu mencantumkan nama yang benar. Kolom "supervisor" pada data
// supervisi sering hanya berisi teks umum (mis. "Kepala Sekolah"), jadi hanya dipakai
// sebagai cadangan bila pengaturan kepala sekolah belum diisi.
$kepalaSekolah = trim(setting('kepala_sekolah', ''));
if ($kepalaSekolah === '' || $kepalaSekolah === '-') {
    $kepalaSekolah = trim((string) ($s['supervisor'] ?? ''));
}
if ($kepalaSekolah === '') {
    $kepalaSekolah = '-';
}
$nipKepalaSekolah = trim(setting('nip_kepala_sekolah', ''));
if ($nipKepalaSekolah === '') {
    $nipKepalaSekolah = '-';
}
$predColor = $pred === 'A' ? '#047857' : ($pred === 'B' ? '#1d4ed8' : ($pred === 'C' ? '#b45309' : '#b91c1c'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan Supervisi — <?= e((string) $s['guru']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:920px;margin:0 auto}
  .toolbar{max-width:920px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  @media print{body{background:#fff;padding:0}.toolbar{display:none}}
</style>
</head>
<body>
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="supervisi_detail.php?id=<?= (int) $s['id'] ?>">Kembali</a>
  <a class="btn btn-ghost" href="?id=<?= (int) $s['id'] ?>&auto=1" onclick="setTimeout(function(){window.print();},350);return false;" data-print>Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <?php $logoKop = logo_sekolah(); ?>
    <div class="sheet-head<?= $logoKop !== '' ? ' kop-logo' : '' ?>">
      <?php if ($logoKop !== ''): ?>
        <div class="kop-gambar"><img src="<?= e($logoKop) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>"></div>
      <?php endif; ?>
      <div class="kop-teks">
      <h2>Laporan Hasil Supervisi Akademik</h2>
      <p><?= e(tetap_kata(setting('nama_sekolah', 'Sekolah'))) ?></p>
      <p style="font-size:.78rem"><?= e(setting('alamat_sekolah', '-')) ?> &middot; NPSN <?= e(setting('npsn', '-')) ?></p>
      </div>
    </div>

    <?php if ($fotoGuru): ?>
      <div class="sheet-foto">
        <img class="foto-cetak" src="<?= e($fotoGuru) ?>" alt="Foto <?= e((string) $s['guru']) ?>">
        <div class="xs mut center" style="margin-top:4px">Foto 3&times;4</div>
      </div>
    <?php endif; ?>

    <div class="sheet-meta">
      <div><b>Nama Guru</b>: <?= e((string) $s['guru']) ?></div>
      <div><b>NIP</b>: <?= e((string) ($s['nip'] ?: '-')) ?></div>
      <div><b>Mata Pelajaran</b>: <?= e((string) ($s['mapel'] ?: '-')) ?></div>
      <div><b>Jabatan</b>: <?= e((string) ($s['jabatan'] ?: '-')) ?></div>
      <div><b>Jenis Supervisi</b>: <?= e((string) $s['instrumen']) ?></div>
      <div><b>Tanggal Supervisi</b>: <?= e(tanggal_id((string) $s['tanggal'])) ?></div>
      <div><b>Periode</b>: <?= e((string) ($s['periode'] ?: '-')) ?></div>
      <div><b>Kepala Sekolah</b>: <?= e($kepalaSekolah) ?></div>
      <div><b>Supervisor / Penilai</b>: <?= e((string) ($s['supervisor'] ?: '-')) ?></div>
    </div>

    <table class="sheet">
      <thead>
        <tr>
          <th class="ctr" style="width:34px">No</th>
          <th>Komponen / Indikator Penilaian</th>
          <th class="ctr" style="width:56px">Skor</th>
          <th class="ctr" style="width:64px">Maks</th>
          <th style="width:210px">Catatan</th>
        </tr>
      </thead>
      <tbody>
      <?php $n = 0; foreach ($detail as $d): $n++; ?>
        <tr>
          <td class="ctr"><?= $n ?></td>
          <td>
            <?= e((string) $d['komponen']) ?>
            <?php if ($d['indikator']): ?><br><span style="color:#635F82;font-size:.92em"><?= e((string) $d['indikator']) ?></span><?php endif; ?>
          </td>
          <td class="ctr"><?= (int) $d['skor'] ?></td>
          <td class="ctr"><?= (int) $d['skor_maks'] ?></td>
          <td style="font-size:.94em"><?= e((string) ($d['catatan'] ?: '-')) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <th colspan="2" style="text-align:right">Jumlah Skor</th>
          <th class="ctr"><?= r1($s['total_skor']) ?></th>
          <th class="ctr"><?= r1($s['skor_maks']) ?></th>
          <th></th>
        </tr>
      </tbody>
    </table>

    <div class="nilai-box">
      <div class="nilai-chip"><b><?= r1($s['nilai']) ?></b><span>Nilai Akhir</span></div>
      <div class="nilai-chip"><b style="color:<?= $predColor ?>"><?= e($pred) ?></b><span>Predikat &middot; <?= e(predikat_label($pred)) ?></span></div>
    </div>

    <table class="sheet">
      <tbody>
        <tr><th style="width:220px">Kelebihan / Kekuatan</th><td><?= nl2br(e((string) ($s['kelebihan'] ?: '-'))) ?></td></tr>
        <tr><th>Kekurangan / Area Perbaikan</th><td><?= nl2br(e((string) ($s['kekurangan'] ?: '-'))) ?></td></tr>
        <tr><th>Rekomendasi Tindak Lanjut</th><td><?= nl2br(e((string) ($s['tindak_lanjut'] ?: '-'))) ?></td></tr>
        <tr><th>Kriteria Predikat</th><td class="sm">
          A (Amat Baik) &ge; <?= e(setting('batas_a', '90')) ?> &nbsp;|&nbsp;
          B (Baik) &ge; <?= e(setting('batas_b', '80')) ?> &nbsp;|&nbsp;
          C (Cukup) &ge; <?= e(setting('batas_c', '70')) ?> &nbsp;|&nbsp;
          D (Perlu Pembinaan) &lt; <?= e(setting('batas_c', '70')) ?>
        </td></tr>
      </tbody>
    </table>

    <div class="sheet-foot">
      <div class="sheet-sign">
        <div>Mengetahui,<br>Kepala Sekolah</div>
        <div class="space"></div>
        <u><?= e($kepalaSekolah) ?></u><br>
        <span class="sm">NIP. <?= e($nipKepalaSekolah) ?></span>
      </div>
      <div class="sheet-sign">
        <div>Guru yang Disupervisi,</div>
        <div class="space"></div>
        <u><?= e((string) $s['guru']) ?></u><br>
        <span class="sm">NIP. <?= e((string) ($s['nip'] ?: '-')) ?></span>
      </div>
    </div>
    <p class="xs mut center" style="margin-top:22px">Dicetak dari <?= e(APP_NAME) ?> pada <?= e(tanggal_id(date('Y-m-d'))) ?>.</p>
  </div>
</div>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
<?php if (isset($_GET['auto'])): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
<?php endif; ?>
</script>
</body>
</html>
<?php terjemahkan_selesai(); ?>
