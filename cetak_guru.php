<?php
/**
 * Cetak Data Guru — daftar guru beserta NIP/NIK dan akun loginnya (nama pengguna + sandi).
 *
 * Catatan penting soal SANDI:
 *   Sandi pengguna disimpan terenkripsi (password_hash), sehingga sandi lama TIDAK mungkin
 *   ditampilkan kembali. Karena itu lembar ini menyediakan aksi "Tetapkan Sandi Baru":
 *   admin menentukan sandi baru (sama dengan NIP, atau sandi acak), aplikasi menyimpan
 *   hash-nya, dan sandi tersebut ditampilkan SEKALI pada lembar ini untuk dibagikan ke guru.
 *   Sandi yang sudah tampil tidak disimpan dalam bentuk teks di mana pun.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Tampung keluaran agar istilah Sekolah/Madrasah bisa disesuaikan sebelum dikirim.
ob_start();
$GLOBALS['__vs_buf_level'] = ob_get_level();

$u = require_admin();
$sekolah = current_sekolah();
$sid = sekolah_id();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/** Sandi acak yang mudah dibaca (huruf/angka tanpa karakter membingungkan). */
function sandi_acak(int $panjang = 8): string
{
    $abjad = 'abcdefghjkmnpqrstuvwxyz23456789';
    $maks = strlen($abjad) - 1;
    $out = '';
    for ($i = 0; $i < $panjang; $i++) {
        $out .= $abjad[random_int(0, $maks)];
    }
    return $out;
}

// Nama pengguna & kata sandi memakai helper bersama (inc/helpers.php),
// sehingga NIP yang kosong/diisi "-" tidak menghasilkan akun aneh.

// ---------------------------------------------------------------- aksi: tetapkan sandi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'tetapkan') {
        $mode   = ($_POST['mode'] ?? 'nip') === 'acak' ? 'acak' : 'nip';
        $lingkup = ($_POST['lingkup'] ?? 'hanya_baru') === 'semua' ? 'semua' : 'hanya_baru';
        $status = ($_POST['status'] ?? 'aktif') === 'semua' ? 'semua' : 'aktif';
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));

        if ($lingkup === 'semua' && $konfirmasi !== 'SETUJUI') {
            flash('Untuk menetapkan ulang sandi SEMUA guru, ketik SETUJUI pada kolom konfirmasi.', 'err');
            redirect('cetak_guru.php');
        }

        $guruList = all('SELECT g.*, u.id AS uid, u.username AS username FROM guru g
                         LEFT JOIN users u ON u.guru_id = g.id
                         WHERE g.sekolah_id = ?' . ($status === 'aktif' ? ' AND g.aktif = 1' : '') . '
                         ORDER BY g.nama', [$sid]);

        $dibuat = 0;
        $diubah = 0;
        $sekali = [];
        $now = date('Y-m-d H:i:s');

        db()->exec('BEGIN IMMEDIATE');
        try {
            foreach ($guruList as $g) {
                $punyaAkun = !empty($g['uid']);
                if ($punyaAkun && $lingkup === 'hanya_baru') {
                    continue; // jangan sentuh akun yang sudah ada
                }
                // Mode "sandi = NIP": bila guru belum punya NIP, otomatis memakai 'guru123'.
                $sandi = $mode === 'nip' ? guru_sandi_awal($g) : sandi_acak(8);

                if ($punyaAkun) {
                    q('UPDATE users SET password_hash = ?, aktif = 1 WHERE id = ?',
                        [password_hash($sandi, PASSWORD_DEFAULT), (int) $g['uid']]);
                    $username = (string) $g['username'];
                    $diubah++;
                } else {
                    $username = guru_username_unik($g);
                    q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, dibuat) VALUES (?,?,?,?,?,?,1,?)',
                        [$username, password_hash($sandi, PASSWORD_DEFAULT), $g['nama'], 'guru', $sid, (int) $g['id'], $now]);
                    $dibuat++;
                }
                // Keterangan sandi ditampilkan sekali saja (tidak disimpan sebagai teks).
                $sekali[(int) $g['id']] = ['username' => $username, 'sandi' => $sandi, 'nama' => $g['nama']];
            }
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        if (!$sekali) {
            flash('Tidak ada akun yang perlu ditetapkan sandinya (semua guru sudah punya akun).', 'warn');
            redirect('cetak_guru.php');
        }

        $_SESSION['sandi_sekali'] = $sekali;
        flash($diubah . ' sandi ditetapkan ulang dan ' . $dibuat . ' akun baru dibuat. '
            . 'Sandi ditampilkan SEKALI pada lembar cetak di bawah — catat atau cetak sekarang, jangan tutup halaman ini sebelum menyimpannya.', 'warn');
        redirect('cetak_guru.php?tampil=1&status=' . $status . '&lingkup=' . $lingkup);
    }

    flash('Aksi tidak dikenali.', 'err');
    redirect('cetak_guru.php');
}

// ---------------------------------------------------------------- data untuk lembar cetak
$status = ($_GET['status'] ?? 'aktif') === 'semua' ? 'semua' : 'aktif';
$fAkun = (string) ($_GET['akun'] ?? 'semua');
if (!in_array($fAkun, ['semua', 'punya', 'belum'], true)) {
    $fAkun = 'semua';
}

// Sandi sekali-tampil: dibaca lalu langsung dibersihkan dari sesi.
$sekali = [];
if (isset($_SESSION['sandi_sekali'])) {
    $sekali = (array) $_SESSION['sandi_sekali'];
    unset($_SESSION['sandi_sekali']);
}

$w = ['g.sekolah_id = ?'];
$p = [$sid];
if ($status === 'aktif') {
    $w[] = 'g.aktif = 1';
}
if ($fAkun === 'punya') {
    $w[] = 'u.id IS NOT NULL';
} elseif ($fAkun === 'belum') {
    $w[] = 'u.id IS NULL';
}
$where = implode(' AND ', $w);

$rows = all("SELECT g.id, g.nama, g.nip, g.mapel, g.jabatan, g.aktif,
        u.username, u.id AS uid, u.aktif AS akun_aktif, u.login_terakhir, u.foto
    FROM guru g LEFT JOIN users u ON u.guru_id = g.id
    WHERE $where ORDER BY g.nama", $p);

$jmlPunyaAkun = 0;
foreach ($rows as $r) {
    if ($r['uid']) {
        $jmlPunyaAkun++;
    }
}

$autoPrint = isset($_GET['auto']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Guru &amp; Akun — <?= e(tetap_kata(setting('nama_sekolah', 'Sekolah'))) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<style>
  body{background:#F3F0FF;padding:26px 18px 40px}
  .pengaturan{max-width:1040px;margin:0 auto 18px}
  .sheet-wrap{max-width:1040px;margin:0 auto}
  .toolbar{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}
  .sandi-baru{
    display:inline-block;font-weight:800;letter-spacing:.05em;padding:2px 7px;border-radius:6px;
    background:rgba(245,158,11,.18);border:1px solid rgba(217,119,6,.4);color:#92400e;font-family:ui-monospace,Menlo,Consolas,monospace;
  }
  .sandi-mati{color:#635F82;letter-spacing:.12em}
  table.sheet.compact th,table.sheet.compact td{padding:6px 7px;font-size:.78rem}
  .sheet-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  @media (max-width:780px){
    .print-sheet{padding:18px 14px}
    table.sheet.compact{min-width:640px}
    .sheet-meta{grid-template-columns:minmax(0,1fr)}
  }
  @media print{
    body{background:#fff;padding:0}
    .pengaturan,.toolbar,.no-print{display:none !important}
    .sheet-wrap{max-width:none}
    .print-sheet{box-shadow:none;border:none;padding:0;border-radius:0}
    .sandi-baru{background:#fff;border:none;color:#000;padding:0}
    .sheet-scroll{overflow:visible}
    table.sheet.compact{min-width:0}
  }
  @page{size:A4 landscape;margin:12mm}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<!-- ==================== Panel pengaturan (tidak ikut tercetak) ==================== -->
<div class="pengaturan no-print">
  <?php foreach (flash_take() as $f): ?>
    <div class="alert <?= $f['tipe'] === 'ok' ? 'alert-ok' : ($f['tipe'] === 'warn' ? 'alert-warn' : 'alert-err') ?>">
      <?= ikon($f['tipe'] === 'ok' ? 'check' : 'star', 16) ?><span><?= e($f['pesan']) ?></span>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-head">
      <div>
        <h2>Cetak Data Guru &amp; Akun</h2>
        <p>Lembar ini memuat nama guru, NIP/NIK, dan akun login (nama pengguna + sandi) untuk dibagikan ke guru.</p>
      </div>
      <div class="flex">
        <a class="btn btn-ghost" href="guru.php">Kembali ke Data Guru</a>
        <button class="btn btn-primary" type="button" data-print><?= ikon('print', 16) ?> Cetak / Simpan PDF</button>
      </div>
    </div>

    <div class="alert alert-warn" style="margin-bottom:16px">
      <?= ikon('star', 16) ?>
      <span><b>Tentang kolom sandi:</b> sandi guru disimpan terenkripsi, jadi sandi lama <b>tidak dapat ditampilkan</b>.
        Bila guru lupa sandinya, gunakan tombol <b>Tetapkan Sandi Baru</b> di bawah — sandi baru akan langsung terlihat
        pada lembar <b>satu kali</b> ini (sandi tetap tidak tersimpan sebagai teks di aplikasi).</span>
    </div>

    <div class="grid g2" style="align-items:start">
      <!-- filter tampilan -->
      <form method="get" class="grid" style="gap:12px">
        <h3 class="mb0">Cakupan data</h3>
        <div class="grid g2" style="gap:12px">
          <div>
            <label class="f" for="status">Status guru</label>
            <select id="status" name="status">
              <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Hanya guru aktif</option>
              <option value="semua" <?= $status === 'semua' ? 'selected' : '' ?>>Semua guru (termasuk nonaktif)</option>
            </select>
          </div>
          <div>
            <label class="f" for="akun">Kepemilikan akun</label>
            <select id="akun" name="akun">
              <option value="semua" <?= $fAkun === 'semua' ? 'selected' : '' ?>>Semua</option>
              <option value="punya" <?= $fAkun === 'punya' ? 'selected' : '' ?>>Sudah punya akun</option>
              <option value="belum" <?= $fAkun === 'belum' ? 'selected' : '' ?>>Belum punya akun</option>
            </select>
          </div>
        </div>
        <div class="flex">
          <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('filter', 14) ?> Tampilkan</button>
          <a class="btn btn-ghost btn-sm" href="cetak_guru.php">Reset</a>
        </div>
        <div class="hint">Menampilkan <b><?= count($rows) ?></b> guru &middot; <?= $jmlPunyaAkun ?> sudah punya akun &middot;
          <?= count($rows) - $jmlPunyaAkun ?> belum punya akun.</div>
      </form>

      <!-- tetapkan sandi baru -->
      <form method="post" class="grid" style="gap:12px" data-confirm="Tetapkan sandi baru untuk guru sesuai pilihan? Sandi lama tidak dapat dikembalikan.">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="tetapkan">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <h3 class="mb0">Tetapkan sandi baru (opsional)</h3>
        <div class="grid g2" style="gap:12px">
          <div>
            <label class="f" for="mode">Sandi baru</label>
            <select id="mode" name="mode">
              <option value="nip">Sama dengan NIP guru ('guru123' bila belum ada NIP)</option>
              <option value="acak">Sandi acak 8 karakter</option>
            </select>
          </div>
          <div>
            <label class="f" for="lingkup">Untuk guru</label>
            <select id="lingkup" name="lingkup">
              <option value="hanya_baru">Yang belum punya akun saja (aman)</option>
              <option value="semua">Semua guru (sandi lama direset)</option>
            </select>
          </div>
        </div>
        <div id="blokKonfirmasi" style="display:none">
          <label class="f" for="konfirmasi">Ketik SETUJUI untuk mereset sandi semua guru</label>
          <input type="text" id="konfirmasi" name="konfirmasi" placeholder="SETUJUI">
        </div>
        <div class="flex">
          <button class="btn btn-primary" type="submit"><?= ikon('key', 16) ?> Tetapkan sandi &amp; tampilkan di lembar</button>
        </div>
        <div class="hint">Sandi yang baru ditetapkan tampil sekali pada lembar cetak, lalu disembunyikan lagi pada pemuatan berikutnya.</div>
      </form>
    </div>
  </div>
</div>

<!-- ==================== Lembar cetak ==================== -->
<div class="sheet-wrap">
  <div class="print-sheet">
    <?php $logoKop = logo_sekolah(); ?>
    <div class="sheet-head<?= $logoKop !== '' ? ' kop-logo' : '' ?>">
      <?php if ($logoKop !== ''): ?>
        <div class="kop-gambar"><img src="<?= e($logoKop) ?>" alt="Logo <?= e(istilah_sekolah('Sekolah')) ?>"></div>
      <?php endif; ?>
      <div class="kop-teks">
      <h2>Daftar Data Guru dan Akun</h2>
      <p><?= e(tetap_kata(setting('nama_sekolah', 'Sekolah'))) ?></p>
      <?php foreach (kop_baris_identitas() as $bk): ?>
        <p style="font-size:.78rem"><?= e(tetap_kata($bk)) ?></p>
      <?php endforeach; ?>
      </div>
    </div>

    <div class="sheet-meta">
      <div><b>Tahun Ajaran</b>: <?= e(setting('tahun_ajaran', '-')) ?></div>
      <div><b>Semester</b>: <?= e(setting('semester', '-')) ?></div>
      <div><b>Jumlah Guru</b>: <?= count($rows) ?> orang<?= $status === 'aktif' ? ' (aktif)' : '' ?></div>
      <div><b>Sudah Punya Akun</b>: <?= $jmlPunyaAkun ?> orang</div>
      <div><b>Dicetak</b>: <?= e(tanggal_id(date('Y-m-d'))) ?></div>
      <div><b>Cakupan Akun</b>: <?= e(['semua' => 'Semua guru', 'punya' => 'Sudah punya akun', 'belum' => 'Belum punya akun'][$fAkun]) ?></div>
    </div>

    <?php if (!$rows): ?>
      <p class="sm" style="text-align:center;padding:20px 0">Belum ada data guru pada cakupan ini.</p>
    <?php else: ?>
      <div class="sheet-scroll">
      <table class="sheet compact">
        <thead>
          <tr>
            <th class="ctr" style="width:32px">No</th>
            <th style="width:24%">Nama Guru</th>
            <th style="width:15%">NIP / NIK</th>
            <th style="width:16%">Mata Pelajaran</th>
            <th style="width:15%">Akun (Nama Pengguna)</th>
            <th style="width:14%">Kata Sandi</th>
            <th class="ctr" style="width:9%">Status Akun</th>
          </tr>
        </thead>
        <tbody>
        <?php $no = 0; foreach ($rows as $r): $no++; ?>
          <tr>
            <td class="ctr"><?= $no ?></td>
            <td>
              <b><?= e((string) $r['nama']) ?></b>
              <?php if ($r['jabatan']): ?><br><span style="color:#635F82;font-size:.92em"><?= e((string) $r['jabatan']) ?></span><?php endif; ?>
              <?php if ((int) $r['aktif'] === 0): ?><br><span style="color:#b45309;font-size:.9em">(guru nonaktif)</span><?php endif; ?>
            </td>
            <td><?= e((string) ($r['nip'] ?: '-')) ?></td>
            <td><?= e((string) ($r['mapel'] ?: '-')) ?></td>
            <td>
              <?php if ($r['username']): ?>
                <b style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= e((string) $r['username']) ?></b>
              <?php else: ?>
                <span style="color:#635F82">— belum ada akun —</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $s = $sekali[(int) $r['id']] ?? null;
              if ($s !== null) {
                  echo '<span class="sandi-baru">' . e((string) $s['sandi']) . '</span>';
              } elseif ($r['username']) {
                  echo '<span class="sandi-mati">&bull;&bull;&bull;&bull;&bull;&bull;</span>';
              } else {
                  echo '<span style="color:#635F82">—</span>';
              }
              ?>
            </td>
            <td class="ctr">
              <?php if ($r['username']): ?>
                <?= (int) $r['akun_aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($sekali): ?>
        <p class="sm" style="margin-top:12px;padding:9px 11px;border:1px solid #b45309;border-radius:8px">
          <b>Perhatian:</b> sandi bertanda kotak di atas baru saja ditetapkan dan hanya tampil pada lembar ini.
          Sandi lama sudah tidak berlaku. Mintalah setiap guru mengganti sandinya setelah masuk pertama kali.
        </p>
      <?php else: ?>
        <p class="sm" style="margin-top:12px;color:#635F82">
          Kata sandi tidak ditampilkan karena tersimpan terenkripsi. Bila ada guru yang lupa sandinya,
          gunakan panel <b>Tetapkan sandi baru</b> di halaman ini (sebelum mencetak), lalu sandi barunya akan tercetak di kolom ini.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="sheet-foot">
      <div class="sheet-sign">
        <div><?= ttd_mengetahui_html() ?></div>
        <div class="space"></div>
        <u><?= e(setting('kepala_sekolah', '-')) ?></u><br>
        <span class="sm">NIP. <?= e(setting('nip_kepala_sekolah', '-')) ?></span>
      </div>
      <div class="sheet-sign">
        <?php // Baris "tempat, tanggal" di atas nama penanda tangan sisi kanan (permintaan pemilik) —
              // sebelumnya hanya tanggalnya; kini disambung nama Kecamatan dari identitas lembaga. ?>
        <div class="ttd-tempat"><?= e(ttd_tempat_tanggal()) ?></div>
        <div>Operator / Administrator</div>
        <div class="space"></div>
        <u><?= e((string) ($u['nama'] ?? '-')) ?></u><br>
        <span class="sm"><?= e((string) ($u['username'] ?? '')) ?></span>
      </div>
    </div>

    <p class="xs mut center" style="margin-top:20px">
      Dicetak dari <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> pada <?= e(tanggal_id(date('Y-m-d'))) ?>.
      Lembar ini memuat data akun — simpan atau bagikan dengan hati-hati.
    </p>
  </div>

  <div class="toolbar no-print">
    <a class="btn btn-ghost" href="guru.php">Kembali</a>
    <a class="btn btn-primary" href="cetak_guru.php?auto=1&amp;status=<?= e($status) ?>&amp;akun=<?= e($fAkun) ?>"><?= ikon('print', 16) ?> Cetak / Simpan PDF</a>
  </div>
</div>

<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
(function () {
  var lingkup = document.getElementById('lingkup');
  var blok = document.getElementById('blokKonfirmasi');
  if (lingkup && blok) {
    var sync = function () { blok.style.display = lingkup.value === 'semua' ? '' : 'none'; };
    lingkup.addEventListener('change', sync);
    sync();
  }
  <?php if ($autoPrint): ?>
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  <?php endif; ?>
})();
</script>
</body>
</html>
<?php terjemahkan_selesai(); ?>
