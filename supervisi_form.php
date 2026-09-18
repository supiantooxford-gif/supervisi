<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();

$instList = instrumen_list();
$kode = (string) ($_GET['kode'] ?? $_POST['kode'] ?? (string) $instList[0]['kode']);
$ins = one('SELECT * FROM instrumen WHERE kode = ?', [$kode]);
if (!$ins) {
    redirect('index.php');
}
$iid = (int) $ins['id'];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = $id ? one('SELECT * FROM supervisi WHERE id = ? AND instrumen_id = ? AND sekolah_id = ?', [$id, $iid, $sid]) : null;
if ($id && !$edit) {
    flash('Data supervisi tidak ditemukan.', 'err');
    redirect('supervisi.php?kode=' . urlencode($kode));
}

// Butir aktif dipakai untuk penilaian baru. Saat mengedit penilaian lama,
// butir yang sudah diberi skor tetap ditampilkan walau statusnya nonaktif
// supaya nilainya tidak hilang dari lembar penilaian.
$butir = all('SELECT b.* FROM butir b
    WHERE b.instrumen_id = ?
      AND (b.aktif = 1 OR EXISTS (SELECT 1 FROM detail d WHERE d.butir_id = b.id AND d.supervisi_id = ?))
    ORDER BY b.urutan, b.id', [$iid, $edit ? $id : -1]);
if (!$butir) {
    flash('Menu ini belum memiliki butir penilaian aktif. Tambahkan butir terlebih dahulu di menu Instrumen Penilaian.', 'err');
    redirect('instrumen.php');
}

$guruList = all('SELECT * FROM guru WHERE aktif = 1 AND sekolah_id = ? ORDER BY nama', [$sid]);

$form = [
    'guru_id'      => $edit ? (int) $edit['guru_id'] : (int) ($_GET['guru'] ?? 0),
    'tanggal'      => $edit ? (string) $edit['tanggal'] : date('Y-m-d'),
    'periode'      => $edit ? (string) $edit['periode'] : (setting('tahun_ajaran', '') . ' ' . setting('semester', '')),
    'supervisor'   => $edit ? (string) $edit['supervisor'] : setting('kepala_sekolah', ''),
    'kelebihan'    => $edit ? (string) $edit['kelebihan'] : '',
    'kekurangan'   => $edit ? (string) $edit['kekurangan'] : '',
    'tindak_lanjut' => $edit ? (string) $edit['tindak_lanjut'] : '',
];
$skor = [];
$catatan = [];
if ($edit) {
    foreach (all('SELECT * FROM detail WHERE supervisi_id = ?', [$id]) as $d) {
        $skor[(int) $d['butir_id']] = (int) $d['skor'];
        $catatan[(int) $d['butir_id']] = (string) $d['catatan'];
    }
}

$err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $form['guru_id'] = (int) ($_POST['guru_id'] ?? 0);
    $form['tanggal'] = (string) ($_POST['tanggal'] ?? '');
    $form['periode'] = trim((string) ($_POST['periode'] ?? ''));
    $form['supervisor'] = trim((string) ($_POST['supervisor'] ?? ''));
    $form['kelebihan'] = trim((string) ($_POST['kelebihan'] ?? ''));
    $form['kekurangan'] = trim((string) ($_POST['kekurangan'] ?? ''));
    $form['tindak_lanjut'] = trim((string) ($_POST['tindak_lanjut'] ?? ''));
    $status = ($_POST['status'] ?? 'draft') === 'final' ? 'final' : 'draft';

    $skorIn = is_array($_POST['skor'] ?? null) ? $_POST['skor'] : [];
    $catIn = is_array($_POST['catatan'] ?? null) ? $_POST['catatan'] : [];
    $belum = [];
    foreach ($butir as $b) {
        $bid = (int) $b['id'];
        if (isset($skorIn[$bid]) && $skorIn[$bid] !== '') {
            $s = (int) $skorIn[$bid];
            $skor[$bid] = max(0, min((int) $b['skor_maks'], $s));
        } else {
            $belum[] = $b['komponen'];
        }
        $catatan[$bid] = trim((string) ($catIn[$bid] ?? ''));
    }

    if ($form['guru_id'] <= 0 || !one('SELECT id FROM guru WHERE id = ? AND sekolah_id = ?', [$form['guru_id'], $sid])) {
        $err[] = 'Guru yang disupervisi belum dipilih.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['tanggal'])) {
        $err[] = 'Tanggal supervisi belum valid.';
    }
    if ($belum) {
        $err[] = 'Masih ada ' . count($belum) . ' butir yang belum diberi skor: ' . implode(', ', array_slice($belum, 0, 5)) . (count($belum) > 5 ? ', ...' : '') . '.';
    }

    // Cek duplikat guru + tanggal pada instrumen yang sama
    $dup = one('SELECT id FROM supervisi WHERE guru_id = ? AND instrumen_id = ? AND tanggal = ? AND id <> ?',
        [$form['guru_id'], $iid, $form['tanggal'], $id]);
    if ($dup) {
        $err[] = 'Sudah ada supervisi untuk guru dan tanggal tersebut pada menu ini. Ubah tanggal atau edit data yang sudah ada.';
    }

    // Guru nonaktif/tidak ditemukan
    if (!$err) {
        $maksPer = [];
        foreach ($butir as $b) {
            $maksPer[(int) $b['id']] = (int) $b['skor_maks'];
        }
        $h = hitung_nilai($skor, $maksPer);
        $now = date('Y-m-d H:i:s');

        db()->exec('BEGIN IMMEDIATE');
        try {
            if ($edit) {
                q('UPDATE supervisi SET guru_id=?, tanggal=?, periode=?, supervisor=?, total_skor=?, skor_maks=?, nilai=?, predikat=?,
                       kelebihan=?, kekurangan=?, tindak_lanjut=?, status=?, diubah=? WHERE id=?',
                    [$form['guru_id'], $form['tanggal'], $form['periode'], $form['supervisor'], $h['total_skor'], $h['skor_maks'],
                     $h['nilai'], $h['predikat'], $form['kelebihan'], $form['kekurangan'], $form['tindak_lanjut'], $status, $now, $id]);
                $sid = $id;
            } else {
                q('INSERT INTO supervisi (sekolah_id, guru_id, instrumen_id, tanggal, periode, supervisor, total_skor, skor_maks, nilai, predikat,
                       kelebihan, kekurangan, tindak_lanjut, status, dibuat, diubah)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$sid, $form['guru_id'], $iid, $form['tanggal'], $form['periode'], $form['supervisor'], $h['total_skor'], $h['skor_maks'],
                     $h['nilai'], $h['predikat'], $form['kelebihan'], $form['kekurangan'], $form['tindak_lanjut'], $status, $now, $now]);
                $sid = (int) db()->lastInsertId();
            }
            $del = db()->prepare('DELETE FROM detail WHERE supervisi_id = ?');
            $del->execute([$sid]);
            $insD = db()->prepare('INSERT INTO detail (supervisi_id, butir_id, skor, catatan) VALUES (?,?,?,?)');
            foreach ($butir as $b) {
                $bid = (int) $b['id'];
                $insD->execute([$sid, $bid, (int) ($skor[$bid] ?? 0), (string) ($catatan[$bid] ?? '')]);
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        $namaGuru = (string) val('SELECT nama FROM guru WHERE id = ? AND sekolah_id = ?', [$form['guru_id'], $sid], '');
        flash('Supervisi ' . $ins['nama'] . ' untuk ' . $namaGuru . ' tersimpan (nilai ' . r1($h['nilai']) . ' - predikat ' . $h['predikat'] . ').');
        redirect('supervisi_detail.php?id=' . $sid);
    }
}

$maksTotal = 0;
foreach ($butir as $b) {
    $maksTotal += (int) $b['skor_maks'];
}
$skalaLabel = [
    4 => 'Amat Baik — dilaksanakan lengkap & konsisten',
    3 => 'Baik — dilaksanakan dengan baik',
    2 => 'Cukup — dilaksanakan sebagian',
    1 => 'Kurang — belum dilaksanakan',
];

page_top(($edit ? 'Edit: ' : '') . (string) $ins['nama'], $kode);
?>

<?php foreach ($err as $m): ?>
  <div class="alert alert-err"><?= ikon('star', 16) ?><span><?= e($m) ?></span></div>
<?php endforeach; ?>

<form method="post" id="formPenilaian" class="grid g21" style="align-items:start">
  <?= csrf_field() ?>
  <input type="hidden" name="kode" value="<?= e($kode) ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="status" id="inpStatus" value="draft">

  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head">
        <div><h2>Identitas Supervisi</h2><p><?= e((string) $ins['deskripsi']) ?></p></div>
        <span class="chip"><?= count($butir) ?> butir</span>
      </div>
      <div class="grid g2">
        <div>
          <label class="f" for="guru_id">Guru yang Disupervisi *</label>
          <select id="guru_id" name="guru_id" required>
            <option value="">— pilih guru —</option>
            <?php foreach ($guruList as $g): ?>
              <option value="<?= (int) $g['id'] ?>" <?= $form['guru_id'] === (int) $g['id'] ? 'selected' : '' ?>>
                <?= e((string) $g['nama']) ?><?= $g['mapel'] ? ' — ' . e((string) $g['mapel']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$guruList): ?>
            <div class="hint">Belum ada data guru. <a href="guru.php">Tambah data guru</a> terlebih dahulu.</div>
          <?php endif; ?>
        </div>
        <div>
          <label class="f" for="tanggal">Tanggal Supervisi *</label>
          <input type="date" id="tanggal" name="tanggal" value="<?= e($form['tanggal']) ?>" required>
        </div>
        <div>
          <label class="f" for="periode">Periode / Tahun Ajaran</label>
          <input type="text" id="periode" name="periode" value="<?= e($form['periode']) ?>" placeholder="2024/2025 Ganjil">
        </div>
        <div>
          <label class="f" for="supervisor">Supervisor / Penilai</label>
          <input type="text" id="supervisor" name="supervisor" value="<?= e($form['supervisor']) ?>" placeholder="Nama kepala sekolah / pengawas">
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div><h2>Butir Penilaian</h2><p>Berikan skor 1 sampai 4 pada setiap butir sesuai kondisi nyata.</p></div>
        <span class="chip"><span id="liveTerisi">0 / <?= count($butir) ?></span> terisi</span>
      </div>

      <?php foreach ($butir as $ix => $b): $bid = (int) $b['id']; ?>
        <div class="butir">
          <div class="butir-head">
            <div class="butir-no"><?= $ix + 1 ?></div>
            <div>
              <div class="butir-komponen"><?= e((string) $b['komponen']) ?>
                <?php if ((int) $b['aktif'] !== 1): ?><span class="pill pill-gray" style="margin-left:6px;vertical-align:middle">Butir nonaktif</span><?php endif; ?>
              </div>
              <?php if ($b['indikator']): ?><div class="butir-indikator"><?= e((string) $b['indikator']) ?></div><?php endif; ?>
            </div>
          </div>
          <div class="skala">
            <?php foreach ([4, 3, 2, 1] as $s): ?>
              <label>
                <input type="radio" name="skor[<?= $bid ?>]" value="<?= $s ?>"
                       data-maks="<?= (int) $b['skor_maks'] ?>"
                       data-label="<?= e((string) $b['komponen']) ?>"
                       <?= (int) ($skor[$bid] ?? 0) === $s ? 'checked' : '' ?>>
                <span><b><?= $s ?></b> &middot; <?= e(explode(' — ', $skalaLabel[$s])[0]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="butir-note">
            <input type="text" name="catatan[<?= $bid ?>]" value="<?= e((string) ($catatan[$bid] ?? '')) ?>"
                   placeholder="Catatan/deskripsi kondisi butir ini (opsional)">
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Catatan Kualitatif</h2><p>Umpan balik untuk guru yang disupervisi.</p></div></div>
      <div class="grid g3">
        <div>
          <label class="f" for="kelebihan">Kelebihan / Kekuatan</label>
          <textarea id="kelebihan" name="kelebihan" placeholder="Hal-hal yang sudah baik..."><?= e($form['kelebihan']) ?></textarea>
        </div>
        <div>
          <label class="f" for="kekurangan">Kekurangan / Area Perbaikan</label>
          <textarea id="kekurangan" name="kekurangan" placeholder="Hal-hal yang perlu diperbaiki..."><?= e($form['kekurangan']) ?></textarea>
        </div>
        <div>
          <label class="f" for="tindak_lanjut">Rekomendasi Tindak Lanjut</label>
          <textarea id="tindak_lanjut" name="tindak_lanjut" placeholder="Langkah pembinaan selanjutnya..."><?= e($form['tindak_lanjut']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <div class="card skorlive">
      <div class="card-head" style="margin-bottom:8px"><div><h2>Hasil Sementara</h2><p>Terhitung otomatis.</p></div></div>
      <div class="center">
        <div class="skorbig" id="liveNilai">0,00</div>
        <div class="xs mut">NILAI AKHIR (0-100)</div>
      </div>
      <div class="bar-mini" style="height:9px"><i id="liveBar" style="width:0%"></i></div>
      <div class="center"><span class="pill pill-gray" id="livePredikat">D — Perlu Pembinaan</span></div>
      <div class="divider"></div>
      <div class="grid g2" style="gap:12px">
        <div class="center"><div class="b" id="liveTotal">0</div><div class="xs mut">TOTAL SKOR</div></div>
        <div class="center"><div class="b" id="liveMaks"><?= $maksTotal ?></div><div class="xs mut">SKOR MAKSIMAL</div></div>
      </div>
      <div class="divider"></div>
      <div class="xs mut">
        <b>Rumus:</b> (jumlah skor &divide; skor maksimal) &times; 100.<br>
        Predikat: A &ge; <?= e(setting('batas_a', '90')) ?>, B &ge; <?= e(setting('batas_b', '80')) ?>, C &ge; <?= e(setting('batas_c', '70')) ?>, D di bawah itu.
      </div>
      <div class="form-actions" style="flex-direction:column;gap:10px;align-items:stretch">
        <button class="btn btn-primary full" type="submit" onclick="document.getElementById('inpStatus').value='final'">
          <?= ikon('check', 16) ?> Simpan &amp; Final
        </button>
        <button class="btn btn-ghost full" type="submit"><?= ikon('doc', 16) ?> Simpan sebagai Draf</button>
        <a class="btn btn-ghost full" href="supervisi.php?kode=<?= e($kode) ?>">Batal</a>
      </div>
    </div>

    <div class="card card-tight">
      <h3 class="mb8">Panduan Skor</h3>
      <div class="grid" style="gap:8px">
        <?php foreach ([4, 3, 2, 1] as $s): ?>
          <div class="flex" style="gap:10px;align-items:flex-start">
            <span class="pill pill-<?= $s === 4 ? 'A' : ($s === 3 ? 'B' : ($s === 2 ? 'C' : 'D')) ?>"><?= $s ?></span>
            <span class="xs"><?= e(explode(' — ', $skalaLabel[$s])[1]) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</form>

<script>window.VS_TH = {a: <?= (float) setting('batas_a', '90') ?>, b: <?= (float) setting('batas_b', '80') ?>, c: <?= (float) setting('batas_c', '70') ?>};</script>
<?php
page_bottom();
