<?php
/**
 * KELOLA JENIS DOKUMEN (khusus Super Admin) — daftar GLOBAL.
 *
 * Permintaan pemilik: "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen ke
 * pengawas dengan inputan: Nama Dokumen (Pilihan: Kurikulum Madrasah (KM), Rencana Kerja Tahunan
 * (RKT), Rencana Kerja Jangka Menengah (RKJM), Laporan Supervisi, EDM/RKAM, PKG & PTK), Unggah
 * Berkas, Keterangan. Pilihan nama dokumen bisa dirubah/di edit/ dihapus melalui akun super admin."
 *
 * Daftar ini dipakai sebagai PILIHAN pada formulir kirim dokumen (kepala madrasah). Nama jenis
 * ikut disimpan sebagai snapshot pada setiap dokumen yang dikirim, sehingga dokumen lama tetap
 * terbaca walau jenisnya kelak diubah/dihapus (relasi `jenis_id` memakai ON DELETE SET NULL).
 * Halaman ini lintas sekolah → istilah generik "Sekolah/Madrasah".
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_super();
$GLOBALS['__vs_istilah_nonaktif'] = true;   // halaman lintas sekolah: istilah generik

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $j = $id > 0 ? dokumen_jenis_by_id($id) : null;

    if ($aksi === 'tambah') {
        $nama = komentar_potong((string) ($_POST['nama'] ?? ''), 120);
        if ($nama === '') {
            flash('Nama dokumen wajib diisi.', 'err');
        } elseif (dokumen_jenis_nama_terpakai($nama)) {
            flash('Nama dokumen "' . $nama . '" sudah ada pada daftar.', 'err');
        } else {
            q('INSERT INTO dokumen_jenis (nama, urutan, aktif, dibuat) VALUES (?,?,?,?)',
                [$nama, dokumen_jenis_urutan_baru(), !empty($_POST['aktif']) ? 1 : 0, date('Y-m-d H:i:s')]);
            flash('Nama dokumen "' . $nama . '" ditambahkan dan langsung tersedia sebagai pilihan pada formulir kirim dokumen.');
        }
        redirect('dokumen_jenis.php');
    }

    if (!$j) {
        flash('Jenis dokumen tidak ditemukan.', 'err');
        redirect('dokumen_jenis.php');
    }

    if ($aksi === 'ubah') {
        $nama = komentar_potong((string) ($_POST['nama'] ?? ''), 120);
        if ($nama === '') {
            flash('Nama dokumen wajib diisi.', 'err');
        } elseif (dokumen_jenis_nama_terpakai($nama, $id)) {
            flash('Nama dokumen "' . $nama . '" sudah dipakai jenis lain.', 'err');
        } else {
            q('UPDATE dokumen_jenis SET nama = ?, diubah = ? WHERE id = ?', [$nama, date('Y-m-d H:i:s'), $id]);
            flash('Nama dokumen diubah menjadi "' . $nama . '".');
        }
    } elseif ($aksi === 'aktif' || $aksi === 'nonaktif') {
        q('UPDATE dokumen_jenis SET aktif = ?, diubah = ? WHERE id = ?',
            [$aksi === 'aktif' ? 1 : 0, date('Y-m-d H:i:s'), $id]);
        flash($aksi === 'aktif'
            ? 'Jenis dokumen "' . (string) $j['nama'] . '" diaktifkan kembali (muncul sebagai pilihan).'
            : 'Jenis dokumen "' . (string) $j['nama'] . '" dinonaktifkan (tidak lagi muncul sebagai pilihan; dokumen lama tetap terbaca).', 'warn');
    } elseif ($aksi === 'hapus') {
        $dipakai = (int) val('SELECT COUNT(*) FROM dokumen_madrasah WHERE jenis_id = ?', [$id], 0);
        // Dokumen yang sudah dikirim TIDAK dihapus: relasinya SET NULL dan nama jenisnya
        // tersimpan sebagai snapshot pada tiap dokumen.
        q('DELETE FROM dokumen_jenis WHERE id = ?', [$id]);
        flash('Jenis dokumen "' . (string) $j['nama'] . '" dihapus.'
            . ($dipakai > 0 ? ' ' . $dipakai . ' dokumen yang sudah dikirim tetap tersimpan (nama jenisnya ikut tersimpan pada dokumennya).' : ''),
            $dipakai > 0 ? 'warn' : 'ok');
    } elseif ($aksi === 'naik' || $aksi === 'turun') {
        dokumen_jenis_geser($id, $aksi === 'naik' ? -1 : 1);
    }
    redirect('dokumen_jenis.php');
}

$daftar = dokumen_jenis_list(false);
$pakai = [];
foreach (all('SELECT jenis_id, COUNT(*) jml FROM dokumen_madrasah GROUP BY jenis_id') as $r) {
    $pakai[(int) $r['jenis_id']] = (int) $r['jml'];
}
$jmlAktif = dokumen_jenis_jumlah(true);
$jmlDokumen = dokumen_jumlah();

page_top('Jenis Dokumen (Global)', 'dokumen_jenis');
?>
<div class="alert alert-ok" style="margin-bottom:18px">
  <?= ikon('info', 16) ?>
  <span>Daftar di bawah ini adalah <b>pilihan Nama Dokumen</b> pada formulir
    <b>Kirim Dokumen ke Pengawas</b> (akun kepala sekolah/madrasah). Daftarnya <b>global</b> — dipakai
    semua sekolah/madrasah — dan hanya Super Admin yang dapat mengubahnya.</span>
</div>

<div class="grid g4" style="gap:12px">
  <div class="nilai-chip"><b><?= count($daftar) ?></b><span>Jenis dokumen</span></div>
  <div class="nilai-chip"><b><?= (int) $jmlAktif ?></b><span>Muncul sebagai pilihan</span></div>
  <div class="nilai-chip"><b><?= (int) $jmlDokumen ?></b><span>Dokumen terkirim</span></div>
  <div class="nilai-chip"><b><?= count(array_filter($pakai)) ?></b><span>Jenis pernah dipakai</span></div>
</div>

<div class="grid g21" style="align-items:start">
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Daftar Jenis Dokumen</h2>
        <p>Urutan di sini = urutan pada pilihan di formulir kirim dokumen.</p>
      </div>
      <span class="chip"><?= count($daftar) ?> baris</span>
    </div>

    <?php if (!$daftar): ?>
      <div class="empty">
        <?= ikon('doc', 30) ?>
        <h3>Belum ada jenis dokumen</h3>
        <p>Tambahkan nama dokumen pada formulir di samping supaya kepala sekolah/madrasah dapat
          mengirim dokumen ke pengawas.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl" id="tblJenisDokumen">
          <thead>
            <tr><th style="width:52px">Urut</th><th>Nama Dokumen</th><th class="ctr">Dokumen terkirim</th>
              <th class="ctr">Status</th><th class="ctr" style="width:210px">Aksi</th></tr>
          </thead>
          <tbody>
          <?php foreach ($daftar as $i => $j): $dipakai = $pakai[(int) $j['id']] ?? 0; ?>
            <tr>
              <td class="ctr xs mut"><?= (int) ($i + 1) ?></td>
              <td><b><?= e((string) $j['nama']) ?></b>
                <?php if (trim((string) ($j['diubah'] ?? '')) !== ''): ?>
                  <span class="tbl-sub">diubah <?= e(waktu_id_polos((string) $j['diubah'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="ctr"><?= $dipakai > 0 ? (int) $dipakai : '<span class="mut xs">—</span>' ?></td>
              <td class="ctr">
                <span class="pill <?= (int) $j['aktif'] === 1 ? 'pill-ok' : 'pill-gray' ?>">
                  <?= (int) $j['aktif'] === 1 ? 'Pilihan aktif' : 'Nonaktif' ?>
                </span>
              </td>
              <td>
                <div class="row-actions">
                  <a class="btn btn-ghost btn-sm" href="dokumen_jenis.php?edit=<?= (int) $j['id'] ?>#formJenis"
                     title="Ubah nama dokumen"><?= ikon('edit', 14) ?> Ubah</a>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="naik"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit" title="Naikkan urutan" <?= $i === 0 ? 'disabled' : '' ?>><?= ikon('up', 14) ?></button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="turun"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit" title="Turunkan urutan" <?= $i === count($daftar) - 1 ? 'disabled' : '' ?>><?= ikon('down', 14) ?></button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="<?= (int) $j['aktif'] === 1 ? 'nonaktif' : 'aktif' ?>">
                    <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('eye', 14) ?> <?= (int) $j['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                  </form>
                  <form method="post" data-confirm="Hapus jenis dokumen &quot;<?= e((string) $j['nama']) ?>&quot;?<?= $dipakai > 0 ? ' ' . (int) $dipakai . ' dokumen yang sudah dikirim tetap tersimpan.' : '' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 14) ?> Hapus</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php
  $editId = (int) ($_GET['edit'] ?? 0);
  $edit = $editId > 0 ? dokumen_jenis_by_id($editId) : null;
  ?>
  <form method="post" class="card" id="formJenis" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="<?= $edit ? 'ubah' : 'tambah' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <div class="card-head">
      <div>
        <h2><?= $edit ? 'Ubah Nama Dokumen' : 'Tambah Nama Dokumen' ?></h2>
        <p><?= $edit ? 'Perubahan nama langsung berlaku pada pilihan di formulir kirim dokumen.'
          : 'Nama ini akan muncul sebagai pilihan pada formulir kirim dokumen.' ?></p>
      </div>
      <?php if ($edit): ?><span class="chip">Mode ubah</span><?php endif; ?>
    </div>
    <label class="f" for="nama">Nama Dokumen *</label>
    <input type="text" id="nama" name="nama" maxlength="120" required
           value="<?= e((string) ($edit['nama'] ?? '')) ?>"
           placeholder="mis. Kurikulum Madrasah (KM)">
    <div class="hint">Tulis nama sebagaimana yang ingin ditampilkan pada pilihan. Nama yang sama tidak
      boleh dipakai dua kali.</div>
    <?php if (!$edit): ?>
      <label class="f mt16" style="display:flex;gap:9px;align-items:center;font-weight:600">
        <input type="checkbox" name="aktif" value="1" checked style="width:16px;height:16px;accent-color:var(--accent)">
        Langsung tampilkan sebagai pilihan pada formulir
      </label>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= ikon($edit ? 'check' : 'plus', 16) ?>
        <?= $edit ? 'Simpan Perubahan' : 'Tambah' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost" href="dokumen_jenis.php">Batal</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Catatan</h3>
      <p class="sm mut" style="margin:4px 0 0">
        <b>Menonaktifkan</b> sebuah jenis hanya menyembunyikannya dari pilihan — dokumen yang sudah
        dikirim tetap tersimpan. <b>Menghapus</b> jenis juga tidak menghapus dokumen yang sudah
        dikirim, karena nama jenisnya ikut tersimpan pada setiap dokumen (snapshot).
      </p>
    </div>
    <span class="chip">Hanya Super Admin</span>
  </div>
</div>
<?php
page_bottom();
