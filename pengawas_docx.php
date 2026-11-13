<?php
/**
 * UNDUHAN WORD (.docx) "DAFTAR AKUN PENGAWAS" — Supervisi Guru v1.29.0.
 *
 * Permintaan pemilik (tangkapan layar kepala kartu "Daftar Akun Pengawas" dengan kotak merah):
 * *"Yang saya tandai warna merah tambahkan fitur download dan cetak. Hasil download/cetak seperti
 * contoh."* Bentuknya sederhana seperti contoh: judul + tabel berkolom **No, Pengawas Bina,
 * Sekolah/Madrasah Binaan (bernomor 1..n), Masa Aktif** — tanpa kop sekolah dan tanpa tanda tangan.
 *
 * Sumber data = `pengawas_binaan_ringkas()` (sama dengan halaman Akun Pengawas & lembar cetak
 * `cetak_pengawas.php`), jadi urutan akun, daftar binaan, dan angka masa aktifnya selalu sama.
 * Berkas .docx dibuat sendiri oleh `inc/docx.php` (tanpa pustaka luar/ZipArchive). Halaman A4
 * POTRAIT (bawaan docx_bangun()), sama seperti lembar cetaknya.
 *
 * Akses: Super Admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';

$u = require_login();
if (!is_super()) {
    http_response_code(403);
    exit('Unduhan daftar akun pengawas ini hanya untuk akun Super Admin.');
}
// Halaman lintas sekolah: istilah generik "Sekolah/Madrasah" (tidak diterjemahkan menjadi "Madrasah").
$GLOBALS['__vs_istilah_nonaktif'] = true;

$ringkas = pengawas_binaan_ringkas();
$baris = $ringkas['baris'];
$tot = $ringkas['tot'];
if (!$baris) {
    flash('Belum ada akun pengawas yang dapat diunduh.', 'err');
    redirect('pengawas.php');
}
$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];

// Susun baris tabel: nomor, nama pengawas, daftar binaan bernomor, ringkasan masa aktif.
$isi = [];
$n = 0;
foreach ($baris as $b) {
    $n++;
    $nama = (string) $b['nama'];
    if (!$b['aktif']) {
        $nama .= "\n(" . ($b['ditinjau'] ? 'Nonaktif' : 'Nonaktif, menunggu ditinjau') . ')';
    }
    $daftar = [];
    foreach ($b['binaan'] as $i => $sk) {
        $daftar[] = ($i + 1) . '. ' . (string) $sk['nama'];
    }
    if ((int) $b['nonaktif'] > 0) {
        $daftar[] = '(' . (int) $b['nonaktif'] . ' binaan nonaktif tidak dihitung)';
    }
    $isi[] = [
        (string) $n,
        $nama,
        $daftar ? implode("\n", $daftar) : 'Belum ada sekolah/madrasah binaan',
        (string) $b['masa_teks'],
    ];
}
$isi[] = [
    '',
    ['JUMLAH (' . (int) $tot['akun'] . ' pengawas)', true],
    [(int) $tot['binaan'] . ' penugasan binaan aktif', true],
    [pengawas_masa_teks($tot), true],
];

$blok = [
    ['teks' => 'DATA SEKOLAH/MADRASAH DI APLIKASI ' . APP_NAME
        . ' BERDASARKAN PENGAWAS BINA DAN MASA AKTIF', 'tebal' => true, 'tengah' => true,
        'ukuran' => 26, 'jarak' => 40],
    ['teks' => 'Lintas sekolah/madrasah (semua lembaga aktif). Dicetak: '
        . waktu_id_polos(date('Y-m-d H:i:s')) . ' oleh ' . $namaUser
        . ' (' . role_label((string) $u['role']) . ').', 'tengah' => true, 'ukuran' => 18, 'jarak' => 140],
    ['tabel' => [
        ['No', 'Pengawas Bina', 'Sekolah/Madrasah Binaan', 'Masa Aktif'],
        $isi,
    ]],
    ['teks' => 'Keterangan: kolom Masa Aktif merangkum masa aktif seluruh sekolah/madrasah binaan aktif '
        . 'pada baris itu - angka hari = sisa masa aktif terdekat (masa uji coba 15 hari bagi lembaga '
        . 'yang belum melakukan aktivasi, atau sisa masa aktif 1 tahun bagi yang sudah), tanpa batas = '
        . 'masa aktif tanpa tanggal berakhir, dan terkunci = masa aktif/masa uji sudah habis sehingga '
        . 'aplikasi lembaga itu terkunci sampai kode aktivasi dimasukkan. Yang dihitung hanya binaan '
        . 'aktif; binaan nonaktif tidak dapat dibuka pengawas.', 'ukuran' => 18, 'jarak' => 60],
    ['teks' => 'Dicetak dari ' . APP_NAME . ' v' . APP_VERSION . ' pada '
        . tanggal_id(date('Y-m-d')) . '.', 'ukuran' => 18, 'tengah' => true],
];

docx_kirim('Daftar-Akun-Pengawas-' . date('Y-m-d') . '.docx',
    docx_bangun($blok, 'Daftar Akun Pengawas'));
