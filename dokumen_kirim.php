<?php
/**
 * KIRIM DOKUMEN KE PENGAWAS (akun kepala sekolah/madrasah).
 *
 * Permintaan pemilik: "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen ke
 * pengawas dengan inputan: Nama Dokumen (Pilihan: Kurikulum Madrasah (KM), Rencana Kerja Tahunan
 * (RKT), Rencana Kerja Jangka Menengah (RKJM), Laporan Supervisi, EDM/RKAM, PKG & PTK), Unggah
 * Berkas, Keterangan. Pilihan nama dokumen bisa dirubah/di edit/ dihapus melalui akun super admin."
 *
 * Pilihan pemilik: yang boleh mengirim HANYA kepala sekolah/madrasah (role admin). Pengawas
 * melihat kiriman itu pada menu **Dokumen Madrasah** (dokumen_madrasah.php), terkumpul per madrasah.
 *
 * Formulir memakai `<form enctype="multipart/form-data">` BIASA (tombol submit) sehingga tetap
 * berjalan walau skrip gagal dimuat; validasi di server (upload_dokumen.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
if ($u['role'] !== 'admin' && !is_admin()) {
    http_response_code(403);
    exit('Halaman ini hanya untuk kepala sekolah/madrasah (pengirim dokumen ke pengawas).');
}
$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

$cari = trim((string) ($_GET['cari'] ?? ''));
$jenisPilih = (int) ($_GET['jenis'] ?? 0);
$statusPilih = (string) ($_GET['status'] ?? '');
if ($statusPilih !== '' && !isset(dokumen_status_list()[$statusPilih])) {
    $statusPilih = '';
}
// TAHUN AJARAN (permintaan pemilik): pilihan beberapa tahun ajaran. BAWAAN halaman = tahun ajaran
// aktif sekolah; memilih "Semua tahun ajaran" menampilkan seluruh periode. Nilai yang dipilih
// sekaligus menjadi dasar perhitungan PROGRES.
$taPilihan = dokumen_tahun_pilihan($sekolah);
$taDariUrl = dokumen_ta_normalisasi((string) ($_GET['ta'] ?? ''));
$taDipilih = array_key_exists('ta', $_GET) ? $taDariUrl : dokumen_ta_berlaku('', $sekolah);
if ($taDipilih !== '' && !in_array($taDipilih, $taPilihan, true)) {
    $taDipilih = dokumen_ta_berlaku('', $sekolah);   // tahun ajaran asing diabaikan (bukan galat)
}
$taBerlaku = $taDipilih !== '' ? $taDipilih : dokumen_ta_berlaku('', $sekolah);
// Seluruh dokumen madrasah ini (tanpa saringan) → dasar ringkasan status tinjauan pengawas.
$semuaSaya = dokumen_list_sekolah($sid);
$daftar = dokumen_list_sekolah($sid, $cari, $statusPilih);
if ($jenisPilih > 0) {
    $daftar = array_values(array_filter($daftar, static fn(array $d): bool => (int) ($d['jenis_id'] ?? 0) === $jenisPilih));
}
if ($taDipilih !== '') {
    // Aturan periode yang sama dipakai halaman pengawas, Super Admin, dan perhitungan progres.
    $daftar = array_values(array_filter($daftar,
        static fn(array $d): bool => dokumen_ta_cocok($d, $taDipilih)));
}
$ringkasSaya = dokumen_ringkas_status($semuaSaya);
$jumlahDitinjau = dokumen_ditinjau_jumlah($sid);
// PROGRES PENGIRIMAN (permintaan pemilik): pembandingnya jumlah Nama Dokumen yang diisi Super Admin.
// Kartu progres memakai TAHUN AJARAN YANG SEDANG DIPILIH supaya angkanya selalu sama dengan daftar
// di bawahnya ('' = seluruh tahun ajaran).
$progress = dokumen_progress($sid, $taDipilih);

$jenisList = dokumen_jenis_list(true);
$batas = dokumen_batas_server();
$jml = dokumen_jumlah($sid);
// BATAS MODE TERBATAS (permintaan pemilik): "Lembaga yang belum aktifasi batasi hanya bisa
// mengirim 1 dokumen saja ke pengawas." Dokumen yang sudah ada tetap boleh dihapus/diganti.
$batasDokumen = batas_dokumen_tercapai($sid);
$namaPengawas = [];   // nama pengawas yang membina sekolah ini (untuk keterangan di kepala halaman)
foreach (all('SELECT u.nama FROM pengawas_sekolah ps JOIN users u ON u.id = ps.user_id
              WHERE ps.sekolah_id = ? AND u.aktif = 1 ORDER BY u.nama', [$sid]) as $r) {
    $namaPengawas[] = (string) $r['nama'];
}

page_top('Kirim Dokumen ke Pengawas', 'dokumen_kirim');
?>
<div class="alert alert-ok" style="margin-bottom:18px">
  <?= ikon('send', 16) ?>
  <span>Dokumen yang Anda kirim akan masuk ke akun <b>pengawas</b> pada menu
    <b>Dokumen Madrasah</b><?= $namaPengawas ? ' (' . e(implode(', ', $namaPengawas)) . ')' : '' ?> —
    terkumpul menurut nama sekolah/madrasah sehingga tidak tercampur dengan madrasah lain.</span>
</div>

<?php
// ============================================================================================
// PROGRES PENGIRIMAN DOKUMEN (permintaan pemilik)
// "Tambahkan fitur progress pengiriman dokumen di akun pengawas dan kepala sekolah. Progres dilihat
//  dari jumlah nama dokumen yang diisi lewat super admin."
// Penyebutnya = jumlah Nama Dokumen AKTIF pada menu Jenis Dokumen (Global) milik Super Admin.
// ============================================================================================
?>
<div class="card" id="cardProgressDokumen">
  <div class="card-head">
    <div>
      <h2><?= ikon('chart', 18) ?> Progres Pengiriman Dokumen</h2>
      <p>Perbandingan jumlah <b>Nama Dokumen</b> yang diminta (diatur Super Admin) dengan yang sudah
        Anda kirim<?= $taDipilih !== '' ? ' pada <b>Tahun Ajaran ' . e($taDipilih) . '</b>' : ' pada <b>seluruh tahun ajaran</b>' ?>.</p>
    </div>
    <form method="get" class="flex" style="gap:8px;align-items:flex-end;margin:0">
      <?php // Saringan tahun ajaran halaman ini ikut dipakai oleh kartu progres. ?>
      <input type="hidden" name="cari" value="<?= e($cari) ?>">
      <input type="hidden" name="jenis" value="<?= (int) $jenisPilih ?>">
      <input type="hidden" name="status" value="<?= e($statusPilih) ?>">
      <div>
        <label class="f" for="ta">Tahun Ajaran</label>
        <select id="ta" name="ta">
          <?php foreach ($taPilihan as $t): ?>
            <option value="<?= e($t) ?>" <?= $taDipilih === $t ? 'selected' : '' ?>>T.A. <?= e($t) ?></option>
          <?php endforeach; ?>
          <option value="" <?= $taDipilih === '' ? 'selected' : '' ?>>Semua tahun ajaran</option>
        </select>
      </div>
      <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('filter', 14) ?> Terapkan</button>
    </form>
  </div>

  <?php if ($progress['jenis_kosong']): ?>
    <div class="alert alert-warn" style="margin:0">
      <?= ikon('info', 16) ?>
      <span>Super Admin belum mengisi daftar <b>Nama Dokumen</b> pada menu
        <b>Jenis Dokumen (Global)</b>, jadi progres belum dapat dihitung. Hubungi Super Admin aplikasi.</span>
    </div>
  <?php else: ?>
    <div class="progress-head">
      <div class="progress-angka">
        <b id="progressPersen"><?= (int) $progress['persen'] ?>%</b>
        <span class="xs mut">terkirim</span>
      </div>
      <div class="progress-info">
        <div class="progress-track" role="img"
             aria-label="Progres pengiriman dokumen <?= (int) $progress['persen'] ?> persen">
          <i style="width:<?= (int) $progress['persen'] ?>%"></i>
        </div>
        <p class="sm" style="margin:8px 0 0" id="progressTeks">
          <b><?= (int) $progress['terkirim'] ?></b> dari <b><?= (int) $progress['total'] ?></b> nama dokumen
          sudah dikirim<?= $taDipilih !== '' ? ' (T.A. ' . e($taDipilih) . ')' : ' (seluruh tahun ajaran)' ?> &middot;
          <b><?= (int) $progress['belum'] ?></b> belum dikirim.
          <?php if ((int) $progress['lainnya'] > 0): ?>
            <span class="xs mut">Ada <?= (int) $progress['lainnya'] ?> dokumen lain di luar daftar Nama Dokumen.</span>
          <?php endif; ?>
        </p>
      </div>
    </div>

    <div class="progress-daftar">
      <?php foreach ($progress['daftar'] as $pj): ?>
        <div class="progress-item <?= $pj['terkirim'] ? 'ada' : 'belum' ?>" id="progressJenis<?= (int) $pj['id'] ?>">
          <?= ikon($pj['terkirim'] ? 'check' : 'clock', 14) ?>
          <span class="progress-nama"><?= e($pj['nama']) ?></span>
          <?php if ($pj['terkirim']): ?>
            <span class="pill pill-ok"><?= (int) $pj['jumlah'] ?> berkas</span>
            <?php if ((int) $pj['ditinjau'] > 0): ?>
              <span class="pill pill-gray"><?= (int) $pj['ditinjau'] ?> ditinjau</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="pill pill-warn">belum dikirim</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($progress['belum'] > 0): ?>
      <p class="hint" style="margin-top:12px">Gunakan formulir di samping untuk mengirim dokumen yang
        belum ada. Setiap dokumen yang dikirim otomatis menambah progres di atas.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="grid g4" style="gap:12px" id="ringkasStatusKiriman">
  <div class="nilai-chip"><b><?= (int) $jml ?></b><span>Dokumen terkirim</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasSaya['menunggu'] ?? 0) ?></b><span>Belum ditinjau</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasSaya['disetujui'] ?? 0) ?></b><span>Dokumen disetujui</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasSaya['revisi'] ?? 0) ?></b><span>Dokumen direvisi</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasSaya['ditolak'] ?? 0) ?></b><span>Dokumen ditolak</span></div>
  <div class="nilai-chip"><b><?= count($jenisList) ?></b><span>Pilihan nama dokumen</span></div>
  <div class="nilai-chip"><b><?= e(ukuran_teks($batas)) ?></b><span>Batas ukuran berkas</span></div>
  <div class="nilai-chip"><b><?= count($namaPengawas) ?></b><span>Pengawas pembina</span></div>
</div>

<?php if ($jumlahDitinjau > 0): ?>
  <div class="card card-tight" id="cardHasilTinjauan">
    <div class="card-head">
      <div>
        <h2><?= ikon('check', 18) ?> Hasil Tinjauan Pengawas</h2>
        <p>Penilaian pengawas atas dokumen yang Anda kirim — lengkap dengan keterangan/alasannya.</p>
      </div>
      <span class="chip"><?= (int) $jumlahDitinjau ?> dokumen ditinjau</span>
    </div>
    <div class="komentar-list">
      <?php foreach (dokumen_ditinjau_sekolah($sid, 5) as $dt):
        $st = (string) $dt['status']; ?>
        <div class="komentar-item">
          <div class="komentar-head">
            <b><?= e((string) $dt['nama_dokumen']) ?></b>
            <span class="pill <?= dokumen_status_pil($st) ?>" id="tinjauDok<?= (int) $dt['id'] ?>">
              <?= ikon(dokumen_status_ikon($st), 12) ?> <?= e(dokumen_status_label($st)) ?></span>
            <span class="xs mut">oleh <?= e((string) ($dt['peninjau'] ?: 'Pengawas')) ?>,
              <?= e(waktu_id_polos((string) $dt['ditinjau_pada'])) ?></span>
          </div>
          <p class="komentar-isi"><?= trim((string) ($dt['catatan'] ?? '')) !== ''
            ? nl2br(e((string) $dt['catatan']))
            : '<span class="mut">Tanpa keterangan tambahan dari pengawas.</span>' ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid g21" style="align-items:start">
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Daftar Dokumen Terkirim</h2>
        <p>Seluruh dokumen yang sudah Anda kirim dari sekolah/madrasah ini.</p>
      </div>
      <span class="chip"><?= count($daftar) ?> baris</span>
    </div>

    <?php if (!$jenisList): ?>
      <div class="alert alert-warn" style="margin:0 0 14px">
        <?= ikon('info', 16) ?>
        <span>Daftar <b>Nama Dokumen</b> belum diisi Super Admin, jadi dokumen belum dapat dikirim.
          Hubungi Super Admin aplikasi.</span>
      </div>
    <?php endif; ?>

    <form class="filterbar" method="get" style="margin-bottom:14px">
      <div>
        <label class="f" for="cari">Cari dokumen</label>
        <input type="text" id="cari" name="cari" value="<?= e($cari) ?>" placeholder="nama dokumen / keterangan / berkas">
      </div>
      <div>
        <label class="f" for="jenis">Nama Dokumen</label>
        <select id="jenis" name="jenis">
          <option value="0">Semua nama dokumen</option>
          <?php foreach ($jenisList as $j): ?>
            <option value="<?= (int) $j['id'] ?>" <?= $jenisPilih === (int) $j['id'] ? 'selected' : '' ?>><?= e((string) $j['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="f" for="fTahun">Tahun Ajaran</label>
        <select id="fTahun" name="ta">
          <option value="">Semua tahun ajaran</option>
          <?php foreach ($taPilihan as $t): ?>
            <option value="<?= e($t) ?>" <?= $taDipilih === $t ? 'selected' : '' ?>>T.A. <?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="f" for="status">Status Tinjauan</label>
        <select id="status" name="status">
          <option value="">Semua status</option>
          <?php foreach (dokumen_status_list() as $kunci => $label): ?>
            <option value="<?= e($kunci) ?>" <?= $statusPilih === $kunci ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Saring</button>
      <a class="btn btn-ghost" href="dokumen_kirim.php">Reset</a>
    </form>

    <?php if (!$daftar): ?>
      <div class="empty">
        <?= ikon('doc', 30) ?>
        <h3><?= $jml > 0 ? 'Tidak ada dokumen yang cocok' : 'Belum ada dokumen terkirim' ?></h3>
        <p><?= $jml > 0 ? 'Ubah kata kunci atau saringan nama dokumen/status tinjauan.' : 'Gunakan formulir di samping untuk mengirim dokumen pertama Anda ke pengawas.' ?></p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl" id="tblDokumenKirim">
          <thead>
            <tr><th>Nama Dokumen</th><th style="width:110px">Tahun Ajaran</th><th>Berkas</th><th>Keterangan</th>
              <th style="width:140px">Status Tinjauan</th><th>Dikirim</th><th class="ctr">Aksi</th></tr>
          </thead>
          <tbody>
          <?php foreach ($daftar as $d): $st = (string) ($d['status'] ?? dokumen_status_awal());
            $dTa = dokumen_ta_normalisasi((string) ($d['tahun_ajaran'] ?? '')); ?>
            <tr id="dok<?= (int) $d['id'] ?>">
              <td><b><?= e((string) $d['nama_dokumen']) ?></b>
                <?php if (trim((string) ($d['jenis'] ?? '')) !== '' && (string) $d['jenis'] !== (string) $d['nama_dokumen']): ?>
                  <span class="tbl-sub"><?= e((string) $d['jenis']) ?></span>
                <?php endif; ?>
              </td>
              <td class="xs">
                <?php if ($dTa !== ''): ?>
                  <span class="pill pill-B">T.A. <?= e($dTa) ?></span>
                <?php else: ?>
                  <?php // Dokumen lama (sebelum fitur tahun ajaran) — ditandai dari tanggal unggahnya. ?>
                  <span class="pill pill-gray">dari tanggal</span>
                  <span class="tbl-sub"><?= e((string) (dokumen_tahun_ajaran((string) $d['dibuat']) ?: '-')) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <a class="doc-berkas" href="<?= e((string) $d['url']) ?>" target="_blank" rel="noopener noreferrer">
                  <?= ikon(dokumen_ikon((string) $d['nama_berkas']), 14) ?> <?= e((string) $d['nama_berkas']) ?>
                </a>
                <span class="tbl-sub"><?= e(ukuran_teks((int) $d['ukuran'])) ?></span>
              </td>
              <td class="xs mut" style="max-width:260px"><?= $d['keterangan'] !== '' && $d['keterangan'] !== null
                ? nl2br(e((string) $d['keterangan'])) : '<span class="mut">—</span>' ?></td>
              <td>
                <span class="pill <?= dokumen_status_pil($st) ?>" id="statusDok<?= (int) $d['id'] ?>">
                  <?= ikon(dokumen_status_ikon($st), 12) ?> <?= e(dokumen_status_label($st)) ?></span>
                <?php if ($st !== dokumen_status_awal() && trim((string) ($d['catatan'] ?? '')) !== ''): ?>
                  <span class="tbl-sub">Keterangan/alasan: <b><?= e((string) $d['catatan']) ?></b></span>
                <?php endif; ?>
                <?php if ($st !== dokumen_status_awal()): ?>
                  <span class="tbl-sub">oleh <?= e((string) ($d['ditinjau_oleh'] ?: 'Pengawas')) ?>,
                    <?= e(waktu_id_polos((string) $d['ditinjau_pada'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="xs mut"><?= e(waktu_id_polos((string) $d['dibuat'])) ?>
                <?php if (trim((string) ($d['pengirim'] ?? '')) !== ''): ?><span class="tbl-sub">oleh <?= e((string) $d['pengirim']) ?></span><?php endif; ?>
              </td>
              <td class="ctr">
                <form method="post" action="upload_dokumen.php" data-confirm="Hapus dokumen &quot;<?= e((string) $d['nama_dokumen']) ?>&quot;? Dokumen ini juga hilang dari akun pengawas.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 14) ?> Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <form method="post" action="upload_dokumen.php" enctype="multipart/form-data" class="card" id="formKirimDokumen"
        data-maks="<?= (int) $batas ?>" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="unggah">
    <div class="card-head">
      <div>
        <h2><?= ikon('upload', 16) ?> Kirim Dokumen ke Pengawas</h2>
        <p>Pilih nama dokumen, unggah berkasnya, lalu isi keterangan bila perlu.</p>
      </div>
    </div>

    <?php if ($batasDokumen): ?>
      <?php // BATAS MODE TERBATAS (permintaan pemilik): lembaga yang belum diaktivasi hanya boleh
            // mengirim 1 dokumen. Formulir diganti pemberitahuan + jalan menuju aktivasi. ?>
      <div class="alert alert-warn" id="batasDokumenTercapai" style="margin:0 0 4px">
        <?= ikon('lock', 16) ?>
        <span><b>Aplikasi belum diaktifasi.</b> Mode terbatas hanya mengizinkan
          <b><?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas</b> dan batas itu sudah
          terpakai (<?= (int) $jml ?> dokumen). Hapus dokumen lama bila ingin menggantinya, atau
          lakukan aktifasi agar dapat mengirim dokumen tanpa batas.</span>
      </div>
      <p class="sm mut" style="margin:0 0 14px">Dokumen yang sudah terkirim tetap dapat dilihat dan
        ditinjau pengawas; menghapusnya lalu mengirim dokumen pengganti tetap diperbolehkan.</p>
      <div class="form-actions">
        <a class="btn btn-primary" href="aktivasi.php?batas=dokumen&amp;kembali=dokumen_kirim.php">
          <?= ikon('key', 16) ?> Status &amp; Aktivasi</a>
        <a class="btn btn-ghost" href="dokumen_kirim.php#tblDokumenKirim"><?= ikon('doc', 16) ?> Lihat Dokumen</a>
      </div>
    <?php else: ?>

    <?php // TAHUN AJARAN (permintaan pemilik): diletakkan DI ATAS Nama Dokumen. ?>
    <label class="f" for="ta_kirim">Tahun Ajaran *</label>
    <select id="ta_kirim" name="tahun_ajaran" required>
      <?php foreach ($taPilihan as $t): ?>
        <option value="<?= e($t) ?>" <?= $taBerlaku === $t ? 'selected' : '' ?>>T.A. <?= e($t) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="hint">Pilih tahun ajaran dokumen ini. Daftar berisi beberapa tahun ajaran
      (tahun ajaran aktif sekolah beserta tahun-tahun di sekitarnya).</div>

    <div class="mt16">
      <label class="f" for="jenis_id">Nama Dokumen *</label>
      <select id="jenis_id" name="jenis_id" required>
        <option value="">— pilih nama dokumen —</option>
        <?php foreach ($jenisList as $j): ?>
          <option value="<?= (int) $j['id'] ?>"><?= e((string) $j['nama']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">Daftar pilihan ini diatur Super Admin pada menu <b>Jenis Dokumen (Global)</b>.</div>
    </div>

    <div class="mt16">
      <label class="f" for="berkas">Unggah Berkas *</label>
      <input type="file" id="berkas" name="berkas" required
             accept=".pdf,.docx,.xlsx,.pptx,.csv,.txt,.zip,.jpg,.jpeg,.png,.gif,.webp,.svg">
      <div class="hint" id="berkasHint">
        Format PDF, DOCX, XLSX, PPTX, CSV, TXT, ZIP, atau gambar (JPG/PNG/GIF/WEBP/SVG) &middot;
        maksimal <?= e(ukuran_teks($batas)) ?>.
      </div>
    </div>

    <div class="mt16">
      <label class="f" for="keterangan">Keterangan</label>
      <textarea id="keterangan" name="keterangan" rows="4" maxlength="<?= (int) DOKUMEN_KET_MAKS ?>"
                placeholder="mis. Dokumen kurikulum tahun ajaran ini, mohon diperiksa."></textarea>
      <div class="hint">Keterangan tampil pada daftar dokumen di akun pengawas.</div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="btnKirimDokumen"><?= ikon('send', 16) ?> Kirim Dokumen</button>
      <span class="xs mut" id="dokumenStatus"></span>
    </div>
    <?php endif; ?>
  </form>
</div>
<?php
page_bottom();
