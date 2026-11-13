<?php
/**
 * REKAP KEPALA SEKOLAH/MADRASAH — menu SUPER ADMIN "Layanan".
 *
 * Permintaan pemilik: *"Pada halaman super admin pada bagian layanan tambahkan menu yang
 * menampilkan rekapan: No, nama sekolah/madrasah, nama kepala sekolah, Nomer HP kepala sekolah.
 * Tujuannya untuk mempermudah super admin mengecek kepala sekolah disetiap lembaga. tambahkan
 * fitur cetak pdf"*
 *
 * Isi tabel sengaja HANYA empat kolom yang diminta (No, Nama Sekolah/Madrasah, Nama Kepala
 * Sekolah, Nomor HP Kepala Sekolah). Nama jenis lembaga, keadaan aktif/nonaktif, dan penanda
 * "Belum diisi" dicetak sebagai keterangan kecil di dalam sel — bukan kolom tambahan — supaya
 * tidak melebar saat dicetak.
 *
 * Data diambil dari helper bersama `rekap_kepala_daftar()` / `rekap_kepala_ringkas()`
 * (inc/helpers.php) sehingga halaman ini dan lembar cetak/PDF (`cetak_rekap_kepala.php`) selalu
 * menampilkan isi yang sama.
 *
 * Halaman LINTAS sekolah → istilah generik "Sekolah/Madrasah"
 * (`$GLOBALS['__vs_istilah_nonaktif']`), dan nama resmi sekolah dibungkus `tetap_kata()`.
 * Hanya Super Admin yang boleh membuka.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_super();
$GLOBALS['__vs_istilah_nonaktif'] = true;   // lintas sekolah/madrasah → istilah generik

// ---------------------------------------------------------------- saringan
$fCari = trim((string) ($_GET['cari'] ?? ''));
$fStatus = (string) ($_GET['status'] ?? '');
if (!in_array($fStatus, ['', 'aktif', 'nonaktif'], true)) {
    $fStatus = '';   // nilai saringan tak dikenal diabaikan (bukan 403)
}
$adaSaringan = $fCari !== '' || $fStatus !== '';

$daftar = rekap_kepala_daftar($fCari, $fStatus);
// Ringkasan SELALU dihitung dari seluruh sekolah supaya gambaran menyeluruh tidak berubah saat disaring.
$ringkas = rekap_kepala_ringkas(rekap_kepala_daftar());

// Tautan cetak membawa saringan yang sedang dilihat (dibangun dari nilai yang sudah divalidasi).
$qCetak = array_filter(['cari' => $fCari, 'status' => $fStatus], static fn($v) => $v !== '');
$urlCetak = 'cetak_rekap_kepala.php' . ($qCetak ? '?' . http_build_query($qCetak) : '');

page_top('Rekap Kepala Sekolah', 'rekap_kepala');
?>
<div class="alert alert-ok">
  <?= ikon('users', 16) ?>
  <span>Rekapan <b>nama kepala sekolah/madrasah</b> beserta <b>nomor HP</b> setiap lembaga, untuk
    memudahkan Super Admin memeriksa kelengkapan data kontak. Nama kepala dan nomor HP diisi lembaga
    masing-masing pada menu <b>Profil &amp; Pengaturan</b> (atau oleh Super Admin pada
    <b>Kelola Sekolah</b>); data yang belum diisi ditandai <b>Belum diisi</b> di bawah ini.</span>
</div>

<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('school', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= (int) $ringkas['total'] ?></div>
      <div class="stat-lbl">Sekolah/madrasah terdaftar</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('check', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= (int) $ringkas['aktif'] ?></div>
      <div class="stat-lbl">Lembaga aktif</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('user', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= (int) $ringkas['kepala'] ?></div>
      <div class="stat-lbl">Nama kepala sekolah terisi</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic o"><?= ikon('whatsapp', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= (int) $ringkas['hp'] ?></div>
      <div class="stat-lbl">Nomor HP kepala sekolah terisi</div></div>
  </div>
</div>

<?php if ((int) $ringkas['belum_lengkap'] > 0): ?>
  <div class="alert alert-warn" id="alertBelumLengkap">
    <?= ikon('alert', 16) ?>
    <span><b><?= (int) $ringkas['belum_lengkap'] ?> lembaga</b> belum lengkap: nama kepala sekolah
      dan/atau nomor HP-nya masih kosong (<?= (int) $ringkas['lengkap'] ?> lembaga sudah lengkap).
      Lengkapi lewat menu <b>Kelola Sekolah</b> atau minta lembaga mengisinya pada
      <b>Profil &amp; Pengaturan</b>.</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('filter', 16) ?> Saring</h2>
      <p>Cari berdasarkan nama sekolah/madrasah, nama kepala sekolah, atau nomor HP.</p>
    </div>
    <?php if ($adaSaringan): ?>
      <a class="btn btn-ghost btn-sm" href="rekap_kepala.php"><?= ikon('switch', 14) ?> Tampilkan semua</a>
    <?php endif; ?>
  </div>
  <form class="filterbar" method="get" id="formSaringRekapKepala">
    <div>
      <label class="f" for="cari">Cari</label>
      <input type="text" id="cari" name="cari" value="<?= e($fCari) ?>"
             placeholder="nama sekolah / kepala sekolah / nomor HP">
    </div>
    <div>
      <label class="f" for="status">Keadaan Lembaga</label>
      <select id="status" name="status">
        <option value="">Semua lembaga</option>
        <option value="aktif" <?= $fStatus === 'aktif' ? 'selected' : '' ?>>Aktif (disetujui)</option>
        <option value="nonaktif" <?= $fStatus === 'nonaktif' ? 'selected' : '' ?>>Nonaktif / ditolak</option>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit"><?= ikon('filter', 15) ?> Tampilkan</button>
    <a class="btn btn-ghost" href="rekap_kepala.php">Reset</a>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h2><?= ikon('users', 17) ?> Daftar Kepala Sekolah/Madrasah</h2>
      <p>Urut nama sekolah/madrasah (A-Z)<?= $adaSaringan ? ' — sesuai saringan di atas' : '' ?>.</p>
    </div>
    <div class="flex" style="gap:8px;flex-wrap:wrap">
      <span class="chip" id="chipRekapKepala"><?= count($daftar) ?> baris</span>
      <a class="btn btn-primary btn-sm" id="btnCetakRekapKepala" href="<?= e($urlCetak) ?>"
         target="_blank" rel="noopener"><?= ikon('print', 15) ?> Cetak / Simpan PDF</a>
    </div>
  </div>

  <?php if (!$daftar): ?>
    <div class="empty">
      <?= ikon('users', 34) ?>
      <h3><?= $ringkas['total'] > 0 ? 'Tidak ada lembaga yang cocok' : 'Belum ada sekolah/madrasah' ?></h3>
      <p><?= $ringkas['total'] > 0
        ? 'Ubah kata kunci atau keadaan lembaganya.'
        : 'Daftar kepala sekolah/madrasah akan muncul di sini setelah ada lembaga terdaftar.' ?></p>
      <?php if ($adaSaringan): ?>
        <a class="btn btn-ghost" href="rekap_kepala.php"><?= ikon('switch', 16) ?> Tampilkan semua lembaga</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl" id="tblRekapKepala">
        <thead>
          <tr>
            <th style="width:52px">No</th>
            <th>Nama Sekolah/Madrasah</th>
            <th style="width:30%">Nama Kepala Sekolah</th>
            <th style="width:22%">Nomor HP Kepala Sekolah</th>
          </tr>
        </thead>
        <tbody>
        <?php $no = 0; foreach ($daftar as $b): $no++; ?>
          <tr id="rk<?= (int) $b['id'] ?>">
            <td class="ctr xs mut"><?= $no ?></td>
            <td>
              <b><?= e(tetap_kata((string) $b['nama'])) ?></b>
              <span class="tbl-sub">
                <?= e(jenis_sekolah_teks((string) $b['jenis'])) ?>
                <?php if (!$b['aktif']): ?>
                  &middot; <span class="pill pill-gray"><?= $b['verifikasi'] === 'ditolak' ? 'Ditolak' : 'Nonaktif' ?></span>
                <?php endif; ?>
              </span>
            </td>
            <td>
              <?php if ($b['kepala_ada']): ?>
                <?= e((string) $b['kepala']) ?>
              <?php else: ?>
                <span class="pill pill-warn">Belum diisi</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($b['hp_ada']): ?>
                <b><?= e((string) $b['hp']) ?></b>
              <?php else: ?>
                <span class="pill pill-warn">Belum diisi</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><b>JUMLAH</b></td>
            <td><b><?= count(array_filter($daftar, static fn(array $b): bool => $b['kepala_ada'])) ?></b> terisi</td>
            <td><b><?= count(array_filter($daftar, static fn(array $b): bool => $b['hp_ada'])) ?></b> terisi</td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Sumber data</h3>
      <p class="sm mut" style="margin:4px 0 0">
        Nama kepala sekolah dan nomor HP diambil dari data tiap lembaga pada menu
        <b>Profil &amp; Pengaturan</b> (diisi lembaga) atau <b>Kelola Sekolah</b> (diisi Super Admin).
        Bila nomor HP kosong, dipakai nomor kontak dari formulir pendaftaran lembaga. Nomor yang tidak
        masuk akal (mis. &quot;-&quot; atau &quot;0&quot;) dianggap belum diisi.
      </p>
    </div>
    <span class="chip">Hanya Super Admin</span>
  </div>
</div>
<?php
page_bottom();
