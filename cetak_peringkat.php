<?php
/**
 * LEMBAR CETAK "Peringkat Sekolah" (A4 landscape) — dasbor lintas sekolah Super Admin.
 *
 * Permintaan pemilik (v1.27.0): pada kartu "Peringkat Sekolah" di dasbor Super Admin ditambahkan
 * tombol **Cetak** dan **Unduh Word**. Halaman ini adalah lembar cetak (A4 landscape) yang isinya
 * SAMA dengan tabel di dasbor — datanya diambil dari peringkat_sekolah() (satu sumber data), jadi
 * angka, label masa aktif, dan urutannya tidak mungkin berbeda.
 *
 * Halaman ini lintas sekolah (tidak ada satu lembaga sebagai identitas), sehingga:
 *   - TIDAK memakai kop sekolah (kop_cetak()) — judul + keterangan aplikasi saja;
 *   - istilah memakai bentuk generik "Sekolah" ($GLOBALS['__vs_istilah_nonaktif']);
 *   - tidak ada tanda tangan (rekapan lintas sekolah, bukan dokumen lembaga).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Tampung keluaran agar istilah bisa disesuaikan sebelum dikirim (pola semua lembar cetak).
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();
// Halaman lintas sekolah: jangan menerjemahkan "Sekolah" menjadi "Madrasah".
$GLOBALS['__vs_istilah_nonaktif'] = true;

$u = require_login();
if (!is_super()) {
    http_response_code(403);
    exit('Lembar cetak peringkat sekolah hanya untuk Super Admin.');
}

$pk = peringkat_sekolah();
$baris = $pk['baris'];
$tot = $pk['tot'];
if (!$baris) {
    flash('Belum ada sekolah yang dapat dicetak.', 'err');
    redirect('sekolah.php');
}

$autoPrint = isset($_GET['auto']);
$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Peringkat Sekolah — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .sheet-wrap{max-width:1100px;margin:0 auto}
  .toolbar{max-width:1100px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .sheet-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table.sheet.peringkat th,table.sheet.peringkat td{padding:6px 8px}
  table.sheet.peringkat td.nama b{display:block}
  table.sheet.peringkat td .sub{display:block;font-size:.74rem;color:#635F82}
  table.sheet.peringkat tfoot td{font-weight:700;background:rgba(99,95,130,.06)}
  @media (max-width:820px){
    .print-sheet{padding:18px 14px}
    table.sheet.peringkat{min-width:720px}
    .sheet-meta{grid-template-columns:minmax(0,1fr)}
  }
  @media print{
    body{background:#fff;padding:0}
    .toolbar,.no-print{display:none !important}
    .sheet-wrap{max-width:none}
    .print-sheet{box-shadow:none;border:none;padding:0;border-radius:0}
    .sheet-scroll{overflow:visible}
    table.sheet.peringkat{min-width:0}
  }
  @page{size:A4 landscape;margin:12mm}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print">
  <a class="btn btn-ghost" href="index.php?semua=1">Kembali</a>
  <a class="btn btn-ghost" href="peringkat_docx.php"><?= ikon('doc', 16) ?> Unduh Word</a>
  <a class="btn btn-primary" href="cetak_peringkat.php?auto=1"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="sheet-wrap">
  <div class="print-sheet">
    <div class="sheet-head">
      <h2>Peringkat Sekolah</h2>
      <p><?= e(APP_NAME) ?> — Sistem Informasi Supervisi Akademik Guru</p>
      <p>Lintas sekolah/madrasah (semua lembaga aktif)</p>
    </div>

    <div class="sheet-meta">
      <div><b>Lingkup</b>: Seluruh sekolah yang disetujui &amp; aktif (<?= (int) $tot['sekolah'] ?> lembaga)</div>
      <div><b>Urutan</b>: Masa aktif paling lama di atas, lalu nama sekolah</div>
      <div><b>Dicetak</b>: <?= e(waktu_id_polos(date('Y-m-d H:i:s'))) ?></div>
      <div><b>Oleh</b>: <?= e($namaUser) ?> (<?= e(role_label((string) $u['role'])) ?>)</div>
    </div>

    <div class="sheet-scroll">
      <table class="sheet peringkat">
        <thead>
          <tr>
            <th class="ctr" style="width:34px">No</th>
            <th>Sekolah</th>
            <th style="width:180px">Masa Aktif</th>
            <th class="ctr" style="width:56px">Guru</th>
            <th class="ctr" style="width:78px">Supervisi</th>
            <th class="ctr" style="width:76px">Rata-rata</th>
            <th class="ctr" style="width:76px">Predikat</th>
            <th class="ctr" style="width:96px">Cakupan</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($baris as $ix => $r): ?>
          <tr>
            <td class="ctr"><?= $ix + 1 ?></td>
            <td class="nama">
              <b><?= e((string) $r['nama']) ?></b>
              <span class="sub">
                NPSN <?= e((string) ($r['npsn'] ?: '-')) ?> &middot; <?= e((string) ($r['kepala_sekolah'] ?: '-')) ?>
              </span>
            </td>
            <td><?= e(peringkat_masa_teks($r)) ?></td>
            <td class="ctr"><?= (int) $r['guru'] ?></td>
            <td class="ctr"><?= (int) $r['supervisi'] ?><?php if ((int) $r['draft'] > 0): ?><span class="sub">+<?= (int) $r['draft'] ?> draf</span><?php endif; ?></td>
            <td class="ctr"><?= $r['rata_nilai'] !== null ? e(r1($r['rata_nilai'])) : 'belum ada' ?></td>
            <td class="ctr"><?= $r['predikat'] !== null ? e((string) $r['predikat']) : '—' ?></td>
            <td class="ctr"><?= (int) $r['cakupan'] ?>%<span class="sub"><?= (int) $r['guru_dinilai'] ?>/<?= (int) $r['guru'] ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="ctr" colspan="3">JUMLAH (<?= (int) $tot['sekolah'] ?> sekolah)</td>
            <td class="ctr"><?= (int) $tot['guru'] ?></td>
            <td class="ctr"><?= (int) $tot['supervisi'] ?></td>
            <td class="ctr"><?= $tot['rata'] !== null ? e(r1($tot['rata'])) : '—' ?></td>
            <td class="ctr"><?= $tot['predikat'] !== null ? e((string) $tot['predikat']) : '—' ?></td>
            <td class="ctr">—</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p class="xs" style="margin-top:14px;color:#635F82">
      Keterangan: <b>Guru</b> = guru aktif, <b>Supervisi</b> = penilaian berstatus final (draf
      ditampilkan terpisah), <b>Rata-rata</b> = rata-rata nilai supervisi final, <b>Predikat</b> =
      A/B/C/D menurut ambang masing-masing sekolah, <b>Cakupan</b> = jumlah guru yang sudah
      disupervisi dibagi jumlah guru aktif. <b>Masa Aktif</b> dibaca dari masa aktif 1 tahun / masa
      uji coba yang tercatat pada menu Status &amp; Aktivasi.
    </p>

    <p class="xs mut center" style="margin-top:18px">
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
