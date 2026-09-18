<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();
$sid = sekolah_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    // Simpan KHUSUS jenis sekolah (tombol pada kartu "Jenis Sekolah"), tanpa mengubah
    // pengaturan lain — supaya perubahan istilah Sekolah/Madrasah bisa langsung disimpan.
    if ($aksi === 'simpan_jenis') {
        $jenis = (($_POST['jenis_sekolah'] ?? 'umum') === 'madrasah') ? 'madrasah' : 'umum';
        if (!array_key_exists('jenis_sekolah', $_POST)) {
            flash('Pilih jenis sekolah terlebih dahulu.', 'err');
            redirect('pengaturan.php');
        }
        set_setting('jenis_sekolah', $jenis);
        // Kalimat pesan sengaja disusun tanpa kata umum "Sekolah" pada cabang madrasah,
        // karena pesan ini ikut melewati penyesuaian istilah saat ditampilkan.
        flash($jenis === 'madrasah'
            ? 'Jenis disimpan: MADRASAH. Seluruh istilah kini memakai kata "Madrasah" — termasuk pada hasil cetak.'
            : 'Jenis disimpan: SEKOLAH UMUM. Istilah kembali memakai kata "Sekolah" pada aplikasi dan hasil cetak.');
        redirect('pengaturan.php');
    }

    if ($aksi === 'simpan') {
        $teks = ['nama_sekolah', 'npsn', 'alamat_sekolah', 'kepala_sekolah', 'nip_kepala_sekolah', 'tahun_ajaran', 'semester'];
        foreach ($teks as $k) {
            set_setting($k, trim((string) ($_POST[$k] ?? '')));
        }
        // Jenis sekolah: menentukan istilah "Sekolah" atau "Madrasah" di seluruh aplikasi & cetakan.
        // Hanya diubah bila bidangnya benar-benar dikirim (form lama tanpa bidang ini tidak menimpa).
        if (array_key_exists('jenis_sekolah', $_POST)) {
            $jenis = (($_POST['jenis_sekolah'] ?? 'umum') === 'madrasah') ? 'madrasah' : 'umum';
            set_setting('jenis_sekolah', $jenis);
        }

        $a = (float) ($_POST['batas_a'] ?? 90);
        $b = (float) ($_POST['batas_b'] ?? 80);
        $c = (float) ($_POST['batas_c'] ?? 70);
        if (!($a > $b && $b > $c && $c >= 0 && $a <= 100)) {
            flash('Ambang predikat tidak valid. Pastikan A > B > C dan berada pada rentang 0-100.', 'err');
        } else {
            set_setting('batas_a', (string) $a);
            set_setting('batas_b', (string) $b);
            set_setting('batas_c', (string) $c);
            flash('Pengaturan aplikasi disimpan.');
        }
        if (!empty($_POST['hitung_ulang'])) {
            $th = thresholds(settings(true));
            $n = 0;
            foreach (all('SELECT id, nilai FROM supervisi WHERE sekolah_id = ?', [$sid]) as $s) {
                q('UPDATE supervisi SET predikat = ? WHERE id = ?', [predikat_dari((float) $s['nilai'], $th), (int) $s['id']]);
                $n++;
            }
            flash($n . ' predikat dihitung ulang memakai ambang baru.');
        }
    } elseif ($aksi === 'tandai_asli') {
        $n = (int) val('SELECT COUNT(*) FROM guru WHERE demo = 1 AND sekolah_id = ?', [$sid], 0);
        q('UPDATE guru SET demo = 0 WHERE sekolah_id = ?', [$sid]);
        flash($n . ' data guru ditandai sebagai data asli (bukan contoh), sehingga tidak akan tersentuh oleh pembersihan data contoh lagi.');
    } elseif ($aksi === 'hapus_demo') {
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));
        $nama = all('SELECT nama FROM guru WHERE demo = 1 AND sekolah_id = ? ORDER BY nama', [$sid]);
        $jmlSup = (int) val('SELECT COUNT(*) FROM supervisi s WHERE s.sekolah_id = ? AND s.guru_id IN (SELECT id FROM guru WHERE demo = 1 AND sekolah_id = ?)', [$sid, $sid], 0);
        if ($konfirmasi !== 'HAPUS') {
            flash('Ketik HAPUS pada kolom konfirmasi untuk benar-benar menghapus ' . count($nama) . ' data guru bertanda contoh.', 'err');
        } else {
            $ids = array_column($nama, 'nama');
            db()->exec('BEGIN IMMEDIATE');
            try {
                foreach (all('SELECT id FROM guru WHERE demo = 1 AND sekolah_id = ?', [$sid]) as $g) {
                    q('DELETE FROM users WHERE guru_id = ?', [(int) $g['id']]);
                    q('DELETE FROM guru WHERE id = ?', [(int) $g['id']]);
                }
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw $ex;
            }
            flash(count($ids) . ' data guru bertanda contoh beserta ' . $jmlSup . ' hasil supervisinya dihapus.', 'warn');
        }
    } elseif ($aksi === 'kosongkan') {
        if (strtoupper(trim((string) ($_POST['konfirmasi'] ?? ''))) !== 'HAPUS') {
            flash('Ketik HAPUS pada kolom konfirmasi untuk mengosongkan seluruh data supervisi.', 'err');
        } else {
            db()->exec('BEGIN IMMEDIATE');
            try {
                q('DELETE FROM detail WHERE supervisi_id IN (SELECT id FROM supervisi WHERE sekolah_id = ?)', [$sid]);
                q('DELETE FROM supervisi WHERE sekolah_id = ?', [$sid]);
                db()->exec('COMMIT');
            } catch (Throwable $ex) {
                db()->exec('ROLLBACK');
                throw $ex;
            }
            flash('Seluruh data supervisi dikosongkan. Data guru dan pengguna tetap tersimpan.', 'warn');
        }
    }
    redirect('pengaturan.php');
}

$adaDemo = (int) val('SELECT COUNT(*) FROM guru WHERE demo = 1 AND sekolah_id = ?', [$sid], 0);
$namaDemo = all('SELECT nama FROM guru WHERE demo = 1 AND sekolah_id = ? ORDER BY nama', [$sid]);
$supDemo = (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ? AND guru_id IN (SELECT id FROM guru WHERE demo = 1 AND sekolah_id = ?)', [$sid, $sid], 0);
$jmlSupervisi = (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ?', [$sid], 0);
$jmlDetail = (int) val('SELECT COUNT(*) FROM detail WHERE supervisi_id IN (SELECT id FROM supervisi WHERE sekolah_id = ?)', [$sid], 0);
$jmlGuru = (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$sid], 0);
// Butir penilaian GLOBAL (sama untuk semua sekolah) — dikelola Super Admin di menu Butir Penilaian.
$jmlButir = jumlah_butir_global();
$th = thresholds();

page_top('Profil & Pengaturan Sekolah', 'pengaturan');
?>
<!-- Kartu logo: dipakai pada KOP semua dokumen cetak (form sendiri). -->
<div class="card" id="cardLogo">
  <div class="card-head">
    <div>
      <h2>Logo <?= e(istilah_sekolah('Sekolah')) ?></h2>
      <p>Tampil pada kop dokumen yang dicetak: laporan supervisi, jadwal supervisi, dan daftar data guru.</p>
    </div>
    <span class="pill <?= ada_logo() ? 'pill-ok' : 'pill-gray' ?>"><?= ada_logo() ? 'Logo terpasang' : 'Belum ada logo' ?></span>
  </div>

  <div class="foto-baris">
    <div class="foto-sisi">
      <?php if (ada_logo()): ?>
        <span class="logo-kotak"><img src="<?= e(logo_sekolah()) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>"></span>
        <div class="xs mut center">Pratinjau logo</div>
      <?php else: ?>
        <span class="logo-kosong"><?= ikon('school', 30) ?></span>
        <div class="xs mut center">Belum ada logo</div>
      <?php endif; ?>
    </div>

    <form method="post" action="upload_logo.php" enctype="multipart/form-data" class="foto-form" id="formLogo">
      <?= csrf_field() ?>
      <div class="foto-input-baris">
        <div style="flex:1;min-width:0">
          <label class="f" for="logo">Pilih berkas logo</label>
          <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required>
          <div class="hint" id="logoHint">
            Format PNG, JPG, GIF, WEBP, atau SVG &middot; maksimal 15 MB.
            Sebaiknya gambar persegi dengan latar transparan (PNG/SVG) agar rapi pada kop.
          </div>
        </div>
        <div class="foto-preview-wrap" id="logoPreviewWrap" hidden>
          <span class="logo-kotak"><span id="logoPreview"></span></span>
          <div class="xs mut center" id="logoUkuran"></div>
        </div>
      </div>
      <div class="flex mt16">
        <button class="btn btn-primary" type="submit" id="btnLogo"><?= ikon('upload', 16) ?> <?= ada_logo() ? 'Ganti Logo' : 'Unggah Logo' ?></button>
        <span class="xs mut" id="logoStatus"></span>
      </div>
    </form>

    <?php if (ada_logo()): ?>
      <form method="post" action="upload_logo.php" data-confirm="Hapus logo? Kop dokumen cetak akan kembali tanpa logo.">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="hapus">
        <button class="btn btn-danger" type="submit"><?= ikon('trash', 16) ?> Hapus Logo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Kartu jenis sekolah: punya FORM dan TOMBOL SIMPAN sendiri, jadi perubahan
     pilihan Sekolah Umum / Madrasah bisa langsung disimpan tanpa menggulir ke bawah. -->
<div class="card" id="cardJenis">
  <div class="card-head">
    <div>
      <h2>Jenis <?= e(istilah_sekolah('Sekolah')) ?></h2>
      <p>Menentukan istilah yang dipakai di seluruh aplikasi dan hasil cetak.</p>
    </div>
    <span class="pill <?= is_madrasah() ? 'pill-B' : 'pill-gray' ?>" id="jenisChip"><?= is_madrasah() ? 'Madrasah' : 'Sekolah Umum' ?></span>
  </div>

  <form method="post" class="grid g2" style="gap:16px;align-items:start">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan_jenis">
    <div>
      <label class="f" for="jenis_sekolah">Pilih Jenis *</label>
      <select id="jenis_sekolah" name="jenis_sekolah" data-tersimpan="<?= e(jenis_sekolah()) ?>">
        <option value="umum" <?= jenis_sekolah() === 'umum' ? 'selected' : '' ?>>Sekolah Umum (SD/SMP/SMA/SMK)</option>
        <option value="madrasah" <?= is_madrasah() ? 'selected' : '' ?>>Madrasah (MI/MTs/MA)</option>
      </select>
      <div class="hint">
        Bila memilih <b>Madrasah</b>, seluruh tulisan "Sekolah" pada aplikasi dan hasil cetak otomatis
        berubah menjadi "Madrasah" — misalnya <b>Kepala Sekolah → Kepala Madrasah</b>,
        <b>Nama Sekolah → Nama Madrasah</b>, dan judul lembar cetak ikut menyesuaikan.
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit" id="btnSimpanJenis">
          <?= ikon('check', 16) ?><span id="btnSimpanJenisLabel">Simpan Jenis Sekolah</span>
        </button>
        <span class="hint" id="jenisCatatan" style="margin:0"></span>
      </div>
    </div>

    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no">i</div>
        <div>
          <div class="butir-komponen">Contoh perubahan istilah</div>
          <div class="butir-indikator">
            &bull; Kepala Sekolah &rarr; Kepala Madrasah<br>
            &bull; Nama Sekolah &rarr; Nama Madrasah<br>
            &bull; Identitas Sekolah &rarr; Identitas Madrasah<br>
            &bull; Data Guru/Madrasah, kop lembar cetak, dan label lain mengikuti pilihan ini.
          </div>
        </div>
      </div>
      <div class="hint" style="margin-top:10px">
        Jenis yang tersimpan saat ini:
        <b><?= is_madrasah() ? 'Madrasah' : 'Sekolah Umum' ?></b>.
        Setelah menekan <b>Simpan Jenis Sekolah</b>, seluruh tulisan pada aplikasi dan hasil cetak
        langsung memakai istilah tersebut.
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var sel = document.getElementById('jenis_sekolah');
  var btn = document.getElementById('btnSimpanJenis');
  var lbl = document.getElementById('btnSimpanJenisLabel');
  var cat = document.getElementById('jenisCatatan');
  if (!sel || !btn) return;
  var tersimpan = sel.getAttribute('data-tersimpan') || 'umum';
  function sync() {
    var berubah = sel.value !== tersimpan;
    if (lbl) { lbl.textContent = berubah ? 'Simpan Jenis Sekolah (belum disimpan)' : 'Simpan Jenis Sekolah'; }
    btn.className = berubah ? 'btn btn-primary' : 'btn btn-ghost';
    if (cat) {
      cat.innerHTML = berubah
        ? '<b style="color:#b45309">Pilihan belum disimpan</b> — tekan tombol ini untuk menerapkan.'
        : 'Pilihan sudah sesuai dengan yang tersimpan.';
    }
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<form method="post" class="grid g2" style="align-items:start">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="simpan">

  <div class="card">
    <div class="card-head"><div><h2>Identitas <?= e(istilah_sekolah('Sekolah')) ?></h2><p>Tampil pada header aplikasi dan kop laporan cetak. Perubahan hanya berlaku untuk <?= e(strtolower(istilah_sekolah('Sekolah'))) ?> ini.</p></div></div>
    <div class="grid" style="gap:14px">
      <div>
        <label class="f" for="nama_sekolah">Nama <?= e(istilah_sekolah('Sekolah')) ?></label>
        <input type="text" id="nama_sekolah" name="nama_sekolah" value="<?= e(setting('nama_sekolah')) ?>">
      </div>
      <div class="grid g2">
        <div>
          <label class="f" for="npsn">NPSN</label>
          <input type="text" id="npsn" name="npsn" value="<?= e(setting('npsn')) ?>">
        </div>
        <div>
          <label class="f" for="tahun_ajaran">Tahun Ajaran</label>
          <input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e(setting('tahun_ajaran')) ?>" placeholder="2024/2025">
        </div>
      </div>
      <div>
        <label class="f" for="alamat_sekolah">Alamat Sekolah</label>
        <input type="text" id="alamat_sekolah" name="alamat_sekolah" value="<?= e(setting('alamat_sekolah')) ?>">
      </div>
      <div class="grid g2">
        <div>
          <label class="f" for="semester">Semester</label>
          <select id="semester" name="semester">
            <?php foreach (['Ganjil', 'Genap'] as $s): ?>
              <option value="<?= e($s) ?>" <?= setting('semester') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="f" for="kepala_sekolah">Nama Kepala <?= e(istilah_sekolah('Sekolah')) ?> / Supervisor</label>
          <input type="text" id="kepala_sekolah" name="kepala_sekolah" value="<?= e(setting('kepala_sekolah')) ?>">
        </div>
      </div>
      <div>
        <label class="f" for="nip_kepala_sekolah">NIP Kepala <?= e(istilah_sekolah('Sekolah')) ?></label>
        <input type="text" id="nip_kepala_sekolah" name="nip_kepala_sekolah" value="<?= e(setting('nip_kepala_sekolah')) ?>">
      </div>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head"><div><h2>Ambang Predikat</h2><p>Nilai akhir 0-100 dikonversi otomatis menjadi predikat.</p></div></div>
      <div class="grid g3">
        <div>
          <label class="f" for="batas_a">A (Amat Baik) &ge;</label>
          <input type="number" id="batas_a" name="batas_a" value="<?= e(number_format($th['batas_a'], 0, '.', '')) ?>" min="0" max="100" step="0.5">
        </div>
        <div>
          <label class="f" for="batas_b">B (Baik) &ge;</label>
          <input type="number" id="batas_b" name="batas_b" value="<?= e(number_format($th['batas_b'], 0, '.', '')) ?>" min="0" max="100" step="0.5">
        </div>
        <div>
          <label class="f" for="batas_c">C (Cukup) &ge;</label>
          <input type="number" id="batas_c" name="batas_c" value="<?= e(number_format($th['batas_c'], 0, '.', '')) ?>" min="0" max="100" step="0.5">
        </div>
      </div>
      <label class="f mt16" style="display:flex;gap:9px;align-items:center;font-weight:600">
        <input type="checkbox" name="hitung_ulang" value="1" style="width:16px;height:16px;accent-color:var(--accent)">
        Hitung ulang predikat seluruh data supervisi setelah disimpan
      </label>
      <div class="hint">D (Perlu Pembinaan) berlaku untuk nilai di bawah ambang C.</div>
      <div class="divider"></div>
      <button class="btn btn-primary full" type="submit"><?= ikon('check', 16) ?> Simpan Pengaturan</button>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Ringkasan Data</h2><p>Isi basis data aplikasi saat ini.</p></div></div>
      <div class="grid g2" style="gap:12px">
        <div class="nilai-chip"><b><?= $jmlGuru ?></b><span>Guru</span></div>
        <div class="nilai-chip"><b><?= $jmlSupervisi ?></b><span>Supervisi</span></div>
        <div class="nilai-chip"><b><?= $jmlDetail ?></b><span>Baris penilaian</span></div>
        <div class="nilai-chip"><b><?= $jmlButir ?></b><span>Butir instrumen</span></div>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-head"><div><h2>Pemeliharaan Data</h2><p>Gunakan dengan hati-hati. Tindakan di bawah tidak dapat dibatalkan.</p></div></div>
  <div class="grid g3">
    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no">1</div>
        <div>
          <div class="butir-komponen">Tandai sebagai data asli</div>
          <div class="butir-indikator">Bila Anda sudah mengganti isi data contoh dengan data guru sekolah yang sebenarnya, tekan tombol ini supaya data tersebut
            tidak lagi dianggap contoh dan aman dari pembersihan.</div>
        </div>
      </div>
      <form method="post" class="mt16">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="tandai_asli">
        <button class="btn btn-primary" type="submit" <?= $adaDemo === 0 ? 'disabled' : '' ?>>
          <?= ikon('check', 16) ?> Tandai <?= $adaDemo ?> guru sebagai data asli
        </button>
      </form>
      <div class="hint">Saat ini <?= $adaDemo ?> dari <?= $jmlGuru ?> data guru masih bertanda "contoh".</div>
    </div>

    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no" style="background:linear-gradient(135deg,#f59e0b,#ea580c)">2</div>
        <div>
          <div class="butir-komponen">Hapus data bertanda contoh</div>
          <div class="butir-indikator">Menghapus <?= $adaDemo ?> guru bertanda contoh beserta <?= $supDemo ?> hasil supervisinya.
            <b>Periksa daftar nama di bawah dulu</b> — bila di dalamnya sudah ada data guru asli Anda, gunakan tombol nomor 1 di samping.</div>
        </div>
      </div>
      <?php if ($adaDemo > 0): ?>
        <div class="mt16" style="max-height:118px;overflow:auto;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:10px;padding:9px 11px">
          <?php foreach ($namaDemo as $n): ?>
            <div class="xs">&bull; <?= e((string) $n['nama']) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" class="mt16" data-confirm="Hapus <?= $adaDemo ?> data guru bertanda contoh beserta <?= $supDemo ?> hasil supervisinya?">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus_demo">
        <div class="grid g2" style="gap:10px">
          <div>
            <label class="f" for="konfirmasi_demo">Ketik HAPUS untuk konfirmasi</label>
            <input type="text" id="konfirmasi_demo" name="konfirmasi" placeholder="HAPUS">
          </div>
          <div style="align-self:end">
            <button class="btn btn-danger full" type="submit" <?= $adaDemo === 0 ? 'disabled' : '' ?>><?= ikon('trash', 16) ?> Hapus data contoh</button>
          </div>
        </div>
      </form>
    </div>

    <div class="butir" style="margin:0">
      <div class="butir-head">
        <div class="butir-no" style="background:linear-gradient(135deg,#dc2626,#b91c1c)">3</div>
        <div>
          <div class="butir-komponen">Kosongkan data supervisi</div>
          <div class="butir-indikator">Menghapus semua penilaian dan catatan (<?= $jmlSupervisi ?> supervisi). Data guru, akun, dan instrumen tetap tersimpan.
            Berguna untuk membuang hasil supervisi contoh sebelum mulai penilaian yang sebenarnya.</div>
        </div>
      </div>
      <form method="post" class="mt16" data-confirm="Yakin mengosongkan SELURUH data supervisi? Tindakan ini tidak dapat dibatalkan.">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="kosongkan">
        <div class="grid g2" style="gap:10px">
          <div>
            <label class="f" for="konfirmasi">Ketik HAPUS untuk konfirmasi</label>
            <input type="text" id="konfirmasi" name="konfirmasi" placeholder="HAPUS">
          </div>
          <div style="align-self:end">
            <button class="btn btn-danger full" type="submit"><?= ikon('trash', 16) ?> Kosongkan data supervisi</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Tentang Aplikasi</h3>
      <p class="sm mut" style="margin:4px 0 0"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &mdash; sistem informasi supervisi akademik <b>multi-sekolah</b>: empat menu supervisi, penilaian berbasis skor, perhitungan nilai dan predikat otomatis, serta laporan siap cetak. Setiap sekolah memiliki data, instrumen, dan akun yang terpisah.
        <?php if (is_super()): ?><br>Sebagai Super Admin, Anda dapat menambah/mengelola sekolah lain di halaman <a href="sekolah.php">Kelola Sekolah</a>.<?php endif; ?>
      </p>
    </div>
    <span class="chip">Skala butir 1-4</span>
  </div>
</div>
<?php
page_bottom();
