<?php
/**
 * TEMA TAMPILAN WARNA APLIKASI — halaman Super Admin.
 *
 * Permintaan pemilik: "Pada halaman super admin tambahkan fitur untuk mengganti tema tampilan warna
 * aplikasi secara keseluruhan, dari halaman depan (landing) hingga halaman akun. Minimal ada 5
 * pilihan tema yang bisa dipilih oleh super admin."
 *
 * Pilihan tema disimpan TINGKAT APLIKASI (`app_pengaturan` kunci `tema_app`) sehingga berlaku untuk
 * SEMUA halaman (halaman depan/landing, halaman masuk, halaman pendaftaran, seluruh halaman akun,
 * sampai lembar cetak) dan semua sekolah/madrasah sekaligus. Tata letak, bentuk, dan fungsi aplikasi
 * TIDAK berubah — hanya rona warnanya (lihat blok "TEMA TAMPILAN WARNA APLIKASI" di assets/style.css
 * dan modul inc/tema.php).
 *
 * Halaman ini KHUSUS Super Admin (peran lain 403) dan lintas sekolah → istilah generik "Sekolah".
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_super();
// Halaman lintas sekolah/madrasah → istilah generik "Sekolah".
$GLOBALS['__vs_istilah_nonaktif'] = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'simpan') {
        $pilih = (string) ($_POST['tema'] ?? '');
        if (tema_simpan($pilih)) {
            flash('Tema tampilan aplikasi diganti menjadi "' . tema_nama() . '". Rona warna baru langsung '
                . 'berlaku pada halaman depan (landing), halaman masuk, halaman pendaftaran, seluruh '
                . 'halaman akun, dan lembar cetak.');
        } else {
            // Kunci tema tak dikenal TIDAK disimpan apa adanya (mis. kiriman yang diubah dari peramban).
            flash('Tema yang dipilih tidak dikenal, jadi tema sebelumnya tetap dipakai.', 'warn');
        }
        redirect('tema_tampilan.php');
    } elseif ($aksi === 'bawaan') {
        tema_bawaan_kembalikan();
        flash('Tema tampilan dikembalikan ke bawaan aplikasi ("' . tema_nama() . '").', 'warn');
        redirect('tema_tampilan.php');
    }
    redirect('tema_tampilan.php');
}

$daftar = tema_daftar();
$aktif = tema_app();
$bawaan = tema_masih_bawaan();

page_top('Tema Tampilan', 'tema_tampilan');
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('palette', 16) ?> Tema Tampilan Aplikasi</h2>
      <p>Pilih <b>rona warna aplikasi secara keseluruhan</b> — berlaku mulai <b>halaman depan
        (landing)</b>, halaman masuk, halaman pendaftaran, seluruh <b>halaman akun</b> (dashboard,
        data guru, supervisi, laporan, jadwal, pengaturan, dst.), sampai <b>lembar cetak</b>.
        Yang berubah hanya warnanya: tata letak, bentuk kartu, dan seluruh fungsi tetap sama.</p>
    </div>
    <div class="flex">
      <span class="chip" id="chipTema"><?= $bawaan ? 'tema bawaan' : 'sudah disesuaikan' ?></span>
      <?php if (!$bawaan): ?>
        <form method="post" data-confirm="Kembalikan tema tampilan aplikasi ke bawaan (Indigo Violet)?">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="bawaan">
          <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('switch', 14) ?> Kembalikan Bawaan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="alert alert-ok" style="margin-bottom:4px">
    <?= ikon('check', 16) ?>
    <span>Tema yang sedang dipakai: <b id="temaAktif"><?= e($daftar[$aktif]['nama']) ?></b> —
      berlaku untuk seluruh sekolah/madrasah pada aplikasi ini.</span>
  </div>

  <div class="tema-grid" id="gridTema">
    <?php foreach ($daftar as $kunci => $t): ?>
      <?php $dipakai = $kunci === $aktif; ?>
      <div class="card tema-kartu<?= $dipakai ? ' on' : '' ?>" id="tema-<?= e($kunci) ?>">
        <div class="tema-kartu-head">
          <span class="tema-nama"><?= e($t['nama']) ?></span>
          <?php if ($dipakai): ?>
            <span class="pill pill-ok">sedang dipakai</span>
          <?php endif; ?>
        </div>
        <div class="tema-contoh" aria-hidden="true">
          <i style="background:<?= e($t['warna'][0]) ?>"></i>
          <i style="background:linear-gradient(135deg,<?= e($t['warna'][0]) ?>,<?= e($t['warna'][1]) ?>)"></i>
          <i style="background:<?= e($t['warna'][2]) ?>"></i>
        </div>
        <p class="tema-ket"><?= e($t['ket']) ?></p>
        <div class="tema-warna">
          <span><?= e($t['warna'][0]) ?></span>
          <span><?= e($t['warna'][1]) ?></span>
          <span><?= e($t['warna'][2]) ?></span>
        </div>
        <div class="tema-aksi">
          <?php if ($dipakai): ?>
            <button class="btn btn-ghost btn-sm" type="button" disabled>Tema aktif</button>
          <?php else: ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="simpan">
              <input type="hidden" name="tema" value="<?= e($kunci) ?>">
              <button class="btn btn-primary btn-sm" type="submit" data-tema="<?= e($kunci) ?>">
                <?= ikon('check', 14) ?> Pakai tema ini
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="divider"></div>
  <p class="tema-catatan">
    <?= ikon('info', 14) ?>
    <b>Catatan:</b> pilihan tema berlaku untuk <b>semua pengguna dan semua sekolah/madrasah</b> pada
    aplikasi ini (bukan per sekolah), karena halaman depan dipakai bersama. Warna predikat
    (A/B/C/D), warna status (berhasil/peringatan/galat), serta pas foto dan logo sekolah
    <b>tidak ikut berubah</b> supaya maknanya tetap konsisten. Bila tampilan terasa belum berubah,
    muat ulang halaman sekali untuk menyegarkan berkas gaya yang tersimpan di peramban.
  </p>
</div>
<?php
page_bottom();
