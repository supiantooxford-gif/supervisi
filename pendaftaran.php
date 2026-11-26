<?php
/**
 * Pendaftaran Sekolah — halaman Super Admin untuk meninjau sekolah pendaftar.
 *
 * Permintaan pemilik (pembaruan): pendaftaran lewat halaman publik (daftar.php) kini
 * **DISETUJUI OTOMATIS dengan mode terbatas** (`verifikasi = 'disetujui'`, `aktif = 1`,
 * `aktivasi = 'dibatasi'`), jadi sekolah itu sudah bisa dipakai (terbatas) tanpa menunggu.
 * Halaman ini mempertahankan:
 *   1. daftar **Pendaftar Mandiri Terbaru** — jejak siapa yang mendaftar + status aktivasi,
 *      dengan tombol **Tolak** (alasan) & **Tinjau ulang** supaya Super Admin tetap punya kendali;
 *   2. daftar **Menunggu Persetujuan** — untuk data LAMA yang masih berstatus menunggu;
 *   3. daftar **Pernah Ditolak**.
 * Tombol "Setujui" di daftar menunggu mengembalikan sekolah lama itu ke keadaan disetujui.
 * Menu & butir penilaian bersifat GLOBAL (tidak pernah digandakan per sekolah).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

// Pendaftaran Sekolah (calon sekolah): memakai istilah generik "Sekolah" (bukan Madrasah)
// karena mengelola sekolah umum dan madrasah sekaligus.
$GLOBALS['__vs_istilah_nonaktif'] = true;

$u = require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    // BUKA/TUTUP PENDAFTARAN (v1.34.0, permintaan pemilik) — TINGKAT APLIKASI, jadi tidak
    // memerlukan `id` sekolah dan diperiksa SEBELUM pencarian data pendaftaran di bawah.
    if ($aksi === 'daftar_simpan') {
        $tutup = (string) ($_POST['daftar_status'] ?? 'buka') === 'tutup';
        $batas = max(0, (int) preg_replace('/\D/', '', (string) ($_POST['daftar_batas'] ?? '0')));
        $waRaw = trim((string) ($_POST['daftar_wa'] ?? ''));
        if ($waRaw === '' || !aktivasi_wa_sah($waRaw)) {
            flash('Nomor WA admin belum benar — tulis angka saja (boleh diawali 0 atau 62), '
                . '9 sampai 15 digit, mis. ' . DAFTAR_WA_BAWAAN . '.', 'err');
            redirect('pendaftaran.php#kartuPendaftaran');
        }
        daftar_status_simpan($tutup, $batas, $waRaw);
        $infoBaru = daftar_status_info();
        flash($infoBaru['tutup']
            ? 'Pendaftaran sekolah/madrasah DITUTUP. Pengunjung halaman pendaftaran akan melihat '
              . 'keterangan: "' . $infoBaru['pesan'] . '"'
            : 'Pendaftaran sekolah/madrasah DIBUKA kembali'
              . ($infoBaru['batas'] > 0
                  ? ' dengan batas ' . $infoBaru['batas'] . ' pendaftar (terdaftar sekarang '
                    . $infoBaru['terdaftar'] . ').'
                  : ' tanpa batas jumlah pendaftar.'),
            $infoBaru['tutup'] ? 'warn' : 'ok');
        redirect('pendaftaran.php#kartuPendaftaran');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $s = $id ? one('SELECT * FROM sekolah WHERE id = ?', [$id]) : null;

    if (!$s) {
        flash('Data pendaftaran tidak ditemukan.', 'err');
        redirect('pendaftaran.php');
    }

    if ($aksi === 'setujui') {
        db()->exec('BEGIN IMMEDIATE');
        try {
            q('UPDATE sekolah SET verifikasi = "disetujui", aktif = 1, alasan_tolak = NULL WHERE id = ?', [$id]);
            q('UPDATE users SET aktif = 1 WHERE sekolah_id = ? AND role = "admin"', [$id]);
            // Menu & butir penilaian GLOBAL: tidak ada yang perlu disalin ke sekolah baru.
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }

        $admin = one('SELECT username, nama FROM users WHERE sekolah_id = ? AND role = "admin" ORDER BY id LIMIT 1', [$id]);
        flash('Sekolah "' . $s['nama'] . '" DISETUJUI dan siap dipakai. Admin sekolah dapat masuk dengan nama pengguna "'
            . ($admin ? (string) $admin['username'] : '-') . '". Butir penilaian memakai daftar global yang sama. '
            . 'Catatan: sekolah ini masih berjalan MODE TERBATAS (maksimal ' . (int) AKTIVASI_MAKS_GURU . ' data guru, '
            . (int) AKTIVASI_MAKS_SUPERVISI_MENU . ' supervisi tiap menu, ' . (int) AKTIVASI_MAKS_JADWAL
            . ' baris jadwal) sampai kode aktivasi lembaganya dimasukkan lewat Kelola Sekolah.');
        redirect('pendaftaran.php');
    }

    if ($aksi === 'tolak') {
        $alasan = trim((string) ($_POST['alasan'] ?? ''));
        q('UPDATE sekolah SET verifikasi = "ditolak", aktif = 0, alasan_tolak = ? WHERE id = ?',
            [$alasan !== '' ? $alasan : 'Tidak memenuhi syarat penggunaan aplikasi.', $id]);
        // Akun admin sengaja TIDAK dinonaktifkan: penahanan login dilakukan oleh status sekolah,
        // supaya pendaftar melihat alasan penolakan saat mencoba masuk.
        flash('Pendaftaran sekolah "' . $s['nama'] . '" ditolak. Alasan penolakan akan tampil saat pendaftar mencoba masuk.', 'warn');
        redirect('pendaftaran.php');
    }

    if ($aksi === 'batalkan_tolak') {
        // Sejak pendaftaran disetujui OTOMATIS, "tinjau ulang" mengembalikan sekolah ke keadaan
        // disetujui (bisa dipakai lagi dalam mode terbatas) — bukan ke 'menunggu', karena tidak ada
        // lagi tahap menunggu persetujuan pada alur baru.
        q('UPDATE sekolah SET verifikasi = "disetujui", aktif = 1, alasan_tolak = NULL WHERE id = ?', [$id]);
        q('UPDATE users SET aktif = 1 WHERE sekolah_id = ? AND role = "admin"', [$id]);
        flash('Penolakan dibatalkan. Sekolah "' . $s['nama'] . '" kembali DISETUJUI dan dapat dipakai lagi'
            . (aktivasi_terbatas($s) ? ' (masih mode terbatas sampai kode aktivasi dimasukkan).' : '.'), 'warn');
        redirect('pendaftaran.php');
    }

    if ($aksi === 'hapus') {
        $konfirmasi = strtoupper(trim((string) ($_POST['konfirmasi'] ?? '')));
        if ($konfirmasi !== 'HAPUS') {
            flash('Ketik HAPUS untuk menghapus data pendaftaran "' . $s['nama'] . '".', 'err');
            redirect('pendaftaran.php');
        }
        db()->exec('BEGIN IMMEDIATE');
        try {
            q('DELETE FROM users WHERE sekolah_id = ? AND role <> "super"', [$id]);
            q('DELETE FROM pengawas_sekolah WHERE sekolah_id = ?', [$id]);
            q('DELETE FROM supervisi WHERE sekolah_id = ?', [$id]);
            q('DELETE FROM guru WHERE sekolah_id = ?', [$id]);
            // Menu & butir penilaian GLOBAL — tidak ikut terhapus.
            q('DELETE FROM sekolah WHERE id = ?', [$id]);
            db()->exec('COMMIT');
        } catch (Throwable $ex) {
            db()->exec('ROLLBACK');
            throw $ex;
        }
        flash('Data pendaftaran sekolah "' . $s['nama'] . '" dihapus.', 'warn');
        redirect('pendaftaran.php');
    }

    flash('Aksi tidak dikenali.', 'err');
    redirect('pendaftaran.php');
}

/** Ambil daftar sekolah dengan status verifikasi tertentu + ringkasan kontennya. */
function pendaftaran_list(string $status): array
{
    return all('SELECT s.*,
        (SELECT u.username FROM users u WHERE u.sekolah_id = s.id AND u.role = "admin" ORDER BY u.id LIMIT 1) AS admin_username,
        (SELECT u.nama FROM users u WHERE u.sekolah_id = s.id AND u.role = "admin" ORDER BY u.id LIMIT 1) AS admin_nama,
        (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = s.id) AS jml_guru,
        (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id) AS jml_supervisi
        FROM sekolah s WHERE s.verifikasi = ? ORDER BY s.tanggal_daftar DESC, s.id DESC', [$status]);
}

$menunggu = pendaftaran_list('menunggu');
$ditolak = pendaftaran_list('ditolak');
$disetujui = (int) val('SELECT COUNT(*) FROM sekolah WHERE verifikasi = "disetujui"', [], 0);

/**
 * Pendaftar MANDIRI terbaru. Sejak permintaan pemilik ("buat sistem yang menyetujui otomatis tapi
 * disetujui dengan mode terbatas"), sekolah yang mendaftar lewat daftar.php langsung berstatus
 * `disetujui` sehingga TIDAK lagi muncul pada daftar "Menunggu Persetujuan". Daftar ini
 * mempertahankan jejaknya di halaman Pendaftaran supaya Admin tahu siapa yang baru mendaftar dan
 * masih menunggu AKTIVASI (kodenya ada di Kelola Sekolah).
 */
$otomatis = all('SELECT s.*,
    (SELECT u.username FROM users u WHERE u.sekolah_id = s.id AND u.role = "admin" ORDER BY u.id LIMIT 1) AS admin_username,
    (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = s.id) AS jml_guru,
    (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id) AS jml_supervisi
    FROM sekolah s
    WHERE s.tanggal_daftar IS NOT NULL AND TRIM(s.tanggal_daftar) <> ""
    ORDER BY s.tanggal_daftar DESC, s.id DESC LIMIT 20');
$otomatisTerbatas = 0;
foreach ($otomatis as $o) {
    if (aktivasi_terbatas($o)) {
        $otomatisTerbatas++;
    }
}

$daftarInfo = daftar_status_info();   // keadaan buka/tutup pendaftaran (satu sumber aturan)
page_top('Pendaftaran Sekolah', 'pendaftaran');
?>
<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic o"><?= ikon('star', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($menunggu) ?></div><div class="stat-lbl">Menunggu persetujuan</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('check', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $disetujui ?></div><div class="stat-lbl">Sekolah disetujui</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic p"><?= ikon('building', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= count($ditolak) ?></div><div class="stat-lbl">Pendaftaran ditolak</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('school', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= jumlah_sekolah() ?></div><div class="stat-lbl">Sekolah aktif di aplikasi</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic o"><?= ikon('lock', 20) ?></div>
    <div><div class="stat-num" style="font-size:1.35rem"><?= $otomatisTerbatas ?></div>
      <div class="stat-lbl">Menunggu aktivasi</div></div>
  </div>
</div>

<div class="card" id="kartuPendaftaran">
  <div class="card-head">
    <div>
      <h2><?= ikon('switch', 16) ?> Buka/Tutup Pendaftaran Sekolah/Madrasah</h2>
      <p>Selama pendaftaran <b>ditutup</b>, halaman <a href="daftar.php" target="_blank" rel="noopener">pendaftaran</a>
        tidak menerima pendaftar baru dan formulirnya diganti keterangan kepada Admin.</p>
    </div>
    <div class="flex">
      <span class="pill <?= $daftarInfo['tutup'] ? 'pill-warn' : 'pill-ok' ?>" id="pilPendaftaranDibuka">
        <?= $daftarInfo['tutup'] ? 'Ditutup' : 'Dibuka' ?></span>
    </div>
  </div>

  <div class="alert <?= $daftarInfo['tutup'] ? 'alert-warn' : 'alert-ok' ?>" id="pratinjauPendaftaranTutup">
    <?= ikon($daftarInfo['tutup'] ? 'alert' : 'info', 16) ?>
    <span>
      <?php if ($daftarInfo['tutup']): ?>
        Pendaftaran sedang <b>ditutup</b> (<?= e($daftarInfo['keterangan']) ?>). Pengunjung melihat:
        <b>&ldquo;<?= e($daftarInfo['pesan']) ?>&rdquo;</b>
        beserta tombol WhatsApp ke nomor itu.
      <?php else: ?>
        Pendaftaran sedang <b>dibuka</b> (<?= e($daftarInfo['keterangan']) ?>). Bila ditutup,
        pengunjung akan melihat keterangan
        <b>&ldquo;<?= e($daftarInfo['pesan']) ?>&rdquo;</b> beserta tombol WhatsApp ke nomor itu.
      <?php endif; ?>
    </span>
  </div>

  <div class="grid g4" style="gap:14px;margin-bottom:16px">
    <div class="nilai-chip"><b><?= (int) $daftarInfo['terdaftar'] ?></b><span>Sekolah/madrasah terdaftar</span></div>
    <div class="nilai-chip"><b><?= $daftarInfo['batas'] > 0 ? (int) $daftarInfo['batas'] : '&infin;' ?></b><span>Batas pendaftar</span></div>
    <div class="nilai-chip"><b><?= $daftarInfo['batas'] > 0 ? (int) $daftarInfo['sisa'] : '&infin;' ?></b><span>Sisa kuota pendaftar</span></div>
    <div class="nilai-chip"><b style="font-size:1.1rem;padding-top:4px"><?= e($daftarInfo['wa']) ?></b><span>WA admin saat ditutup</span></div>
  </div>

  <form method="post" class="form-grid" id="formPendaftaran">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="daftar_simpan">
    <div class="grid g3" style="gap:16px">
      <div>
        <label class="f" for="daftarStatus">Keadaan pendaftaran</label>
        <select id="daftarStatus" name="daftar_status">
          <?php // Nilai yang ditampilkan = pilihan TERSIMPAN (tutup_manual), bukan keadaan efektif:
                // bila pendaftaran tertutup karena BATAS tercapai, tombolnya tetap "Dibuka" dan
                // keterangannya di bawah menjelaskan sebabnya. ?>
          <option value="buka" <?= $daftarInfo['tutup_manual'] ? '' : 'selected' ?>>Dibuka — menerima pendaftar baru</option>
          <option value="tutup" <?= $daftarInfo['tutup_manual'] ? 'selected' : '' ?>>Ditutup — tidak menerima pendaftar baru</option>
        </select>
        <?php if ($daftarInfo['sebab'] === 'batas'): ?>
          <div class="hint"><b>Batas tercapai</b> — pendaftaran tertutup otomatis sampai batasnya
            dinaikkan atau diisi 0.</div>
        <?php endif; ?>
      </div>
      <div>
        <label class="f" for="daftarBatas">Batas jumlah pendaftar</label>
        <input type="number" id="daftarBatas" name="daftar_batas" min="0" step="1"
               value="<?= (int) $daftarInfo['batas'] ?>" inputmode="numeric">
        <div class="hint">Isi <b>0</b> bila tidak dibatasi. Bila batas tercapai, pendaftaran
          otomatis ditutup dengan keterangan yang sama.</div>
      </div>
      <div>
        <label class="f" for="daftarWa">Nomor WA admin</label>
        <input type="text" id="daftarWa" name="daftar_wa" value="<?= e($daftarInfo['wa']) ?>"
               inputmode="numeric" autocomplete="off">
        <div class="hint">Ditampilkan pada keterangan penutupan, mis. <?= e(DAFTAR_WA_BAWAAN) ?>.</div>
      </div>
    </div>
    <div class="flex">
      <button class="btn btn-primary" type="submit"><?= ikon('check', 15) ?> Simpan Pengaturan Pendaftaran</button>
      <?php if ($daftarInfo['tutup']): ?>
        <span class="hint" style="margin:0">Pendaftaran ditutup sejak
          <?= $daftarInfo['sebab'] === 'batas' ? 'batas pendaftar tercapai' : 'diubah Admin' ?><?= daftar_status_diubah() !== '' ? ' (' . e(substr(daftar_status_diubah(), 0, 10)) . ')' : '' ?>.</span>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="alert alert-ok">
  <?= ikon('check', 16) ?>
  <span><b>Pendaftaran kini DISETUJUI OTOMATIS dengan mode terbatas.</b> Sekolah/madrasah yang mendaftar
    lewat halaman pendaftaran langsung tercatat &amp; bisa masuk (maksimal <?= (int) AKTIVASI_MAKS_GURU ?>
    data guru, <?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi tiap menu, <?= (int) AKTIVASI_MAKS_JADWAL ?>
    baris jadwal, <?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas) sampai <b>kode aktivasi</b> lembaganya dimasukkan. Kirimkan kode dari
    <a href="sekolah.php">Kelola Sekolah</a> kepada pengelolanya — atau aktifkan langsung dari kartu sekolah.</span>
</div>

<?php if ($menunggu): ?>
<div class="alert alert-warn">
  <?= ikon('star', 16) ?>
  <span>Ada <b><?= count($menunggu) ?></b> pendaftaran <b>lama</b> yang masih menunggu (data sebelum
    sistem persetujuan otomatis). Sekolah itu baru bisa dipakai setelah Anda menekan <b>Setujui</b>.</span>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h2>Menunggu Persetujuan</h2><p>Periksa data pendaftar, lalu setujui atau tolak. Menu &amp; butir penilaian bawaan dibuat otomatis saat disetujui.</p></div>
    <div class="flex">
      <span class="chip"><?= count($menunggu) ?> pendaftar</span>
      <a class="btn btn-ghost btn-sm" href="sekolah.php"><?= ikon('building', 15) ?> Kelola Sekolah</a>
    </div>
  </div>

  <?php if (!$menunggu): ?>
    <div class="empty">
      <?= ikon('check', 34) ?>
      <h3>Tidak ada pendaftaran yang menunggu</h3>
      <p>Pendaftaran baru kini <b>disetujui otomatis</b> (mode terbatas), jadi tidak lagi menunggu di sini —
        daftar pendaftarnya ada pada kartu <b>Pendaftar Mandiri Terbaru</b> di bawah. Halaman pendaftaran
        publik ada di <b>daftar.php</b> dan tautannya tersedia di halaman masuk.</p>
    </div>
  <?php else: ?>
    <div class="grid" style="gap:16px">
      <?php foreach ($menunggu as $s): ?>
        <div class="butir" style="margin:0;border-color:rgba(217,119,6,.4);background:linear-gradient(135deg,rgba(245,158,11,.07),rgba(255,255,255,.5))">
          <div class="butir-head" style="align-items:flex-start">
            <div class="stat-ic o" style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#ea580c)"><?= ikon('star', 19) ?></div>
            <div style="flex:1;min-width:0">
              <div class="butir-komponen">
                <?= e((string) $s['nama']) ?>
                <span class="pill pill-warn" style="margin-left:6px;vertical-align:middle">menunggu persetujuan</span>
              </div>
              <div class="butir-indikator">
                NPSN <?= e((string) ($s['npsn'] ?: '-')) ?> &middot; <?= e((string) ($s['alamat'] ?: '-')) ?><br>
                Kepala sekolah: <?= e((string) ($s['kepala_sekolah'] ?: '-')) ?> &middot;
                T.A. <?= e((string) ($s['tahun_ajaran'] ?: '-')) ?> <?= e((string) ($s['semester'] ?: '')) ?><br>
                Penanggung jawab: <b><?= e((string) ($s['kontak_nama'] ?: '-')) ?></b>
                <?php if ($s['kontak_telepon']): ?>&middot; HP/WA <?= e((string) $s['kontak_telepon']) ?><?php endif; ?>
                <?php if ($s['kontak_email']): ?>&middot; <?= e((string) $s['kontak_email']) ?><?php endif; ?><br>
                Akun admin: <b><?= e((string) ($s['admin_username'] ?: '-')) ?></b>
                <?= $s['admin_nama'] ? ' (' . e((string) $s['admin_nama']) . ')' : '' ?>
                &middot; mendaftar: <?= e($s['tanggal_daftar'] ? tanggal_id(substr((string) $s['tanggal_daftar'], 0, 10)) : '-') ?>
                <?= $s['dibuat'] ? ' pukul ' . e(substr((string) $s['tanggal_daftar'], 11, 5)) : '' ?>
              </div>
              <div class="alert alert-warn mt16" style="margin-bottom:0">
                <?= ikon('lock', 15) ?>
                <span>Setelah disetujui, sekolah ini berjalan <b>mode terbatas</b> (maksimal
                  <?= (int) AKTIVASI_MAKS_GURU ?> data guru, <?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi
                  pada setiap menu, <?= (int) AKTIVASI_MAKS_JADWAL ?> baris jadwal,
                  <?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas) sampai
                  <b>kode aktivasi</b> lembaganya dimasukkan. Kode &amp; tombol aktivasi ada di
                  <a href="sekolah.php">Kelola Sekolah</a> pada kartu sekolah yang bersangkutan.</span>
              </div>
            </div>
          </div>

          <div class="grid g3 mt16" style="gap:12px">
            <div class="nilai-chip" style="min-width:0"><b><?= (int) $s['jml_guru'] ?></b><span>Guru terdaftar</span></div>
            <div class="nilai-chip" style="min-width:0"><b><?= (int) $s['jml_supervisi'] ?></b><span>Supervisi</span></div>
            <div class="nilai-chip" style="min-width:0"><b style="font-size:1rem;padding-top:5px"><?= e((string) ($s['npsn'] ?: '-')) ?></b><span>NPSN</span></div>
          </div>

          <div class="grid g2 mt16" style="gap:14px;align-items:start">
            <form method="post" data-confirm="Setujui pendaftaran <?= e((string) $s['nama']) ?>? Sekolah langsung bisa masuk, tetapi masih MODE TERBATAS sampai kode aktivasi lembaganya dimasukkan.">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="setujui"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-primary full" type="submit"><?= ikon('check', 16) ?> Setujui &amp; Aktifkan Sekolah</button>
            </form>

            <form method="post" class="grid" style="gap:8px">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <div><label class="f">Alasan penolakan (dikirim ke pendaftar)</label>
                <input type="text" name="alasan" placeholder="mis. data sekolah belum lengkap"></div>
              <button class="btn btn-danger" type="submit"><?= ikon('trash', 15) ?> Tolak Pendaftaran</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h2>Pendaftar Mandiri Terbaru</h2>
      <p>Sekolah/madrasah yang mendaftar lewat halaman pendaftaran. Statusnya <b>disetujui otomatis</b>;
        yang berjalan <b>mode terbatas</b> perlu diberi <b>kode aktivasi</b> (ada di Kelola Sekolah).</p>
    </div>
    <div class="flex">
      <span class="chip"><?= count($otomatis) ?> pendaftar</span>
      <?php if ($otomatisTerbatas > 0): ?>
        <span class="pill pill-warn"><?= $otomatisTerbatas ?> menunggu aktivasi</span>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="sekolah.php"><?= ikon('building', 15) ?> Kelola Sekolah</a>
    </div>
  </div>

  <?php if (!$otomatis): ?>
    <div class="empty"><p class="sm mut">Belum ada sekolah yang mendaftar sendiri.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Nama</th><th>Penanggung Jawab</th><th>Akun Admin</th><th class="ctr">Guru</th>
            <th class="ctr">Supervisi</th><th class="ctr">Status</th><th>Mendaftar</th><th class="ctr">Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($otomatis as $o):
            $terbatas = aktivasi_terbatas($o);
            $ditolakIni = (string) $o['verifikasi'] === 'ditolak';
            ?>
          <tr>
            <td><b><?= e((string) $o['nama']) ?></b>
              <span class="tbl-sub"><?= e((string) ($o['alamat'] ?: '-')) ?></span></td>
            <td><?= e((string) ($o['kontak_nama'] ?: ($o['kepala_sekolah'] ?: '-'))) ?>
              <?php if ($o['kontak_telepon']): ?><span class="tbl-sub"><?= e((string) $o['kontak_telepon']) ?></span><?php endif; ?></td>
            <td><?= e((string) ($o['admin_username'] ?: '-')) ?></td>
            <td class="ctr"><?= (int) $o['jml_guru'] ?></td>
            <td class="ctr"><?= (int) $o['jml_supervisi'] ?></td>
            <td class="ctr">
              <?php if ($ditolakIni): ?>
                <span class="pill pill-D">Ditolak</span>
              <?php else: ?>
                <span class="pill <?= $terbatas ? 'pill-warn' : 'pill-ok' ?>"><?= $terbatas ? 'Mode terbatas' : 'Aktif' ?></span>
              <?php endif; ?>
            </td>
            <td class="xs nowrap"><?= e((string) ($o['tanggal_daftar'] ? tanggal_id(substr((string) $o['tanggal_daftar'], 0, 10)) : '-')) ?>
              <?php
              // MASA UJI COBA 30 HARI (permintaan pemilik): Super Admin perlu tahu sampai kapan
              // pendaftar yang belum diaktivasi masih bisa dipakai sebelum aplikasinya terkunci.
              $oUji = aktivasi_status($o);
              if ($oUji['uji'] && $oUji['uji_sampai_ts'] > 0):
              ?>
                <span class="tbl-sub">Uji coba <?= $oUji['uji_habis']
                    ? 'habis ' . e(waktu_id_polos($oUji['uji_sampai']))
                    : 'sampai ' . e(waktu_id_polos($oUji['uji_sampai'])) . ' (&plusmn;' . (int) $oUji['uji_sisa_hari'] . ' hari)' ?></span>
              <?php endif; ?></td>
            <td>
              <div class="row-actions">
                <?php // Kirim WA pengingat aktivasi ke pendaftar (nomor HP/telepon lembaga dari pendaftaran). ?>
                <?= wa_lembaga_tombol($o, 'btn btn-ok btn-sm', 'WA', '<span class="mut xs">&mdash;</span>') ?>
                <a class="btn btn-ghost btn-sm" href="sekolah.php" title="Buka Kelola Sekolah untuk kode aktivasi"><?= ikon('switch', 13) ?> Kelola</a>
                <?php if ($ditolakIni): ?>
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="batalkan_tolak"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Tinjau ulang</button>
                  </form>
                <?php else: ?>
                  <?php // Super Admin tetap boleh MENOLAK sekolah yang sudah otomatis disetujui. ?>
                  <form method="post" data-confirm="Tolak pendaftaran <?= e((string) $o['nama']) ?>? Sekolah tidak bisa dipakai sampai ditinjau ulang.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <div class="flex" style="gap:5px">
                      <input type="text" name="alasan" placeholder="alasan penolakan" style="width:150px;padding:6px 8px;font-size:.75rem">
                      <button class="btn btn-danger btn-sm" type="submit">Tolak</button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Kode aktivasi tiap sekolah/madrasah ada pada kartu sekolahnya di
      <a href="sekolah.php">Kelola Sekolah</a> (tombol <b>Salin</b>), atau aktifkan langsung dari sana.</p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Pernah Ditolak</h2><p>Pendaftaran yang ditolak tidak dapat dipakai masuk. Penolakan dapat dibatalkan bila pendaftar memperbaiki datanya.</p></div>
    <span class="chip"><?= count($ditolak) ?> data</span>
  </div>
  <?php if (!$ditolak): ?>
    <div class="empty"><p class="sm mut">Belum ada pendaftaran yang ditolak.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Sekolah</th><th>Penanggung Jawab</th><th>Akun Admin</th><th>Alasan</th><th class="ctr">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($ditolak as $s): ?>
          <tr>
            <td><b><?= e((string) $s['nama']) ?></b><span class="tbl-sub"><?= e((string) ($s['alamat'] ?: '-')) ?></span></td>
            <td><?= e((string) ($s['kontak_nama'] ?: '-')) ?><span class="tbl-sub"><?= e((string) ($s['kontak_telepon'] ?: ($s['kontak_email'] ?: '-'))) ?></span></td>
            <td><?= e((string) ($s['admin_username'] ?: '-')) ?></td>
            <td class="sm"><?= e((string) ($s['alasan_tolak'] ?: '-')) ?></td>
            <td>
              <div class="row-actions">
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="batalkan_tolak"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Tinjau ulang</button>
                </form>
                <form method="post" data-confirm="Hapus data pendaftaran <?= e((string) $s['nama']) ?> beserta akunnya?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <div class="flex" style="gap:5px">
                    <input type="text" name="konfirmasi" placeholder="HAPUS" style="width:88px;padding:6px 8px;font-size:.75rem">
                    <button class="btn btn-danger btn-sm" type="submit"><?= ikon('trash', 13) ?></button>
                  </div>
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

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Bagikan tautan pendaftaran</h3>
      <p class="sm mut" style="margin:4px 0 0">Sampaikan tautan berikut kepada sekolah yang ingin memakai aplikasi ini:</p>
      <p class="sm" style="margin:8px 0 0">
        <code style="background:rgba(99,95,130,.12);padding:4px 8px;border-radius:8px"><?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'alamat-aplikasi') . rtrim((string) dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/daftar.php') ?></code>
      </p>
    </div>
    <span class="chip">Tautan publik</span>
  </div>
</div>
<?php
page_bottom();
