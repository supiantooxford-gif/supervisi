<?php
/**
 * LEMBAR CETAK "DAFTAR AKUN PENGAWAS" (A4 POTRAIT) — Supervisi Guru v1.29.0.
 *
 * Permintaan pemilik (tangkapan layar kepala kartu "Daftar Akun Pengawas" dengan kotak merah):
 * *"Yang saya tandai warna merah tambahkan fitur download dan cetak. Hasil download/cetak seperti
 * contoh."* Contohnya tabel sederhana berkolom **No, Pengawas Bina, Madrasah Binaan (bernomor
 * 1..n), Masa Aktif** — TANPA kop sekolah dan TANPA tanda tangan.
 *
 * Kolom Masa Aktif mengacu pada masa aktif **sekolah binaannya** (bukan akun pengawasnya), jadi
 * teksnya dirangkum `pengawas_masa_teks()`: sisa masa aktif terdekat ("15 hari" untuk masa uji
 * coba, "365 hari" untuk masa aktif 1 tahun) + rincian jenis binaan bila lebih dari satu.
 *
 * Data diambil dari helper bersama `pengawas_binaan_ringkas()` sehingga urutan akun, daftar binaan,
 * dan angka masa aktifnya SAMA dengan halaman Akun Pengawas dan unduhan Word (`pengawas_docx.php`).
 *
 * Akses: Super Admin (sama dengan halaman Akun Pengawas). Halaman ini hanya MEMBACA data.
 * Pemakaian: cetak_pengawas.php  /  cetak_pengawas.php?auto=1 (langsung dialog cetak)
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
    exit('Lembar daftar akun pengawas ini hanya untuk Super Admin.');
}

$ringkas = pengawas_binaan_ringkas();
$baris = $ringkas['baris'];
$tot = $ringkas['tot'];
$autoPrint = isset($_GET['auto']);
$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar Akun Pengawas — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:860px;margin:0 auto}
  .toolbar{max-width:860px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .sheet-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  /* Tabel daftar pengawas: kolom binaan berisi daftar bernomor, jadi barisnya dibuat rapat. */
  table.sheet.pengawas th,table.sheet.pengawas td{padding:6px 8px;vertical-align:top}
  table.sheet.pengawas th{text-align:center}
  table.sheet.pengawas ol{margin:0;padding-left:20px}
  table.sheet.pengawas ol li{margin:0 0 1px;font-size:.82rem;line-height:1.4}
  table.sheet.pengawas td.p-masa{font-size:.82rem;line-height:1.45}
  table.sheet.pengawas td .sub{display:block;font-size:.72rem;color:#635F82;margin-top:2px}
  table.sheet.pengawas tfoot td{font-weight:700;background:rgba(99,95,130,.06)}
  /* Satu baris tidak boleh terpotong antar halaman saat dicetak. */
  table.sheet.pengawas tr{break-inside:avoid}
  .ket-print{font-size:.72rem;color:#635F82;margin-top:12px;line-height:1.55}
  @media (max-width:820px){
    .print-sheet{padding:18px 14px}
    table.sheet.pengawas{min-width:640px}
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
    table.sheet.pengawas{min-width:0}
  }
  @page{size:A4 portrait;margin:16mm 18mm}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="pengawas.php">Kembali</a>
  <a class="btn btn-ghost" href="pengawas_docx.php"><?= ikon('doc', 16) ?> Unduh Word</a>
  <a class="btn btn-primary" href="cetak_pengawas.php?auto=1"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <div class="sheet-head">
      <h2>Data Sekolah/Madrasah di Aplikasi <?= e(APP_NAME) ?> Berdasarkan Pengawas Bina dan Masa Aktif</h2>
      <p>Lintas sekolah/madrasah (semua lembaga aktif)</p>
    </div>

    <div class="sheet-meta">
      <div><b>Lingkup</b>: <?= (int) $tot['akun'] ?> akun pengawas (<?= (int) $tot['akun_aktif'] ?> aktif),
        <?= (int) $tot['binaan'] ?> penugasan binaan aktif</div>
      <div><b>Urutan</b>: nama pengawas (A-Z), sekolah binaan diurutkan menurut nama</div>
      <div><b>Dicetak</b>: <?= e(waktu_id_polos(date('Y-m-d H:i:s'))) ?></div>
      <div><b>Oleh</b>: <?= e($namaUser) ?> (<?= e(role_label((string) $u['role'])) ?>)</div>
    </div>

    <?php if (!$baris): ?>
      <p><i>Belum ada akun pengawas untuk dicetak.</i></p>
    <?php else: ?>
      <div class="sheet-scroll">
        <table class="sheet pengawas">
          <thead>
            <tr>
              <th style="width:36px">No</th>
              <th style="width:23%">Pengawas Bina</th>
              <th>Sekolah/Madrasah Binaan</th>
              <th style="width:23%">Masa Aktif</th>
            </tr>
          </thead>
          <tbody>
          <?php $n = 0; foreach ($baris as $b): $n++; ?>
            <tr>
              <td class="ctr"><?= $n ?></td>
              <td>
                <b><?= e(tetap_kata((string) $b['nama'])) ?></b>
                <?php if (!$b['aktif']): ?>
                  <span class="sub"><?= $b['ditinjau'] ? 'Nonaktif' : 'Nonaktif (menunggu ditinjau)' ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$b['binaan']): ?>
                  <span class="sub">Belum ada sekolah/madrasah binaan</span>
                <?php else: ?>
                  <ol>
                    <?php foreach ($b['binaan'] as $sk): ?>
                      <li><?= e(tetap_kata((string) $sk['nama'])) ?></li>
                    <?php endforeach; ?>
                  </ol>
                  <?php if ((int) $b['nonaktif'] > 0): ?>
                    <span class="sub"><?= (int) $b['nonaktif'] ?> binaan nonaktif tidak dihitung</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="p-masa"><?= e($b['masa_teks']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="ctr">&nbsp;</td>
              <td>JUMLAH (<?= (int) $tot['akun'] ?> pengawas)</td>
              <td><?= (int) $tot['binaan'] ?> penugasan binaan aktif</td>
              <td><?= e(pengawas_masa_teks($tot)) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>

    <div class="ket-print">
      Keterangan: kolom <b>Masa Aktif</b> merangkum masa aktif seluruh sekolah/madrasah binaan aktif
      pada baris itu &mdash; angka hari = sisa masa aktif terdekat (masa uji coba <b>15 hari</b> bagi
      lembaga yang belum melakukan aktivasi, atau sisa <b>masa aktif 1 tahun</b> bagi yang sudah),
      <b>tanpa batas</b> = masa aktif tanpa tanggal berakhir, dan <b>terkunci</b> = masa aktif/masa uji
      sudah habis sehingga aplikasi lembaga itu terkunci sampai kode aktivasi dimasukkan. Yang
      dihitung hanya binaan aktif; binaan nonaktif tidak dapat dibuka pengawas.
    </div>

    <p class="xs mut center" style="margin-top:16px">
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
