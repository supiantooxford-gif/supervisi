<?php
/**
 * HALAMAN DEPAN (LANDING PAGE) — modul bersama.
 *
 * Permintaan pemilik: "Buat halaman depan aplikasi menjadi tampilan web landing page yang
 * professional dan menarik yang menampilkan beranda, fitur aplikasi, keunggulan, testimoni,
 * tentang aplikasi, kontak. panel login tetap dipertahankan. Untuk mengisi halaman landing page
 * di isi lewat akun super admin."
 *
 * Isi halaman depan disimpan di DUA tempat:
 *  - teks tunggal (beranda, judul tiap bagian, statistik, kontak) → tabel `app_pengaturan`
 *    dengan awalan kunci `landing_` (tingkat APLIKASI, dipakai bersama semua sekolah/madrasah);
 *  - daftar berulang (fitur, keunggulan, testimoni) dan pesan kontak → tabel `landing_*`.
 *
 * Nilai BAWAAN ada di `landing_bawaan()` / `landing_daftar_bawaan()`; dipakai bila Super Admin
 * belum pernah mengubahnya. Mengosongkan sebuah isian berarti benar-benar kosong (bagian itu
 * tidak dicetak), bukan kembali ke bawaan.
 *
 * PENTING: seluruh tulisan pada halaman depan memakai istilah "sekolah/madrasah" (halaman ini
 * publik dan melayani sekolah umum maupun madrasah sekaligus).
 *
 * Modul ini boleh dimuat tanpa init.php (dipakai juga oleh `inc/db.php` saat mengisi data awal),
 * jadi JANGAN memanggil fungsi basis data apa pun pada saat modul dimuat.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

const LANDING_JUDUL_MAKS = 160;
const LANDING_TEKS_MAKS  = 700;
const LANDING_ISI_MAKS   = 900;
const LANDING_NAMA_MAKS  = 90;
const LANDING_PESAN_MAKS = 1200;
const LANDING_MAKS_SESI  = 5;   // maksimal pengiriman pesan kontak per sesi

/** Jenis daftar yang boleh dikelola di halaman depan. */
function landing_jenis_list(): array
{
    return ['slider', 'fitur', 'unggulan', 'testimoni'];
}

/** Hanya menerima jenis yang dikenal (menjaga nama tabel tetap aman). */
function landing_jenis_sah(string $jenis): string
{
    $j = strtolower(trim($jenis));
    return in_array($j, landing_jenis_list(), true) ? $j : '';
}

/** Nama tabel daftar halaman depan (WHITELIST — jangan menerima nama tabel dari pengguna). */
function landing_tabel(string $jenis): string
{
    return match (landing_jenis_sah($jenis)) {
        'slider'    => 'landing_slider',
        'fitur'     => 'landing_fitur',
        'unggulan'  => 'landing_unggulan',
        'testimoni' => 'landing_testimoni',
        default     => '',
    };
}

/** Label tampilan tiap jenis daftar. */
function landing_jenis_label(string $jenis): string
{
    return match (landing_jenis_sah($jenis)) {
        'slider'    => 'Slider Beranda',
        'fitur'     => 'Fitur Aplikasi',
        'unggulan'  => 'Keunggulan',
        'testimoni' => 'Testimoni',
        default     => '',
    };
}

/** Jenis daftar yang itemnya menyimpan FOTO (dipakai halaman pengisian & unggahan). */
function landing_jenis_berfoto(string $jenis): bool
{
    return in_array(landing_jenis_sah($jenis), ['slider', 'testimoni'], true);
}

// ---------------------------------------------------------------------------------------------
// ISI BAWAAN
// ---------------------------------------------------------------------------------------------

/**
 * Isi bawaan halaman depan (dipakai satu kali saat pemasangan & sebagai nilai awal isian).
 * Semuanya dapat diubah/dikosongkan Super Admin lewat menu "Halaman Depan (Landing)".
 */
function landing_bawaan(): array
{
    return [
        // --- Beranda
        // CATATAN (v1.17.0): judul, kalimat pengantar, deskripsi, tombol (cta) dan tiga poin
        // centang pada bagian beranda DIHAPUS — permintaan pemilik: bagian itu diganti
        // SLIDER FOTO/GAMBAR (Judul Slider, foto, keterangan) yang isinya dikelola Super Admin
        // pada tab "Slider Beranda". Yang tersisa dari bagian beranda hanya LABEL kecil +
        // empat kotak statistik di bawahnya.
        'label'     => 'Sistem Informasi Supervisi Akademik',

        // --- Statistik (4 pasang angka + label; kosongkan bila tidak ingin ditampilkan)
        'stat_1_angka' => '4',
        'stat_1_label' => 'Menu Supervisi',
        'stat_2_angka' => '54',
        'stat_2_label' => 'Butir Penilaian',
        'stat_3_angka' => 'A-D',
        'stat_3_label' => 'Predikat Nilai Otomatis',
        'stat_4_angka' => '100%',
        'stat_4_label' => 'Berbasis Web, Tanpa Instalasi',

        // --- Judul tiap bagian
        'fitur_judul'    => 'Fitur Aplikasi',
        'fitur_sub'      => 'Semua kebutuhan supervisi akademik dalam satu aplikasi: dari menyiapkan '
            . 'instrumen hingga mencetak dokumen hasilnya.',
        'unggulan_judul' => 'Keunggulan',
        'unggulan_sub'   => 'Alasan sekolah/madrasah memilih aplikasi ini untuk supervisi guru.',
        'testimoni_judul'       => 'Testimoni',
        'testimoni_judul_besar' => 'Apa Kata Pengguna Kami?',
        'testimoni_sub'         => 'Pengalaman kepala sekolah/madrasah, pengawas, dan guru yang memakai aplikasi ini.',
        'tentang_judul'  => 'Tentang Aplikasi',
        'tentang_sub'    => 'Sekilas aplikasi ini beserta siapa yang mengembangkannya.',
        'kontak_judul'   => 'Kontak',
        'kontak_sub'     => 'Ingin mencoba, bertanya, atau mengaktifkan aplikasi? Hubungi kami.',

        // --- Kontak
        'kontak_alamat'  => '',
        'kontak_telepon' => AKTIVASI_WA_ADMIN,
        'kontak_email'   => '',
        'kontak_jam'     => 'Senin - Sabtu, 07.00 - 21.00 WIB',
        'kontak_maps'    => '',
        'kontak_ajakan'  => 'Silakan kirim pesan lewat formulir di samping, atau hubungi langsung '
            . 'lewat WhatsApp/telepon.',
    ];
}

/**
 * Isi bawaan daftar fitur/keunggulan/testimoni.
 * Dipakai SEKALI saat pemasangan; sesudah itu sepenuhnya dikelola Super Admin.
 */
function landing_daftar_bawaan(): array
{
    return [
        // Slider beranda: gambar/foto + judul + keterangan (permintaan pemilik v1.17.0).
        // Isi bawaan ini SENGAJA tanpa foto — Super Admin melengkapi fotonya sendiri; selama belum
        // ada foto, slider memakai latar gradasi bergambar ikon (bukan kotak rusak).
        'slider' => [
            ['Supervisi Akademik Guru yang Terukur dan Rapi',
                'Aplikasi supervisi akademik untuk sekolah/madrasah: penilaian kinerja guru memakai butir penilaian yang jelas, nilai dan predikat dihitung otomatis, serta dokumen siap cetak.'],
            ['Butir Penilaian Jelas, Nilai & Predikat Otomatis',
                'Setiap butir diberi skor 1-4, lalu aplikasi menghitung nilai akhir beserta predikatnya mengikuti ambang yang ditetapkan sekolah/madrasah.'],
            ['Laporan, Rekap, dan Dokumen Siap Cetak',
                'Hasil supervisi langsung tersedia dalam laporan dan rekap (matriks guru x menu beserta grafik), lengkap dengan jadwal supervisi dan berkas yang dapat dicetak.'],
        ],
        'fitur' => [
            ['check', 'Menu & Butir Penilaian', 'Empat menu supervisi (administrasi, perencanaan modul ajar, pengamatan pembelajaran, dan penilaian) beserta butir penilaian dan indikatornya.'],
            ['chart', 'Nilai & Predikat Otomatis', 'Setiap butir diberi skor 1-4; aplikasi menghitung nilai akhir dan predikat A/B/C/D mengikuti ambang yang ditetapkan sekolah/madrasah.'],
            ['calendar', 'Jadwal Supervisi', 'Susun jadwal supervisi guru dalam satu tabel lintas tanggal, lengkap dengan mata pelajaran, kelas, dan supervisor.'],
            ['report', 'Laporan & Rekap', 'Rekap matriks guru dan menu beserta grafik, peringkat guru, serta daftar guru yang belum disupervisi.'],
            ['camera', 'Bukti Foto Pengamatan', 'Lampirkan bukti foto pengamatan pembelajaran (maksimal dua foto) yang ikut tercetak pada lembar hasil supervisi.'],
            ['doc', 'Dokumen Siap Cetak', 'Lembar hasil supervisi, data guru, jadwal, dan daftar butir penilaian — memakai kop serta tanda tangan kepala sekolah/madrasah.'],
            ['upload', 'Impor Data Guru', 'Masukkan data guru sekaligus dari berkas Excel atau CSV, dan akun guru dibuatkan otomatis.'],
            ['school', 'Multi-Sekolah/Madrasah & Multi-Akun', 'Satu pemasangan melayani banyak sekolah/madrasah dengan akun Super Admin, kepala sekolah/madrasah, guru, dan pengawas.'],
        ],
        'unggulan' => [
            ['clock', 'Mudah Digunakan', 'Tampilan sederhana dan alur yang jelas, sehingga kepala sekolah/madrasah maupun guru langsung paham tanpa pelatihan panjang.'],
            ['shield', 'Data Setiap Sekolah/Madrasah Terpisah', 'Akun hanya melihat data sekolah/madrasah yang bersangkutan, dan kata sandi tersimpan teracak.'],
            ['eye', 'Pengawas Terhubung', 'Pengawas sekolah/madrasah dapat melihat dan mengunduh rekapan supervisi setiap binaannya, serta memberi komentar dan meninjau dokumen.'],
            ['globe', 'Bisa Dibuka di Mana Saja', 'Cukup memakai peramban: laptop, komputer, maupun telepon pintar.'],
            ['star', 'Hasil Langsung Terlihat', 'Nilai dan predikat langsung tersedia setelah penilaian disimpan, tanpa perlu menghitung manual.'],
            ['support', 'Dukungan Penuh', 'Admin aplikasi siap membantu saat dibutuhkan, dan sekolah/madrasah baru dapat mendaftar sendiri.'],
        ],
        'testimoni' => [
            ['Kepala Sekolah/Madrasah (contoh)', 'Pengguna Aplikasi', 'Supervisi jadi lebih terarah karena butir penilaiannya jelas, dan hasilnya bisa langsung dicetak sebagai arsip.'],
            ['Guru (contoh)', 'Pengguna Aplikasi', 'Saya tahu persis aspek apa yang dinilai, sehingga bisa memperbaiki perencanaan dan pelaksanaan pembelajaran.'],
            ['Pengawas Sekolah/Madrasah (contoh)', 'Pengguna Aplikasi', 'Rekapan tiap binaan bisa dilihat dalam satu tempat, jadi pembinaan berikutnya lebih mudah direncanakan.'],
        ],
    ];
}

// ---------------------------------------------------------------------------------------------
// TEKS TUNGGAL (app_pengaturan)
// ---------------------------------------------------------------------------------------------

/** Isi satu kunci halaman depan (kunci tanpa awalan "landing_"). */
function landing_teks(string $kunci, ?string $def = null): string
{
    $bawaan = landing_bawaan();
    $nilaiBawaan = $bawaan[$kunci] ?? ($def ?? '');
    return app_setting('landing_' . $kunci, (string) $nilaiBawaan);
}

/** Simpan banyak kunci sekaligus: ['judul' => '...', 'lead' => '...']. */
function landing_simpan_teks(array $nilai): void
{
    foreach ($nilai as $k => $v) {
        app_setting_set('landing_' . (string) $k, (string) $v);
    }
}

/** Empat pasang angka + label statistik beranda (baris kosong tidak ditampilkan). */
function landing_stat_list(): array
{
    $out = [];
    for ($i = 1; $i <= 4; $i++) {
        $angka = trim(landing_teks('stat_' . $i . '_angka'));
        $label = trim(landing_teks('stat_' . $i . '_label'));
        if ($angka === '' && $label === '') {
            continue;
        }
        $out[] = ['angka' => $angka, 'label' => $label];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// DAFTAR (fitur / keunggulan / testimoni)
// ---------------------------------------------------------------------------------------------

/** Isi satu daftar (hanya yang aktif bila $aktifSaja). */
function landing_list(string $jenis, bool $aktifSaja = true): array
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '') {
        return [];
    }
    $sql = 'SELECT * FROM ' . $tabel;
    if ($aktifSaja) {
        $sql .= ' WHERE aktif = 1';
    }
    $sql .= ' ORDER BY urutan, id';
    try {
        return all($sql);
    } catch (Throwable $e) {
        return [];
    }
}

function landing_item_by_id(string $jenis, int $id): ?array
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '' || $id <= 0) {
        return null;
    }
    return one('SELECT * FROM ' . $tabel . ' WHERE id = ?', [$id]);
}

function landing_urutan_baru(string $jenis): int
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '') {
        return 10;
    }
    $maks = (int) val('SELECT COALESCE(MAX(urutan), 0) FROM ' . $tabel, [], 0);
    return $maks + 10;
}

function landing_item_tambah(string $jenis, array $d): void
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '') {
        return;
    }
    $kini = date('Y-m-d H:i:s');
    if ($jenis === 'testimoni') {
        q('INSERT INTO ' . $tabel . ' (nama, jabatan, isi, foto, urutan, aktif, dibuat) VALUES (?,?,?,?,?,?,?)',
            [$d['nama'] ?? '', $d['jabatan'] ?? '', $d['isi'] ?? '', $d['foto'] ?? null,
                landing_urutan_baru($jenis), 1, $kini]);
        return;
    }
    if ($jenis === 'slider') {
        q('INSERT INTO ' . $tabel . ' (judul, keterangan, foto, urutan, aktif, dibuat) VALUES (?,?,?,?,?,?)',
            [$d['judul'] ?? '', $d['keterangan'] ?? '', $d['foto'] ?? null,
                landing_urutan_baru($jenis), 1, $kini]);
        return;
    }
    q('INSERT INTO ' . $tabel . ' (ikon, judul, teks, urutan, aktif, dibuat) VALUES (?,?,?,?,?,?)',
        [$d['ikon'] ?? 'check', $d['judul'] ?? '', $d['teks'] ?? '', landing_urutan_baru($jenis), 1, $kini]);
}

function landing_item_simpan(string $jenis, int $id, array $d): void
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '' || $id <= 0) {
        return;
    }
    if ($jenis === 'testimoni') {
        q('UPDATE ' . $tabel . ' SET nama = ?, jabatan = ?, isi = ?, aktif = ? WHERE id = ?',
            [$d['nama'] ?? '', $d['jabatan'] ?? '', $d['isi'] ?? '', (int) ($d['aktif'] ?? 1), $id]);
        return;
    }
    if ($jenis === 'slider') {
        q('UPDATE ' . $tabel . ' SET judul = ?, keterangan = ?, aktif = ? WHERE id = ?',
            [$d['judul'] ?? '', $d['keterangan'] ?? '', (int) ($d['aktif'] ?? 1), $id]);
        return;
    }
    q('UPDATE ' . $tabel . ' SET ikon = ?, judul = ?, teks = ?, aktif = ? WHERE id = ?',
        [$d['ikon'] ?? 'check', $d['judul'] ?? '', $d['teks'] ?? '', (int) ($d['aktif'] ?? 1), $id]);
}

/** Simpan hanya kolom foto testimoni (dipakai setelah unggahan berhasil). */
function landing_testimoni_foto(int $id, string $url): void
{
    if ($id <= 0) {
        return;
    }
    q('UPDATE landing_testimoni SET foto = ? WHERE id = ?', [$url, $id]);
}

/** Simpan hanya kolom foto satu slide slider beranda (dipakai setelah unggahan berhasil). */
function landing_slider_foto(int $id, string $url): void
{
    if ($id <= 0) {
        return;
    }
    q('UPDATE landing_slider SET foto = ? WHERE id = ?', [$url, $id]);
}

/** Foto satu slide slider (string kosong bila belum diunggah). */
function landing_slider_gambar(array $slide): string
{
    return trim((string) ($slide['foto'] ?? ''));
}

function landing_item_hapus(string $jenis, int $id): void
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '' || $id <= 0) {
        return;
    }
    q('DELETE FROM ' . $tabel . ' WHERE id = ?', [$id]);
}

/** Tukar urutan dengan tetangga terdekat (naik = -1, turun = +1). */
function landing_item_geser(string $jenis, int $id, int $arah): void
{
    $tabel = landing_tabel($jenis);
    if ($tabel === '' || $id <= 0) {
        return;
    }
    $ini = one('SELECT id, urutan FROM ' . $tabel . ' WHERE id = ?', [$id]);
    if (!$ini) {
        return;
    }
    $op = $arah < 0 ? '<' : '>';
    $urut = $arah < 0 ? 'DESC' : 'ASC';
    $tetangga = one('SELECT id, urutan FROM ' . $tabel . ' WHERE (urutan ' . $op . ' ? OR (urutan = ? AND id ' . $op . ' ?))
                     ORDER BY urutan ' . $urut . ', id ' . $urut . ' LIMIT 1',
        [(int) $ini['urutan'], (int) $ini['urutan'], $id]);
    if (!$tetangga) {
        return;
    }
    q('UPDATE ' . $tabel . ' SET urutan = ? WHERE id = ?', [(int) $tetangga['urutan'], $id]);
    q('UPDATE ' . $tabel . ' SET urutan = ? WHERE id = ?', [(int) $ini['urutan'], (int) $tetangga['id']]);
}

// ---------------------------------------------------------------------------------------------
// PESAN KONTAK
// ---------------------------------------------------------------------------------------------

function landing_pesan_list(int $maks = 100): array
{
    $maks = max(1, min(500, $maks));
    try {
        return all('SELECT * FROM landing_pesan ORDER BY id DESC LIMIT ' . $maks);
    } catch (Throwable $e) {
        return [];
    }
}

function landing_pesan_baru(): int
{
    try {
        return (int) val('SELECT COUNT(*) FROM landing_pesan WHERE dibaca = 0', [], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function landing_pesan_jumlah(): int
{
    try {
        return (int) val('SELECT COUNT(*) FROM landing_pesan', [], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function landing_pesan_hapus(int $id): void
{
    if ($id <= 0) {
        return;
    }
    q('DELETE FROM landing_pesan WHERE id = ?', [$id]);
}

function landing_pesan_tandai(int $id, bool $dibaca = true): void
{
    if ($id <= 0) {
        return;
    }
    q('UPDATE landing_pesan SET dibaca = ? WHERE id = ?', [$dibaca ? 1 : 0, $id]);
}

/** Ringkasan isi tabel landing (dipakai halaman Super Admin). */
function landing_ringkas(): array
{
    $out = [];
    foreach (['slider', 'fitur', 'unggulan', 'testimoni'] as $j) {
        $tabel = landing_tabel($j);
        try {
            $total = (int) val('SELECT COUNT(*) FROM ' . $tabel, [], 0);
            $aktif = (int) val('SELECT COUNT(*) FROM ' . $tabel . ' WHERE aktif = 1', [], 0);
        } catch (Throwable $e) {
            $total = 0;
            $aktif = 0;
        }
        $out[$j] = ['total' => $total, 'aktif' => $aktif];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// KONTAK & IKON
// ---------------------------------------------------------------------------------------------

/** Tautan WhatsApp dari nomor pada isian kontak (08… → 628…). */
function landing_wa_tautan(string $pesan = ''): string
{
    $nomor = preg_replace('/\D+/', '', landing_teks('kontak_telepon')) ?? '';
    if ($nomor === '') {
        return '';
    }
    $nomor = wa_internasional($nomor);
    $tautan = 'https://wa.me/' . $nomor;
    if ($pesan !== '') {
        $tautan .= '?text=' . rawurlencode($pesan);
    }
    return $tautan;
}

/** Daftar ikon yang boleh dipilih untuk fitur & keunggulan. */
function landing_ikon_list(): array
{
    return [
        'check'    => 'Centang / Butir Penilaian',
        'chart'    => 'Grafik / Nilai Otomatis',
        'report'   => 'Laporan & Rekap',
        'calendar' => 'Jadwal / Tanggal',
        'camera'   => 'Kamera / Bukti Foto',
        'doc'      => 'Dokumen / Cetak Berkas',
        'print'    => 'Cetak / Printer',
        'upload'   => 'Impor / Unggah',
        'school'   => 'Sekolah / Lembaga',
        'globe'    => 'Dunia / Internet',
        'star'     => 'Bintang / Kualitas',
        'medal'    => 'Medali / Penghargaan',
        'clock'    => 'Jam / Waktu',
        'shield'   => 'Perisai / Keamanan',
        'eye'      => 'Mata / Pengawasan',
        'users'    => 'Beberapa Pengguna',
        'user'     => 'Pengguna',
        'key'      => 'Kunci / Akun',
        'support'  => 'Dukungan / Bantuan',
        'chat'     => 'Percakapan',
        'heart'    => 'Hati / Kepuasan',
    ];
}

function landing_ikon_sah(string $nama): string
{
    return array_key_exists($nama, landing_ikon_list()) ? $nama : 'check';
}

/** Ikon halaman depan (memakai daftar di atas — tanpa pustaka luar). */
function landing_ikon(string $nama, int $size = 24): string
{
    $p = [
        'check'    => '<path d="M9.6 16.2 5.4 12l-1.4 1.4 5.6 5.6 12-12L20.2 5.6z"/>',
        'chart'    => '<path d="M4 20h16v-2H4zM6 16h3V8H6zm4.5 0h3V4h-3zM15 16h3v-6h-3z"/>',
        'report'   => '<path d="M5 3h9l5 5v13H5zm2 2v14h10V9h-4V5zm2 6h6v1.6H9zm0 3.4h6V16H9z"/>',
        'calendar' => '<path d="M5 3h2v2h10V3h2v2h2v16H3V5h2zm0 6v10h14V9zM7 11h3v3H7z"/>',
        'camera'   => '<path d="M9 4h6l1.5 2H21v14H3V6h4.5zM12 8a5 5 0 1 0 0 10 5 5 0 0 0 0-10m0 2a3 3 0 1 1 0 6 3 3 0 0 1 0-6"/>',
        'doc'      => '<path d="M14 3v5h5"/><path d="M19 8.5V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z"/>',
        'print'    => '<path d="M7 3h10v5H7zm-3 7h16v7h-3v4H7v-4H4zm5 4v4h6v-4z"/>',
        'upload'   => '<path d="M11 3h2v8h3l-4 4-4-4h3zM5 18h14v3H5z"/>',
        'school'   => '<path d="M12 3 2 8v13h20V8zm0 2.2 7 3.5V19H5V8.7zM9 19v-5h6v5z"/>',
        'globe'    => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18m0 2c1.7 0 3 2.9 3 7s-1.3 7-3 7-3-2.9-3-7 1.3-7 3-7"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.8-4.5 4.2 1.2 6L12 16.7 6.5 19.6l1.2-6L3.2 9.4l6.1-.8z"/>',
        'medal'    => '<path d="M9 2h6l-2 6h-2zM12 9a6 6 0 1 0 0 12 6 6 0 0 0 0-12m0 3 1 2 2 .3-1.5 1.4.4 2-1.9-1-1.9 1 .4-2L9 13.3l2-.3z"/>',
        'clock'    => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18m0 2a7 7 0 1 1 0 14 7 7 0 0 1 0-14m-1 2v6l4 2 .8-1.6L13 11.5V7z"/>',
        'shield'   => '<path d="M12 2 4 6v6c0 5 3.4 9.2 8 10 4.6-.8 8-5 8-10V6z"/>',
        'eye'      => '<path d="M12 5c6 0 10 7 10 7s-4 7-10 7S2 12 2 12s4-7 10-7m0 3.5A3.5 3.5 0 1 0 12 15.5a3.5 3.5 0 0 0 0-7"/>',
        'users'    => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m0 2c-4 0-7 2.2-7 5v1h14v-1c0-2.8-3-5-7-5m8.5-1.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6m.5 2c2.4 0 4 1.2 4 3v1h-4v-1c0-1.2-.4-2.2-1.2-3z"/>',
        'user'     => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8m0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5"/>',
        'key'      => '<path d="M14 3a5 5 0 0 0-4.6 7L3 16.4V21h4.6l1.4-1.4V17h2v-2h2l1.2-1.2A5 5 0 1 0 14 3m1.6 3.4a1.6 1.6 0 1 1 0 3.2 1.6 1.6 0 0 1 0-3.2"/>',
        'support'  => '<path d="M12 2a7 7 0 0 0-7 7v4h2V9a5 5 0 0 1 10 0v4h2V9a7 7 0 0 0-7-7M6 15h3v6H6zm9 0h3v6h-3z"/>',
        'chat'     => '<path d="M4 4h16v12H8l-4 4z"/>',
        'heart'    => '<path d="M12 21S3 14.6 3 8.9A4.9 4.9 0 0 1 12 6a4.9 4.9 0 0 1 9 2.9C21 14.6 12 21 12 21"/>',
        // Ikon informasi kontak (dipakai bagian kontak halaman depan).
        'telepon'  => '<path d="M7 3h3l2 5-2 1a11 11 0 0 0 5 5l1-2 5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 5 5a2 2 0 0 1 2-2"/>',
        'email'    => '<path d="M3 5h18v14H3zm2.7 2L12 11.4 18.3 7z"/>',
        'lokasi'   => '<path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7m0 4.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5"/>',
    ];
    $d = $p[$nama] ?? $p['check'];
    return '<svg class="lp-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
        . ' fill="currentColor" aria-hidden="true">' . $d . '</svg>';
}

// ---------------------------------------------------------------------------------------------
// ALAT BANTU (pemotongan teks + unggahan foto testimoni)
// ---------------------------------------------------------------------------------------------

/** Potong teks ke sejumlah karakter (memakai mb_* bila tersedia). */
function landing_potong(?string $teks, int $maks): string
{
    $teks = trim((string) $teks);
    if ($maks <= 0) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return (string) mb_substr($teks, 0, $maks);
    }
    return strlen($teks) > $maks ? substr($teks, 0, $maks) : $teks;
}

/**
 * Unggah satu berkas berkas foto dari formulir ($_FILES[$field]) ke media proxy platform.
 * Yang dikembalikan hanya URL hasil respons; URL itulah yang disimpan ke basis data.
 *
 * @return array{ok:bool, url:string, pesan:string}
 */
function landing_unggah_foto_berkas(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || trim((string) ($f['name'] ?? '')) === '') {
        return ['ok' => false, 'url' => '', 'pesan' => 'Tidak ada berkas foto yang dipilih.'];
    }
    $kode = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($kode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'url' => '', 'pesan' => ($kode === UPLOAD_ERR_INI_SIZE || $kode === UPLOAD_ERR_FORM_SIZE)
            ? 'Berkasnya melebihi batas server (' . ini_get('upload_max_filesize') . '). Perkecil dulu fotonya.'
            : 'Gagal diunggah (kode ' . $kode . '). Silakan coba lagi.'];
    }
    $namaAsli = (string) $f['name'];
    $ukuran = (int) ($f['size'] ?? 0);
    $tmp = (string) ($f['tmp_name'] ?? '');
    $cek = validasi_foto($namaAsli, $ukuran, $tmp);
    if (empty($cek['ok'])) {
        return ['ok' => false, 'url' => '', 'pesan' => (string) $cek['pesan']];
    }
    $bytes = @file_get_contents($tmp);
    if ($bytes === false) {
        return ['ok' => false, 'url' => '', 'pesan' => 'Gagal membaca berkas foto di server.'];
    }
    $hasil = media_upload($bytes, $namaAsli);
    if (empty($hasil['ok'])) {
        return ['ok' => false, 'url' => '', 'pesan' => (string) ($hasil['pesan'] ?? 'Unggahan foto ditolak.')];
    }
    return ['ok' => true, 'url' => (string) $hasil['url'], 'pesan' => ''];
}

// ---------------------------------------------------------------------------------------------
// ISI AWAL (dijalankan SEKALI oleh migrasi)
// ---------------------------------------------------------------------------------------------

/**
 * Isi halaman depan dengan isi bawaan SEKALI SAJA.
 * Penanda `landing_seed` membuat isi yang sudah disunting Super Admin tidak pernah ditimpa
 * (termasuk bila ia mengosongkan seluruh daftar atau seluruh teksnya).
 */
function landing_seed(PDO $pdo): void
{
    $sudah = (int) $pdo->query("SELECT COUNT(*) FROM app_pengaturan WHERE kunci = 'landing_seed'")->fetchColumn();
    if ($sudah > 0) {
        return;
    }

    $st = $pdo->prepare('INSERT INTO app_pengaturan (kunci, nilai) VALUES (?, ?)
                         ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai');
    foreach (landing_bawaan() as $k => $v) {
        $st->execute(['landing_' . $k, (string) $v]);
    }

    $kini = date('Y-m-d H:i:s');
    $isi = landing_daftar_bawaan();
    $urut = 10;
    foreach ($isi['fitur'] as $f) {
        $pdo->prepare('INSERT INTO landing_fitur (ikon, judul, teks, urutan, aktif, dibuat) VALUES (?,?,?,?,1,?)')
            ->execute([$f[0], $f[1], $f[2], $urut, $kini]);
        $urut += 10;
    }
    $urut = 10;
    foreach ($isi['unggulan'] as $f) {
        $pdo->prepare('INSERT INTO landing_unggulan (ikon, judul, teks, urutan, aktif, dibuat) VALUES (?,?,?,?,1,?)')
            ->execute([$f[0], $f[1], $f[2], $urut, $kini]);
        $urut += 10;
    }
    $urut = 10;
    foreach ($isi['testimoni'] as $f) {
        $pdo->prepare('INSERT INTO landing_testimoni (nama, jabatan, isi, urutan, aktif, dibuat) VALUES (?,?,?,?,1,?)')
            ->execute([$f[0], $f[1], $f[2], $urut, $kini]);
        $urut += 10;
    }

    $st->execute(['landing_seed', date('Y-m-d H:i:s')]);
}

/**
 * Isi SLIDER BERANDA dengan contoh bawaan SEKALI SAJA (penanda `landing_slider_seed`).
 *
 * Terpisah dari `landing_seed()` karena slider baru ditambahkan pada v1.17.0 — basis data yang
 * sudah pernah menjalankan `landing_seed()` (termasuk data pemilik) tetap mendapat slidenya,
 * sedangkan suntingan Super Admin sesudahnya TIDAK pernah ditimpa.
 * Contoh bawaan sengaja TANPA foto: Super Admin melengkapi fotonya sendiri; selama belum ada foto,
 * slider memakai latar gradasi berikon (bukan kotak rusak).
 */
function landing_slider_seed(PDO $pdo): void
{
    $sudah = (int) $pdo->query("SELECT COUNT(*) FROM app_pengaturan WHERE kunci = 'landing_slider_seed'")->fetchColumn();
    if ($sudah > 0) {
        return;
    }
    $simpanPenanda = static function (PDO $pdo, string $kapan): void {
        $pdo->prepare('INSERT INTO app_pengaturan (kunci, nilai) VALUES (?, ?)
                       ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai')
            ->execute(['landing_slider_seed', $kapan]);
    };

    // JANGAN menambah contoh bawaan bila tabelnya SUDAH BERISI. Tabel `landing_slider` bisa dibuat
    // lebih dahulu daripada fungsi ini (mis. migrasi terputus, atau aplikasi sudah dipakai pemilik
    // lalu slidenya diisi sendiri) — dalam keadaan itu isi pemilik TIDAK BOLEH ditambahi contoh.
    // Penanda tetap dipasang supaya pemeriksaan ini hanya dilakukan sekali.
    $jml = (int) $pdo->query('SELECT COUNT(*) FROM landing_slider')->fetchColumn();
    if ($jml > 0) {
        $simpanPenanda($pdo, date('Y-m-d H:i:s'));
        return;
    }

    $kini = date('Y-m-d H:i:s');
    $urut = 10;
    foreach (landing_daftar_bawaan()['slider'] as $s) {
        $pdo->prepare('INSERT INTO landing_slider (judul, keterangan, foto, urutan, aktif, dibuat) VALUES (?,?,NULL,?,1,?)')
            ->execute([$s[0], $s[1], $urut, $kini]);
        $urut += 10;
    }
    $simpanPenanda($pdo, $kini);
}
