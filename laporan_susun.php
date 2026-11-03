<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

/**
 * MENU "SUSUN LAPORAN" (permintaan pemilik, skema v23) — halaman KEPALA SEKOLAH/MADRASAH
 * (dan Super Admin saat mengelola sebuah sekolah).
 *
 * Halaman ini menyusun dokumen laporan supervisi: kafer (sampul), kata pengantar, Bab 1
 * Pendahuluan, Bab 2 Pelaksanaan Supervisi, Bab 3 Hasil Supervisi (otomatis dari data), dan
 * Bab 4 Kesimpulan & Rekomendasi. Bagian teks memakai TEMPLATE BAWAAN yang dapat disunting dan
 * disimpan PER SEKOLAH (dipakai lagi pada setiap cetak) — lihat inc/laporan.php.
 */
$u = require_login();

// Hanya kepala sekolah/madrasah (peran admin) dan Super Admin dalam konteks sekolah.
// Peran diperiksa SEBELUM require_sekolah() supaya guru & pengawas selalu dijawab 403 — bukan
// dialihkan diam-diam ke halaman lain karena belum memilih sekolah.
if ($u['role'] === 'guru' || $u['role'] === 'pengawas') {
    http_response_code(403);
    exit('Halaman Susun Laporan hanya untuk kepala sekolah/madrasah.');
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

if (!is_admin()) {
    http_response_code(403);
    exit('Halaman Susun Laporan hanya untuk kepala sekolah/madrasah.');
}

// Permintaan pemilik: "Menu susun laporan tidak bisa diakses untuk Lembaga yang belum aktifasi."
// Ditegakkan untuk KETIGA halaman (susun, cetak, unduh Word) supaya laporan tidak bisa dibuat
// lewat alamat langsung.
if (aktivasi_terbatas($sekolah)) {
    aktivasi_halang('laporan', 'laporan_susun.php');
}

$bagian = laporan_bagian();
$kelompok = laporan_kelompok();

// ---------------------------------------------------------------- AKSI (POST)
$f = laporan_filter($_GET, $sid);
if (!array_key_exists('periode', $_GET)) {
    // Bawaan: periode yang cocok dengan Tahun Ajaran + Semester sekolah (bila ada datanya).
    $f['periode'] = laporan_periode_bawaan($sid);
}
$kembali = 'laporan_susun.php' . laporan_query($f);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'simpan') {
        $kirim = $_POST['teks'] ?? [];
        $jml = 0;
        if (is_array($kirim)) {
            foreach (array_keys($bagian) as $kunci) {
                if (!array_key_exists($kunci, $kirim)) {
                    continue; // bagian yang tidak dikirim tidak diubah
                }
                laporan_teks_simpan($sid, $kunci, laporan_teks_bersih((string) $kirim[$kunci]));
                $jml++;
            }
        }
        // Penyaring yang sedang dilihat ikut dibawa kembali supaya pratinjau tidak berubah.
        $fPost = laporan_filter($_POST, $sid);
        flash($jml > 0
            ? 'Laporan tersimpan (' . (int) $jml . ' bagian). Teks ini dipakai lagi pada setiap cetak.'
            : 'Tidak ada bagian laporan yang dikirim.');
        redirect('laporan_susun.php' . laporan_query($fPost));
    }

    if ($aksi === 'reset') {
        laporan_teks_reset($sid);
        flash('Seluruh bagian laporan dikembalikan ke template bawaan.', 'warn');
        $fPost = laporan_filter($_POST, $sid);
        redirect('laporan_susun.php' . laporan_query($fPost));
    }

    redirect('laporan_susun.php');
}

$ringkas = laporan_ringkas($sid, $f);
$tautanCetak = 'laporan_cetak.php' . laporan_query($f);
$tautanWord = 'laporan_docx.php' . laporan_query($f);
$periodeAda = laporan_periode_ada($sid);

page_top('Susun Laporan Supervisi', 'laporan_susun.php');
?>
<div class="alert alert-ok">
  <?= ikon('report', 16) ?>
  <span>
    Menu ini <b>membuat dokumen laporan supervisi</b> sekolah/madrasah ini: kafer (sampul), kata
    pengantar, Bab 1 Pendahuluan, Bab 2 Pelaksanaan, Bab 3 Hasil Supervisi, dan Bab 4 Kesimpulan
    &amp; Rekomendasi. Bagian teks sudah terisi <b>template bawaan</b> — silakan disunting lalu
    <b>Simpan</b>; isinya dipakai lagi pada setiap cetak.
  </span>
</div>

<div class="grid g21 mt16">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= ikon('filter', 16) ?> Pratinjau &amp; Cetak</h2>
        <p>Pilih <b>periode/semester</b> dan <b>rentang tanggal</b> supervisi yang dimasukkan ke Bab 3.</p>
      </div>
    </div>
    <form method="get" action="laporan_susun.php" class="filterbar" id="formFilter">
      <div>
        <label for="periode">Periode / Semester</label>
        <select name="periode" id="periode">
          <option value="">Semua periode yang tercatat</option>
          <?php foreach ($periodeAda as $p): ?>
            <option value="<?= e($p) ?>" <?= $f['periode'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($periodeAda === []): ?>
          <div class="hint">Belum ada periode tercatat pada data supervisi sekolah ini.</div>
        <?php endif; ?>
      </div>
      <div>
        <label for="dari">Dari tanggal</label>
        <input type="date" name="dari" id="dari" value="<?= e($f['dari']) ?>">
      </div>
      <div>
        <label for="sampai">Sampai tanggal</label>
        <input type="date" name="sampai" id="sampai" value="<?= e($f['sampai']) ?>">
      </div>
      <div><button class="btn btn-ghost" type="submit"><?= ikon('search', 15) ?> Terapkan</button></div>
      <div><a class="btn btn-ghost" href="laporan_susun.php?periode=">Semua data</a></div>
    </form>

    <div class="alert alert-ok mt16" style="margin:0">
      <?= ikon('info', 16) ?>
      <span>Laporan memuat <b><?= (int) $ringkas['guru'] ?> guru</b>,
        <b><?= (int) $ringkas['supervisi'] ?> kegiatan supervisi</b>,
        nilai rata-rata <b><?= e(r1($ringkas['rata'])) ?></b>,
        predikat terbanyak <b><?= e((string) $ringkas['predikat']) ?></b>.</span>
    </div>

    <div class="flex mt16">
      <a class="btn btn-primary" id="btnPratinjau" href="<?= e($tautanCetak) ?>" target="_blank" rel="noopener">
        <?= ikon('eye', 16) ?> Lihat Pratinjau Laporan
      </a>
      <a class="btn btn-ghost" id="btnCetak" href="<?= e($tautanCetak . (str_contains($tautanCetak, '?') ? '&' : '?') . 'auto=1') ?>" target="_blank" rel="noopener">
        <?= ikon('print', 16) ?> Cetak / Simpan PDF
      </a>
      <a class="btn btn-ok" id="btnWord" href="<?= e($tautanWord) ?>">
        <?= ikon('doc', 16) ?> Unduh Word (.docx)
      </a>
    </div>
    <div class="hint">
      Pratinjau &amp; cetakan memakai penyaring di atas. Lembar cetak disusun untuk kertas
      <b>A4</b> dengan tanda tangan kepala sekolah/madrasah di bawah.
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= ikon('info', 16) ?> Penanda Otomatis</h2>
        <p>Boleh dipakai di dalam teks; akan diisi otomatis saat dicetak.</p>
      </div>
    </div>
    <div class="chip-list">
      <?php foreach (laporan_penanda() as $tanda => $ket): ?>
        <span class="chip" title="<?= e($ket) ?>"><code><?= e($tanda) ?></code></span>
      <?php endforeach; ?>
    </div>
    <div class="hint">Contoh: <code>Tahun Ajaran {tahun_ajaran}</code> pada Sekolah/Madrasah ini.</div>
  </div>
</div>

<form method="post" action="laporan_susun.php" id="formLaporan">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="simpan">
  <input type="hidden" name="periode" value="<?= e($f['periode']) ?>">
  <input type="hidden" name="dari" value="<?= e($f['dari']) ?>">
  <input type="hidden" name="sampai" value="<?= e($f['sampai']) ?>">

  <?php foreach ($kelompok as $kode => $info): ?>
    <div class="card mt16">
      <div class="card-head">
        <div>
          <h2><?= e($info['judul']) ?></h2>
          <p><?= e($info['ket']) ?></p>
        </div>
        <?php if (in_array($kode, ['bab2', 'bab3'], true)): ?>
          <span class="pill pill-gray">sebagian diisi otomatis</span>
        <?php endif; ?>
      </div>

      <?php if ($kode === 'bab2'): ?>
        <?php // Bagian awal Bab 2 diisi OTOMATIS dari data aplikasi (guru, jadwal, instrumen). ?>
        <div class="alert alert-warn" style="margin:0 0 16px">
          <?= ikon('clipboard', 16) ?>
          <span>Bagian <b>A. Daftar Guru yang Disupervisi</b>, <b>B. Jadwal Supervisi</b>, dan
            <b>C. Instrumen Supervisi</b> diambil otomatis dari Data Guru, Jadwal Supervisi, dan
            daftar menu supervisi aplikasi ini. Yang disunting pada kartu ini hanya bagian
            <b>D</b> dan <b>E</b>.</span>
        </div>
      <?php endif; ?>

      <?php if ($kode === 'bab3'): ?>
        <div class="alert alert-warn" style="margin:0">
          <?= ikon('clipboard', 16) ?>
          <span>Bab 3 <b>tidak disunting</b>: seluruh hasil supervisi setiap guru ditampilkan
            otomatis (ringkasan per guru + rincian menu, nilai, predikat, dan catatan) sesuai
            penyaring <b>Periode/Semester</b> dan <b>rentang tanggal</b> di atas.</span>
        </div>
      <?php else: ?>
        <?php foreach ($bagian as $kunci => $b):
          if ($b['kelompok'] !== $kode) {
            continue;
          }
          $disunting = laporan_teks_disunting($sid, $kunci);
          $isi = laporan_teks_mentah($sid, $kunci);
          $id = 'teks_' . $kunci;
        ?>
          <div class="lap-bagian">
            <div class="lap-bagian-head">
              <label for="<?= e($id) ?>"><?= e($b['label']) ?></label>
              <?php if ($disunting): ?>
                <span class="pill pill-ok">sudah disunting</span>
              <?php else: ?>
                <span class="pill pill-gray">template bawaan</span>
              <?php endif; ?>
            </div>
            <?php if ($b['jenis'] === 'baris'): ?>
              <input type="text" name="teks[<?= e($kunci) ?>]" id="<?= e($id) ?>" value="<?= e($isi) ?>" maxlength="<?= (int) LAPORAN_TEKS_MAKS ?>">
            <?php else: ?>
              <textarea name="teks[<?= e($kunci) ?>]" id="<?= e($id) ?>" rows="<?= $kunci === 'kata_pengantar' ? 9 : ($b['jenis'] === 'daftar' ? 7 : 8) ?>"
                        maxlength="<?= (int) LAPORAN_TEKS_MAKS ?>"><?= e($isi) ?></textarea>
              <div class="hint">
                <?= $b['jenis'] === 'daftar'
                    ? 'Satu baris = satu butir bernomor. Baris kosong dilewati.'
                    : 'Baris kosong memisahkan paragraf baru.' ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card mt16">
    <div class="card-head">
      <div>
        <h2><?= ikon('print', 16) ?> Tanda Tangan</h2>
        <p>Dicetak di paling bawah Bab 4 (Kesimpulan dan Rekomendasi).</p>
      </div>
    </div>
    <table class="tbl">
      <tbody>
        <tr>
          <th style="width:230px">Penanda tangan</th>
          <td>
            <?php $kepala = trim(setting('kepala_sekolah', '')); ?>
            <b><?= e($kepala !== '' ? $kepala : 'Belum diisi') ?></b>
            <span class="tbl-sub">
              <?= e(istilah_sekolah('Kepala Sekolah')) ?> &middot; NIP.
              <?= e(trim(setting('nip_kepala_sekolah', '')) !== '' ? setting('nip_kepala_sekolah', '') : '-') ?>
            </span>
          </td>
        </tr>
        <tr>
          <th>Baris di atas nama</th>
          <td>
            <?= e(ttd_tempat_tanggal()) ?>
            <span class="tbl-sub">Nama kecamatan dari Identitas &amp; Pengaturan + tanggal saat laporan dicetak.</span>
          </td>
        </tr>
      </tbody>
    </table>
    <div class="hint">
      Nama &amp; NIP kepala sekolah/madrasah diambil dari menu <b>Profil &amp; Pengaturan</b>.
      Bila belum diisi, lengkapi lebih dahulu agar lembar laporan tidak kosong.
    </div>
  </div>

  <div class="card mt16">
    <div class="flex">
      <button class="btn btn-primary" type="submit" id="btnSimpan"><?= ikon('check', 16) ?> Simpan Laporan</button>
      <a class="btn btn-ghost" href="<?= e($tautanCetak) ?>" target="_blank" rel="noopener"><?= ikon('eye', 16) ?> Lihat Pratinjau</a>
      <a class="btn btn-ghost" href="<?= e($tautanWord) ?>"><?= ikon('doc', 16) ?> Unduh Word</a>
    </div>
  </div>
</form>

<div class="card mt16">
  <div class="card-head">
    <div>
      <h2><?= ikon('alert', 16) ?> Kembalikan Template Bawaan</h2>
      <p>Seluruh teks laporan sekolah/madrasah ini kembali ke template bawaan aplikasi.</p>
    </div>
  </div>
  <form method="post" action="laporan_susun.php">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="reset">
    <input type="hidden" name="periode" value="<?= e($f['periode']) ?>">
    <input type="hidden" name="dari" value="<?= e($f['dari']) ?>">
    <input type="hidden" name="sampai" value="<?= e($f['sampai']) ?>">
    <button class="btn btn-danger" type="submit" id="btnReset"
            data-confirm="Kembalikan seluruh teks laporan ke template bawaan? Suntingan Anda akan hilang.">
      <?= ikon('trash', 16) ?> Kembalikan ke Template Bawaan
    </button>
  </form>
</div>
<?php
page_bottom();
