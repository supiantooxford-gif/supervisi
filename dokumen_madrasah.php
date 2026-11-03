<?php
/**
 * DOKUMEN MADRASAH — menu baru pada AKUN PENGAWAS (hanya melihat & mengunduh).
 *
 * Permintaan pemilik: "Hasil unggah dokumen akan masuk di akun pengawas di menu baru
 * (Dokumen Madrasah) yang isinya hasil unggahan di setiap madrasah. Di filter/terkumpul berdasarkan
 * nama madrasah agar tidak campur antara madrasah satu dengan lainnya."
 * Pilihan pemilik: SATU TABEL dengan kolom **Madrasah** + saringan per madrasah.
 *
 * Data yang tampil HANYA dokumen dari sekolah binaan pengawas yang sedang masuk (anti-IDOR:
 * `dokumen_binaan()` menyaring ke daftar binaan). Super Admin dalam konteks sekolah juga boleh
 * membuka halaman ini untuk memeriksa — lihat penjagaan di bawah.
 *
 * FITUR TINJAUAN (permintaan pemilik): "Pada daftar dokumen madrasah yang ada di akun pengawas, pada
 * saat pengawas melihat setiap dokumen tersebut tambahkan fitur: Dokumen disetujui, dokumen direvisi,
 * dokumen ditolak dan tambahkan keterangannya/alasannya." → formulir tinjauan per baris (di dalam
 * elemen <details> supaya tetap bekerja TANPA JavaScript) berisi pilihan status + isian
 * keterangan/alasan, dan kolom Status pada tabel. Hanya PENGAWAS yang boleh meninjau.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$pengawas = is_pengawas();

// Halaman ini untuk PENGAWAS (melihat dokumen madrasah binaannya) dan SUPER ADMIN (pemilik
// aplikasi). Kepala sekolah/madrasah & guru DITOLAK: dokumen madrasah lain tidak boleh terlihat
// (kepala sekolah memakai menu "Kirim Dokumen ke Pengawas" untuk melihat kirimannya sendiri).
if (!$pengawas && !is_super()) {
    http_response_code(403);
    exit('Halaman ini untuk pengawas sekolah/madrasah.');
}

// ---------------------------------------------------------------- aksi TINJAUAN (pengawas)
// Peran diperiksa SEBELUM CSRF: pengguna yang tidak berhak memang tidak pernah melihat formulirnya,
// jadi jawabannya harus 403 (bukan 419) — pola yang sama dengan aktivasi.php & laporan.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$pengawas) {
        http_response_code(403);
        exit('Hanya pengawas sekolah/madrasah yang dapat meninjau dokumen madrasah.');
    }
    csrf_guard();
    $aksiT = (string) ($_POST['aksi'] ?? '');
    if ($aksiT === 'tinjau') {
        $did = (int) ($_POST['id'] ?? 0);
        $statusBaru = (string) ($_POST['status'] ?? dokumen_status_awal());
        $catatanBaru = komentar_potong((string) ($_POST['catatan'] ?? ''), (int) DOKUMEN_CATATAN_MAKS);

        // WAJIB milik madrasah BINAAN pengawas ini (anti-IDOR) — bukan sekadar ada di basis data.
        $d = dokumen_by_id($did);
        if (!$d || !pengawas_punya((int) $u['id'], (int) $d['sekolah_id'])) {
            flash('Dokumen itu tidak ditemukan pada madrasah binaan Anda.', 'err');
            redirect('dokumen_madrasah.php');
        }
        if (!isset(dokumen_status_list()[$statusBaru])) {
            flash('Status tinjauan tidak dikenal.', 'err');
            redirect('dokumen_madrasah.php?madrasah=' . (int) $d['sekolah_id']);
        }
        // Alasan WAJIB untuk "revisi" & "ditolak" — pengawas harus menjelaskan apa yang harus
        // diperbaiki / mengapa ditolak. Untuk "disetujui" keterangan bersifat opsional.
        if (dokumen_status_wajib_catatan($statusBaru) && $catatanBaru === '') {
            flash('Keterangan/alasan wajib diisi untuk status "'
                . dokumen_status_label($statusBaru) . '". Tuliskan apa yang harus diperbaiki/ditindaklanjuti.', 'err');
            redirect('dokumen_madrasah.php?madrasah=' . (int) $d['sekolah_id'] . '&tinjau=' . $did . '#tinjau' . $did);
        }
        dokumen_tinjau($did, $statusBaru, $catatanBaru, (string) ($u['nama'] ?? 'Pengawas'));
        // Nama madrasah diambil terpisah: dokumen_by_id() mengembalikan baris tabel APA ADANYA
        // (tanpa kolom hasil JOIN `madrasah`) — memakai $d['madrasah'] memicu
        // "Undefined array key" yang keluar SEBELUM header sehingga redirect gagal.
        $namaMadrasah = (string) val('SELECT nama FROM sekolah WHERE id = ?', [(int) $d['sekolah_id']], '');
        flash('Dokumen "' . (string) $d['nama_dokumen'] . '" (' . $namaMadrasah . ') ditandai '
            . strtoupper(dokumen_status_label($statusBaru)) . '. Hasil tinjauan langsung terlihat oleh kepala sekolah/madrasah pengirim.');
        redirect('dokumen_madrasah.php?madrasah=' . (int) $d['sekolah_id'] . '#dok' . $did);
    }
    redirect('dokumen_madrasah.php');
}

$fMadrasah = (int) ($_GET['madrasah'] ?? 0);
$fJenis = (int) ($_GET['jenis'] ?? 0);
$fStatus = (string) ($_GET['status'] ?? '');
if ($fStatus !== '' && !isset(dokumen_status_list()[$fStatus])) {
    $fStatus = '';
}
$fCari = trim((string) ($_GET['cari'] ?? ''));
// TAHUN AJARAN (permintaan pemilik): saringan periode dokumen sekaligus dasar PROGRES PENGIRIMAN.
$fTa = dokumen_ta_normalisasi((string) ($_GET['ta'] ?? ''));
// Pilihan tahun ajaran: seluruh tahun ajaran yang punya dokumen pada binaan (pengawas) atau
// pada sekolah mana pun (Super Admin), ditambah tahun ajaran aktif bila ada konteks sekolah.
$pilihanTa = dokumen_tahun_ada($pengawas ? 0 : $fMadrasah);
$taPilihan = $pilihanTa;
if (!$taPilihan) {
    $taPilihan = dokumen_tahun_pilihan(current_sekolah());
}
if ($fTa !== '' && !in_array($fTa, $taPilihan, true)) {
    $fTa = '';   // saringan tahun ajaran asing diabaikan (bukan 403)
}

if ($pengawas) {
    $binaan = sekolah_binaan((int) $u['id'], true);
    $namaBinaan = [];
    foreach ($binaan as $b) {
        $namaBinaan[(int) $b['id']] = (string) $b['nama'];
    }
    // Saringan madrasah hanya boleh binaan sendiri (selain itu diabaikan, bukan 403,
    // supaya saringan yang salah tulis tidak mengunci halaman).
    if ($fMadrasah > 0 && !isset($namaBinaan[$fMadrasah])) {
        $fMadrasah = 0;
    }
    $daftar = dokumen_binaan((int) $u['id'], ['sekolah' => $fMadrasah, 'jenis' => $fJenis,
        'cari' => $fCari, 'status' => $fStatus]);
    $jumlahPer = dokumen_per_madrasah((int) $u['id']);
    $totalSemua = 0;
    foreach ($jumlahPer as $r) {
        $totalSemua += (int) $r['jumlah'];
    }
    $namaTampil = $namaBinaan;
} else {
    // Super Admin (pemilik aplikasi): seluruh dokumen lintas madrasah.
    $daftar = dokumen_list(['sekolah' => $fMadrasah, 'jenis' => $fJenis, 'cari' => $fCari, 'status' => $fStatus]);
    $totalSemua = dokumen_jumlah();
    $jumlahPer = [];
    foreach (all('SELECT d.sekolah_id, s.nama, COUNT(*) jml FROM dokumen_madrasah d
                  JOIN sekolah s ON s.id = d.sekolah_id GROUP BY d.sekolah_id ORDER BY s.nama') as $r) {
        $jumlahPer[(int) $r['sekolah_id']] = ['nama' => (string) $r['nama'], 'jumlah' => (int) $r['jml']];
    }
    $namaTampil = [];
    foreach (all('SELECT id, nama FROM sekolah ORDER BY nama') as $r) {
        $namaTampil[(int) $r['id']] = (string) $r['nama'];
    }
    $GLOBALS['__vs_istilah_nonaktif'] = true;   // lintas sekolah → istilah generik
}

$jenisList = dokumen_jenis_list(false);
// Ringkasan status dihitung dari SELURUH dokumen binaan (bukan hanya yang tampil setelah saringan),
// supaya pengawas tahu berapa dokumen yang masih menunggu ditinjau.
$semuaBinaan = $pengawas
    ? dokumen_binaan((int) $u['id'], [])
    : dokumen_list([]);
$ringkasStatus = dokumen_ringkas_status($semuaBinaan);

// Saringan tahun ajaran diterapkan pada daftar yang tampil (aturan yang sama dengan binaan/kepala).
if ($fTa !== '') {
    $daftar = array_values(array_filter($daftar, static fn(array $d): bool => dokumen_ta_cocok($d, $fTa)));
}

// ============================================================================================
// PROGRES PENGIRIMAN DOKUMEN PER BINAAN (permintaan pemilik)
// "Tambahkan fitur progress pengiriman dokumen di akun pengawas dan kepala sekolah. Progres dilihat
//  dari jumlah nama dokumen yang diisi lewat super admin."
// Penyebutnya = jumlah Nama Dokumen AKTIF pada menu Jenis Dokumen (Global) milik Super Admin.
// ============================================================================================
$progressBinaan = $pengawas ? dokumen_progress_binaan((int) $u['id'], $fTa) : [];
$progressRata = $progressBinaan ? dokumen_progress_rata($progressBinaan) : 0;
$progressTotal = dokumen_jenis_jumlah(true);
// Ringkasan per Nama Dokumen: berapa binaan yang sudah mengirim (agar pengawas tahu apa yang kurang).
$progressJenis = [];
foreach ($progressBinaan as $sidB => $pb) {
    foreach ($pb['progress']['daftar'] as $pj) {
        $kunci = (int) $pj['id'];
        if (!isset($progressJenis[$kunci])) {
            $progressJenis[$kunci] = ['nama' => (string) $pj['nama'], 'ada' => 0, 'jumlah' => 0];
        }
        if ($pj['terkirim']) {
            $progressJenis[$kunci]['ada']++;
        }
        $progressJenis[$kunci]['jumlah'] += (int) $pj['jumlah'];
    }
}

page_top('Dokumen Madrasah', 'dokumen_madrasah');
?>
<div class="alert alert-ok" style="margin-bottom:18px">
  <?= ikon('doc', 16) ?>
  <span>Kumpulan dokumen yang dikirim kepala sekolah/madrasah kepada pengawas (KM, RKT, RKJM,
    Laporan Supervisi, EDM/RKAM, PKG &amp; PTK, dan nama lain yang diatur Admin aplikasi).
    Baris dikelompokkan menurut <b>nama madrasah pada kolom Madrasah</b> dan dapat disaring per madrasah.
    <?php if ($pengawas): ?>Setiap dokumen dapat Anda <b>tinjau</b>: tandai <b>Dokumen Disetujui</b>,
      <b>Dokumen Direvisi</b>, atau <b>Dokumen Ditolak</b> beserta keterangan/alasannya — hasilnya
      langsung terlihat oleh kepala sekolah/madrasah pengirim.<?php else: ?>Status tinjauan diisi oleh
      pengawas pembina masing-masing madrasah.<?php endif; ?></span>
</div>

<div class="grid g4" style="gap:12px" id="ringkasStatusDokumen">
  <div class="nilai-chip"><b><?= (int) $totalSemua ?></b><span>Total dokumen</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['menunggu'] ?? 0) ?></b><span>Belum ditinjau</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['disetujui'] ?? 0) ?></b><span>Dokumen disetujui</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['revisi'] ?? 0) ?></b><span>Dokumen direvisi</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['ditolak'] ?? 0) ?></b><span>Dokumen ditolak</span></div>
  <div class="nilai-chip"><b><?= count($daftar) ?></b><span>Baris ditampilkan</span></div>
  <div class="nilai-chip"><b><?= count(array_filter($jumlahPer, static fn(array $r): bool => (int) $r['jumlah'] > 0)) ?></b><span><?= $pengawas ? 'Binaan mengirim' : 'Sekolah mengirim' ?></span></div>
  <div class="nilai-chip"><b><?= count($jenisList) ?></b><span>Jenis dokumen</span></div>
</div>

<?php if ($pengawas && $progressBinaan): ?>
  <?php // ==========================================================================================
        // PROGRES PENGIRIMAN DOKUMEN per madrasah binaan (permintaan pemilik). Penyebutnya jumlah
        // Nama Dokumen yang diisi Super Admin (menu "Jenis Dokumen (Global)").
        // ========================================================================================== ?>
  <div class="card" id="cardProgressPengawas">
    <div class="card-head">
      <div>
        <h2><?= ikon('chart', 18) ?> Progres Pengiriman Dokumen Binaan</h2>
        <p>Berapa <b>Nama Dokumen</b> yang diminta (diatur Super Admin) sudah dikirim tiap madrasah
          binaan<?= $fTa !== '' ? ' pada <b>T.A. ' . e($fTa) . '</b>' : '' ?>.</p>
      </div>
      <div class="flex" style="gap:8px;align-items:center">
        <span class="pill <?= $progressRata >= 80 ? 'pill-ok' : ($progressRata >= 40 ? 'pill-warn' : 'pill-err') ?>">
          Rata-rata <?= (int) $progressRata ?>%
        </span>
        <span class="chip"><?= count($progressBinaan) ?> binaan &middot; <?= (int) $progressTotal ?> nama dokumen</span>
      </div>
    </div>

    <div class="progress-daftar">
      <?php foreach ($progressJenis as $pj): ?>
        <div class="progress-item <?= $pj['ada'] >= count($progressBinaan) && count($progressBinaan) > 0 ? 'ada' : 'belum' ?>">
          <?= ikon('doc', 14) ?>
          <span class="progress-nama"><?= e($pj['nama']) ?></span>
          <span class="pill <?= $pj['ada'] > 0 ? 'pill-ok' : 'pill-warn' ?>">
            <?= (int) $pj['ada'] ?>/<?= count($progressBinaan) ?> binaan mengirim</span>
          <span class="xs mut"><?= (int) $pj['jumlah'] ?> berkas</span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap mt16">
      <table class="tbl" id="tblProgressPengawas">
        <thead>
          <tr><th>Madrasah Binaan</th><th style="width:220px">Progres</th>
            <th class="ctr">Terkirim</th><th class="ctr">Belum</th><th>Nama Dokumen Belum Dikirim</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($progressBinaan as $sidB => $pb): $p = $pb['progress']; ?>
          <tr id="progressBinaan<?= (int) $sidB ?>">
            <td><b><?= e((string) $pb['nama']) ?></b></td>
            <td>
              <div class="progress-track sm" role="img"
                   aria-label="Progres <?= (int) $p['persen'] ?> persen">
                <i style="width:<?= (int) $p['persen'] ?>%"></i>
              </div>
              <span class="tbl-sub"><b><?= (int) $p['persen'] ?>%</b> &middot;
                <?= (int) $p['terkirim'] ?>/<?= (int) $p['total'] ?> nama dokumen</span>
            </td>
            <td class="ctr"><span class="pill pill-ok"><?= (int) $p['terkirim'] ?></span></td>
            <td class="ctr"><span class="pill <?= (int) $p['belum'] > 0 ? 'pill-warn' : 'pill-gray' ?>"><?= (int) $p['belum'] ?></span></td>
            <td class="xs mut">
              <?php
              $belumNama = [];
              foreach ($p['daftar'] as $pj) {
                  if (!$pj['terkirim']) {
                      $belumNama[] = (string) $pj['nama'];
                  }
              }
              ?>
              <?= $belumNama ? e(implode(', ', $belumNama)) : '<span class="mut">lengkap</span>' ?>
              <?php if ((int) $p['lainnya'] > 0): ?>
                <span class="tbl-sub">+<?= (int) $p['lainnya'] ?> dokumen di luar daftar</span>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn btn-ghost btn-sm" href="dokumen_madrasah.php?madrasah=<?= (int) $sidB ?>#cardProgressPengawas">
                <?= ikon('eye', 13) ?> Lihat</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><b>Rata-rata binaan</b></td>
            <td class="ctr"><b><?= (int) $progressTotal > 0 ? (int) array_sum(array_map(static fn(array $r): int => (int) $r['progress']['terkirim'], $progressBinaan)) : 0 ?></b></td>
            <td class="ctr"><b><?= (int) array_sum(array_map(static fn(array $r): int => (int) $r['progress']['belum'], $progressBinaan)) ?></b></td>
            <td colspan="2" class="xs mut">Persentase rata-rata <b><?= (int) $progressRata ?>%</b></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="hint">Progres dihitung dari jumlah <b>Nama Dokumen</b> yang diisi Super Admin pada menu
      <b>Jenis Dokumen (Global)</b> dibandingkan dokumen yang benar-benar dikirim madrasah itu.
      Kolom terakhir menyebut nama dokumen yang belum dikirim.</p>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('filter', 16) ?> Saring Per Madrasah</h2>
      <p>Pilih nama madrasah supaya dokumen satu madrasah tidak tercampur dengan yang lain.</p>
    </div>
    <?php if ($fMadrasah > 0 || $fJenis > 0 || $fCari !== '' || $fStatus !== ''): ?>
      <a class="btn btn-ghost btn-sm" href="dokumen_madrasah.php"><?= ikon('switch', 14) ?> Tampilkan semua</a>
    <?php endif; ?>
  </div>
  <form class="filterbar" method="get" id="formSaringDokumen">
    <div>
      <label class="f" for="madrasah">Madrasah</label>
      <select id="madrasah" name="madrasah">
        <option value="0">— semua madrasah<?= $pengawas ? ' binaan' : '' ?> —</option>
        <?php foreach ($jumlahPer as $sid => $r): ?>
          <option value="<?= (int) $sid ?>" <?= $fMadrasah === (int) $sid ? 'selected' : '' ?>>
            <?= e((string) $r['nama']) ?> (<?= (int) $r['jumlah'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="jenis">Nama Dokumen</label>
      <select id="jenis" name="jenis">
        <option value="0">Semua nama dokumen</option>
        <?php foreach ($jenisList as $j): ?>
          <option value="<?= (int) $j['id'] ?>" <?= $fJenis === (int) $j['id'] ? 'selected' : '' ?>><?= e((string) $j['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="ta">Tahun Ajaran</label>
      <select id="ta" name="ta">
        <option value="">Semua tahun ajaran</option>
        <?php foreach ($taPilihan as $t): ?>
          <option value="<?= e($t) ?>" <?= $fTa === $t ? 'selected' : '' ?>>T.A. <?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="status">Status Tinjauan</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <?php foreach (dokumen_status_list() as $kunci => $label): ?>
          <option value="<?= e($kunci) ?>" <?= $fStatus === $kunci ? 'selected' : '' ?>>
            <?= e($label) ?><?= $kunci === 'menunggu' ? ' (' . (int) ($ringkasStatus['menunggu'] ?? 0) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="cari">Cari</label>
      <input type="text" id="cari" name="cari" value="<?= e($fCari) ?>" placeholder="nama dokumen / keterangan / berkas">
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Tampilkan</button>
    <a class="btn btn-ghost" href="dokumen_madrasah.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h2>Daftar Dokumen Madrasah</h2>
      <p>Satu tabel semua madrasah — kolom <b>Madrasah</b> menunjukkan asal dokumennya.</p>
    </div>
    <span class="chip"><?= count($daftar) ?> baris</span>
  </div>

  <?php if (!$daftar): ?>
    <div class="empty">
      <?= ikon('doc', 34) ?>
      <h3>Belum ada dokumen</h3>
      <p><?= $pengawas
        ? ($totalSemua > 0
            ? 'Tidak ada dokumen yang cocok dengan saringan Anda. Coba tampilkan semua madrasah.'
            : 'Belum ada kepala sekolah/madrasah binaan Anda yang mengirim dokumen. Dokumen akan muncul di sini setelah dikirim.')
        : 'Belum ada dokumen yang dikirim oleh sekolah/madrasah mana pun.' ?></p>
      <?php if ($fMadrasah > 0 || $fJenis > 0 || $fCari !== '' || $fStatus !== ''): ?>
        <a class="btn btn-ghost" href="dokumen_madrasah.php"><?= ikon('switch', 16) ?> Tampilkan semua dokumen</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl tbl-dokumen-madrasah" id="tblDokumenMadrasah">
        <thead>
          <tr>
            <th style="width:46px">No</th>
            <th>Madrasah</th>
            <th>Nama Dokumen</th>
            <th style="width:110px">Tahun Ajaran</th>
            <th>Berkas</th>
            <th>Keterangan</th>
            <th style="width:130px">Status Tinjauan</th>
            <th>Dikirim</th>
            <th style="width:120px">Tinjau</th>
          </tr>
        </thead>
        <tbody>
        <?php $no = 0; $madrasahSebelum = null; foreach ($daftar as $d): $no++; $namaM = (string) $d['madrasah']; ?>
          <?php if ($fMadrasah === 0 && $madrasahSebelum !== null && strcasecmp($madrasahSebelum, $namaM) !== 0): ?>
            <tr class="baris-pemisah"><td colspan="9"></td></tr>
          <?php endif; ?>
          <?php $madrasahSebelum = $namaM; $st = (string) ($d['status'] ?? dokumen_status_awal()); ?>
          <tr id="dok<?= (int) $d['id'] ?>">
            <td class="ctr xs mut"><?= $no ?></td>
            <td><b><?= e(tetap_kata($namaM)) ?></b></td>
            <td><b><?= e((string) $d['nama_dokumen']) ?></b>
              <?php if (trim((string) ($d['jenis'] ?? '')) !== '' && (string) $d['jenis'] !== (string) $d['nama_dokumen']): ?>
                <span class="tbl-sub"><?= e((string) $d['jenis']) ?></span>
              <?php endif; ?>
            </td>
            <td class="xs">
              <?php $dTa = dokumen_ta_normalisasi((string) ($d['tahun_ajaran'] ?? '')); ?>
              <?php if ($dTa !== ''): ?>
                <span class="pill pill-B">T.A. <?= e($dTa) ?></span>
              <?php else: ?>
                <span class="pill pill-gray" title="Dokumen sebelum fitur tahun ajaran — dihitung dari tanggal unggah">
                  <?= e((string) (dokumen_tahun_ajaran((string) $d['dibuat']) ?: '-')) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <a class="doc-berkas" href="<?= e((string) $d['url']) ?>" target="_blank" rel="noopener noreferrer">
                <?= ikon(dokumen_ikon((string) $d['nama_berkas']), 14) ?> <?= e((string) $d['nama_berkas']) ?>
              </a>
              <span class="tbl-sub"><?= e(ukuran_teks((int) $d['ukuran'])) ?></span>
            </td>
            <td class="xs mut" style="max-width:280px"><?= ($d['keterangan'] ?? '') !== '' && $d['keterangan'] !== null
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
            <td>
              <?php if ($pengawas): ?>
                <?php // Formulir tinjauan per dokumen. Memakai <details> (toggle bawaan peramban)
                      // sehingga tetap bekerja walau skrip gagal dimuat. ?>
                <details class="tinjau-dok" id="tinjau<?= (int) $d['id'] ?>"<?= ((int) ($_GET['tinjau'] ?? 0) === (int) $d['id']) ? ' open' : '' ?>>
                  <summary class="btn btn-ghost btn-sm"><?= ikon('edit', 13) ?> Tinjau</summary>
                  <form method="post" class="tinjau-form" data-confirm="Simpan hasil tinjauan dokumen &quot;<?= e((string) $d['nama_dokumen']) ?>&quot;? Kepala sekolah/madrasah pengirim akan melihat hasilnya.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="tinjau">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <label class="f" for="status<?= (int) $d['id'] ?>">Hasil tinjauan</label>
                    <select id="status<?= (int) $d['id'] ?>" name="status" required>
                      <?php foreach (dokumen_status_list() as $kunci => $label): ?>
                        <option value="<?= e($kunci) ?>" <?= $st === $kunci ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label class="f mt8" for="catatan<?= (int) $d['id'] ?>">Keterangan / Alasan</label>
                    <textarea id="catatan<?= (int) $d['id'] ?>" name="catatan" rows="3"
                              maxlength="<?= (int) DOKUMEN_CATATAN_MAKS ?>"
                              placeholder="mis. Lampiran belum lengkap — mohon dilengkapi pada bagian …"><?= e((string) ($d['catatan'] ?? '')) ?></textarea>
                    <div class="hint">Wajib diisi untuk <b>Dokumen Direvisi</b> dan <b>Dokumen Ditolak</b>;
                      opsional untuk <b>Dokumen Disetujui</b>. Pilih <b>Belum Ditinjau</b> untuk membatalkan tinjauan.</div>
                    <button class="btn btn-primary btn-sm mt8" type="submit"><?= ikon('check', 14) ?> Simpan Tinjauan</button>
                  </form>
                </details>
              <?php else: ?>
                <span class="xs mut">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Klik nama berkas untuk membukanya di tab baru. Pengiriman dokumen dilakukan oleh
      kepala sekolah/madrasah; <b>peninjauan</b> (disetujui / direvisi / ditolak beserta keterangan
      atau alasannya) dilakukan oleh pengawas dan hasilnya langsung terlihat pada akun kepala
      sekolah/madrasah pengirim.</p>
  <?php endif; ?>
</div>
<?php
page_bottom();
