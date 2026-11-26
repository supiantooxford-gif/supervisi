<?php
/**
 * UNDUH WORD (.docx) "Daftar Sekolah/Madrasah Binaan" — akun PENGAWAS.
 *
 * Permintaan pemilik (v1.36.1): pada kotak merah di kepala kartu "Daftar Sekolah/Madrasah Binaan"
 * ditambahkan tombol **Unduh Word**; isinya hanya **Nomor, Nama Lembaga, dan Masa Aktif**.
 *
 * Satu sumber data dengan tabel di dasbor pengawas:
 *   - daftar & urutannya  : sekolah_binaan($userId, true)  (sama seperti index.php cabang pengawas)
 *   - label masa aktif    : aktivasi_masa_aktif()          (sama seperti kolom "Masa Aktif")
 *   - bentuk teksnya      : peringkat_masa_teks()          (sama seperti tabel Peringkat Sekolah)
 * Jadi isi Word tidak mungkin berbeda dari yang tampil di layar.
 *
 * Berkas .docx disusun sendiri oleh `inc/docx.php` (tanpa ZipArchive / pustaka luar).
 * Halaman A4 POTRAIT — hanya tiga kolom.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';

$u = require_login();
if (!is_pengawas()) {
    http_response_code(403);
    exit('Unduhan daftar binaan hanya untuk Pengawas Sekolah/Madrasah.');
}

// Sekolah yang sudah dinonaktifkan tidak ditampilkan pada daftar binaan (index.php) — di sini sama.
$binaan = sekolah_binaan((int) $u['id'], true);
if (!$binaan) {
    flash('Belum ada sekolah/madrasah binaan yang dapat diunduh.', 'err');
    redirect('index.php');
}

$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];

$blok = [];
$blok[] = ['teks' => 'DAFTAR SEKOLAH/MADRASAH BINAAN', 'tengah' => true, 'tebal' => true, 'ukuran' => 30, 'jarak' => 40];
$blok[] = ['teks' => APP_NAME . ' — Sistem Informasi Supervisi Akademik Guru', 'tengah' => true, 'ukuran' => 22, 'jarak' => 20];
$blok[] = ['teks' => 'Pengawas Sekolah/Madrasah', 'tengah' => true, 'ukuran' => 20, 'jarak' => 160];

$blok[] = ['teks' => 'Pengawas: ' . $namaUser, 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['teks' => 'Jumlah binaan: ' . count($binaan) . ' sekolah/madrasah', 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['teks' => 'Diunduh: ' . waktu_id_polos(date('Y-m-d H:i:s')), 'ukuran' => 20, 'jarak' => 160];

$isi = [];
foreach ($binaan as $ix => $s) {
    $isi[] = [
        (string) ($ix + 1),
        (string) $s['nama'],
        peringkat_masa_teks($s),
    ];
}
$blok[] = ['tabel' => [['No', 'Nama Lembaga', 'Masa Aktif'], $isi]];

$blok[] = ['teks' => 'Keterangan: Masa Aktif dibaca dari masa aktif 1 tahun / masa uji coba yang tercatat '
    . 'pada menu Status & Aktivasi masing-masing lembaga. Binaan yang sudah dinonaktifkan Admin tidak '
    . 'ditampilkan pada daftar ini.', 'ukuran' => 18, 'jarak' => 60];
$blok[] = ['teks' => 'Diunduh dari ' . APP_NAME . ' v' . APP_VERSION . ' pada ' . tanggal_id(date('Y-m-d')) . '.',
    'ukuran' => 18, 'tengah' => true];

$bytes = docx_bangun($blok, 'Daftar Sekolah dan Madrasah Binaan');
docx_kirim('Daftar-Binaan-' . date('Y-m-d') . '.docx', $bytes);
