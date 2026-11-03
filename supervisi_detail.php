<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];
$admin = is_admin();

$id = (int) ($_GET['id'] ?? 0);
$s = one('SELECT s.*, g.nama AS guru, g.nip, g.mapel, g.jabatan, i.nama AS instrumen, i.kode, i.deskripsi
          FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
          WHERE s.id = ? AND s.sekolah_id = ?', [$id, $sid]);
if (!$s) {
    flash('Data supervisi tidak ditemukan.', 'err');
    redirect('index.php');
}
if (!$admin && !is_pengawas() && (int) ($u['guru_id'] ?? 0) !== (int) $s['guru_id']) {
    http_response_code(403);
    exit('Anda hanya dapat melihat hasil supervisi sendiri.');
}

// Butir bisa berupa butir GLOBAL (butir_id terisi) atau butir LAMA/arsip (butir_id NULL,
// nama & skor maksimalnya tersimpan di kolom *_arsip) — keduanya ditampilkan.
$detail = all('SELECT d.skor, d.catatan,
        COALESCE(b.komponen, d.komponen_arsip) AS komponen,
        COALESCE(b.indikator, NULL) AS indikator,
        COALESCE(b.skor_maks, d.maks_arsip, 4) AS skor_maks,
        COALESCE(b.urutan, d.urutan_arsip, 999) AS urutan,
        CASE WHEN d.butir_id IS NULL THEN 1 ELSE 0 END AS arsip
    FROM detail d LEFT JOIN butir b ON b.id = d.butir_id
    WHERE d.supervisi_id = ? ORDER BY urutan, d.id', [$id]);

$riwayat = all('SELECT s.id, s.tanggal, s.nilai, s.predikat, i.nama AS instrumen, i.kode
                FROM supervisi s JOIN instrumen i ON i.id = s.instrumen_id
                WHERE s.guru_id = ? AND s.sekolah_id = ? AND s.status = "final" ORDER BY s.tanggal DESC', [(int) $s['guru_id'], $sid]);

$th = thresholds();
$pred = (string) $s['predikat'];

// Bukti foto pengamatan pembelajaran (hanya menu "Supervisi Pengamatan Pembelajaran").
$fotoBukti = supervisi_foto_berlaku((string) $s['kode']) ? supervisi_foto($id) : [];

page_top('Detail Supervisi', (string) $s['kode']);
?>
<div class="card">
  <div class="card-head">
    <div>
      <div class="flex" style="gap:10px;margin-bottom:8px">
        <span class="pill pill-<?= e($pred) ?>"><?= e($pred) ?> &middot; <?= e(predikat_label($pred)) ?></span>
        <span class="pill <?= $s['status'] === 'final' ? 'pill-ok' : 'pill-warn' ?>"><?= $s['status'] === 'final' ? 'Final' : 'Draf' ?></span>
        <span class="pill pill-gray"><?= e((string) $s['instrumen']) ?></span>
      </div>
      <h2><?= e((string) $s['guru']) ?></h2>
      <p><?= e((string) ($s['mapel'] ?: '-')) ?> &middot; <?= e((string) ($s['jabatan'] ?: '-')) ?> &middot; NIP <?= e((string) ($s['nip'] ?: '-')) ?></p>
    </div>
    <div class="flex">
      <a class="btn btn-ghost" href="cetak.php?id=<?= (int) $s['id'] ?>" target="_blank"><?= ikon('print', 16) ?> Cetak Laporan</a>
      <?php if ($admin): ?>
        <a class="btn btn-ghost" href="supervisi_form.php?kode=<?= e((string) $s['kode']) ?>&id=<?= (int) $s['id'] ?>"><?= ikon('edit', 16) ?> Edit Penilaian</a>
      <?php endif; ?>
      <a class="btn btn-ghost" href="supervisi.php?kode=<?= e((string) $s['kode']) ?>">Kembali</a>
    </div>
  </div>

  <div class="nilai-box">
    <div class="nilai-chip"><b><?= r1($s['total_skor']) ?><span class="xs mut" style="font-weight:400">/<?= r1($s['skor_maks']) ?></span></b><span>Total skor</span></div>
    <div class="nilai-chip"><b><?= r1($s['nilai']) ?></b><span>Nilai akhir</span></div>
    <div class="nilai-chip"><b style="color:<?= $pred === 'A' ? '#047857' : ($pred === 'B' ? '#1d4ed8' : ($pred === 'C' ? '#b45309' : '#b91c1c')) ?>"><?= e($pred) ?></b><span><?= e(predikat_label($pred)) ?></span></div>
    <div class="nilai-chip"><b style="font-size:1rem;padding-top:6px"><?= e(tanggal_id((string) $s['tanggal'])) ?></b><span>Tanggal</span></div>
  </div>

  <div class="grid g4" style="gap:12px">
    <div><label class="f">Supervisor</label><div class="sm"><?= e((string) ($s['supervisor'] ?: '-')) ?></div></div>
    <div><label class="f">Periode</label><div class="sm"><?= e((string) ($s['periode'] ?: '-')) ?></div></div>
    <div><label class="f">Terakhir diubah</label><div class="sm"><?= e((string) ($s['diubah'] ?: '-')) ?></div></div>
    <div><label class="f">Jumlah butir dinilai</label><div class="sm"><?= count($detail) ?></div></div>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h2>Butir Penilaian</h2><p>Rincian skor per butir beserta catatan supervisor.</p></div></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th style="width:44px">#</th><th>Komponen &amp; Indikator</th><th class="ctr" style="width:90px">Skor</th><th style="width:170px">Capaian</th><th>Catatan</th></tr></thead>
      <tbody>
      <?php $n = 0; foreach ($detail as $d): $n++; $pct = ((int) $d['skor_maks']) > 0 ? ((int) $d['skor'] / (int) $d['skor_maks'] * 100) : 0; ?>
        <tr>
          <td><?= $n ?></td>
          <td>
            <b><?= e((string) $d['komponen']) ?></b>
            <?php if ($d['indikator']): ?><span class="tbl-sub"><?= e((string) $d['indikator']) ?></span><?php endif; ?>
          </td>
          <td class="ctr"><b><?= (int) $d['skor'] ?></b> <span class="mut">/<?= (int) $d['skor_maks'] ?></span></td>
          <td>
            <div class="bar-mini"><i class="lv<?= e(predikat_dari($pct)) ?>" style="width:<?= min(100, $pct) ?>%"></i></div>
            <span class="xs mut"><?= $pct ?>%</span>
          </td>
          <td class="sm"><?= e((string) ($d['catatan'] ?: '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid g3">
  <div class="card">
    <h3 class="mb8">Kelebihan / Kekuatan</h3>
    <p class="sm" style="margin:0"><?= nl2br(e((string) ($s['kelebihan'] ?: '-'))) ?></p>
  </div>
  <div class="card">
    <h3 class="mb8">Kekurangan / Area Perbaikan</h3>
    <p class="sm" style="margin:0"><?= nl2br(e((string) ($s['kekurangan'] ?: '-'))) ?></p>
  </div>
  <div class="card">
    <h3 class="mb8">Rekomendasi Tindak Lanjut</h3>
    <p class="sm" style="margin:0"><?= nl2br(e((string) ($s['tindak_lanjut'] ?: '-'))) ?></p>
  </div>
</div>

<?php
// Admin/kepala sekolah: kartu penuh (galeri + form unggah) — halaman ini muncul setelah
// menyimpan supervisi baru, jadi unggahan bisa langsung dilakukan dari sini.
// Peran lain (guru/pengawas): hanya melihat galerinya.
$kartuFotoPenuh = $admin && supervisi_foto_berlaku((string) $s['kode']);
if ($kartuFotoPenuh) {
    // Form unggah berdiri sendiri → kartu ini tidak boleh diletakkan di dalam form lain.
    echo kartu_foto_pengamatan((int) $s['id'], $fotoBukti, 'detail');
} elseif ($fotoBukti) {
    ?>
<div class="card" id="cardFotoPengamatanLihat">
  <div class="card-head">
    <div><h2>Bukti Foto Pengamatan Pembelajaran</h2><p>Dokumentasi guru saat mengajar di kelas, diunggah supervisor.</p></div>
    <span class="chip"><?= count($fotoBukti) ?> dari <?= (int) SUPERVISI_FOTO_MAKS ?> foto</span>
  </div>
  <div class="foto-bukti-grid">
    <?php foreach ($fotoBukti as $ix => $f): ?>
      <figure class="foto-bukti">
        <img src="<?= e((string) $f['url']) ?>" alt="Bukti foto pengamatan <?= $ix + 1 ?>" loading="lazy">
        <figcaption><span class="xs mut">Foto <?= $ix + 1 ?> dari <?= (int) SUPERVISI_FOTO_MAKS ?></span></figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
</div>
<?php
}
?>

<div class="card">
  <div class="card-head"><div><h2>Riwayat Supervisi Guru Ini</h2><p>Seluruh supervisi final yang pernah dilakukan.</p></div></div>
  <?php if ($riwayat): ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Tanggal</th><th>Menu Supervisi</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($riwayat as $r): ?>
          <tr <?= (int) $r['id'] === $id ? 'style="background:rgba(var(--accent-rgb),.07)"' : '' ?>>
            <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
            <td><?= e((string) $r['instrumen']) ?></td>
            <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
            <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?></span></td>
            <td class="right"><a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>">Lihat</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty"><p class="sm">Belum ada supervisi final lain.</p></div>
  <?php endif; ?>
</div>
<?php
page_bottom();
