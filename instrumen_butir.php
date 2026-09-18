<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Butir GLOBAL: hanya Super Admin, tanpa konteks sekolah.
$u = require_super();

$kode = (string) ($_GET['kode'] ?? $_POST['kode'] ?? '');
$ins = one('SELECT * FROM instrumen WHERE kode = ?', [$kode]);
if (!$ins) {
    flash('Menu supervisi tidak ditemukan.', 'err');
    redirect('instrumen.php');
}
$iid = (int) $ins['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $b = $id ? one('SELECT * FROM butir WHERE id = ? AND instrumen_id = ?', [$id, $iid]) : null;
    $url = 'instrumen_butir.php?kode=' . urlencode($kode);

    if ($aksi === 'tambah') {
        $komponen = trim((string) ($_POST['komponen'] ?? ''));
        $indikator = trim((string) ($_POST['indikator'] ?? ''));
        $maks = (int) ($_POST['skor_maks'] ?? 4);
        $maks = max(1, min(10, $maks));
        if ($komponen === '') {
            flash('Nama komponen butir wajib diisi.', 'err');
        } else {
            $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) + 1 FROM butir WHERE instrumen_id = ?', [$iid], 1);
            q('INSERT INTO butir (instrumen_id, urutan, komponen, indikator, skor_maks, aktif) VALUES (?,?,?,?,?,1)',
                [$iid, $urutan, $komponen, $indikator, $maks]);
            flash('Butir "' . $komponen . '" ditambahkan. Butir ini muncul pada penilaian berikutnya.');
        }
        redirect($url);
    }

    if ($aksi === 'tambah_banyak') {
        $teks = (string) ($_POST['daftar'] ?? '');
        $maks = max(1, min(10, (int) ($_POST['skor_maks_banyak'] ?? 4)));
        $baris = preg_split('/\r\n|\r|\n/', $teks) ?: [];
        $urutan = (int) val('SELECT COALESCE(MAX(urutan), 0) FROM butir WHERE instrumen_id = ?', [$iid], 0);
        $n = 0;
        $lewats = 0;
        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($baris as $line) {
                $line = trim(str_replace(["\t", ';'], '|', $line));
                if ($line === '') {
                    continue;
                }
                $pecah = array_map('trim', explode('|', $line, 2));
                $komponen = $pecah[0] ?? '';
                $indikator = $pecah[1] ?? '';
                if ($komponen === '' || function_exists('mb_strlen') && mb_strlen($komponen) < 2) {
                    $lewats++;
                    continue;
                }
                if (strlen($komponen) > 200) {
                    $komponen = substr($komponen, 0, 200);
                }
                $urutan++;
                q('INSERT INTO butir (instrumen_id, urutan, komponen, indikator, skor_maks, aktif) VALUES (?,?,?,?,?,1)',
                    [$iid, $urutan, $komponen, $indikator, $maks]);
                $n++;
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        flash($n > 0
            ? $n . ' butir ditambahkan sekaligus' . ($lewats > 0 ? ' (' . $lewats . ' baris dilewati karena kosong/terlalu pendek)' : '') . '.'
            : 'Tidak ada butir yang bisa ditambahkan. Isi minimal satu baris.', $n > 0 ? 'ok' : 'err');
        redirect($url);
    }

    if ($aksi === 'aktif_semua' || $aksi === 'nonaktif_semua') {
        $baru = $aksi === 'aktif_semua' ? 1 : 0;
        $jml = (int) val('SELECT COUNT(*) FROM butir WHERE instrumen_id = ? AND aktif <> ?', [$iid, $baru], 0);
        if ($jml > 0) {
            q('UPDATE butir SET aktif = ? WHERE instrumen_id = ?', [$baru, $iid]);
        }
        flash($jml > 0
            ? $jml . ' butir pada menu ini ' . ($baru === 1 ? 'diaktifkan' : 'dinonaktifkan') . ' sekaligus. Nilai yang sudah tersimpan tetap utuh.'
            : 'Tidak ada butir yang perlu diubah.', $jml > 0 && $baru === 0 ? 'warn' : 'ok');
        redirect($url);
    }

    if (!$b) {
        flash('Butir tidak ditemukan.', 'err');
        redirect($url);
    }

    if ($aksi === 'ubah') {
        $komponen = trim((string) ($_POST['komponen'] ?? ''));
        $indikator = trim((string) ($_POST['indikator'] ?? ''));
        $maksBaru = max(1, min(10, (int) ($_POST['skor_maks'] ?? 4)));
        if ($komponen === '') {
            flash('Nama komponen butir wajib diisi.', 'err');
            redirect($url);
        }
        $maksLama = (int) $b['skor_maks'];
        $pemakai = $maksLama !== $maksBaru ? supervisi_pemakai_butir((int) $b['id']) : [];

        db()->exec('BEGIN IMMEDIATE');
        try {
            q('UPDATE butir SET komponen=?, indikator=?, skor_maks=? WHERE id=?', [$komponen, $indikator, $maksBaru, $id]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        if ($pemakai) {
            recompute_supervisi($pemakai);
            flash('Butir "' . $komponen . '" diperbarui. Skor maksimal berubah dari ' . $maksLama . ' menjadi ' . $maksBaru
                . ', sehingga nilai ' . count($pemakai) . ' hasil supervisi dihitung ulang otomatis.', 'warn');
        } else {
            flash('Butir "' . $komponen . '" diperbarui.');
        }
        redirect($url);
    }

    if ($aksi === 'aktif' || $aksi === 'nonaktif') {
        $baru = $aksi === 'aktif' ? 1 : 0;
        q('UPDATE butir SET aktif = ? WHERE id = ?', [$baru, $id]);
        flash($baru === 1
            ? 'Butir "' . $b['komponen'] . '" diaktifkan kembali dan dipakai pada penilaian berikutnya.'
            : 'Butir "' . $b['komponen'] . '" dinonaktifkan. Tidak muncul pada penilaian baru, namun nilai yang sudah tersimpan tetap utuh.',
            $baru === 1 ? 'ok' : 'warn');
        redirect($url);
    }

    if ($aksi === 'hapus') {
        $pemakai = supervisi_pemakai_butir((int) $b['id']);
        $paksa = (string) ($_POST['paksa'] ?? '') === '1';
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));

        if ($pemakai && (!$paksa || $konfirmasi !== 'HAPUS')) {
            flash('Butir "' . $b['komponen'] . '" masih dipakai pada ' . count($pemakai) . ' hasil supervisi. '
                . 'Nonaktifkan saja bila ingin tetap menyimpan riwayat, atau pilih hapus permanen beserta penilaiannya.', 'err');
            redirect($url . '&hapus=' . $id);
        }

        db()->exec('BEGIN IMMEDIATE');
        try {
            q('DELETE FROM butir WHERE id = ?', [$id]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        $ulang = recompute_supervisi($pemakai);
        flash('Butir "' . $b['komponen'] . '" dihapus permanen.'
            . ($ulang > 0 ? ' ' . $ulang . ' hasil supervisi dihitung ulang (penilaian yang tidak punya butir tersisa ikut terhapus).' : ''),
            $ulang > 0 ? 'warn' : 'ok');
        redirect($url);
    }

    if ($aksi === 'naik' || $aksi === 'turun') {
        $arah = $aksi === 'naik' ? '<' : '>';
        $urut = $aksi === 'naik' ? 'DESC' : 'ASC';
        $tetangga = one("SELECT * FROM butir WHERE instrumen_id = ? AND urutan $arah ? ORDER BY urutan $urut LIMIT 1", [$iid, (int) $b['urutan']]);
        if ($tetangga) {
            db()->exec('BEGIN IMMEDIATE');
            try {
                q('UPDATE butir SET urutan = ? WHERE id = ?', [(int) $tetangga['urutan'], (int) $b['id']]);
                q('UPDATE butir SET urutan = ? WHERE id = ?', [(int) $b['urutan'], (int) $tetangga['id']]);
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw $ex;
            }
        }
        redirect($url);
    }

    redirect($url);
}

$rows = all('SELECT b.*,
        (SELECT COUNT(*) FROM detail d WHERE d.butir_id = b.id) AS dipakai,
        (SELECT COUNT(DISTINCT d.supervisi_id) FROM detail d WHERE d.butir_id = b.id) AS jml_supervisi
    FROM butir b WHERE b.instrumen_id = ? ORDER BY b.urutan, b.id', [$iid]);

$jmlAktif = 0;
$jmlNonaktif = 0;
$maksAktif = 0;
foreach ($rows as $r) {
    if ((int) $r['aktif'] === 1) {
        $jmlAktif++;
        $maksAktif += (int) $r['skor_maks'];
    } else {
        $jmlNonaktif++;
    }
}

// Konfirmasi hapus dua langkah untuk butir yang sudah dipakai.
$hapusId = (int) ($_GET['hapus'] ?? 0);
$bHapus = null;
if ($hapusId > 0) {
    foreach ($rows as $r) {
        if ((int) $r['id'] === $hapusId) {
            $bHapus = $r;
            break;
        }
    }
}

$jmlSupervisiMenu = (int) val('SELECT COUNT(*) FROM supervisi WHERE instrumen_id = ?', [$iid], 0);

page_top('Butir: ' . (string) $ins['nama'], 'instrumen');
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>Butir Penilaian — <?= e((string) $ins['nama']) ?></h2>
      <p><?= e((string) $ins['deskripsi']) ?></p>
    </div>
    <div class="flex">
      <span class="chip"><?= $jmlAktif ?> butir aktif &middot; skor maks <?= $maksAktif ?></span>
      <?php if ($jmlNonaktif > 0): ?><span class="chip"><?= $jmlNonaktif ?> nonaktif</span><?php endif; ?>
      <a class="btn btn-ghost" href="instrumen.php">Daftar Menu</a>
      <a class="btn btn-ghost" href="cetak_butir.php?kode=<?= e($kode) ?>" title="Cetak daftar butir aktif menu ini"><?= ikon('print', 15) ?> Cetak Daftar Butir</a>
      <a class="btn btn-ghost" href="supervisi.php?kode=<?= e($kode) ?>"><?= ikon('eye', 15) ?> Lihat Penilaian</a>
    </div>
  </div>

  <div class="grid g4">
    <div class="card stat card-tight">
      <div class="stat-ic"><?= ikon('check', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $jmlAktif ?></div><div class="stat-lbl">Butir aktif</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic s"><?= ikon('chart', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $maksAktif ?></div><div class="stat-lbl">Total skor maksimal</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic g"><?= ikon('report', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $jmlSupervisiMenu ?></div><div class="stat-lbl">Hasil supervisi menu ini</div></div>
    </div>
    <div class="card stat card-tight">
      <div class="stat-ic o"><?= ikon('star', 18) ?></div>
      <div><div class="stat-num" style="font-size:1.3rem"><?= $jmlNonaktif ?></div><div class="stat-lbl">Butir nonaktif</div></div>
    </div>
  </div>
</div>

<?php if ($bHapus): ?>
  <div class="card" style="border-color:rgba(220,38,38,.4);background:rgba(255,245,245,.7)">
    <div class="card-head">
      <div>
        <h2>Hapus butir: <?= e((string) $bHapus['komponen']) ?></h2>
        <p>Butir ini sudah dipakai pada <b><?= (int) $bHapus['jml_supervisi'] ?></b> hasil supervisi
          (<?= (int) $bHapus['dipakai'] ?> baris skor). Pilih salah satu tindakan di bawah.</p>
      </div>
      <a class="btn btn-ghost" href="instrumen_butir.php?kode=<?= e($kode) ?>">Batalkan</a>
    </div>
    <div class="grid g2">
      <div class="butir" style="margin:0">
        <div class="butir-head">
          <div class="butir-no" style="background:linear-gradient(135deg,#f59e0b,#ea580c)">1</div>
          <div>
            <div class="butir-komponen">Nonaktifkan (disarankan)</div>
            <div class="butir-indikator">Butir tidak lagi muncul pada penilaian baru, tetapi seluruh nilai dan catatan yang sudah tersimpan tetap utuh dan tetap tercetak pada laporan lama.</div>
          </div>
        </div>
        <form method="post" class="mt16">
          <?= csrf_field() ?>
          <input type="hidden" name="kode" value="<?= e($kode) ?>">
          <input type="hidden" name="aksi" value="nonaktif">
          <input type="hidden" name="id" value="<?= (int) $bHapus['id'] ?>">
          <button class="btn btn-primary" type="submit"><?= ikon('check', 16) ?> Nonaktifkan butir ini</button>
        </form>
      </div>

      <div class="butir" style="margin:0">
        <div class="butir-head">
          <div class="butir-no" style="background:linear-gradient(135deg,#dc2626,#b91c1c)">2</div>
          <div>
            <div class="butir-komponen">Hapus permanen</div>
            <div class="butir-indikator">Butir dihapus beserta <?= (int) $bHapus['dipakai'] ?> baris skornya, lalu nilai
              <?= (int) $bHapus['jml_supervisi'] ?> hasil supervisi dihitung ulang. Penilaian yang tidak menyisakan butir apa pun akan ikut terhapus.
              <b>Tidak dapat dibatalkan.</b></div>
          </div>
        </div>
        <form method="post" class="mt16" data-confirm="Hapus PERMANEN butir &quot;<?= e((string) $bHapus['komponen']) ?>&quot; beserta <?= (int) $bHapus['dipakai'] ?> baris skornya?">
          <?= csrf_field() ?>
          <input type="hidden" name="kode" value="<?= e($kode) ?>">
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="id" value="<?= (int) $bHapus['id'] ?>">
          <input type="hidden" name="paksa" value="1">
          <div class="grid g2" style="gap:10px">
            <div>
              <label class="f" for="konfirmasi">Ketik HAPUS untuk konfirmasi</label>
              <input type="text" id="konfirmasi" name="konfirmasi" placeholder="HAPUS">
            </div>
            <div style="align-self:end">
              <button class="btn btn-danger full" type="submit"><?= ikon('trash', 16) ?> Hapus permanen</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h2>Daftar Butir</h2><p>Ubah nama, indikator, skor maksimal, urutan, atau aktif/nonaktifkan setiap butir langsung dari tabel ini.</p></div>
    <div class="flex">
      <span class="chip"><?= count($rows) ?> butir</span>
      <?php if ($jmlAktif > 0): ?>
        <form method="post" data-confirm="Nonaktifkan <?= $jmlAktif ?> butir aktif pada menu ini sekaligus? Nilai yang sudah tersimpan tidak dihapus.">
          <?= csrf_field() ?>
          <input type="hidden" name="kode" value="<?= e($kode) ?>">
          <input type="hidden" name="aksi" value="nonaktif_semua">
          <button class="btn btn-ghost btn-sm" type="submit">Nonaktifkan semua</button>
        </form>
      <?php endif; ?>
      <?php if ($jmlNonaktif > 0): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="kode" value="<?= e($kode) ?>">
          <input type="hidden" name="aksi" value="aktif_semua">
          <button class="btn btn-ghost btn-sm" type="submit">Aktifkan semua</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><?= ikon('doc', 34) ?><h3>Belum ada butir</h3><p>Tambahkan satu per satu, atau tempel daftar butir sekaligus pada formulir di bawah.</p></div>
  <?php else: ?>
    <div class="grid" style="gap:12px">
      <?php $no = 0; foreach ($rows as $b): $no++; $nonaktif = (int) $b['aktif'] !== 1; ?>
        <div class="butir" style="margin:0;<?= $nonaktif ? 'opacity:.68' : '' ?>">
          <div class="butir-head" style="align-items:center">
            <div class="butir-no" style="margin-top:0"><?= $no ?></div>
            <div style="flex:1;min-width:0">
              <div class="butir-komponen">
                <?= e((string) $b['komponen']) ?>
                <span class="pill <?= $nonaktif ? 'pill-gray' : 'pill-ok' ?>" style="vertical-align:middle;margin-left:6px"><?= $nonaktif ? 'Nonaktif' : 'Aktif' ?></span>
                <span class="pill pill-gray" style="vertical-align:middle;margin-left:4px" title="Jumlah baris skor yang memakai butir ini"><?= (int) $b['dipakai'] ?> skor</span>
              </div>
              <div class="butir-indikator"><?= $b['indikator'] ? e((string) $b['indikator']) : '<span class="mut">tanpa indikator</span>' ?>
                &middot; skor maksimal <?= (int) $b['skor_maks'] ?>
                <?php if ((int) $b['jml_supervisi'] > 0): ?>&middot; dipakai di <?= (int) $b['jml_supervisi'] ?> hasil supervisi<?php endif; ?>
              </div>
            </div>
            <div class="flex" style="gap:4px">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="kode" value="<?= e($kode) ?>">
                <input type="hidden" name="aksi" value="naik"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit" title="Naikkan urutan">▲</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="kode" value="<?= e($kode) ?>">
                <input type="hidden" name="aksi" value="turun"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit" title="Turunkan urutan">▼</button></form>
            </div>
          </div>

          <details class="editbox">
            <summary><?= ikon('edit', 14) ?> Edit butir ini</summary>
            <form method="post" class="grid" style="gap:10px;margin-top:12px">
              <?= csrf_field() ?>
              <input type="hidden" name="kode" value="<?= e($kode) ?>">
              <input type="hidden" name="aksi" value="ubah">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <div class="grid g2" style="gap:10px">
                <div>
                  <label class="f">Komponen / Aspek *</label>
                  <input type="text" name="komponen" value="<?= e((string) $b['komponen']) ?>" required>
                </div>
                <div>
                  <label class="f">Skor Maksimal</label>
                  <input type="number" name="skor_maks" value="<?= (int) $b['skor_maks'] ?>" min="1" max="10">
                </div>
              </div>
              <div>
                <label class="f">Indikator Penilaian</label>
                <input type="text" name="indikator" value="<?= e((string) $b['indikator']) ?>" placeholder="Deskripsi/kriteria penilaian">
              </div>
              <?php if ((int) $b['dipakai'] > 0): ?>
                <div class="hint">Butir ini sudah dipakai pada penilaian. Mengubah skor maksimal akan menghitung ulang nilai supervisi terkait secara otomatis (skor lama dipangkas bila melebihi batas baru).</div>
              <?php endif; ?>
              <div class="flex">
                <button class="btn btn-primary btn-sm" type="submit"><?= ikon('check', 14) ?> Simpan perubahan</button>
              </div>
            </form>
          </details>

          <div class="flex between mt16" style="gap:8px">
            <span class="xs mut">Urutan ke-<?= (int) $b['urutan'] ?> &middot; tampil sebagai butir nomor <?= $no ?> pada formulir penilaian</span>
            <div class="flex" style="gap:6px">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="kode" value="<?= e($kode) ?>">
                <input type="hidden" name="aksi" value="<?= $nonaktif ? 'aktif' : 'nonaktif' ?>">
                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $nonaktif ? 'Aktifkan' : 'Nonaktifkan' ?></button>
              </form>
              <a class="btn btn-danger btn-sm" href="instrumen_butir.php?kode=<?= e($kode) ?>&hapus=<?= (int) $b['id'] ?>"
                 title="<?= (int) $b['dipakai'] > 0 ? 'Butir sudah dipakai penilaian — akan ditawarkan nonaktifkan atau hapus permanen' : 'Hapus butir' ?>">
                <?= ikon('trash', 14) ?> Hapus
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hint">Penomoran butir pada formulir mengikuti urutan di atas. Butir nonaktif dilewati pada penilaian baru, tetapi nilainya tetap tampil pada laporan yang sudah tersimpan.</p>
  <?php endif; ?>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <div class="card-head"><div><h2>Tambah Satu Butir</h2><p>Tambahkan aspek penilaian baru pada menu ini.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="kode" value="<?= e($kode) ?>">
      <input type="hidden" name="aksi" value="tambah">
      <div>
        <label class="f" for="komponen">Komponen / Aspek *</label>
        <input type="text" id="komponen" name="komponen" required placeholder="Contoh: Penggunaan Media Pembelajaran">
      </div>
      <div>
        <label class="f" for="indikator">Indikator Penilaian</label>
        <input type="text" id="indikator" name="indikator" placeholder="Contoh: Media relevan dan digunakan secara efektif">
      </div>
      <div style="max-width:190px">
        <label class="f" for="skor_maks">Skor Maksimal</label>
        <input type="number" id="skor_maks" name="skor_maks" value="4" min="1" max="10">
      </div>
      <button class="btn btn-primary" type="submit" style="justify-self:start"><?= ikon('plus', 16) ?> Tambah Butir</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Tambah Banyak Butir Sekaligus</h2><p>Tempel daftar butir dari dokumen Word/Excel, satu butir per baris.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="kode" value="<?= e($kode) ?>">
      <input type="hidden" name="aksi" value="tambah_banyak">
      <div>
        <label class="f" for="daftar">Daftar Butir</label>
        <textarea id="daftar" name="daftar" rows="8" placeholder="Kalender Pendidikan | Ketersediaan dan kesesuaian dengan kalender dinas
Program Tahunan | Memuat alokasi waktu dan kompetensi satu tahun
Program Semester"></textarea>
        <div class="hint">Format: <b>Komponen | Indikator</b>. Indikator boleh dikosongkan. Pemisah juga bisa Tab atau titik koma (hasil tempel dari Excel).</div>
      </div>
      <div style="max-width:190px">
        <label class="f" for="skor_maks_banyak">Skor Maksimal Butir Baru</label>
        <input type="number" id="skor_maks_banyak" name="skor_maks_banyak" value="4" min="1" max="10">
      </div>
      <div class="flex">
        <button class="btn btn-primary" type="submit"><?= ikon('plus', 16) ?> Tambah Semua Butir</button>
        <a class="btn btn-ghost" href="instrumen_butir.php?kode=<?= e($kode) ?>">Bersihkan</a>
      </div>
    </form>
  </div>
</div>

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Catatan penting</h3>
      <p class="sm mut" style="margin:4px 0 0">
        Menambah butir baru tidak mengubah nilai lama (nilai yang sudah tersimpan tetap). Mengubah <b>skor maksimal</b> atau
        menghapus butir yang sudah dipakai akan membuat nilai hasil supervisi terkait dihitung ulang secara otomatis dari butir yang tersisa.
        Untuk menyesuaikan butir agar cocok dengan instrumen sekolah, sebaiknya nonaktifkan butir lama lalu tambahkan butir versi Anda.
      </p>
    </div>
    <span class="chip">Skala 1-4 (dapat diubah per butir)</span>
  </div>
</div>
<?php
page_bottom();
