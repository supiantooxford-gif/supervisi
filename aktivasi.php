<?php
/**
 * STATUS & AKTIVASI SEKOLAH (permintaan pemilik).
 *
 * "Buatkan kode aktifasi untuk sekolah yang mendaftar. Bedanya jika belum diaktifasi tidak semua
 *  fitur bisa digunakan atau dibatasi. Pembatasan pada hanya bisa input 1 data guru dan hanya bisa
 *  melakukan satu kali supervisi pada setiap menu supervisi. Jika melebihi muncul pop up aplikasi
 *  belum diaktifasi, segera lakukan aktifasi agar semua fitur bisa digunakan."
 *
 * Dua keadaan saja (keputusan pemilik): `dibatasi` → `aktif`.
 *
 * Siapa boleh apa (pembaruan permintaan pemilik):
 *   - **Admin/kepala sekolah** (pengelola sekolah itu) dan **Super Admin** boleh MEMASUKKAN kode
 *     aktivasi yang dikirim Super Admin untuk mengaktifkan sekolahnya sendiri.
 *   - **Hanya Super Admin** yang boleh MELIHAT/MENYALIN kode lembaga dan mengembalikan sekolah ke
 *     mode terbatas.
 *   - Guru & pengawas hanya melihat status.
 *
 * Halaman ini juga menjadi tujuan pengalihan saat sebuah tindakan melewati batas
 * (lihat aktivasi_halang()): popup "Aplikasi Belum Diaktifasi" langsung ditampilkan.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$sekolah = current_sekolah();
if (!$sekolah) {
    // Super Admin lintas sekolah: pilih sekolah dulu (kelola) supaya status yang tampil benar.
    flash(is_super()
        ? 'Pilih sekolah yang ingin dilihat status aktivasi & kodenya lewat menu Kelola Sekolah.'
        : 'Sekolah Anda belum ditautkan ke akun ini.', 'warn');
    redirect(is_super() ? 'sekolah.php' : 'index.php');
}

$sid = (int) $sekolah['id'];
$boleh = boleh_aktivasi();                    // boleh MEMASUKKAN kode (admin sekolah / super dalam konteks)
$lihatKode = boleh_lihat_kode_aktivasi();     // boleh MELIHAT & menyalin kode (hanya Super Admin)
$kode = $lihatKode ? kode_aktivasi_sekolah($sekolah) : '';

// ---------------------------------------------------------------- keadaan aktivasi
// PENTING: seluruh keadaan di bawah dihitung SEBELUM blok aksi karena handler POST memakainya
// ($terkunci untuk pesan kunci). Kalau dihitung setelahnya, PHP memunculkan
// "Undefined variable" — keluarannya dikirim lebih dulu sehingga redirect gagal (respons 200).
$terbatas = aktivasi_terbatas($sekolah);
$status = aktivasi_status($sekolah);      // masa aktif 1 tahun + sisa waktunya (hitung mundur)
$kadaluarsa = $status['habis'];
// MASA UJI COBA 30 HARI (permintaan pemilik): sekolah yang BELUM diaktivasi hanya diberi waktu
// (kini 15 hari) dihitung sejak terdaftar. Berbeda dari `$kadaluarsa` yang berarti masa aktif 1 tahun
// sudah habis PADAHAL sekolah sudah pernah diaktivasi.
$ujiBerjalan = $status['keadaan'] === 'dibatasi';
$ujiHabis = $status['uji_habis'];
// TERKUNCI = masa aktif habis / masa uji coba habis → seluruh akun dikunci ke halaman ini sampai
// kode aktifasi dimasukkan (permintaan pemilik). Berbeda dari sekolah yang belum pernah diaktivasi
// dan masa uji cobanya MASIH berjalan (mode terbatas).
$terkunci = aktivasi_terkunci($sekolah);
$waTautan = aktivasi_tautan_permohonan($sekolah);
// NOMINAL BIAYA AKTIVASI (isi menu disamakan dengan aplikasi e-GURU): nominal yang berlaku bagi
// lembaga ini, kalimat permintaan pembayaran, dan tautan WhatsApp pengiriman BUKTI TRANSFER.
// Dihitung di blok keadaan (SEBELUM blok aksi) seperti variabel lain di atas.
$pernahAktif = aktivasi_pernah_aktif($status, $sekolah);
$biayaBerlaku = aktivasi_biaya_berlaku($status, $sekolah);
$waBukti = aktivasi_tautan_bukti($sekolah, $status);
// Satuan hitung mundur (dicetak PHP supaya tetap terbaca walau skrip gagal dimuat; JS memperbaruinya).
$hm = [
    'hari'  => intdiv($status['sisa_detik'], 86400),
    'jam'   => intdiv($status['sisa_detik'] % 86400, 3600),
    'menit' => intdiv($status['sisa_detik'] % 3600, 60),
    'detik' => $status['sisa_detik'] % 60,
];
$hmLabel = 'Sisa ' . $hm['hari'] . ' hari ' . str_pad((string) $hm['jam'], 2, '0', STR_PAD_LEFT)
    . ':' . str_pad((string) $hm['menit'], 2, '0', STR_PAD_LEFT)
    . ':' . str_pad((string) $hm['detik'], 2, '0', STR_PAD_LEFT);
// Satuan HITUNG MUNDUR MASA UJI COBA 30 HARI (sekolah yang belum diaktivasi).
$hmUji = [
    'hari'  => intdiv($status['uji_sisa_detik'], 86400),
    'jam'   => intdiv($status['uji_sisa_detik'] % 86400, 3600),
    'menit' => intdiv($status['uji_sisa_detik'] % 3600, 60),
    'detik' => $status['uji_sisa_detik'] % 60,
];
$hmUjiLabel = 'Sisa ' . $hmUji['hari'] . ' hari ' . str_pad((string) $hmUji['jam'], 2, '0', STR_PAD_LEFT)
    . ':' . str_pad((string) $hmUji['menit'], 2, '0', STR_PAD_LEFT)
    . ':' . str_pad((string) $hmUji['detik'], 2, '0', STR_PAD_LEFT);
$pakai = aktivasi_pemakaian();

// ---------------------------------------------------------------- aksi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pemeriksaan hak akses didahulukan (sebelum CSRF) supaya jawabannya jelas dan pasti 403:
    // pengguna yang tidak berhak memang tidak pernah melihat formulirnya.
    if (!$boleh) {
        http_response_code(403);
        exit('Hanya pengelola sekolah/madrasah (admin/kepala) dan Super Admin yang dapat melakukan aktivasi.');
    }
    csrf_guard();
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'aktifkan') {
        // Anti tebak: batasi jumlah percobaan kode salah per sesi (berlaku untuk aktivasi pertama
        // maupun perpanjangan).
        if (aktivasi_coba_diblokir()) {
            flash('Percobaan kode aktivasi sudah terlalu banyak (' . (int) AKTIVASI_MAKS_COBA . ' kali). '
                . 'Coba lagi dalam ' . (int) AKTIVASI_MENIT_COBA . ' menit, atau minta kode terbaru kepada Admin aplikasi.', 'err');
            redirect('aktivasi.php');
        }

        // PERPANJANGAN (permintaan pemilik): sekolah yang MASIH AKTIF boleh memasukkan kode
        // aktivasi PERIODE BERIKUTNYA untuk menambah masa aktif 365 hari. Kode yang berlaku adalah
        // kode periode aktif saat ini (kode_aktivasi_sekolah()), yaitu kode yang sama yang
        // ditampilkan Super Admin pada halaman Kelola Sekolah / menu Status & Aktivasi.
        if (!aktivasi_terbatas($sekolah)) {
            $masukan = trim((string) ($_POST['kode'] ?? ''));
            if ($masukan === '') {
                flash('Masukkan kode aktivasi perpanjangan yang dikirim Admin aplikasi.', 'err');
                redirect('aktivasi.php');
            }
            if (!kode_aktivasi_cocok($sekolah, $masukan)) {
                aktivasi_coba_tambah();
                $sisa = aktivasi_coba_sisa();
                flash('Kode aktivasi perpanjangan tidak cocok dengan sekolah ini. Periksa kembali penulisannya (huruf besar/kecil, spasi, dan tanda hubung tidak berpengaruh). '
                    . ($sisa > 0 ? 'Sisa percobaan: ' . $sisa . ' kali.' : 'Percobaan sudah habis — coba lagi dalam ' . (int) AKTIVASI_MENIT_COBA . ' menit.'), 'err');
                redirect('aktivasi.php');
            }
            $sebelum = aktivasi_sampai($sid);
            $sampaiBaru = aktivasi_perpanjang($sid);
            aktivasi_coba_reset();
            flash('Masa aktif sekolah "' . (string) $sekolah['nama'] . '" DIPERPANJANG ' . (int) aktivasi_hari()
                . ' hari — sebelumnya berakhir ' . waktu_id_polos($sebelum) . ', sekarang sampai '
                . waktu_id_polos($sampaiBaru) . '. Seluruh fitur tetap terbuka.');
            redirect('aktivasi.php');
        }

        if ($terkunci) {
            // Masa aktif habis / masa uji coba habis → aplikasi terkunci; kode yang dimasukkan harus
            // kode periode yang berlaku sekarang (kode lama sudah tidak berlaku). Percobaan tetap
            // dibatasi (anti tebak).
            flash($ujiHabis
                ? 'Aplikasi dalam keadaan TERKUNCI karena masa uji coba ' . (int) aktivasi_hari_uji()
                  . ' hari sudah habis. Masukkan KODE AKTIVASI dari HD Admin Aplikasi untuk membuka kembali seluruh fitur.'
                : 'Aplikasi dalam keadaan TERKUNCI karena masa aktif sudah habis. '
                  . 'Masukkan kode aktifasi yang BARU dari HD Admin Aplikasi untuk membuka kembali seluruh fitur.', 'warn');
        }
        $masukan = trim((string) ($_POST['kode'] ?? ''));
        if ($masukan === '') {
            flash('Masukkan kode aktivasi lembaga yang dikirim Admin aplikasi.', 'err');
            redirect('aktivasi.php');
        }
        if (!kode_aktivasi_cocok($sekolah, $masukan)) {
            aktivasi_coba_tambah();
            $sisa = aktivasi_coba_sisa();
            flash('Kode aktivasi tidak cocok dengan sekolah ini. Periksa kembali penulisannya (huruf besar/kecil, spasi, dan tanda hubung tidak berpengaruh). '
                . ($sisa > 0
                    ? 'Sisa percobaan: ' . $sisa . ' kali.'
                    : 'Percobaan sudah habis — coba lagi dalam ' . (int) AKTIVASI_MENIT_COBA . ' menit atau minta kode terbaru kepada Admin aplikasi.'), 'err');
            redirect('aktivasi.php');
        }
        aktivasi_aktifkan($sid);
        aktivasi_coba_reset();
        $sampaiBaru = aktivasi_sampai($sid);
        flash('Selamat! Sekolah "' . (string) $sekolah['nama'] . '" AKTIF dengan masa aktif '
            . (int) aktivasi_hari() . ' hari (1 tahun) sampai ' . waktu_id_polos($sampaiBaru)
            . ' — seluruh fitur terbuka: data guru, supervisi tiap menu, jadwal, impor, dan pembuatan akun massal.');
        redirect('aktivasi.php');
    }

    if ($aksi === 'batasi') {
        // Mengembalikan ke mode terbatas tetap HANYA Super Admin.
        if (!$lihatKode) {
            http_response_code(403);
            exit('Hanya Super Admin yang dapat mengembalikan sekolah ke mode terbatas.');
        }
        aktivasi_batasi($sid);
        flash('Sekolah "' . (string) $sekolah['nama'] . '" dikembalikan ke mode TERBATAS (maksimal '
            . (int) AKTIVASI_MAKS_GURU . ' data guru, ' . (int) AKTIVASI_MAKS_SUPERVISI_MENU
            . ' supervisi tiap menu, ' . (int) AKTIVASI_MAKS_JADWAL . ' baris jadwal, '
            . (int) AKTIVASI_MAKS_DOKUMEN . ' dokumen kiriman ke pengawas). Menu Susun Laporan ikut
            terkunci. Masa uji coba '
            . (int) aktivasi_hari_uji() . ' hari dijalankan kembali sejak sekarang.', 'warn');
        redirect('aktivasi.php');
    }

    redirect('aktivasi.php');
}

$batasJenis = aktivasi_batas_jenis();
$batas = (string) ($_GET['batas'] ?? '');
if ($batas !== '' && !isset($batasJenis[$batas])) {
    $batas = '';
}

// Alamat kembali setelah menutup popup (hanya menerima alamat relatif di aplikasi ini).
$kembali = trim((string) ($_GET['kembali'] ?? ''));
if ($kembali !== '' && preg_match('/^[a-z0-9_\-]+\.php(\?[^\s#]*)?$/i', $kembali) !== 1) {
    $kembali = '';
}

page_top('Status & Aktivasi', 'aktivasi');
?>
<?php if ($terkunci): ?>
  <?php // ---- APLIKASI TERKUNCI (masa aktif habis): permintaan pemilik -------------------------
        // "Saat masa aktif habis, buat aplikasi otomatis mengunci semua akun tanpa menghilangkan
        //  data sebelumnya dan otomatis kolom input kode aktifasi muncul. dan meminta pengguna untuk
        //  mengaktifkan kembali dengan menghubungi HD aplikasi."
        // Kartu ini muncul PALING ATAS supaya kolom kode langsung terlihat tanpa menggulir. ?>
  <div class="card terkunci-card" id="cardTerkunci">
    <div class="card-head">
      <div>
        <h2><?= ikon('lock', 18) ?> Aplikasi Terkunci</h2>
        <?php if ($ujiHabis): ?>
          <p>Masa uji coba <b><?= (int) aktivasi_hari_uji() ?> hari</b> (dihitung sejak sekolah/madrasah
            ini terdaftar) sudah habis dan lembaga ini <b>belum diaktivasi</b>, jadi <b>seluruh akun
            terkunci</b> — tidak ada fitur yang dapat dipakai sampai kode aktifasi dimasukkan.</p>
        <?php else: ?>
          <p>Masa aktif <?= (int) aktivasi_hari() ?> hari (1 tahun) sudah habis, jadi <b>seluruh akun
            terkunci</b> — tidak ada fitur yang dapat dipakai sampai kode aktifasi yang baru dimasukkan.</p>
        <?php endif; ?>
      </div>
      <span class="pill pill-err">Terkunci</span>
    </div>

    <div class="alert alert-err alert-aktivasi-habis" style="margin:0 0 16px">
      <?= ikon('alert', 16) ?>
      <?php if ($ujiHabis): ?>
        <span><b>Hubungi HD Admin Aplikasi untuk mengaktifkan kembali.</b>
          Minta <b>kode aktivasi</b><?= AKTIVASI_WA_ADMIN !== '' ? ' (WA ' . e(AKTIVASI_WA_ADMIN) . ')' : '' ?><?php
          if ($status['uji_sampai_ts'] > 0) {
              echo ', karena masa uji coba lembaga ini berakhir pada ' . e(waktu_id_polos($status['uji_sampai']));
          }
          ?> — masa uji coba <?= (int) aktivasi_hari_uji() ?> hari sejak terdaftar sudah habis, sedangkan
          aktivasi pertama belum pernah dilakukan. Setelah kode dimasukkan, seluruh fitur terbuka selama
          <?= (int) aktivasi_hari() ?> hari (1 tahun). <b>Seluruh data sebelumnya tetap aman</b> —
          data guru, supervisi, jadwal, akun, dan laporan <b>tidak dihapus</b> oleh penguncian ini.</span>
      <?php else: ?>
        <span><b>Hubungi HD Admin Aplikasi untuk mengaktifkan kembali.</b>
          Minta <b>kode aktifasi yang baru</b><?= AKTIVASI_WA_ADMIN !== '' ? ' (WA ' . e(AKTIVASI_WA_ADMIN) . ')' : '' ?><?php
          if ($status['sampai_ts'] > 0) {
              echo ', karena masa aktif sebelumnya berakhir pada ' . e(waktu_id_polos($status['sampai']));
          }
          ?>. Setelah kode dimasukkan, seluruh fitur terbuka kembali selama
          <?= (int) aktivasi_hari() ?> hari (1 tahun). <b>Seluruh data sebelumnya tetap aman</b> —
          data guru, supervisi, jadwal, akun, dan laporan <b>tidak dihapus</b> oleh penguncian ini.</span>
      <?php endif; ?>
    </div>

    <?php if ($boleh): ?>
      <form method="post" class="kode-aktivasi" id="formKodeTerkunci" style="margin:0 0 16px">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="aktifkan">
        <h3 style="margin-top:0"><?= ikon('key', 16) ?> <?= $ujiHabis
            ? 'Masukkan kode aktifasi dari HD Admin' : 'Masukkan kode aktifasi yang baru' ?></h3>
        <p class="sm mut" style="margin:4px 0 12px">Tulis kode persis seperti yang dikirim HD Admin
          Aplikasi. Huruf besar/kecil, spasi, dan tanda hubung tidak berpengaruh.</p>
        <label class="f" for="kode">Kode aktifasi dari HD Admin</label>
        <input type="text" id="kode" name="kode" class="kode-kotak" placeholder="XXXX-XXXX-XXXX"
               autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required>
        <p class="xs mut" style="margin:8px 0 0">
          Sisa percobaan kode pada sesi ini: <b><?= (int) aktivasi_coba_sisa() ?></b> kali.
        </p>
        <button class="btn btn-primary mt16" type="submit"><?= ikon('key', 16) ?> Aktifkan Kembali Sekarang</button>
      </form>
    <?php else: ?>
      <div class="alert alert-warn" style="margin:0 0 16px">
        <?= ikon('info', 16) ?>
        <span><b>Kolom kode aktifasi diisi oleh pengelola sekolah.</b> Minta <b>admin/kepala
          sekolah/madrasah</b> Anda memasukkan kode dari HD Admin Aplikasi pada menu
          <b>Status &amp; Aktivasi</b>. Anda juga dapat melihat status di halaman ini.</span>
      </div>
    <?php endif; ?>

    <div class="permohonan-aktivasi">
      <h3 style="margin-top:0"><?= ikon('alert', 16) ?> Hubungi HD Admin untuk melakukan aktifasi</h3>
      <div class="flex mt16" style="gap:10px;flex-wrap:wrap">
        <a class="btn btn-primary" href="<?= e($waTautan) ?>" target="_blank" rel="noopener noreferrer"
           id="btnMintaKodeAktivasi"><?= ikon('check', 16) ?> Kirim Permohonan Kode Aktifasi</a>
      </div>

      <?php // ------------------------------------------------------------------ NOMINAL BIAYA ?>
      <div class="biaya-aktivasi" id="biayaAktivasi">
        <div class="biaya-kepala">
          <span class="biaya-ikon"><?= ikon('card', 18) ?></span>
          <div>
            <span class="xs mut" id="biayaLabel"><?= e(aktivasi_biaya_label($status, $sekolah)) ?></span>
            <b class="biaya-nominal" id="nominalAktivasi"><?= e(rupiah($biayaBerlaku)) ?></b>
          </div>
        </div>
        <p class="biaya-kalimat" id="kalimatBiaya"><?= e(aktivasi_kalimat_biaya($status, $sekolah)) ?></p>
        <?php if ($waBukti !== ''): ?>
          <a class="btn btn-primary btn-bukti" id="btnKirimBukti" href="<?= e($waBukti) ?>"
             target="_blank" rel="noopener noreferrer"><?= ikon('upload', 16) ?> Kirim Bukti Transfer ke WA</a>
        <?php endif; ?>
        <p class="biaya-tarif xs mut">
          <?php if ($pernahAktif): ?>
            Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>
            (masa aktif <?= (int) aktivasi_hari() ?> hari).
          <?php else: ?>
            Aktivasi pertama (masa aktif <?= (int) aktivasi_hari() ?> hari):
            <b><?= e(rupiah(aktivasi_biaya_pertama())) ?></b> &middot;
            Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>.
          <?php endif; ?>
        </p>
      </div>

      <?php // Lembaga yang SUDAH pernah diaktifasi hanya perlu biaya LANGGANAN tahun berikutnya. ?>
      <?php if ($pernahAktif): ?>
        <div class="alert alert-ok biaya-langganan" id="infoLangganan">
          <?= ikon('check', 16) ?>
          <span>Aplikasi ini <b>sudah diaktifasi</b> &mdash; <b>biaya langganan
            <?= e(rupiah(aktivasi_biaya_langganan())) ?> untuk tahun depan</b>.
            Masa aktifnya sudah berakhir, jadi lakukan pembayaran langganan lalu minta
            <b>kode aktivasinya</b> kepada HD Admin.</span>
        </div>
      <?php endif; ?>

      <div class="rekening-aktivasi mt16">
        <div class="rekening-baris">
          <div style="min-width:0">
            <span class="xs mut">Nomor Rekening untuk Aktifasi</span>
            <b id="rekeningTeks"><?= e(AKTIVASI_REKENING) ?></b>
          </div>
          <button class="btn btn-ghost btn-sm" type="button" data-salin="#rekeningTeks"><?= ikon('doc', 14) ?> Salin</button>
        </div>
        <p class="rekening-ket"><?= ikon('alert', 14) ?> <?= e(AKTIVASI_REKENING_KET) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
// Susunan halaman mengikuti aplikasi e-GURU: kartu STATUS di kolom kiri dan kartu
// "CARA KERJA AKTIVASI" di kolom kanan (kolom kanan hanya dirender bila lebarnya cukup,
// lihat .g21 → satu kolom di layar sempit).
?>
<div class="grid g21" style="align-items:start">
<div class="card">
  <div class="card-head">
    <div>
      <h2>Status Aktivasi Lembaga</h2>
      <p>Keadaan aktivasi menentukan apakah seluruh fitur dapat dipakai atau dibatasi.</p>
    </div>
    <div class="flex">
      <span class="pill <?= ($kadaluarsa || $ujiHabis) ? 'pill-err' : ($terbatas ? 'pill-warn' : 'pill-ok') ?>" id="pillStatusAktivasi"><?= $kadaluarsa
        ? 'Masa aktif habis'
        : ($ujiHabis ? 'Masa uji coba habis' : ($terbatas ? 'Belum diaktivasi' : 'Aktif')) ?></span>
      <span class="chip"><?= e((string) $sekolah['nama']) ?></span>
    </div>
  </div>

  <?php if ($kadaluarsa): ?>
    <div class="alert alert-err alert-aktivasi-habis" style="margin-bottom:16px" id="pesanMasaAktifHabis">
      <?= ikon('alert', 16) ?>
      <span><b>Masa aktif aplikasi sudah habis.</b> Masa aktif 1 tahun (<?= (int) aktivasi_hari() ?> hari)
        berakhir pada <b><?= e(waktu_id_polos($status['sampai'])) ?></b>, jadi sekolah ini kembali memakai
        <b>mode terbatas</b>: maksimal <b><?= (int) AKTIVASI_MAKS_GURU ?> data guru</b>,
        <b><?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi pada setiap menu supervisi</b>,
        <b><?= (int) AKTIVASI_MAKS_JADWAL ?> baris jadwal supervisi</b>, dan
        <b><?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas</b>; menu
        <b>Susun Laporan</b> juga belum dapat dibuka.
        <b>Hubungi HD Admin untuk meminta kode aktifasi yang baru</b> — masukkan kode itu pada halaman ini
        untuk membuka kembali seluruh fitur selama <?= (int) aktivasi_hari() ?> hari berikutnya.</span>
    </div>
  <?php elseif ($ujiHabis): ?>
    <?php // MASA UJI COBA 30 HARI HABIS (permintaan pemilik): sekolah ini BELUM pernah diaktivasi,
          // jadi tidak ada "masa aktif 1 tahun" yang berakhir — yang habis adalah masa uji cobanya. ?>
    <div class="alert alert-err alert-aktivasi-habis" style="margin-bottom:16px" id="pesanUjiHabis">
      <?= ikon('alert', 16) ?>
      <span><b>Masa uji coba <?= (int) aktivasi_hari_uji() ?> hari sudah habis.</b>
        Masa uji coba dihitung sejak sekolah/madrasah ini <b>terdaftar</b><?php
        if ($status['uji_mulai'] !== '') {
            echo ' (' . e(waktu_id_polos($status['uji_mulai'])) . ')';
        }
        ?><?php if ($status['uji_sampai_ts'] > 0) {
            echo ' dan berakhir pada <b>' . e(waktu_id_polos($status['uji_sampai'])) . '</b>';
        } ?>, sedangkan <b>aktivasi pertama belum pernah dilakukan</b>. Karena itu aplikasi
        <b>terkunci</b>: seluruh akun tidak dapat memakai fitur sampai kode aktifasi dimasukkan.
        <b>Hubungi HD Admin untuk meminta kode aktifasi</b>, lalu masukkan pada kartu
        <b>Aplikasi Terkunci</b> di bagian atas halaman ini.</span>
    </div>
  <?php elseif ($terbatas): ?>
    <div class="alert alert-warn" style="margin-bottom:16px">
      <?= ikon('lock', 16) ?>
      <span><b>Aplikasi belum diaktifasi.</b> Sekolah ini memakai <b>mode terbatas</b>: maksimal
        <b><?= (int) AKTIVASI_MAKS_GURU ?> data guru</b>, <b><?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi
        pada setiap menu supervisi</b>, <b><?= (int) AKTIVASI_MAKS_JADWAL ?> baris jadwal supervisi</b>,
        dan <b><?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas</b>.
        Impor data guru, pembuatan akun guru secara massal, dan menu <b>Susun Laporan</b> juga belum dibuka.
        <b>Segera lakukan aktifasi agar semua fitur bisa digunakan.</b><?php
        // MASA UJI COBA 30 HARI (permintaan pemilik): sekolah yang belum diaktivasi hanya diberi
        // waktu terbatas sejak terdaftar (kini 15 hari).
        if ($ujiBerjalan && $status['uji_sampai_ts'] > 0) {
            echo ' <b>Masa uji coba ' . (int) aktivasi_hari_uji() . ' hari</b> lembaga ini berakhir pada <b>'
                . e(waktu_id_polos($status['uji_sampai'])) . '</b> (sisa '
                . (int) $status['uji_sisa_hari'] . ' hari) — setelah itu aplikasi terkunci sampai kode aktifasi di-'
                . 'masukkan.';
        }
        ?></span>
    </div>

    <?php // HITUNG MUNDUR MASA UJI COBA 30 HARI (permintaan pemilik: "Pada bagian Status & Aktifasi
          // untuk sekolah yang belum aktifasi tambahkan hitung mundur. Hitungannya dimulai pada
          // saat sekolah mulai terdaftar."). Nilai awal dicetak PHP supaya tetap terbaca walau skrip
          // gagal dimuat; assets/app.js (initHitungMundur) memperbaruinya tiap detik. ?>
    <?php if ($ujiBerjalan && $status['uji_sampai_ts'] > 0): ?>
      <div class="hitung-mundur uji" id="hitungMundurUji" data-hitung-mundur
           data-berakhir="<?= (int) $status['uji_sampai_ts'] ?>" data-sekarang="<?= time() ?>"
           data-habis-ket="Masa uji coba baru saja berakhir — memuat ulang halaman...">
        <div class="hitung-mundur-head">
          <span class="hitung-mundur-judul"><?= ikon('clock', 15) ?> Hitung mundur masa uji coba</span>
          <span class="hitung-mundur-ket" aria-live="polite"><?= e($hmUjiLabel) ?></span>
        </div>
        <div class="hitung-mundur-unit">
          <?php foreach ([['hari', 'Hari'], ['jam', 'Jam'], ['menit', 'Menit'], ['detik', 'Detik']] as [$kunci, $label]): ?>
            <div class="hm-unit">
              <b data-hm="<?= $kunci ?>"><?= $kunci === 'hari'
                  ? (int) $hmUji[$kunci] : e(str_pad((string) $hmUji[$kunci], 2, '0', STR_PAD_LEFT)) ?></b>
              <span><?= $label ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="hitung-mundur-kaki">Masa uji coba <b><?= (int) aktivasi_hari_uji() ?> hari</b> dihitung
          sejak sekolah/madrasah ini <b>terdaftar</b><?php
          if ($status['uji_mulai'] !== '') {
              echo ' (<b>' . e(waktu_id_polos($status['uji_mulai'])) . '</b>)';
          }
          ?> dan berakhir pada <b><?= e(waktu_id_polos($status['uji_sampai'])) ?></b>. Selama masa itu
          seluruh fitur masih dapat dipakai dalam <b>mode terbatas</b>; setelah waktunya habis
          <b>aplikasi terkunci</b> sampai <b>kode aktivasi</b> dari HD Admin dimasukkan.</div>
      </div>
    <?php elseif ($ujiBerjalan): ?>
      <div class="alert alert-warn" style="margin-bottom:16px" id="pesanUjiTanpaTanggal">
        <?= ikon('clock', 16) ?>
        <span>Tanggal pendaftaran sekolah ini belum tercatat, jadi masa uji coba
          <b><?= (int) aktivasi_hari_uji() ?> hari</b> belum dapat dihitung. Aplikasi tetap dapat
          dipakai dalam mode terbatas — segera lakukan aktifasi agar semua fitur terbuka.</span>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="alert alert-ok" style="margin-bottom:16px">
      <?= ikon('shield', 16) ?>
      <span><b>Aplikasi sudah aktif.</b> Seluruh fitur terbuka tanpa pembatasan<?php
        // Ditulis rapat ke kalimat di atas supaya tidak ada spasi sebelum titik pada tampilan.
        if ($status['aktivasi_pada'] !== '') {
            echo ' — diaktifkan ' . e(waktu_id_polos($status['aktivasi_pada']));
        }
        if ($status['sampai_ts'] > 0) {
            echo ' dengan masa aktif ' . (int) aktivasi_hari() . ' hari, berlaku sampai '
                . e(waktu_id_polos($status['sampai'])) . '.';
        } else {
            echo '.';
        }
        if ($boleh) {
            echo ' Kode aktivasi perpanjangan dari HD Admin dapat dimasukkan pada kartu '
                . 'Aktivasi Perpanjangan di bawah untuk menambah masa aktif '
                . (int) aktivasi_hari() . ' hari lagi.';
        }
        ?></span>
    </div>
  <?php endif; ?>

  <?php // HITUNG MUNDUR masa aktif (permintaan pemilik). Nilai awal dicetak PHP supaya tetap
        // terbaca walau skrip gagal dimuat; assets/app.js (initHitungMundur) memperbaruinya tiap detik. ?>
  <?php if ($status['aktif'] && $status['sampai_ts'] > 0): ?>
    <div class="hitung-mundur" id="hitungMundurAktivasi" data-hitung-mundur
         data-berakhir="<?= (int) $status['sampai_ts'] ?>" data-sekarang="<?= time() ?>">
      <div class="hitung-mundur-head">
        <span class="hitung-mundur-judul"><?= ikon('clock', 15) ?> Hitung mundur masa aktif</span>
        <span class="hitung-mundur-ket" aria-live="polite"><?= e($hmLabel) ?></span>
      </div>
      <div class="hitung-mundur-unit">
        <?php foreach ([['hari', 'Hari'], ['jam', 'Jam'], ['menit', 'Menit'], ['detik', 'Detik']] as [$kunci, $label]): ?>
          <div class="hm-unit">
            <b data-hm="<?= $kunci ?>"><?= $kunci === 'hari'
                ? (int) $hm[$kunci] : e(str_pad((string) $hm[$kunci], 2, '0', STR_PAD_LEFT)) ?></b>
            <span><?= $label ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hitung-mundur-kaki">Masa aktif <b><?= (int) aktivasi_hari() ?> hari (1 tahun)</b>
        berakhir pada <b><?= e(waktu_id_polos($status['sampai'])) ?></b>. Setelah itu aplikasi kembali ke
        mode terbatas sampai <b>kode aktifasi yang baru</b> dari HD Admin dimasukkan.</div>
    </div>
  <?php elseif ($status['habis']): ?>
    <div class="hitung-mundur habis" id="hitungMundurAktivasi">
      <?= ikon('clock', 15) ?>
      <span>Masa aktif 1 tahun telah berakhir pada
        <b><?= e(waktu_id_polos($status['sampai'])) ?></b>. Hubungi HD Admin untuk meminta
        <b>kode aktifasi yang baru</b>.</span>
    </div>
  <?php endif; ?>

  <div class="grid g4" style="gap:12px">
    <div class="nilai-chip" style="min-width:0">
      <b><?= (int) $pakai['guru'] ?><?= $terbatas ? ' / ' . (int) AKTIVASI_MAKS_GURU : '' ?></b>
      <span>Data guru</span>
    </div>
    <div class="nilai-chip" style="min-width:0">
      <b><?= (int) $pakai['supervisi'] ?><?= $terbatas ? ' / ' . (int) AKTIVASI_MAKS_SUPERVISI_MENU . ' per menu' : '' ?></b>
      <span>Penilaian supervisi</span>
    </div>
    <div class="nilai-chip" style="min-width:0">
      <b><?= (int) $pakai['jadwal'] ?><?= $terbatas ? ' / ' . (int) AKTIVASI_MAKS_JADWAL : '' ?></b>
      <span>Baris jadwal</span>
    </div>
    <div class="nilai-chip" style="min-width:0">
      <b><?= (int) $pakai['dokumen'] ?><?= $terbatas ? ' / ' . (int) AKTIVASI_MAKS_DOKUMEN : '' ?></b>
      <span>Dokumen ke pengawas</span>
    </div>
    <div class="nilai-chip" style="min-width:0">
      <b><?= $terbatas ? 'Dibatasi' : 'Penuh' ?></b>
      <span>Fitur tersedia</span>
    </div>
    <div class="nilai-chip" style="min-width:0">
      <b><?php
        // Dibedakan: belum pernah diaktivasi (belum ada masa aktif) vs masa aktif sudah habis.
        if ($status['aktif'] && $status['sampai_ts'] > 0) {
            echo (int) $status['sisa_hari'] . ' hari';
        } elseif ($status['habis']) {
            echo 'Habis';
        } else {
            echo '-';
        }
      ?></b>
      <span>Masa aktif tersisa</span>
    </div>
    <?php // MASA UJI COBA 30 HARI (permintaan pemilik): hanya relevan bagi sekolah yang belum
          // diaktivasi, jadi chip-nya hanya dicetak untuk keadaan itu. ?>
    <?php if ($ujiBerjalan || $ujiHabis): ?>
      <div class="nilai-chip" style="min-width:0">
        <b><?php
          if ($ujiHabis) {
              echo 'Habis';
          } elseif ($status['uji_sampai_ts'] > 0) {
              echo (int) $status['uji_sisa_hari'] . ' hari';
          } else {
              echo '-';
          }
        ?></b>
        <span>Masa uji coba <?= (int) aktivasi_hari_uji() ?> hari</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
// ---------------------------------------------------------------------------------------------
// KARTU "CARA KERJA AKTIVASI" — isinya mengikuti aplikasi e-GURU (5 langkah + catatan).
// Nomor rekening & nominal biaya hanya dicetak bagi pengguna yang memang berhak melakukan
// aktivasi (admin/kepala sekolah & Super Admin) — guru/pengawas hanya melihat alur umumnya.
// Nomor WA sengaja TIDAK dicetak sebagai teks (cukup di dalam tautan tombol).
// ---------------------------------------------------------------------------------------------
?>
<div class="card" id="cardCaraKerja">
  <div class="card-head">
    <div><h2>Cara Kerja Aktivasi</h2><p>Ringkasan singkat untuk pengelola lembaga.</p></div>
  </div>
  <ol class="portal-alur">
    <li><b>Mendaftar</b> — sekolah/madrasah mengisi formulir pendaftaran pada halaman daftar
      (langsung dapat dipakai dalam <b>mode terbatas</b> selama <b><?= (int) aktivasi_hari_uji() ?> hari</b>
      sejak terdaftar).</li>
    <li><b>Permohonan aktifasi</b> — kirim permohonan kode aktifasi kepada <b>HD Admin</b> lewat
      tombol WhatsApp pada kartu <b>Aktivasi Sekolah/Madrasah</b>.</li>
    <li><b>Transfer &amp; verifikasi</b> —
      <?php if ($boleh): ?>
        transfer <b><?= e(rupiah($biayaBerlaku)) ?></b>
        (<?= $pernahAktif
            ? 'biaya langganan tahun berikutnya'
            : 'aktivasi pertama; langganan tahun berikutnya <b>' . e(rupiah(aktivasi_biaya_langganan())) . '</b>' ?>)
        ke nomor rekening yang tertera, lalu <b>kirim bukti transfer</b> ke WA HD Admin lewat tombol
        <b>Kirim Bukti Transfer ke WA</b>.
      <?php else: ?>
        pengelola sekolah mentransfer biaya aktivasi ke nomor rekening yang tertera, lalu mengirim
        bukti transfernya ke WA HD Admin.
      <?php endif; ?></li>
    <li><b>Masukkan kode</b> — HD Admin mengirim <b>kode aktivasi lembaga</b>; masukkan pada formulir
      di halaman ini. Seluruh fitur langsung terbuka dengan <b>masa aktif <?= (int) aktivasi_hari() ?>
      hari (1 tahun)</b>, lengkap dengan hitung mundur.</li>
    <li><b>Masa aktif habis</b> — aplikasi <b>terkunci otomatis</b> (seluruh data lama tetap aman)
      dan kolom kode aktivasi muncul kembali. Minta <b>kode aktivasi yang baru</b> ke HD Admin untuk
      memperpanjang. Hal yang sama berlaku bagi yang <b>belum diaktivasi</b>: masa uji cobanya juga
      mengunci aplikasi bila habis.</li>
  </ol>
  <div class="divider"></div>
  <ul class="portal-cek">
    <li>Kode aktivasi <b>unik per lembaga</b> (dibuat dari nama sekolah/madrasah), jadi kode milik
      lembaga lain tidak dapat dipakai di sini.</li>
    <li>Sekolah/madrasah yang belum diaktivasi memakai <b>mode terbatas</b> dengan <b>masa uji coba
      <?= (int) aktivasi_hari_uji() ?> hari</b> dihitung sejak terdaftar<?php
      if ($status['uji_mulai'] !== '' && ($ujiBerjalan || $ujiHabis)) {
          echo ' (' . e(waktu_id_polos($status['uji_mulai'])) . ')';
      }
      ?> — setelah waktunya habis aplikasi <b>terkunci</b> sampai kode aktivasi dimasukkan.</li>
    <li>Seluruh data tetap aman saat masa aktif berakhir — yang terkunci hanya penggunaan fiturnya,
      tidak ada data yang dihapus.</li>
    <li>Yang dapat memasukkan kode: <b>admin/kepala sekolah</b> (Super Admin juga dapat membantu).</li>
    <li>Masa aktif dihitung <b>satu tahun (<?= (int) aktivasi_hari() ?> hari)</b> untuk setiap kode;
      kode berikutnya diperlukan bila masa aktif itu habis.</li>
    <?php if ($boleh): ?>
      <li><b>Biaya aktivasi pertama <?= e(rupiah(aktivasi_biaya_pertama())) ?></b> dan <b>biaya
        langganan tahun berikutnya <?= e(rupiah(aktivasi_biaya_langganan())) ?></b> — ditransfer ke
        <b><?= e(AKTIVASI_REKENING) ?></b>, bukti transfernya dikirim ke WA HD Admin lewat tombol
        <b>Kirim Bukti Transfer ke WA</b>.</li>
    <?php endif; ?>
  </ul>
</div>
</div>

<?php if ($lihatKode): ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Kode Aktivasi Lembaga</h2>
        <p>Kode unik untuk <b><?= e((string) $sekolah['nama']) ?></b>. Kode dihitung dari nama lembaga,
          jadi selalu sama dan dapat ditampilkan/dikirim ulang kapan saja.</p>
      </div>
      <span class="chip">Hanya Super Admin</span>
    </div>

    <div class="kode-aktivasi">
      <label class="f" for="kodeAktivasiTop">Kode aktivasi</label>
      <div class="kode-baris">
        <input type="text" id="kodeAktivasiTop" class="kode-kotak" value="<?= e($kode) ?>" readonly>
        <button class="btn btn-ghost" type="button" data-salin="#kodeAktivasiTop"><?= ikon('doc', 15) ?> Salin kode</button>
      </div>
      <p class="xs mut" style="margin:8px 0 0">Kirimkan kode ini kepada pengelola sekolah/madrasah
        (admin/kepala). Ia dapat memasukkannya sendiri pada menu <b>Status &amp; Aktivasi</b>, atau Anda
        masukkan di sini. Kode di atas berlaku untuk <b>periode ke-<?= (int) $status['periode'] ?></b> dan
        memberi masa aktif <b><?= (int) aktivasi_hari() ?> hari (1 tahun)</b><?php
          if ($status['aktif'] && $status['sampai_ts'] > 0) {
              echo ' — masa aktif berjalan sampai ' . e(waktu_id_polos($status['sampai']))
                  . ' (sisa ' . (int) $status['sisa_hari'] . ' hari). Kode ini juga dapat dimasukkan '
                  . 'pengelola sekolah sebagai <b>kode aktivasi PERPANJANGAN</b> selagi masa aktifnya '
                  . 'masih berjalan: masa aktifnya ditambahkan ' . (int) aktivasi_hari() . ' hari '
                  . 'dihitung dari tanggal berakhir di atas (sisa masa aktif tidak hilang).';
          } elseif ($status['habis']) {
              echo ' — masa aktif sebelumnya berakhir ' . e(waktu_id_polos($status['sampai']))
                  . ', jadi kode di atas inilah yang diperlukan sekarang.';
          } else {
              echo ' — belum ada masa aktif berjalan; kode di atas adalah kode pertama lembaga ini.';
          }
        ?></p>
    </div>

    <div class="grid<?= $terbatas ? '' : ' g21' ?> mt16">
      <?php if ($terkunci): ?>
        <?php // Saat terkunci, kolom kode sudah tersedia pada kartu "Aplikasi Terkunci" di atas —
              // jangan cetak form kedua (id kembar + membingungkan). ?>
        <div class="card card-tight" style="margin:0">
          <h3 style="margin-top:0"><?= ikon('info', 16) ?> Kolom kode tersedia di atas</h3>
          <p class="sm mut" style="margin:4px 0 0">Masukkan kode aktivasi <b>periode ke-<?= (int) $status['periode'] ?></b>
            pada kartu <b>Aplikasi Terkunci</b> di bagian atas halaman ini. Kode di atas hanya untuk
            ditampilkan/disalin dan dikirim kepada pengelola sekolah.</p>
        </div>
      <?php else: ?>
        <form method="post" class="card card-tight" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="aktifkan">
          <h3 style="margin-top:0">Aktifkan sekolah ini</h3>
          <p class="sm mut" style="margin:4px 0 12px">Masukkan kode aktivasi lembaga di atas sebagai
            konfirmasi. Setelah aktif, seluruh pembatasan fitur hilang.</p>
          <label class="f" for="kode">Kode aktivasi</label>
          <input type="text" id="kode" name="kode" placeholder="XXXX-XXXX-XXXX" autocomplete="off" required>
          <button class="btn btn-primary mt16" type="submit"><?= ikon('key', 16) ?> Aktifkan Sekolah</button>
        </form>
      <?php endif; ?>

      <?php if (!$terbatas): ?>
        <div class="card card-tight" style="margin:0">
          <h3 style="margin-top:0">Mode terbatas</h3>
          <p class="sm mut" style="margin:4px 0 12px">
            Sekolah ini sedang <b>aktif</b>. Bila perlu, kembalikan ke mode terbatas supaya
            fitur kembali dibatasi sampai diaktivasi ulang.
          </p>
          <form method="post"
                data-confirm="Kembalikan sekolah ini ke mode TERBATAS? Fitur akan dibatasi lagi sampai diaktivasi ulang.">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="batasi">
            <button class="btn btn-ghost btn-sm" type="submit"><?= ikon('lock', 14) ?> Kembalikan ke mode terbatas</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($boleh && $terkunci): ?>
  <?php // Saat terkunci, seluruh isi (kolom kode, ajakan menghubungi HD Admin, nomor rekening) sudah
        // ada pada kartu "Aplikasi Terkunci" di atas — di sini cukup petunjuknya. ?>
  <div class="card card-tight">
    <div class="card-head">
      <div>
        <h2><?= ikon('info', 16) ?> Cara Mengaktifkan Kembali</h2>
        <p>Aplikasi terkunci karena <?= $ujiHabis
          ? 'masa uji coba ' . (int) aktivasi_hari_uji() . ' hari sudah habis (lembaga ini belum pernah diaktivasi)'
          : 'masa aktif 1 tahun sudah habis' ?>.</p>
      </div>
      <span class="chip">Kode dari HD Admin</span>
    </div>
    <ol class="langkah-aktivasi">
      <li>Hubungi <b>HD Admin Aplikasi</b> untuk meminta <?= $ujiHabis
          ? '<b>kode aktivasi</b> (aktivasi pertama lembaga ini belum pernah dilakukan)'
          : '<b>kode aktifasi yang baru</b> (kode periode sebelumnya sudah tidak berlaku)' ?><?php
        echo AKTIVASI_WA_ADMIN !== '' ? ' — WA ' . e(AKTIVASI_WA_ADMIN) : '' ?>.</li>
      <li>Masukkan kode itu pada kolom <b><?= $ujiHabis
          ? '“Masukkan kode aktifasi dari HD Admin”'
          : '“Masukkan kode aktifasi yang baru”' ?></b> di kartu
        <b>Aplikasi Terkunci</b> di atas, lalu tekan <b>Aktifkan Kembali Sekarang</b>.</li>
      <li>Setelah kode benar, seluruh fitur (data guru, supervisi tiap menu, jadwal, impor, dan akun
        massal) terbuka kembali selama <?= (int) aktivasi_hari() ?> hari (1 tahun) —
        <b>data yang sudah ada tidak dihapus</b>.</li>
    </ol>
  </div>
<?php elseif ($boleh): ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Aktivasi Sekolah/Madrasah</h2>
        <?php if ($terbatas): ?>
          <p>Masukkan <b>kode aktivasi</b> yang dikirim Admin aplikasi kepada sekolah/madrasah Anda.
            Setelah kode benar, seluruh fitur langsung terbuka.</p>
        <?php else: ?>
          <p>Lembaga ini sudah aktif. Kolom di bawah dipakai untuk memasukkan
            <b>kode aktivasi perpanjangan</b> yang dikirim HD Admin agar masa aktif bertambah
            <?= (int) aktivasi_hari() ?> hari lagi.</p>
        <?php endif; ?>
      </div>
      <span class="chip"><?= $terbatas ? 'Kode dari Admin' : 'Kode perpanjangan' ?></span>
    </div>

    <div class="grid g21" style="align-items:start">
      <?php if ($terbatas): ?>
        <form method="post" class="card card-tight kode-aktivasi" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="aktifkan">
          <h3 style="margin-top:0">Masukkan kode aktivasi</h3>
          <p class="sm mut" style="margin:4px 0 12px">Tulis kode persis seperti yang dikirim Admin.
            Huruf besar/kecil, spasi, dan tanda hubung tidak berpengaruh.
            Kode berlaku untuk <b><?= (int) aktivasi_hari() ?> hari (1 tahun)</b>; setelah masa aktif
            habis, minta <b>kode yang baru</b> kepada HD Admin (kode lama tidak berlaku lagi).</p>
          <label class="f" for="kode">Kode aktivasi dari Admin</label>
          <input type="text" id="kode" name="kode" class="kode-kotak" placeholder="XXXX-XXXX-XXXX"
                 autocomplete="off" autocapitalize="characters" spellcheck="false" required>
          <p class="xs mut" style="margin:8px 0 0">
            Sisa percobaan kode pada sesi ini: <b><?= (int) aktivasi_coba_sisa() ?></b> kali.
          </p>
          <button class="btn btn-primary mt16" type="submit"><?= ikon('key', 16) ?> Aktifkan Sekarang</button>
        </form>
      <?php else: ?>
        <?php
        // AKTIVASI PERPANJANGAN (permintaan pemilik): "Tampilkan fitur untuk mengisi kode aktifasi
        // perpanjangan di menu status & aktifasi pada akun kepala madrasah untuk menginput kode
        // aktifasi perpanjangan bagi yang sudah melakukan aktifasi pertama."
        // Dua bentuk: lembaga yang SUDAH pernah diaktifasi (kode perpanjangan) dan lembaga yang
        // aktif tanpa pernah memasukkan kode (aktivasi pertama) — keduanya memakai formulir ini.
        $sampaiSekarang = $status['sampai_ts'] > 0 ? $status['sampai'] : '';
        $sampaiTambah = $sampaiSekarang !== ''
            ? date('Y-m-d H:i:s', max(time(), (int) strtotime($sampaiSekarang)) + aktivasi_hari() * 86400)
            : date('Y-m-d H:i:s', time() + aktivasi_hari() * 86400);
        ?>
        <form method="post" class="card card-tight kode-aktivasi" style="margin:0" id="formPerpanjang">
          <?= csrf_field() ?>
          <input type="hidden" name="aksi" value="aktifkan">
          <h3 style="margin-top:0"><?= ikon('shield', 16) ?> <?= $pernahAktif
              ? 'Aktivasi Perpanjangan' : 'Aktivasi Sekolah/madrasah' ?></h3>
          <p class="sm mut" style="margin:4px 0 12px">
            <?php if ($pernahAktif): ?>
              Lembaga ini <b>sudah diaktifasi</b>. Masukkan <b>kode aktivasi perpanjangan</b> dari HD
              Admin untuk menambah masa aktif <b><?= (int) aktivasi_hari() ?> hari (1 tahun)</b> —
              masa aktif dihitung dari tanggal berakhir yang sedang berjalan, jadi sisa masa aktif
              sekarang tidak hilang.
            <?php else: ?>
              Masukkan <b>kode aktivasi</b> yang dikirim Admin aplikasi — masa aktif
              <b><?= (int) aktivasi_hari() ?> hari (1 tahun)</b> dimulai dari kode itu dimasukkan.
            <?php endif; ?>
          </p>
          <label class="f" for="kode"><?= $pernahAktif ? 'Kode aktivasi perpanjangan dari HD Admin' : 'Kode aktivasi dari Admin' ?></label>
          <input type="text" id="kode" name="kode" class="kode-kotak" placeholder="XXXX-XXXX-XXXX"
                 autocomplete="off" autocapitalize="characters" spellcheck="false" required>
          <table class="tbl tbl-ringkas mt16" id="tblPerpanjang">
            <tbody>
              <tr>
                <td>Masa aktif sekarang</td>
                <td><b><?= e($sampaiSekarang !== '' ? waktu_id_polos($sampaiSekarang) : '-') ?></b>
                  <?php if ($status['sisa_detik'] > 0): ?>
                    <span class="pill pill-ok">sisa <?= (int) $status['sisa_hari'] ?> hari</span>
                  <?php endif; ?></td>
              </tr>
              <tr>
                <td>Setelah diperpanjang</td>
                <td><b><?= e(waktu_id_polos($sampaiTambah)) ?></b>
                  <span class="pill pill-gray">+<?= (int) aktivasi_hari() ?> hari</span></td>
              </tr>
            </tbody>
          </table>
          <p class="xs mut" style="margin:8px 0 0">
            Sisa percobaan kode pada sesi ini: <b><?= (int) aktivasi_coba_sisa() ?></b> kali.
          </p>
          <button class="btn btn-primary mt16" type="submit"><?= ikon('key', 16) ?> <?= $pernahAktif
              ? 'Perpanjang Masa Aktif' : 'Aktifkan Sekarang' ?></button>
        </form>
      <?php endif; ?>

      <div class="card card-tight" style="margin:0">
        <h3 style="margin-top:0">Petunjuk</h3>
        <ol class="langkah-aktivasi">
          <li>Admin aplikasi mengirim <b>kode aktivasi</b> khusus untuk
            <b><?= tetap_kata((string) $sekolah['nama']) ?></b> — kode setiap sekolah/madrasah berbeda
            dan <b>berbeda pula setiap periode perpanjangan</b>.</li>
          <li>Masukkan kode itu pada formulir di samping, lalu tekan
            <b><?= $terbatas ? 'Aktifkan Sekarang' : 'Perpanjang Masa Aktif' ?></b>.</li>
          <?php if (!$terbatas): ?>
            <li>Bagi yang <b>sudah melakukan aktivasi pertama</b>, kode <b>perpanjangan</b> menambah
              masa aktif <?= (int) aktivasi_hari() ?> hari dihitung dari tanggal berakhir yang sedang
              berjalan — <b>sisa masa aktif sekarang tidak hilang</b>.</li>
          <?php endif; ?>
          <li>Bila kode belum diterima, hubungi Admin aplikasi. Bila kode salah
            <?= (int) AKTIVASI_MAKS_COBA ?> kali, percobaan dihentikan sementara
            (<?= (int) AKTIVASI_MENIT_COBA ?> menit) — minta kode terbaru kepada Admin.</li>
        </ol>
      </div>
    </div>

    <?php
    // ---- PERMOHONAN KODE AKTIVASI + NOMOR REKENING (permintaan pemilik) ----
    // "Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi" — tombolnya
    // langsung mengarah ke WhatsApp dengan pesan yang sudah disiapkan (nama aplikasi & nama
    // sekolah/madrasah yang didaftarkan diisi otomatis).
    //
    // Permintaan pemilik (pembaruan): keterangan cara kerja tombol dan pratinjau
    // "Pesan yang akan terkirim" TIDAK ditampilkan lagi — cukup judul, tombol, dan nomor rekening.
    // Saat TERKUNCI blok ini sudah ada pada kartu "Aplikasi Terkunci" di atas, jadi tidak dicetak lagi.
    ?>
    <div class="divider"></div>
    <div class="permohonan-aktivasi" id="permohonanAktivasi">
      <h3 class="permohonan-judul" style="margin-top:0"><?= ikon('key', 16) ?> Aktivasi Sekolah/Madrasah</h3>
      <p class="permohonan-ajak"><b>Hubungi HD Admin untuk melakukan aktifasi.</b></p>
      <div class="flex" style="gap:10px;flex-wrap:wrap">
        <a class="btn btn-primary btn-permohonan" href="<?= e($waTautan) ?>" target="_blank" rel="noopener noreferrer"
           id="btnMintaKodeAktivasi"><?= ikon('check', 16) ?> Kirim Permohonan Kode Aktifasi</a>
      </div>

      <?php // ------------------------------------------------------------------ NOMINAL BIAYA ?>
      <div class="biaya-aktivasi" id="biayaAktivasi">
        <div class="biaya-kepala">
          <span class="biaya-ikon"><?= ikon('card', 18) ?></span>
          <div>
            <span class="xs mut" id="biayaLabel"><?= e(aktivasi_biaya_label($status, $sekolah)) ?></span>
            <b class="biaya-nominal" id="nominalAktivasi"><?= e(rupiah($biayaBerlaku)) ?></b>
          </div>
        </div>
        <p class="biaya-kalimat" id="kalimatBiaya"><?= e(aktivasi_kalimat_biaya($status, $sekolah)) ?></p>
        <?php if ($waBukti !== ''): ?>
          <a class="btn btn-primary btn-bukti" id="btnKirimBukti" href="<?= e($waBukti) ?>"
             target="_blank" rel="noopener noreferrer"><?= ikon('upload', 16) ?> Kirim Bukti Transfer ke WA</a>
        <?php endif; ?>
        <p class="biaya-tarif xs mut">
          <?php if ($pernahAktif): ?>
            Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>
            (masa aktif <?= (int) aktivasi_hari() ?> hari).
          <?php else: ?>
            Aktivasi pertama (masa aktif <?= (int) aktivasi_hari() ?> hari):
            <b><?= e(rupiah(aktivasi_biaya_pertama())) ?></b> &middot;
            Langganan tahun berikutnya: <b><?= e(rupiah(aktivasi_biaya_langganan())) ?></b>.
          <?php endif; ?>
        </p>
      </div>

      <?php // Permintaan pemilik: "Jika aplikasi sudah di aktifasi maka muncul info: biaya langganan
            // untuk tahun depan". Lembaga yang sudah diaktifasi HANYA membayar biaya langganan. ?>
      <?php if ($pernahAktif): ?>
        <div class="alert alert-ok biaya-langganan" id="infoLangganan">
          <?= ikon('check', 16) ?>
          <span>Aplikasi ini <b>sudah diaktifasi</b> &mdash; <b>biaya langganan
            <?= e(rupiah(aktivasi_biaya_langganan())) ?> untuk tahun depan</b>.
            <?php if ($status['aktif'] && $status['sampai_ts'] > 0): ?>
              Masa aktif sekarang berjalan sampai <b><?= e(waktu_id_polos($status['sampai'])) ?></b>,
              jadi pembayaran langganan dilakukan menjelang masa aktif itu berakhir.
            <?php elseif ($status['habis']): ?>
              Masa aktif sudah berakhir &mdash; lakukan pembayaran langganan lalu minta kode
              aktivasinya kepada HD Admin.
            <?php else: ?>
              Pembayaran langganan dilakukan saat masa aktif akan berakhir.
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>

      <div class="rekening-aktivasi mt16">
        <div class="rekening-baris">
          <div style="min-width:0">
            <span class="xs mut">Nomor Rekening untuk Aktifasi</span>
            <b id="rekeningTeks"><?= e(AKTIVASI_REKENING) ?></b>
          </div>
          <button class="btn btn-ghost btn-sm" type="button" data-salin="#rekeningTeks"><?= ikon('doc', 14) ?> Salin</button>
        </div>
        <p class="rekening-ket"><?= ikon('alert', 14) ?> <?= e(AKTIVASI_REKENING_KET) ?></p>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h2>Cara Melakukan Aktivasi</h2>
        <p>Sekolah/madrasah yang mendaftar sendiri berjalan dalam mode terbatas sampai diaktivasi.</p>
      </div>
      <span class="chip">Kode dimasukkan pengelola sekolah</span>
    </div>
    <ol class="langkah-aktivasi">
      <li>Pengelola sekolah/madrasah meminta <b>kode aktivasi</b> kepada <b>HD Admin aplikasi</b>
        (kode berbeda untuk setiap lembaga dan berbeda pula untuk setiap periode perpanjangan).</li>
      <li>Kode tersebut dimasukkan oleh <b>admin/kepala sekolah</b> pada menu
        <b>Status &amp; Aktivasi</b> (atau oleh Admin aplikasi).</li>
      <li>Setelah kode benar, seluruh fitur langsung terbuka untuk semua pengguna sekolah ini.</li>
    </ol>
    <p class="sm mut" style="margin:0">
      Halaman ini hanya menampilkan status untuk akun Anda. Pemasukan kode aktivasi dilakukan
      admin/kepala sekolah atau Super Admin.
    </p>
  </div>
<?php endif; ?>

<div class="card card-tight">
  <div class="flex between">
    <div>
      <h3>Catatan</h3>
      <p class="sm mut" style="margin:4px 0 0">
        Pembatasan hanya berlaku pada <b>penambahan data</b>: data guru yang sudah ada tetap dapat
        diubah, dinonaktifkan, atau dihapus; penilaian supervisi yang sudah tersimpan tetap dapat
        dibuka, diubah, dan dicetak; jadwal yang sudah ada tetap dapat diubah dan dicetak.
      </p>
    </div>
    <span class="chip">Data terpisah per sekolah</span>
  </div>
</div>

<?php if ($batas !== ''): ?>
  <?php $info = $batasJenis[$batas]; ?>
  <dialog class="popup popup-aktivasi" id="popupAktivasi"<?= $batas !== '' ? ' open' : '' ?>
          data-kembali="<?= e($kembali !== '' ? $kembali : 'aktivasi.php') ?>">
    <div class="popup-isi">
      <div class="popup-ic"><?= ikon('alert', 26) ?></div>
      <h2>Aplikasi Belum Diaktifasi</h2>
      <p class="popup-lead">Segera lakukan aktifasi agar semua fitur bisa digunakan.</p>
      <div class="alert alert-warn" style="margin:0 0 12px">
        <?= ikon('lock', 16) ?>
        <span><b><?= e($info['judul']) ?> dibatasi.</b> <?= e($info['pesan']) ?></span>
      </div>
      <table class="tbl tbl-ringkas">
        <tbody>
          <tr><td>Sekolah/madrasah</td><td><b><?= tetap_kata((string) $sekolah['nama']) ?></b></td></tr>
          <tr><td>Status</td><td><span class="pill pill-warn">Belum diaktivasi (dibatasi)</span></td></tr>
          <?php if ($ujiBerjalan && $status['uji_sampai_ts'] > 0): ?>
            <tr><td>Masa uji coba <?= (int) aktivasi_hari_uji() ?> hari</td>
              <td>Berakhir <b><?= e(waktu_id_polos($status['uji_sampai'])) ?></b>
                (sisa <?= (int) $status['uji_sisa_hari'] ?> hari) — setelah itu aplikasi terkunci
                sampai kode aktivasi dimasukkan.</td></tr>
          <?php endif; ?>
          <tr><td>Batas mode terbatas</td><td><?= (int) AKTIVASI_MAKS_GURU ?> data guru,
            <?= (int) AKTIVASI_MAKS_SUPERVISI_MENU ?> supervisi tiap menu,
            <?= (int) AKTIVASI_MAKS_JADWAL ?> baris jadwal,
            <?= (int) AKTIVASI_MAKS_DOKUMEN ?> dokumen kiriman ke pengawas &middot;
            menu Susun Laporan belum terbuka</td></tr>
          <tr><td>Cara aktivasi</td><td><?php
              // Permintaan pemilik: keterangan pada popup ini berbunyi persis seperti di bawah
              // (beserta nomor WA Admin aplikasi) bagi pengguna yang punya formulir kode.
              $cara = 'Masukkan kode aktivasi yang dikirim Admin aplikasi pada formulir di halaman ini'
                  . ' (WA Admin ' . AKTIVASI_WA_ADMIN . ').';
              if (!$boleh) {
                  // Guru & pengawas tidak memiliki formulirnya → arahkan meminta pengelola sekolah.
                  $cara = 'Minta admin/kepala sekolah memasukkan kode aktivasi yang dikirim Admin aplikasi'
                      . ' pada menu Status & Aktivasi (WA Admin ' . AKTIVASI_WA_ADMIN . ').';
              }
              echo e($cara);
              ?></td></tr>
        </tbody>
      </table>
      <div class="popup-aksi">
        <?php if ($boleh): ?>
          <a class="btn btn-primary" href="#kode"><?= ikon('key', 16) ?> Masukkan kode aktivasi</a>
        <?php endif; ?>
        <button class="btn btn-ghost" type="button" data-popup-tutup>Tutup</button>
        <?php if ($kembali !== ''): ?>
          <a class="btn btn-ghost" href="<?= e($kembali) ?>">Kembali ke halaman sebelumnya</a>
        <?php endif; ?>
      </div>
    </div>
  </dialog>
<?php endif; ?>
<?php
page_bottom();
