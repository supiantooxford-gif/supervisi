<?php
/**
 * Pendaftaran sekolah/madrasah (halaman PUBLIK, dapat diakses tanpa masuk).
 *
 * Permintaan pemilik (pembaruan): "Pada saat ada sekolah yang mendaftar buat sistem yang menyetujui
 * otomatis tapi disetujui dengan mode terbatas." → sekolah yang mendaftar LANGSUNG **disetujui
 * otomatis** dan akun adminnya bisa dipakai seketika, TETAPI berjalan dalam **mode terbatas**
 * (`aktivasi = 'dibatasi'`): maksimal 1 data guru, 1 supervisi per menu, 1 baris jadwal, dan impor
 * guru/akun massal belum dibuka. **Masa terbatas itu hanya 15 hari** (`AKTIVASI_HARI_UJI`, dipersingkat dari 30 hari,
 * dihitung dari saat sekolah MENDAFTAR → kolom `sekolah.uji_mulai`; permintaan pemilik "sekolah
 * yang belum melakukan aktifasi, selain fiturnya dibatasi waktu penggunaan juga dibatasi"),
 * setelah itu aplikasi TERKUNCI sampai kode aktivasi dimasukkan. Seluruh fitur terbuka setelah
 * **kode aktivasi** lembaganya dimasukkan (lihat aktivasi.php). Admin (Super Admin) tetap dapat
 * menolak/menonaktifkan/menghapus sekolah lewat pendaftaran.php & sekolah.php.
 *
 * ISTILAH (permintaan pemilik): seluruh tulisan yang TAMPIL memakai istilah "sekolah/madrasah"
 * (halaman ini melayani sekolah umum maupun madrasah) dan persetujuan disebut "Admin"
 * (bukan "Super Admin"). Nama field/kolom (mis. name="kepala_sekolah") TIDAK diubah.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$err = [];
$berhasil = false;
$pengawasBaru = null; // diisi bila pendaftar mengisi Nama Pengawas Bina
// Isian identitas lembaga (permintaan pemilik) — sama dengan halaman Identitas Madrasah/Sekolah,
// ditambah pilihan JENIS (Sekolah Umum / Madrasah).
$form = [
    'jenis_sekolah' => 'umum',
    'nama' => '', 'npsn' => '', 'nsm' => '', 'alamat' => '', 'desa' => '', 'kecamatan' => '',
    'kabupaten' => '', 'provinsi' => '', 'telepon' => '', 'email' => '', 'website' => '',
    'kepala_sekolah' => '', 'nip_kepala_sekolah' => '',
    // Pengawas bina sekolah yang mendaftar (permintaan pemilik): namanya otomatis dicatat sebagai
    // akun pengawas NONAKTIF pada halaman Akun Pengawas milik Super Admin untuk ditinjau.
    'pengawas_nama' => '', 'pengawas_nip' => '',
    'tahun_ajaran' => date('Y') . '/' . (date('Y') + 1), 'semester' => 'Ganjil',
    'kontak_nama' => '', 'kontak_telepon' => '', 'kontak_email' => '',
    'admin_nama' => '', 'admin_username' => '',
];

/** Captcha sederhana (penjumlahan) untuk menahan pengiriman otomatis. */
function captcha_baru(): array
{
    $a = random_int(3, 12);
    $b = random_int(2, 9);
    $_SESSION['daftar_captcha'] = $a + $b;
    return [$a, $b];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    foreach (array_keys($form) as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    $jawab = trim((string) ($_POST['captcha'] ?? ''));
    $setuju = !empty($_POST['setuju']);

    // Jenis lembaga: madrasah hanya bila benar-benar dipilih; NSM hanya bermakna pada madrasah
    // (sekolah umum tidak punya NSM) sehingga isinya tidak disimpan pada jenis umum.
    $jenis = $form['jenis_sekolah'] === 'madrasah' ? 'madrasah' : 'umum';
    if ($jenis !== 'madrasah') {
        $form['nsm'] = '';
    }

    // Validasi
    if ($form['nama'] === '') {
        $err[] = 'Nama sekolah/madrasah wajib diisi.';
    }
    if ($form['kontak_nama'] === '') {
        $err[] = 'Nama penanggung jawab (yang dapat dihubungi) wajib diisi.';
    }
    if ($form['kontak_telepon'] === '' && $form['kontak_email'] === '') {
        $err[] = 'Isi minimal satu kontak yang dapat dihubungi: nomor HP/WhatsApp atau email.';
    }
    if ($form['kontak_email'] !== '' && !filter_var($form['kontak_email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Alamat email belum valid.';
    }
    if ($form['tahun_ajaran'] === '') {
        $form['tahun_ajaran'] = date('Y') . '/' . (date('Y') + 1);
    }
    // Pengawas bina bersifat OPSIONAL; bila NIP-nya diisi tanpa nama, isian itu tidak bermakna.
    if ($form['pengawas_nip'] !== '' && $form['pengawas_nama'] === '') {
        $err[] = 'Nama pengawas bina wajib diisi bila NIP pengawas diisi.';
    }
    if ($form['admin_nama'] === '') {
        $err[] = 'Nama admin/kepala sekolah/madrasah (pemilik akun) wajib diisi.';
    }
    $username = strtolower(preg_replace('/\s+/', '', $form['admin_username']));
    if ($username === '' || strlen($username) < 4) {
        $err[] = 'Nama pengguna minimal 4 karakter tanpa spasi.';
    } elseif (!preg_match('/^[a-z0-9._-]+$/', $username)) {
        $err[] = 'Nama pengguna hanya boleh huruf kecil, angka, titik, garis bawah, atau tanda hubung.';
    } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
        $err[] = 'Nama pengguna "' . $username . '" sudah dipakai oleh sekolah/madrasah atau akun lain. Pilih yang lain.';
    }
    if (strlen($password) < 8) {
        $err[] = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $password2) {
        $err[] = 'Ulangi kata sandi dengan sama.';
    }
    if ((int) ($_SESSION['daftar_captcha'] ?? -1) !== (int) $jawab) {
        $err[] = 'Jawaban pertanyaan pengaman salah. Coba lagi.';
    }
    if (!$setuju) {
        $err[] = 'Centang pernyataan bahwa data yang diisi benar.';
    }
    // Batasi jumlah pendaftaran per sesi agar tidak disalahgunakan
    $sudahKirim = (int) ($_SESSION['daftar_kirim'] ?? 0);
    if ($sudahKirim >= 3) {
        $err[] = 'Anda sudah mengirim beberapa pendaftaran dari perangkat ini. Hubungi Admin bila belum mendapat kabar.';
    }

    if (!$err) {
        db()->exec('BEGIN IMMEDIATE');
        try {
            q('INSERT INTO sekolah (nama, npsn, nsm, alamat, desa, kecamatan, kabupaten, provinsi,
                    telepon, email, website, kepala_sekolah, nip_kepala_sekolah, tahun_ajaran, semester,
                    jenis, batas_a, batas_b, batas_c, catatan, aktif, verifikasi, aktivasi,
                    kontak_nama, kontak_telepon, kontak_email, tanggal_daftar, uji_mulai, dibuat)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,90,80,70,?,1,"disetujui","dibatasi",?,?,?,?,?,?)', [
                $form['nama'],
                $form['npsn'] !== '' ? $form['npsn'] : '-',
                $form['nsm'],
                $form['alamat'] !== '' ? $form['alamat'] : '-',
                $form['desa'],
                $form['kecamatan'],
                $form['kabupaten'],
                $form['provinsi'],
                $form['telepon'],
                $form['email'],
                $form['website'],
                $form['kepala_sekolah'] !== '' ? $form['kepala_sekolah'] : '-',
                $form['nip_kepala_sekolah'] !== '' ? $form['nip_kepala_sekolah'] : '-',
                $form['tahun_ajaran'],
                $form['semester'] === 'Genap' ? 'Genap' : 'Ganjil',
                $jenis,
                'Pendaftaran mandiri dari halaman pendaftaran.',
                $form['kontak_nama'],
                $form['kontak_telepon'],
                $form['kontak_email'],
                date('Y-m-d H:i:s'),
                // MASA UJI COBA 30 HARI (permintaan pemilik) dimulai sejak sekolah MENDAFTAR:
                // "dihitung pada saat sekolah mulai terdaftar" (lamanya kini 15 hari, semula 30 hari).
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);
            $sidBaru = (int) db()->lastInsertId();

            // sekolah_id harus ikut sebagai parameter (6 nilai untuk 6 placeholder).
            q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, asal, dibuat) VALUES (?,?,?,?,?,NULL,1,?,?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $form['admin_nama'], 'admin', $sidBaru, 'pendaftaran', date('Y-m-d H:i:s')]);

            // PENGAWAS BINA (permintaan pemilik): nama & NIP yang diisi pendaftar langsung dibuatkan
            // akun pengawas dengan STATUS NONAKTIF (menunggu ditinjau Super Admin) dan sekolah ini
            // ditautkan sebagai binaannya. Satu transaksi dengan pendaftarannya.
            $pengawasBaru = pengawas_pendaftaran($sidBaru, $form['pengawas_nama'], $form['pengawas_nip']);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        $_SESSION['daftar_kirim'] = $sudahKirim + 1;
        unset($_SESSION['daftar_captcha']);
        $berhasil = true;
        $form['admin_username'] = $username;
    } else {
        // Captcha baru setiap kali gagal
        [$ca, $cb] = captcha_baru();
    }
}

if (!$berhasil && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    [$ca, $cb] = captcha_baru();
} elseif (!$berhasil) {
    [$ca, $cb] = captcha_baru();
}

$siapa = current_user();
$sudahMasuk = $siapa !== null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pendaftaran Sekolah/Madrasah &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127891;</text></svg>">
<style>
  .daftar-wrap{min-height:100vh;padding:30px 18px 44px}
  .daftar-inner{max-width:880px;margin:0 auto}
  .daftar-head{display:flex;align-items:center;gap:14px;margin-bottom:22px}
  .daftar-logo{width:54px;height:54px;border-radius:17px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent2));box-shadow:0 12px 26px -12px rgba(var(--accent-rgb),.8);flex:0 0 auto}
  .daftar-head h1{font-size:1.35rem;margin:0}
  .daftar-head p{margin:4px 0 0;color:var(--muted);font-size:.84rem}
  .daftar-foot{margin-top:20px;text-align:center;font-size:.76rem;color:var(--muted)}
  .langkah{display:flex;gap:12px;align-items:flex-start;background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:11px 13px}
  .langkah b{display:block;font-size:.86rem}
  .langkah span{font-size:.79rem;color:var(--muted)}
  .langkah i{width:26px;height:26px;border-radius:9px;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:.78rem;color:#fff;background:linear-gradient(135deg,var(--accent),var(--accent2));flex:0 0 auto}
</style>
</head>
<body class="<?= e(tema_kelas()) ?>">
<div class="bg-deco" aria-hidden="true"><span class="b1"></span><span class="b2"></span><span class="b3"></span></div>

<div class="daftar-wrap">
  <div class="daftar-inner">

    <div class="daftar-head">
      <div class="daftar-logo"><?= ikon('school', 26) ?></div>
      <div>
        <h1>Pendaftaran Sekolah/Madrasah</h1>
        <p><?= e(APP_NAME) ?> &middot; sistem informasi supervisi akademik guru</p>
      </div>
    </div>

    <?php if ($berhasil): ?>
      <div class="card">
        <div class="empty" style="padding:28px 18px">
          <?= ikon('check', 40) ?>
          <h3>Pendaftaran berhasil</h3>
          <p>Terima kasih. Pendaftaran sekolah/madrasah <b><?= e($form['nama']) ?></b> sudah kami terima dan
            <b>DISETUJUI OTOMATIS</b> — akun di bawah ini <b>sudah bisa langsung dipakai masuk</b>,
            dengan catatan aplikasi berjalan <b>mode terbatas</b> sampai kode aktivasi dimasukkan.</p>
        </div>

        <div class="grid g2" style="gap:14px">
          <div class="langkah">
            <i>1</i>
            <div>
              <b>Disetujui otomatis</b>
              <span>Persetujuan dilakukan sistem, tanpa menunggu. Sekolah/madrasah Anda langsung tercatat
                pada aplikasi.</span>
            </div>
          </div>
          <div class="langkah">
            <i>2</i>
            <div>
              <b>Masuk &amp; mulai supervisi (mode terbatas)</b>
              <span>Gunakan akun di bawah ini pada halaman masuk. Aplikasi sudah siap dengan 4 menu supervisi.</span>
            </div>
          </div>
          <div class="langkah">
            <i>3</i>
            <div>
              <b>Aktivasi sekolah/madrasah</b>
              <span>Sebelum diaktivasi, aplikasi berjalan <b>mode terbatas</b> selama
                <b><?= (int) aktivasi_hari_uji() ?> hari</b> sejak sekolah/madrasah mendaftar: maksimal
                <?= (int) AKTIVASI_MAKS_GURU ?> data guru, <?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi
                pada setiap menu, <?= (int) AKTIVASI_MAKS_JADWAL ?> baris jadwal supervisi, dan
                <?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas; menu Susun Laporan juga
                belum terbuka.
                Setelah masa uji coba itu habis, aplikasi <b>terkunci</b> sampai diaktivasi.
                Minta <b>kode aktivasi</b> kepada Admin aplikasi
                <?= AKTIVASI_WA_ADMIN !== '' ? '(WA Admin ' . e(AKTIVASI_WA_ADMIN) . ')' : '' ?>
                lalu masukkan kode itu pada menu <b>Status &amp; Aktivasi</b> agar seluruh fitur terbuka.</span>
            </div>
          </div>
        </div>

        <div class="alert alert-ok" style="margin:16px 0 0">
          <?= ikon('check', 16) ?>
          <span>Sekolah/madrasah Anda sudah <b>disetujui oleh sistem kami</b>. Silahkan login menggunakan
            akun yang telah anda daftarkan. Jika tertarik dengan aplikasi ini, anda bisa melakukan
            <b>Aktifasi</b> dengan menghubungi admin aplikasi.</span>
        </div>

        <?php if ($pengawasBaru): ?>
          <div class="alert alert-warn" style="margin:12px 0 0">
            <?= ikon('eye', 16) ?>
            <span>Pengawas bina <b><?= e($pengawasBaru['nama']) ?></b><?= $pengawasBaru['nip'] !== '' ? ' (NIP ' . e($pengawasBaru['nip']) . ')' : '' ?>
              sudah tercatat pada daftar <b>Akun Pengawas</b> aplikasi dengan <b>status nonaktif</b>, dan
              sekolah/madrasah Anda langsung ditautkan sebagai binaannya. <b>Admin aplikasi akan meninjau</b>
              dan mengaktifkan akun tersebut beserta kata sandinya.</span>
          </div>
        <?php endif; ?>

        <div class="divider"></div>
        <div class="grid g2" style="gap:14px">
          <div><label class="f">Nama Pengguna</label><div class="b"><?= e($form['admin_username']) ?></div></div>
          <div><label class="f">Kata Sandi</label><div class="b">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; <span class="xs mut">(yang Anda isi tadi)</span></div></div>
        </div>
        <p class="hint">Simpan nama pengguna dan kata sandi Anda. Bila lupa, minta Admin mengatur ulang.</p>

        <div class="divider"></div>
        <p class="sm mut" style="margin:0">Bila kode aktivasi belum diterima atau ada kendala lain, hubungi Admin
          <?= e(APP_NAME) ?><?= APP_AUTHOR !== '' ? ' (dikembangkan oleh ' . e(APP_AUTHOR) . ')' : '' ?>.</p>
      </div>

      <div class="flex mt16" style="justify-content:center">
        <a class="btn btn-primary" href="login.php"><?= ikon('key', 16) ?> Ke Halaman Masuk</a>
      </div>
    <?php else: ?>
      <?php if ($sudahMasuk): ?>
        <div class="alert alert-ok">
          <?= ikon('check', 16) ?>
          <span>Anda sedang masuk sebagai <b><?= e((string) ($siapa['nama'] ?? '')) ?></b>.
            <a href="index.php">Buka aplikasi</a> atau lanjutkan mengisi formulir ini.</span>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="alert alert-err">
          <?= ikon('star', 16) ?>
          <span><b>Periksa kembali isian Anda:</b><br><?php foreach ($err as $m): ?>&bull; <?= e($m) ?><br><?php endforeach; ?></span>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-head">
          <div>
            <h2>Formulir Pendaftaran</h2>
            <p>Isi data sekolah/madrasah dan akun admin yang akan mengelola supervisi.
              Pendaftaran <b>disetujui otomatis</b> (tanpa menunggu) dan akun langsung bisa dipakai
              dalam <b>mode terbatas</b> selama <b><?= (int) aktivasi_hari_uji() ?> hari</b> sejak
              mendaftar, sampai kode aktivasi dimasukkan.</p>
          </div>
          <span class="chip">Disetujui otomatis</span>
        </div>

        <div class="grid g3 mb16" style="gap:12px">
          <div class="langkah"><i>1</i><div><b>Isi formulir</b><span>Data sekolah/madrasah + akun admin</span></div></div>
          <div class="langkah"><i>2</i><div><b>Disetujui otomatis</b><span>Langsung tercatat &amp; bisa masuk (mode terbatas)</span></div></div>
          <div class="langkah"><i>3</i><div><b>Aktivasi untuk semua fitur</b><span>Masukkan kode aktivasi dari Admin</span></div></div>
        </div>

        <div class="alert alert-warn" style="margin-bottom:16px">
          <?= ikon('star', 16) ?>
          <span><b>Pendaftaran Gratis.</b> Setelah melakukan pendaftaran silahkan masuk menggunakan akun
            yang telah anda daftarkan untuk melihat secara langsung berbagai fitur yang tersedia.</span>
        </div>

        <form method="post" class="grid" style="gap:18px" autocomplete="off">
          <?= csrf_field() ?>

          <div>
            <div class="section-title mb8"><?= ikon('school', 14) ?> Data Sekolah/Madrasah</div>
            <div class="grid g2">
              <div style="grid-column:1/-1">
                <label class="f" for="jenis_sekolah">Jenis Sekolah/Madrasah *</label>
                <select id="jenis_sekolah" name="jenis_sekolah">
                  <option value="umum" <?= $form['jenis_sekolah'] !== 'madrasah' ? 'selected' : '' ?>>Sekolah Umum (SD/SMP/SMA/SMK)</option>
                  <option value="madrasah" <?= $form['jenis_sekolah'] === 'madrasah' ? 'selected' : '' ?>>Madrasah (MI/MTs/MA/MAK)</option>
                </select>
                <div class="hint">Kolom <b>NSM</b> hanya untuk madrasah; pada pilihan <b>Sekolah Umum</b> kolom NSM tidak ditampilkan.</div>
              </div>
              <div style="grid-column:1/-1">
                <label class="f" for="nama">Nama Sekolah/Madrasah *</label>
                <input type="text" id="nama" name="nama" value="<?= e($form['nama']) ?>" required placeholder="mis. MA. Sirojuth Tholibiin Mootilango">
              </div>
              <div>
                <label class="f" for="npsn">NPSN</label>
                <input type="text" id="npsn" name="npsn" value="<?= e($form['npsn']) ?>" placeholder="8 digit">
              </div>
              <?php // NSM hanya dimiliki MADRASAH — medan ini disembunyikan saat jenisnya Sekolah Umum
                    // (permintaan pemilik). Atribut `hidden` sudah dicetak server saat halaman dirender
                    // ulang dengan jenis umum, jadi tetap benar walau JavaScript gagal dimuat. ?>
              <div id="blokNsm"<?= $form['jenis_sekolah'] === 'madrasah' ? '' : ' hidden' ?>>
                <label class="f" for="nsm">NSM <span class="xs mut">(madrasah)</span></label>
                <input type="text" id="nsm" name="nsm" value="<?= e($form['nsm']) ?>" placeholder="12 digit">
              </div>
              <div style="grid-column:1/-1">
                <label class="f" for="alamat">Alamat (Jalan, Dusun)</label>
                <input type="text" id="alamat" name="alamat" value="<?= e($form['alamat']) ?>" placeholder="Jl. ... , Dusun ...">
              </div>
              <div>
                <label class="f" for="desa">Desa/Kelurahan</label>
                <input type="text" id="desa" name="desa" value="<?= e($form['desa']) ?>">
              </div>
              <div>
                <label class="f" for="kecamatan">Kecamatan</label>
                <input type="text" id="kecamatan" name="kecamatan" value="<?= e($form['kecamatan']) ?>">
              </div>
              <div>
                <label class="f" for="kabupaten">Kabupaten</label>
                <input type="text" id="kabupaten" name="kabupaten" value="<?= e($form['kabupaten']) ?>">
              </div>
              <div>
                <label class="f" for="provinsi">Provinsi</label>
                <input type="text" id="provinsi" name="provinsi" value="<?= e($form['provinsi']) ?>">
              </div>
              <div>
                <label class="f" for="telepon">Telepon</label>
                <input type="text" id="telepon" name="telepon" value="<?= e($form['telepon']) ?>" placeholder="08xxxxxxxxxx">
              </div>
              <div>
                <label class="f" for="email">Email</label>
                <input type="text" id="email" name="email" value="<?= e($form['email']) ?>" placeholder="nama@email.com">
              </div>
              <div>
                <label class="f" for="website">Situs/Web</label>
                <input type="text" id="website" name="website" value="<?= e($form['website']) ?>" placeholder="www.contoh.sch.id">
              </div>
              <div>
                <label class="f" for="tahun_ajaran">Tahun Ajaran</label>
                <input type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e($form['tahun_ajaran']) ?>" placeholder="2026/2027">
              </div>
              <div>
                <label class="f" for="semester">Semester</label>
                <select id="semester" name="semester">
                  <option value="Ganjil" <?= $form['semester'] === 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                  <option value="Genap" <?= $form['semester'] === 'Genap' ? 'selected' : '' ?>>Genap</option>
                </select>
              </div>
              <div>
                <label class="f" for="kepala_sekolah">Nama Kepala Sekolah/Madrasah / Supervisor</label>
                <input type="text" id="kepala_sekolah" name="kepala_sekolah" value="<?= e($form['kepala_sekolah']) ?>" placeholder="nama &amp; gelar">
              </div>
              <div>
                <label class="f" for="nip_kepala_sekolah">NIP</label>
                <input type="text" id="nip_kepala_sekolah" name="nip_kepala_sekolah" value="<?= e($form['nip_kepala_sekolah']) ?>">
              </div>
              <?php // PENGAWAS BINA (permintaan pemilik) — diletakkan DI BAWAH nama kepala sekolah.
                    // Nama yang diisi otomatis masuk ke daftar Akun Pengawas Super Admin dengan
                    // status NONAKTIF untuk ditinjau. ?>
              <div>
                <label class="f" for="pengawas_nama">Nama Pengawas Bina</label>
                <input type="text" id="pengawas_nama" name="pengawas_nama" value="<?= e($form['pengawas_nama']) ?>" placeholder="nama &amp; gelar pengawas">
              </div>
              <div>
                <label class="f" for="pengawas_nip">NIP Pengawas Bina</label>
                <input type="text" id="pengawas_nip" name="pengawas_nip" value="<?= e($form['pengawas_nip']) ?>">
              </div>
              <div style="grid-column:1/-1">
                <div class="hint">Isian pengawas bina boleh dikosongkan. Bila diisi, nama pengawas otomatis
                  tercatat pada daftar <b>Akun Pengawas</b> milik Admin dengan <b>status nonaktif</b> untuk
                  ditinjau lebih dahulu — sekolah/madrasah ini sekaligus ditautkan sebagai binaannya.</div>
              </div>
            </div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div>
            <div class="section-title mb8"><?= ikon('user', 14) ?> Penanggung Jawab / Kontak</div>
            <div class="grid g3">
              <div>
                <label class="f" for="kontak_nama">Nama Penanggung Jawab *</label>
                <input type="text" id="kontak_nama" name="kontak_nama" value="<?= e($form['kontak_nama']) ?>" required placeholder="mis. kepala sekolah/madrasah / operator">
              </div>
              <div>
                <label class="f" for="kontak_telepon">No. HP / WhatsApp</label>
                <input type="text" id="kontak_telepon" name="kontak_telepon" value="<?= e($form['kontak_telepon']) ?>" placeholder="08xxxxxxxxxx">
              </div>
              <div>
                <label class="f" for="kontak_email">Email</label>
                <input type="text" id="kontak_email" name="kontak_email" value="<?= e($form['kontak_email']) ?>" placeholder="nama@email.com">
              </div>
            </div>
            <div class="hint">Isi minimal salah satu (HP/WhatsApp atau email) agar Admin dapat menghubungi Anda.</div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div>
            <div class="section-title mb8"><?= ikon('key', 14) ?> Akun Admin Sekolah/Madrasah</div>
            <div class="grid g2">
              <div>
                <label class="f" for="admin_nama">Nama Admin *</label>
                <input type="text" id="admin_nama" name="admin_nama" value="<?= e($form['admin_nama']) ?>" required placeholder="nama kepala sekolah/madrasah / pengawas / operator">
              </div>
              <div>
                <label class="f" for="admin_username">Nama Pengguna *</label>
                <input type="text" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" required placeholder="huruf kecil/angka, tanpa spasi">
                <div class="hint">Dipakai untuk masuk. Harus belum dipakai akun lain.</div>
              </div>
              <div>
                <label class="f" for="password">Kata Sandi *</label>
                <input type="password" id="password" name="password" required placeholder="minimal 8 karakter">
              </div>
              <div>
                <label class="f" for="password2">Ulangi Kata Sandi *</label>
                <input type="password" id="password2" name="password2" required>
              </div>
            </div>
          </div>

          <div class="divider" style="margin:0"></div>

          <div class="grid g2" style="gap:14px;align-items:end">
            <div>
              <label class="f" for="captcha">Pertanyaan Pengaman *</label>
              <div class="flex" style="gap:10px;align-items:center">
                <span class="chip" id="soalCaptcha" style="font-weight:800"><?= (int) $ca ?> + <?= (int) $cb ?> = ?</span>
                <input type="text" id="captcha" name="captcha" required inputmode="numeric" placeholder="jawaban" style="max-width:150px">
              </div>
            </div>
            <div>
              <label class="f" style="display:flex;gap:9px;align-items:flex-start;font-weight:600">
                <input type="checkbox" name="setuju" value="1" style="width:16px;height:16px;margin-top:2px;accent-color:var(--accent)" required>
                <span>Saya menyatakan data yang diisi benar dan menyetujui aplikasi ini digunakan untuk supervisi sekolah/madrasah kami.</span>
              </label>
            </div>
          </div>

          <div class="flex">
            <button class="btn btn-primary" type="submit"><?= ikon('check', 16) ?> Kirim Pendaftaran</button>
            <a class="btn btn-ghost" href="login.php">Saya sudah punya akun</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="daftar-foot">
      <div><?= e(APP_NAME) ?> Versi <?= e(APP_VERSION) ?></div>
      <div class="mt8" style="font-weight:600;color:var(--ink)">Dikembangkan oleh <?= e(APP_AUTHOR) ?> (<?= e(APP_AUTHOR_JABATAN) ?>)</div>
    </div>
  </div>
</div>
<?php // app.js dipakai untuk menyembunyikan medan NSM saat jenis "Sekolah Umum" (initJenisNsm()). ?>
<script src="assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
