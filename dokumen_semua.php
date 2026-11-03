<?php
/**
 * SEMUA DOKUMEN MADRASAH — menu SUPER ADMIN.
 *
 * Permintaan pemilik: *"Pada halaman super admin tambahkan menu untuk menampilkan seluruh dokumen
 * yang diunggah kepala sekolah dan ditampilkan per sekolah per tahun ajaran."*
 *
 * Pilihan pemilik (jangan diubah tanpa bertanya): **tahun ajaran dihitung dari TANGGAL UNGGAH
 * dokumen** dengan periode **1 Juli – 30 Juni** (bukan dari tahun ajaran aktif sekolah), sehingga
 * dokumen lama tetap masuk kelompok yang benar walau sekolahnya kelak mengganti tahun ajaran.
 *
 * Halaman ini LINTAS sekolah → memakai istilah generik "Sekolah/Madrasah"
 * (`$GLOBALS['__vs_istilah_nonaktif']`), dan nama resmi sekolah dibungkus `tetap_kata()`.
 * Hanya Super Admin yang boleh membuka (kepala sekolah melihat kirimannya sendiri pada menu
 * "Kirim Dokumen ke Pengawas", pengawas pada menu "Dokumen Madrasah").
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

require_super();
$GLOBALS['__vs_istilah_nonaktif'] = true;   // lintas sekolah/madrasah → istilah generik

// ---------------------------------------------------------------- saringan
$fSekolah = (int) ($_GET['sekolah'] ?? 0);
$fTahun = trim((string) ($_GET['tahun'] ?? ''));
$fJenis = (int) ($_GET['jenis'] ?? 0);
$fStatus = (string) ($_GET['status'] ?? '');
if ($fStatus !== '' && !isset(dokumen_status_list()[$fStatus])) {
    $fStatus = '';
}
$fCari = trim((string) ($_GET['cari'] ?? ''));

// Daftar sekolah & tahun ajaran yang benar-benar punya dokumen (dasar pilihan saringan).
$sekolahAda = all('SELECT d.sekolah_id, s.nama, s.jenis, COUNT(*) AS jml
                   FROM dokumen_madrasah d JOIN sekolah s ON s.id = d.sekolah_id
                   GROUP BY d.sekolah_id, s.nama, s.jenis
                   ORDER BY s.nama COLLATE NOCASE');
$namaSekolah = [];
foreach ($sekolahAda as $r) {
    $namaSekolah[(int) $r['sekolah_id']] = ['nama' => (string) $r['nama'], 'jenis' => (string) $r['jenis'], 'jml' => (int) $r['jml']];
}
if ($fSekolah > 0 && !isset($namaSekolah[$fSekolah])) {
    $fSekolah = 0;   // saringan sekolah asing diabaikan (bukan 403)
}
$tahunAda = dokumen_tahun_ada();
if ($fTahun !== '' && !in_array($fTahun, $tahunAda, true)) {
    $fTahun = '';
}
$jenisList = dokumen_jenis_list(false);

$adaSaringan = $fSekolah > 0 || $fTahun !== '' || $fJenis > 0 || $fStatus !== '' || $fCari !== '';

// ---------------------------------------------------------------- data
$daftar = dokumen_semua(['sekolah' => $fSekolah, 'tahun' => $fTahun, 'jenis' => $fJenis,
    'status' => $fStatus, 'cari' => $fCari]);
$kelompok = dokumen_urut_sekolah_tahun($daftar);

// Ringkasan selalu dihitung dari SELURUH dokumen (tanpa saringan) supaya gambaran menyeluruh tetap ada.
$semua = dokumen_semua([]);
$ringkasStatus = dokumen_ringkas_status($semua);
$sekolahKirim = [];
$taAdaDokumen = [];
foreach ($semua as $d) {
    $sekolahKirim[(int) $d['sekolah_id']] = true;
    $ta = (string) ($d['ta'] ?? '');
    if ($ta !== '') {
        $taAdaDokumen[$ta] = true;
    }
}
$jumlahTa = count($taAdaDokumen);

// Matriks ringkasan: sekolah × tahun ajaran.
$matriks = [];
foreach ($semua as $d) {
    $sid = (int) $d['sekolah_id'];
    $ta = (string) ($d['ta'] ?? '');
    $ta = $ta !== '' ? $ta : 'Tidak tercatat';
    $matriks[$sid]['nama'] = (string) $d['madrasah'];
    $matriks[$sid]['jenis'] = (string) ($d['jenis_sekolah'] ?? 'umum');
    $matriks[$sid]['ta'][$ta] = (int) ($matriks[$sid]['ta'][$ta] ?? 0) + 1;
}
$kolomTa = array_keys($taAdaDokumen);
rsort($kolomTa);
if (array_filter(array_keys($matriks), static fn(int $k): bool => isset($matriks[$k]['ta']['Tidak tercatat']))) {
    $kolomTa[] = 'Tidak tercatat';
}
uasort($matriks, static fn(array $a, array $b): int => strcasecmp((string) $a['nama'], (string) $b['nama']));

page_top('Semua Dokumen Madrasah', 'dokumen_semua');
?>
<div class="alert alert-ok" style="margin-bottom:18px">
  <?= ikon('folder', 16) ?>
  <span>Seluruh dokumen yang diunggah kepala sekolah/madrasah ke pengawas, <b>dikelompokkan per
    sekolah/madrasah dan per tahun ajaran</b>. Tahun ajaran dihitung dari <b>tanggal unggah</b>
    (periode <b>1 Juli – 30 Juni</b>) sehingga dokumen lama tetap berada pada kelompoknya.</span>
</div>

<div class="grid g4" style="gap:12px" id="ringkasDokumenSemua">
  <div class="nilai-chip"><b><?= count($semua) ?></b><span>Total dokumen</span></div>
  <div class="nilai-chip"><b><?= count($sekolahKirim) ?></b><span>Sekolah/madrasah mengirim</span></div>
  <div class="nilai-chip"><b><?= (int) $jumlahTa ?></b><span>Tahun ajaran</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['menunggu'] ?? 0) ?></b><span>Belum ditinjau</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['disetujui'] ?? 0) ?></b><span>Disetujui</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['revisi'] ?? 0) ?></b><span>Direvisi</span></div>
  <div class="nilai-chip"><b><?= (int) ($ringkasStatus['ditolak'] ?? 0) ?></b><span>Ditolak</span></div>
  <div class="nilai-chip"><b><?= count($daftar) ?></b><span>Baris ditampilkan</span></div>
</div>

<?php if ($matriks): ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('chart', 16) ?> Rekap per Sekolah per Tahun Ajaran</h2>
      <p>Jumlah dokumen setiap sekolah/madrasah pada tiap tahun ajaran (dari tanggal unggah).</p>
    </div>
    <span class="chip"><?= count($matriks) ?> sekolah &middot; <?= count($kolomTa) ?> periode</span>
  </div>
  <div class="table-wrap">
    <table class="tbl dok-rekap-tbl" id="tblRekapDokumenTahun">
      <thead>
        <tr>
          <th style="width:48px">No</th>
          <th>Sekolah / Madrasah</th>
          <?php foreach ($kolomTa as $ta): ?>
            <th class="ctr">T.A. <?= e($ta) ?></th>
          <?php endforeach; ?>
          <th class="ctr">Jumlah</th>
        </tr>
      </thead>
      <tbody>
      <?php $no = 0; $totalKolom = array_fill_keys($kolomTa, 0); $totalSemua2 = 0; ?>
      <?php foreach ($matriks as $sid => $r): $no++; $jumlahBaris = array_sum($r['ta']); $totalSemua2 += $jumlahBaris; ?>
        <tr>
          <td class="ctr xs mut"><?= $no ?></td>
          <td><b><?= e(tetap_kata((string) $r['nama'])) ?></b>
            <span class="tbl-sub"><?= e(jenis_sekolah_teks((string) $r['jenis'])) ?></span></td>
          <?php foreach ($kolomTa as $ta): $n = (int) ($r['ta'][$ta] ?? 0); $totalKolom[$ta] += $n; ?>
            <td class="ctr"><?= $n > 0
              ? '<a href="dokumen_semua.php?sekolah=' . (int) $sid . '&amp;tahun=' . e(rawurlencode($ta)) . '"><b>' . $n . '</b></a>'
              : '<span class="mut">0</span>' ?></td>
          <?php endforeach; ?>
          <td class="ctr"><b><?= $jumlahBaris ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2"><b>JUMLAH</b></td>
          <?php foreach ($kolomTa as $ta): ?><td class="ctr"><b><?= (int) $totalKolom[$ta] ?></b></td><?php endforeach; ?>
          <td class="ctr"><b><?= (int) $totalSemua2 ?></b></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('filter', 16) ?> Saring</h2>
      <p>Pilih sekolah/madrasah dan/atau tahun ajaran untuk mempersempit daftar di bawah.</p>
    </div>
    <?php if ($adaSaringan): ?>
      <a class="btn btn-ghost btn-sm" href="dokumen_semua.php"><?= ikon('switch', 14) ?> Tampilkan semua</a>
    <?php endif; ?>
  </div>
  <form class="filterbar" method="get" id="formSaringDokumenSemua">
    <div>
      <label class="f" for="sekolah">Sekolah / Madrasah</label>
      <select id="sekolah" name="sekolah">
        <option value="0">— semua sekolah/madrasah —</option>
        <?php foreach ($namaSekolah as $sid => $r): ?>
          <option value="<?= (int) $sid ?>" <?= $fSekolah === (int) $sid ? 'selected' : '' ?>>
            <?= e((string) $r['nama']) ?> (<?= (int) $r['jml'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="tahun">Tahun Ajaran</label>
      <select id="tahun" name="tahun">
        <option value="">Semua tahun ajaran</option>
        <?php foreach ($tahunAda as $ta): ?>
          <option value="<?= e($ta) ?>" <?= $fTahun === $ta ? 'selected' : '' ?>>T.A. <?= e($ta) ?></option>
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
      <label class="f" for="status">Status Tinjauan</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <?php foreach (dokumen_status_list() as $kunci => $label): ?>
          <option value="<?= e($kunci) ?>" <?= $fStatus === $kunci ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="cari">Cari</label>
      <input type="text" id="cari" name="cari" value="<?= e($fCari) ?>"
             placeholder="nama dokumen / keterangan / berkas / pengirim">
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Tampilkan</button>
    <a class="btn btn-ghost" href="dokumen_semua.php">Reset</a>
  </form>
</div>

<?php if (!$daftar): ?>
  <div class="card">
    <div class="empty">
      <?= ikon('doc', 34) ?>
      <h3><?= count($semua) > 0 ? 'Tidak ada dokumen yang cocok' : 'Belum ada dokumen' ?></h3>
      <p><?= count($semua) > 0
        ? 'Ubah saringan sekolah/madrasah, tahun ajaran, atau kata kuncinya.'
        : 'Dokumen akan muncul di sini setelah kepala sekolah/madrasah mengirimkannya lewat menu "Kirim Dokumen ke Pengawas".' ?></p>
      <?php if ($adaSaringan): ?>
        <a class="btn btn-ghost" href="dokumen_semua.php"><?= ikon('switch', 16) ?> Tampilkan semua dokumen</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php $noSekolah = 0; foreach ($kelompok as $sid => $data): $noSekolah++; ?>
  <div class="card" id="sek<?= (int) $sid ?>">
    <div class="card-head">
      <div>
        <h2><?= ikon('school', 17) ?> <?= e(tetap_kata((string) $data['nama'])) ?></h2>
        <p><?= e(jenis_sekolah_teks((string) $data['jenis'])) ?> &middot;
          dikelompokkan per tahun ajaran (dari tanggal unggah dokumen).</p>
      </div>
      <?php $jumlahSekolah = 0; foreach ($data['ta'] as $t) { $jumlahSekolah += count($t['baris']); } ?>
      <span class="chip"><?= (int) $jumlahSekolah ?> dokumen &middot; <?= count($data['ta']) ?> tahun ajaran</span>
    </div>

    <?php foreach ($data['ta'] as $ta => $t): $jmlTa = count($t['baris']); $ringkasTa = dokumen_ringkas_status($t['baris']); ?>
      <div class="dok-tahun" id="ta<?= (int) $sid ?>-<?= e(preg_replace('/[^A-Za-z0-9]/', '', (string) $ta)) ?>">
        <h3>Tahun Ajaran <?= e((string) $ta) ?></h3>
        <span class="pill pill-A"><?= (int) $jmlTa ?> dokumen</span>
        <?php if ($ta !== 'Tidak tercatat'): ?>
          <span class="xs mut"><?= e(dokumen_ta_rentang((string) $ta)) ?></span>
        <?php else: ?>
          <span class="xs mut">tanggal unggahnya tidak tercatat</span>
        <?php endif; ?>
        <span class="xs mut">Belum ditinjau: <?= (int) ($ringkasTa['menunggu'] ?? 0) ?>
          &middot; Disetujui: <?= (int) ($ringkasTa['disetujui'] ?? 0) ?>
          &middot; Direvisi: <?= (int) ($ringkasTa['revisi'] ?? 0) ?>
          &middot; Ditolak: <?= (int) ($ringkasTa['ditolak'] ?? 0) ?></span>
      </div>
      <div class="table-wrap">
        <table class="tbl tbl-dokumen-madrasah">
          <thead>
            <tr>
              <th style="width:46px">No</th>
              <th>Nama Dokumen</th>
              <th style="width:110px">Tahun Ajaran</th>
              <th>Berkas</th>
              <th>Keterangan</th>
              <th style="width:150px">Status Tinjauan</th>
              <th>Dikirim</th>
            </tr>
          </thead>
          <tbody>
          <?php $no = 0; foreach ($t['baris'] as $d): $no++; $st = (string) ($d['status'] ?? dokumen_status_awal()); ?>
            <tr id="dok<?= (int) $d['id'] ?>">
              <td class="ctr xs mut"><?= $no ?></td>
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
                <span class="pill <?= dokumen_status_pil($st) ?>">
                  <?= ikon(dokumen_status_ikon($st), 12) ?> <?= e(dokumen_status_label($st)) ?></span>
                <?php if ($st !== dokumen_status_awal()): ?>
                  <?php if (trim((string) ($d['catatan'] ?? '')) !== ''): ?>
                    <span class="tbl-sub">Keterangan/alasan: <b><?= e((string) $d['catatan']) ?></b></span>
                  <?php endif; ?>
                  <span class="tbl-sub">oleh <?= e((string) ($d['ditinjau_oleh'] ?: 'Pengawas')) ?>,
                    <?= e(waktu_id_polos((string) $d['ditinjau_pada'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="xs mut"><?= e(waktu_id_polos((string) $d['dibuat'])) ?>
                <?php if (trim((string) ($d['pengirim'] ?? '')) !== ''): ?>
                  <span class="tbl-sub">oleh <?= e((string) $d['pengirim']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php
page_bottom();
