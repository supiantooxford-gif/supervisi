<?php
/**
 * UNDUH WORD (.docx) daftar "Peringkat Sekolah" lintas sekolah — dasbor Super Admin.
 *
 * Permintaan pemilik (v1.27.0): tombol **Unduh Word** di samping **Cetak** pada kartu
 * "Peringkat Sekolah". Isi & urutannya SAMA dengan lembar cetak `cetak_peringkat.php`, keduanya
 * membaca `peringkat_sekolah()` (satu sumber data) sehingga angkanya tidak mungkin berbeda dari
 * tabel di dasbor.
 *
 * Berkas .docx disusun sendiri oleh `inc/docx.php` (tanpa ZipArchive / pustaka luar, karena
 * ekstensi zip tidak tersedia di server ini). Halaman A4 LANDSCAPE.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';

$u = require_login();
if (!is_super()) {
    http_response_code(403);
    exit('Unduhan peringkat sekolah hanya untuk Super Admin.');
}
// Halaman lintas sekolah: istilah generik (tidak diterjemahkan menjadi "Madrasah").
$GLOBALS['__vs_istilah_nonaktif'] = true;

$pk = peringkat_sekolah();
$baris = $pk['baris'];
$tot = $pk['tot'];
if (!$baris) {
    flash('Belum ada sekolah yang dapat diunduh.', 'err');
    redirect('sekolah.php');
}

$namaUser = trim((string) ($u['nama'] ?? '')) !== '' ? (string) $u['nama'] : (string) $u['username'];

$blok = [];
$blok[] = ['teks' => 'PERINGKAT SEKOLAH', 'tengah' => true, 'tebal' => true, 'ukuran' => 30, 'jarak' => 40];
$blok[] = ['teks' => APP_NAME . ' — Sistem Informasi Supervisi Akademik Guru', 'tengah' => true, 'ukuran' => 22, 'jarak' => 20];
$blok[] = ['teks' => 'Lintas sekolah/madrasah (semua lembaga aktif)', 'tengah' => true, 'ukuran' => 20, 'jarak' => 160];

$blok[] = ['teks' => 'Lingkup: seluruh sekolah yang disetujui & aktif (' . (int) $tot['sekolah'] . ' lembaga)', 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['teks' => 'Urutan: masa aktif paling lama di atas, lalu nama sekolah', 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['teks' => 'Dicetak: ' . waktu_id_polos(date('Y-m-d H:i:s')), 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['teks' => 'Oleh: ' . $namaUser . ' (' . role_label((string) $u['role']) . ')', 'ukuran' => 20, 'jarak' => 160];

$isi = [];
foreach ($baris as $ix => $r) {
    $sub = 'NPSN ' . ((string) ($r['npsn'] ?? '') !== '' ? (string) $r['npsn'] : '-')
        . ' - ' . ((string) ($r['kepala_sekolah'] ?? '') !== '' ? (string) $r['kepala_sekolah'] : '-');
    $supervisi = (string) (int) $r['supervisi'] . ((int) $r['draft'] > 0 ? ' (+' . (int) $r['draft'] . ' draf)' : '');
    $isi[] = [
        (string) ($ix + 1),
        (string) $r['nama'] . "\n" . $sub,
        peringkat_masa_teks($r),
        (string) (int) $r['guru'],
        $supervisi,
        $r['rata_nilai'] !== null ? r1($r['rata_nilai']) : 'belum ada',
        $r['predikat'] !== null ? (string) $r['predikat'] : '-',
        (string) (int) $r['cakupan'] . '% (' . (int) $r['guru_dinilai'] . '/' . (int) $r['guru'] . ')',
    ];
}
$isi[] = [
    ['JUMLAH (' . (int) $tot['sekolah'] . ' sekolah)', true],
    '', '',
    [(string) (int) $tot['guru'], true],
    [(string) (int) $tot['supervisi'], true],
    [$tot['rata'] !== null ? r1($tot['rata']) : '-', true],
    [$tot['predikat'] !== null ? (string) $tot['predikat'] : '-', true],
    '-',
];
$blok[] = ['tabel' => [['No', 'Sekolah', 'Masa Aktif', 'Guru', 'Supervisi', 'Rata-rata', 'Predikat', 'Cakupan'], $isi]];

$blok[] = ['teks' => 'Keterangan: Guru = guru aktif; Supervisi = penilaian berstatus final (draf '
    . 'ditampilkan terpisah); Rata-rata = rata-rata nilai supervisi final; Predikat = A/B/C/D menurut '
    . 'ambang masing-masing sekolah; Cakupan = jumlah guru yang sudah disupervisi dibagi jumlah guru '
    . 'aktif; Masa Aktif dibaca dari masa aktif 1 tahun / masa uji coba yang tercatat pada menu '
    . 'Status & Aktivasi.', 'ukuran' => 18, 'jarak' => 60];
$blok[] = ['teks' => 'Dicetak dari ' . APP_NAME . ' v' . APP_VERSION . ' pada ' . tanggal_id(date('Y-m-d')) . '.',
    'ukuran' => 18, 'tengah' => true];

$bytes = docx_bangun($blok, 'Peringkat Sekolah', true);
docx_kirim('Peringkat-Sekolah-' . date('Y-m-d') . '.docx', $bytes);
