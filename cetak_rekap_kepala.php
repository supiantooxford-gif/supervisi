<?php
/**
 * LEMBAR CETAK / PDF "REKAP KEPALA SEKOLAH/MADRASAH" (A4 POTRAIT) — Supervisi Guru v1.31.0.
 *
 * Permintaan pemilik: *"... tambahkan menu yang menampilkan rekapan: No, nama sekolah/madrasah,
 * nama kepala sekolah, Nomer HP kepala sekolah ... tambahkan fitur cetak pdf"*
 *
 * Kolomnya PERSIS empat yang diminta (No, Nama Sekolah/Madrasah, Nama Kepala Sekolah,
 * Nomor HP Kepala Sekolah). Data diambil dari helper bersama `rekap_kepala_daftar()` sehingga isi
 * dan urutannya SAMA dengan halaman `rekap_kepala.php` (saringan `?cari=&status=` ikut dibawa).
 *
 * Lembar ini LINTAS sekolah (rekapan untuk Super Admin), jadi TIDAK memakai kop sekolah dan TIDAK
 * ada tanda tangan — sama seperti `cetak_pengawas.php` / `cetak_peringkat.php`.
 *
 * Pemakaian: cetak_rekap_kepala.php  /  cetak_rekap_kepala.php?auto=1 (langsung dialog cetak/PDF)
 *            cetak_rekap_kepala.php?cari=...&status=aktif
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Tampung keluaran agar istilah bisa disesuaikan sebelum dikirim (pola semua lembar cetak).
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();
// Lembar LINTAS sekolah (sekolah umum & madrasah sekaligus) → istilah generik "Sekolah/Madrasah".
$GLOBALS['__vs_istilah_nonaktif'] = true;

$u = require_login();
if (!is_super()) {
    http_response_code(403);
    exit('Lembar rekap kepala sekolah ini hanya untuk Super Admin.');
}

$fCari = trim((string) ($_GET['cari'] ?? ''));
$fStatus = (string) ($_GET['status'] ?? '');
if (!in_array($fStatus, ['', 'aktif', 'nonaktif'], true)) {
    $fStatus = '';
}
$baris = rekap_kepala_daftar($fCari, $fStatus);
$ringkas = rekap_kepala_ringkas($baris);
$autoPrint = isset($_GET['auto']);
$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];
$saringan = [];
if ($fCari !== '') {
    $saringan[] = 'kata kunci "' . $fCari . '"';
}
if ($fStatus === 'aktif') {
    $saringan[] = 'hanya lembaga aktif (disetujui)';
} elseif ($fStatus === 'nonaktif') {
    $saringan[] = 'hanya lembaga nonaktif/ditolak';
}
// Tautan kembali & cetak membawa saringan yang sedang dilihat (dibangun dari nilai yang sudah divalidasi).
$qSaring = array_filter(['cari' => $fCari, 'status' => $fStatus], static fn($v) => $v !== '');
$urlKembali = 'rekap_kepala.php' . ($qSaring ? '?' . http_build_query($qSaring) : '');
$urlCetak = 'cetak_rekap_kepala.php?' . http_build_query($qSaring + ['auto' => 1]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rekap Kepala Sekolah — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:820px;margin:0 auto}
  .toolbar{max-width:820px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .sheet-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  /* Rekap kontak: baris dibuat rapat supaya seluruh lembaga muat dalam sedikit halaman. */
  table.sheet.rekap-kepala th,table.sheet.rekap-kepala td{padding:5px 8px;vertical-align:top}
  table.sheet.rekap-kepala th{text-align:center}
  table.sheet.rekap-kepala td.rk-no{text-align:center;width:34px}
  table.sheet.rekap-kepala td.rk-nama{font-weight:600}
  table.sheet.rekap-kepala td .sub{display:block;font-size:.7rem;color:#635F82;margin-top:1px;font-weight:400}
  table.sheet.rekap-kepala td .kosong{font-style:italic;color:#9A7B12}
  table.sheet.rekap-kepala tfoot td{font-weight:700;background:rgba(99,95,130,.06)}
  /* Satu baris tidak boleh terpotong antar halaman saat dicetak. */
  table.sheet.rekap-kepala tr{break-inside:avoid}
  table.sheet.rekap-kepala thead{display:table-header-group}
  .ket-print{font-size:.72rem;color:#635F82;margin-top:12px;line-height:1.55}
  @media (max-width:820px){
    .print-sheet{padding:18px 14px}
    table.sheet.rekap-kepala{min-width:560px}
    .sheet-meta{grid-template-columns:minmax(0,1fr)}
  }
  @media print{
    /* Tema menetapkan body{min-height:100vh} → saat dicetak peramban menambah halaman kosong. */
    html,body{min-height:0 !important;height:auto}
    body{background:#fff;padding:0}
    .toolbar,.no-print{display:none !important}
    .sheet-wrap{max-width:none}
    .print-sheet{box-shadow:none;border:none;padding:0;border-radius:0}
    .sheet-scroll{overflow:visible}
    table.sheet.rekap-kepala{min-width:0}
  }
  @page{size:A4 portrait;margin:16mm 18mm}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="<?= e($urlKembali) ?>">Kembali</a>
  <a class="btn btn-primary" href="<?= e($urlCetak) ?>"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <div class="sheet-head">
      <h2>Rekap Kepala Sekolah/Madrasah dan Nomor HP</h2>
      <p>Aplikasi <?= e(APP_NAME) ?> — sistem informasi supervisi akademik guru</p>
    </div>

    <div class="sheet-meta">
      <div><b>Lingkup</b>: <?= (int) $ringkas['total'] ?> lembaga
        (<?= (int) $ringkas['aktif'] ?> aktif, <?= (int) $ringkas['nonaktif'] ?> nonaktif/ditolak)</div>
      <div><b>Saringan</b>: <?= $saringan ? e(implode('; ', $saringan)) : 'seluruh sekolah/madrasah terdaftar' ?></div>
      <div><b>Kelengkapan</b>: nama kepala terisi <?= (int) $ringkas['kepala'] ?> dari
        <?= (int) $ringkas['total'] ?> lembaga, nomor HP terisi <?= (int) $ringkas['hp'] ?></div>
      <div><b>Dicetak</b>: <?= e(waktu_id_polos(date('Y-m-d H:i:s'))) ?>
        &mdash; oleh <?= e($namaUser) ?> (<?= e(role_label((string) $u['role'])) ?>)</div>
    </div>

    <?php if (!$baris): ?>
      <p><i>Tidak ada sekolah/madrasah yang cocok dengan saringan ini.</i></p>
    <?php else: ?>
      <div class="sheet-scroll">
        <table class="sheet rekap-kepala">
          <thead>
            <tr>
              <th style="width:34px">No</th>
              <th>Nama Sekolah/Madrasah</th>
              <th style="width:30%">Nama Kepala Sekolah</th>
              <th style="width:22%">Nomor HP Kepala Sekolah</th>
            </tr>
          </thead>
          <tbody>
          <?php $n = 0; foreach ($baris as $b): $n++; ?>
            <tr>
              <td class="rk-no"><?= $n ?></td>
              <td class="rk-nama"><?= e(tetap_kata((string) $b['nama'])) ?>
                <?php if (!$b['aktif']): ?>
                  <span class="sub"><?= $b['verifikasi'] === 'ditolak' ? 'Ditolak' : 'Nonaktif' ?></span>
                <?php endif; ?>
              </td>
              <td><?= $b['kepala_ada'] ? e((string) $b['kepala']) : '<span class="kosong">belum diisi</span>' ?></td>
              <td><?= $b['hp_ada'] ? e((string) $b['hp']) : '<span class="kosong">belum diisi</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2">JUMLAH (<?= (int) $ringkas['total'] ?> lembaga)</td>
              <td><?= (int) $ringkas['kepala'] ?> terisi</td>
              <td><?= (int) $ringkas['hp'] ?> terisi</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <p class="ket-print">
        Keterangan: <b>belum diisi</b> berarti nama kepala sekolah atau nomor HP lembaga itu belum
        dilengkapi. Nama kepala sekolah dan nomor HP diambil dari data tiap lembaga pada menu Profil
        &amp; Pengaturan (diisi lembaga) atau Kelola Sekolah (diisi Super Admin); bila nomor HP kosong
        dipakai nomor kontak dari formulir pendaftaran. Urutan: nama sekolah/madrasah (A-Z).
      </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
<?php terjemahkan_selesai(); ?>
