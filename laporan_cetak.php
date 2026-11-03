<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

/**
 * LEMBAR CETAK "SUSUN LAPORAN" (A4 potrait) — kafer, kata pengantar, Bab 1 Pendahuluan,
 * Bab 2 Pelaksanaan Supervisi, Bab 3 Hasil Supervisi, Bab 4 Kesimpulan dan Rekomendasi, dan
 * tanda tangan Kepala Sekolah/Madrasah (dengan baris "tempat, tanggal" di atas namanya).
 *
 * Isi teks diambil dari inc/laporan.php (template bawaan yang dapat disunting kepala sekolah,
 * disimpan per sekolah) dan seluruh angka/tabel diambil dari data supervisi sekolah ini —
 * tidak ada nilai yang ditulis mati di halaman ini.
 */
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();

$u = require_login();

// Hanya kepala sekolah/madrasah (peran admin) dan Super Admin dalam konteks sekolah.
// Peran diperiksa SEBELUM require_sekolah() supaya guru & pengawas selalu dijawab 403 — bukan
// dialihkan diam-diam ke halaman lain karena belum memilih sekolah.
if ($u['role'] === 'guru' || $u['role'] === 'pengawas') {
    http_response_code(403);
    exit('Lembar laporan hanya untuk kepala sekolah/madrasah.');
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

if (!is_admin()) {
    http_response_code(403);
    exit('Lembar laporan hanya untuk kepala sekolah/madrasah.');
}

// Permintaan pemilik: "Menu susun laporan tidak bisa diakses untuk Lembaga yang belum aktifasi."
if (aktivasi_terbatas($sekolah)) {
    aktivasi_halang('laporan', 'laporan_susun.php');
}

$f = laporan_filter($_GET, $sid);
if (!array_key_exists('periode', $_GET)) {
    $f['periode'] = laporan_periode_bawaan($sid);
}

$ringkas = laporan_ringkas($sid, $f);
$guruRingkas = laporan_guru_ringkas($sid, $f);
$daftarSupervisi = laporan_supervisi_list($sid, $f);
$jadwal = laporan_jadwal($sid, $f);
$instrumen = laporan_instrumen($sid, $f);
$guruDaftar = laporan_guru_daftar($sid, $f);
$perGuru = laporan_per_guru($daftarSupervisi);

$kepala = trim(setting('kepala_sekolah', ''));
$nipKepala = trim(setting('nip_kepala_sekolah', ''));
$namaSekolah = setting('nama_sekolah', istilah_sekolah('Sekolah'));
$logo = logo_sekolah();
$predLabel = static fn(?string $p): string => $p ? $p . ' (' . predikat_label((string) $p) . ')' : '-';
$q = laporan_query($f);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan Supervisi — <?= e(tetap_kata($namaSekolah)) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  /* Gaya khusus LEMBAR cetak laporan (pola yang sama dengan cetak.php/cetak_jadwal.php):
     ukuran kertas A4 POTRAIT dan tampilan layar berupa kertas terpusat. */
  body{background:#F3F0FF;padding:26px 18px 40px}
  .lap-wrap{max-width:860px;margin:0 auto}
  .toolbar{max-width:860px;margin:0 auto 18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  @page{size:A4 portrait;margin:18mm 20mm}
  @media print{
    body{background:#fff;padding:0}
    .lap-wrap{max-width:none}
  }
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="toolbar no-print lap-wrap" style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-bottom:18px">
  <a class="btn btn-ghost" href="laporan_susun.php<?= e($q) ?>">Kembali</a>
  <a class="btn btn-ghost" href="laporan_docx.php<?= e($q) ?>"><?= ikon('doc', 16) ?> Unduh Word (.docx)</a>
  <a class="btn btn-primary" href="laporan_cetak.php<?= e($q . ($q === '' ? '?auto=1' : '&auto=1')) ?>"
     onclick="setTimeout(function(){window.print();},350);return false;" data-print><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
</div>

<div class="lap-wrap">

  <?php // ============================== 1. KAFER (SAMPUL) ============================== ?>
  <section class="lap-kertas lap-halaman">
    <div class="lap-kafer">
      <div class="lap-logo">
        <?php if ($logo !== ''): ?>
          <img src="<?= e($logo) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>">
        <?php else: ?>
          <div class="lap-logo-ikon"><?= ikon(istilah_sekolah('Sekolah') === 'Madrasah' ? 'building' : 'school', 34) ?></div>
        <?php endif; ?>
      </div>
      <p class="lap-kafer-nama"><?= e(tetap_kata($namaSekolah)) ?></p>
      <?php // Sampul hanya memakai baris ALAMAT (baris wilayah/email/NPSN cukup pada kop lembar lain). ?>
      <?php foreach (array_slice(kop_baris_identitas(), 0, 1) as $bk): ?>
        <p class="lap-kafer-alamat"><?= e(tetap_kata($bk)) ?></p>
      <?php endforeach; ?>
      <div class="lap-garis"></div>

      <h2 class="lap-kafer-judul"><?= e(laporan_teks_siap($sid, 'sampul_judul', $ringkas)) ?></h2>
      <?php $subJudulSampul = laporan_teks_siap($sid, 'sampul_subjudul', $ringkas); ?>
      <?php if (trim($subJudulSampul) !== ''): ?>
        <p class="lap-kafer-sub"><?= nl2br(e($subJudulSampul)) ?></p>
      <?php endif; ?>

      <div class="lap-kafer-penyusun">
        <span>Disusun oleh:</span>
        <b><?= e(laporan_teks_siap($sid, 'sampul_penyusun', $ringkas)) ?></b>
        <?= e(trim($kepala) !== '' ? $kepala : '-') ?>
        <?php if (trim($nipKepala) !== ''): ?><br><span class="sm">NIP. <?= e($nipKepala) ?></span><?php endif; ?>
        <br><span class="sm"><?= e(tetap_kata($namaSekolah)) ?> &middot; <?= e(laporan_periode_label($f)) ?></span>
      </div>
    </div>
  </section>

  <?php // ============================== 2. KATA PENGANTAR ============================== ?>
  <section class="lap-kertas lap-halaman">
    <h2 class="lap-judul-bab">Kata Pengantar</h2>
    <?= laporan_html_bagian($sid, 'kata_pengantar', $ringkas) ?>
    <div class="lap-kaki-satu">
      <div class="lap-sign">
        <div class="ttd-tempat"><?= e(ttd_tempat_tanggal()) ?></div>
        <div><?= e(istilah_sekolah('Kepala Sekolah')) ?>,</div>
        <div class="space"></div>
        <u><?= e(trim($kepala) !== '' ? $kepala : '-') ?></u><br>
        <span class="sm">NIP. <?= e(trim($nipKepala) !== '' ? $nipKepala : '-') ?></span>
      </div>
    </div>
  </section>

  <?php // ============================== 3. BAB 1 PENDAHULUAN ============================== ?>
  <section class="lap-kertas lap-halaman">
    <h2 class="lap-judul-bab">Bab 1. Pendahuluan</h2>
    <p class="lap-sub-bab">Laporan Hasil Supervisi Akademik <?= e(tetap_kata($namaSekolah)) ?></p>
    <?= laporan_html_bagian($sid, 'latar_belakang', $ringkas) ?>
    <?= laporan_html_bagian($sid, 'dasar_hukum', $ringkas) ?>
    <?= laporan_html_bagian($sid, 'tujuan', $ringkas) ?>
    <?= laporan_html_bagian($sid, 'manfaat', $ringkas) ?>
  </section>

  <?php // ============================== 4. BAB 2 PELAKSANAAN SUPERVISI ============================== ?>
  <section class="lap-kertas lap-halaman">
    <h2 class="lap-judul-bab">Bab 2. Pelaksanaan Supervisi</h2>
    <p class="lap-sub-bab"><?= e(laporan_periode_label($f)) ?></p>

    <h3 class="lap-sub">A. Daftar Guru yang Disupervisi</h3>
    <?php if ($guruDaftar === []): ?>
      <p class="lap-kosong">Belum ada data guru aktif pada sekolah/madrasah ini.</p>
    <?php else: ?>
      <div class="lap-scroll">
      <table class="lap-tbl lap-padat">
        <thead>
          <tr>
            <th class="ctr" style="width:34px">No</th>
            <th>Nama Guru</th>
            <th style="width:100px">NIP/NUPTK</th>
            <th style="width:110px">Mata Pelajaran</th>
            <th style="width:100px">Jabatan</th>
            <th class="ctr" style="width:80px">Jumlah<br>Supervisi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($guruDaftar as $i => $g): ?>
            <tr>
              <td class="ctr"><?= $i + 1 ?></td>
              <td><?= e((string) $g['nama']) ?></td>
              <td><?= e(trim((string) $g['nip']) !== '' ? (string) $g['nip'] : '-') ?></td>
              <td><?= e(trim((string) $g['mapel']) !== '' ? (string) $g['mapel'] : '-') ?></td>
              <td><?= e(trim((string) $g['jabatan']) !== '' ? (string) $g['jabatan'] : '-') ?></td>
              <td class="ctr"><?= (int) $g['jml'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5">Jumlah guru</td>
            <td class="ctr"><?= count($guruDaftar) ?></td>
          </tr>
        </tfoot>
      </table>
      </div>
    <?php endif; ?>

    <h3 class="lap-sub">B. Jadwal Supervisi</h3>
    <?php if ($jadwal === []): ?>
      <p class="lap-kosong">Belum ada jadwal supervisi yang tercatat untuk periode ini.</p>
    <?php else: ?>
      <div class="lap-scroll">
      <table class="lap-tbl lap-padat">
        <thead>
          <tr>
            <th class="ctr" style="width:30px">No</th>
            <th style="width:78px">Tanggal</th>
            <th>Nama Guru</th>
            <th style="width:95px">Mata Pelajaran</th>
            <th style="width:60px">Kelas</th>
            <th style="width:48px">Jam ke-</th>
            <th style="width:95px">Supervisor</th>
            <th style="width:90px">Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jadwal as $i => $j): ?>
            <tr>
              <td class="ctr"><?= $i + 1 ?></td>
              <td><?= e(tanggal_id((string) $j['tanggal'])) ?></td>
              <td><?= e((string) $j['nama_guru']) ?></td>
              <td><?= e((string) ($j['mapel'] ?: '-')) ?></td>
              <td><?= e((string) ($j['kelas'] ?: '-')) ?></td>
              <td class="ctr"><?= e((string) ($j['jam_ke'] ?: '-')) ?></td>
              <td><?= e((string) ($j['supervisor'] ?: '-')) ?></td>
              <td><?= e((string) ($j['keterangan'] ?: '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <h3 class="lap-sub">C. Instrumen Supervisi</h3>
    <?php if ($instrumen === []): ?>
      <p class="lap-kosong">Belum ada menu supervisi yang aktif.</p>
    <?php else: ?>
      <div class="lap-scroll">
      <table class="lap-tbl lap-padat">
        <thead>
          <tr>
            <th class="ctr" style="width:34px">No</th>
            <th style="width:210px">Menu / Instrumen Supervisi</th>
            <th>Deskripsi</th>
            <th class="ctr" style="width:70px">Jumlah<br>Butir</th>
            <th class="ctr" style="width:80px">Jumlah<br>Supervisi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($instrumen as $i => $ins): ?>
            <tr>
              <td class="ctr"><?= $i + 1 ?></td>
              <td><?= e((string) $ins['nama']) ?></td>
              <td><?= e(trim((string) $ins['deskripsi']) !== '' ? (string) $ins['deskripsi'] : '-') ?></td>
              <td class="ctr"><?= (int) $ins['jml_butir'] ?></td>
              <td class="ctr"><?= (int) $ins['jml'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <?= laporan_html_bagian($sid, 'teknik_analisis', $ringkas) ?>
    <?= laporan_html_bagian($sid, 'prosedur', $ringkas) ?>
  </section>

  <?php // ============================== 5. BAB 3 HASIL SUPERVISI ============================== ?>
  <section class="lap-kertas lap-halaman">
    <h2 class="lap-judul-bab">Bab 3. Hasil Supervisi</h2>
    <p class="lap-sub-bab"><?= e(laporan_periode_label($f)) ?></p>

    <h3 class="lap-sub">A. Ringkasan Hasil Supervisi per Guru</h3>
    <?php if ($guruRingkas === []): ?>
      <p class="lap-kosong">Belum ada hasil supervisi yang tercatat untuk penyaring ini.</p>
    <?php else: ?>
      <div class="lap-scroll">
      <table class="lap-tbl lap-padat">
        <thead>
          <tr>
            <th class="ctr" style="width:34px">No</th>
            <th>Nama Guru</th>
            <th style="width:100px">NIP/NUPTK</th>
            <th style="width:105px">Mata Pelajaran</th>
            <th class="ctr" style="width:70px">Jumlah<br>Supervisi</th>
            <th class="ctr" style="width:56px">Nilai</th>
            <th class="ctr" style="width:95px">Predikat</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($guruRingkas as $i => $g): ?>
            <tr>
              <td class="ctr"><?= $i + 1 ?></td>
              <td><?= e((string) $g['nama']) ?></td>
              <td><?= e(trim((string) $g['nip']) !== '' ? (string) $g['nip'] : '-') ?></td>
              <td><?= e(trim((string) $g['mapel']) !== '' ? (string) $g['mapel'] : '-') ?></td>
              <td class="ctr"><?= (int) $g['jml'] ?></td>
              <td class="ctr"><?= e(r1($g['nilai_rata'])) ?></td>
              <td class="ctr"><?= e($predLabel((string) $g['predikat'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4">Jumlah / rata-rata</td>
            <td class="ctr"><?= (int) $ringkas['supervisi'] ?></td>
            <td class="ctr"><?= e(r1($ringkas['rata'])) ?></td>
            <td class="ctr"><?= (int) $ringkas['guru'] ?> guru</td>
          </tr>
        </tfoot>
      </table>
      </div>
    <?php endif; ?>

    <h3 class="lap-sub">B. Rincian Hasil Supervisi Setiap Guru</h3>
    <?php if ($perGuru === []): ?>
      <p class="lap-kosong">Belum ada rincian supervisi untuk penyaring ini.</p>
    <?php else: ?>
      <?php foreach ($perGuru as $nama => $rows): $g0 = $rows[0]; ?>
        <div class="lap-guru">
          <p class="lap-guru-nama">
            <?= e((string) $nama) ?>
            <span>&middot; NIP <?= e(trim((string) $g0['nip']) !== '' ? (string) $g0['nip'] : '-') ?>
              &middot; <?= e(trim((string) $g0['mapel']) !== '' ? (string) $g0['mapel'] : 'Mata pelajaran -') ?></span>
          </p>
          <div class="lap-scroll">
      <table class="lap-tbl lap-padat">
            <thead>
              <tr>
                <th class="ctr" style="width:30px">No</th>
                <th style="width:150px">Menu Supervisi</th>
                <th style="width:74px">Tanggal</th>
                <th class="ctr" style="width:60px">Skor</th>
                <th class="ctr" style="width:48px">Nilai</th>
                <th class="ctr" style="width:78px">Predikat</th>
                <th>Catatan Hasil Supervisi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $s): ?>
                <tr>
                  <td class="ctr"><?= $i + 1 ?></td>
                  <td><?= e((string) $s['instrumen']) ?></td>
                  <td><?= e(tanggal_id((string) $s['tanggal'])) ?></td>
                  <td class="ctr"><?= e(r1($s['total_skor'])) ?> / <?= e(r1($s['skor_maks'])) ?></td>
                  <td class="ctr"><?= e(r1($s['nilai'])) ?></td>
                  <td class="ctr"><?= e($predLabel((string) $s['predikat'])) ?></td>
                  <td>
                    <b>Kelebihan:</b> <?= e(trim((string) $s['kelebihan']) !== '' ? (string) $s['kelebihan'] : '-') ?><br>
                    <b>Perbaikan:</b> <?= e(trim((string) $s['kekurangan']) !== '' ? (string) $s['kekurangan'] : '-') ?><br>
                    <b>Tindak lanjut:</b> <?= e(trim((string) $s['tindak_lanjut']) !== '' ? (string) $s['tindak_lanjut'] : '-') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <?php // ============================== 6. BAB 4 KESIMPULAN & REKOMENDASI ============================== ?>
  <section class="lap-kertas lap-halaman">
    <h2 class="lap-judul-bab">Bab 4. Kesimpulan dan Rekomendasi</h2>
    <p class="lap-sub-bab"><?= e(laporan_periode_label($f)) ?></p>
    <?= laporan_html_bagian($sid, 'kesimpulan', $ringkas) ?>
    <?= laporan_html_bagian($sid, 'rekomendasi', $ringkas) ?>

    <div class="lap-kaki-satu">
      <div class="lap-sign">
        <?php // Permintaan pemilik: baris "nama tempat, tanggal, tahun" DI ATAS tanda tangan kepala. ?>
        <div class="ttd-tempat"><?= e(ttd_tempat_tanggal()) ?></div>
        <div><?= e(istilah_sekolah('Kepala Sekolah')) ?>,</div>
        <div class="space"></div>
        <u><?= e(trim($kepala) !== '' ? $kepala : '-') ?></u><br>
        <span class="sm">NIP. <?= e(trim($nipKepala) !== '' ? $nipKepala : '-') ?></span>
      </div>
    </div>

    <p class="xs mut center" style="margin-top:26px">
      Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada <?= e(tanggal_id(date('Y-m-d'))) ?>.
    </p>
  </section>

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
