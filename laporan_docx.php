<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';
require_once __DIR__ . '/inc/docx.php';
require_once __DIR__ . '/inc/laporan.php';

/**
 * UNDUH WORD (.docx) laporan supervisi — isi & urutannya sama dengan lembar cetak
 * `laporan_cetak.php` (kafer, kata pengantar, Bab 1-4, tanda tangan kepala sekolah/madrasah).
 * Berkas .docx disusun sendiri oleh inc/docx.php (tanpa pustaka luar) karena ekstensi zip
 * tidak tersedia di server ini.
 */
$u = require_login();

// Hanya kepala sekolah/madrasah (peran admin) dan Super Admin dalam konteks sekolah.
// Peran diperiksa SEBELUM require_sekolah() supaya guru & pengawas selalu dijawab 403 — bukan
// dialihkan diam-diam ke halaman lain karena belum memilih sekolah.
if ($u['role'] === 'guru' || $u['role'] === 'pengawas') {
    http_response_code(403);
    exit('Unduhan laporan hanya untuk kepala sekolah/madrasah.');
}

$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

if (!is_admin()) {
    http_response_code(403);
    exit('Unduhan laporan hanya untuk kepala sekolah/madrasah.');
}

// Permintaan pemilik: "Menu susun laporan tidak bisa diakses untuk Lembaga yang belum aktifasi."
if (aktivasi_terbatas($sekolah)) {
    aktivasi_halang('laporan', 'laporan_susun.php');
}

$f = laporan_filter($_GET, $sid);
if (!array_key_exists('periode', $_GET)) {
    $f['periode'] = laporan_periode_bawaan($sid);
}

$ringkas = laporan_ringkas($sid, $f);
$guruRingkas = laporan_guru_ringkas($sid, $f);
$daftarSupervisi = laporan_supervisi_list($sid, $f);
$jadwal = laporan_jadwal($sid, $f);
$instrumen = laporan_instrumen($sid, $f);
$guruDaftar = laporan_guru_daftar($sid, $f);
$perGuru = laporan_per_guru($daftarSupervisi);

$kepala = trim(setting('kepala_sekolah', ''));
$nipKepala = trim(setting('nip_kepala_sekolah', ''));
$namaSekolah = setting('nama_sekolah', istilah_sekolah('Sekolah'));
$periodeLabel = str_replace('&middot;', '-', laporan_periode_label($f));

/** Huruf besar tanpa bergantung pada ekstensi mbstring (opsional di server ini). */
function lapd_besar(string $teks): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($teks, 'UTF-8') : strtoupper($teks);
}

/** Judul bab Word (tengah, tebal). */
function lapd_bab(string $teks): array
{
    return ['teks' => $teks, 'tengah' => true, 'tebal' => true, 'ukuran' => 28, 'jarak' => 160];
}

/** Sub-judul bagian Word. */
function lapd_sub(string $teks): array
{
    return ['teks' => $teks, 'tebal' => true, 'ukuran' => 24, 'jarak' => 80];
}

/** Blok paragraf/daftar dari satu bagian laporan (identik isinya dengan lembar cetak). */
function lapd_bagian(int $sid, string $kunci, array $ringkas): array
{
    $bagian = laporan_bagian();
    if (!isset($bagian[$kunci])) {
        return [];
    }
    $teks = laporan_teks_siap($sid, $kunci, $ringkas);
    if (trim($teks) === '') {
        return [];
    }
    $blok = [lapd_sub((string) $bagian[$kunci]['label'])];
    if ($bagian[$kunci]['jenis'] === 'daftar') {
        $n = 0;
        foreach (laporan_butir($teks) as $b) {
            $n++;
            $blok[] = ['teks' => $n . '. ' . $b, 'indent' => 284, 'jarak' => 40];
        }
        $blok[] = ['teks' => '', 'jarak' => 40];
        return $blok;
    }
    foreach (laporan_paragraf($teks) as $p) {
        $blok[] = ['teks' => $p, 'jarak' => 100];
    }
    return $blok;
}

/** Blok tanda tangan (rata kanan) dengan baris "tempat, tanggal" di atas nama. */
function lapd_ttd(string $jabatan, string $nama, string $nip): array
{
    return [
        ['teks' => ''],
        ['teks' => ttd_tempat_tanggal(), 'kanan' => true, 'jarak' => 20],
        ['teks' => $jabatan . ',', 'kanan' => true, 'jarak' => 20],
        ['teks' => ''],
        ['teks' => ''],
        ['teks' => $nama, 'kanan' => true, 'tebal' => true, 'jarak' => 20],
        ['teks' => 'NIP. ' . ($nip !== '' ? $nip : '-'), 'kanan' => true, 'jarak' => 20],
    ];
}

$blok = [];

// ---------------------------------------------------------------- KAFER (sampul)
$blok[] = ['teks' => '', 'jarak' => 120];
$blok[] = ['teks' => lapd_besar($namaSekolah), 'tengah' => true, 'tebal' => true, 'ukuran' => 32, 'jarak' => 60];
foreach (array_slice(kop_baris_identitas(), 0, 1) as $baris) {
    $blok[] = ['teks' => $baris, 'tengah' => true, 'ukuran' => 20, 'jarak' => 20];
}
$blok[] = ['teks' => ''];
$blok[] = ['teks' => ''];
$blok[] = ['teks' => '', 'jarak' => 200];
$blok[] = ['teks' => laporan_teks_siap($sid, 'sampul_judul', $ringkas), 'tengah' => true, 'tebal' => true, 'ukuran' => 36, 'jarak' => 200];
$subJudul = trim(laporan_teks_siap($sid, 'sampul_subjudul', $ringkas));
if ($subJudul !== '') {
    $blok[] = ['teks' => $subJudul, 'tengah' => true, 'ukuran' => 22, 'jarak' => 200];
}
$blok[] = ['teks' => ''];
$blok[] = ['teks' => ''];
$blok[] = ['teks' => 'Disusun oleh:', 'tengah' => true, 'jarak' => 20];
$blok[] = ['teks' => laporan_teks_siap($sid, 'sampul_penyusun', $ringkas), 'tengah' => true, 'tebal' => true, 'jarak' => 20];
$blok[] = ['teks' => $kepala !== '' ? $kepala : '-', 'tengah' => true, 'jarak' => 20];
if ($nipKepala !== '') {
    $blok[] = ['teks' => 'NIP. ' . $nipKepala, 'tengah' => true, 'ukuran' => 20, 'jarak' => 20];
}
$blok[] = ['teks' => $periodeLabel, 'tengah' => true, 'ukuran' => 20, 'jarak' => 20];
$blok[] = ['halaman' => true];

// ---------------------------------------------------------------- KATA PENGANTAR
$blok[] = lapd_bab('KATA PENGANTAR');
$blok = array_merge($blok, lapd_bagian($sid, 'kata_pengantar', $ringkas));
$blok = array_merge($blok, lapd_ttd(istilah_sekolah('Kepala Sekolah'), $kepala !== '' ? $kepala : '-', $nipKepala));
$blok[] = ['halaman' => true];

// ---------------------------------------------------------------- BAB 1
$blok[] = lapd_bab('BAB 1. PENDAHULUAN');
foreach (['latar_belakang', 'dasar_hukum', 'tujuan', 'manfaat'] as $k) {
    $blok = array_merge($blok, lapd_bagian($sid, $k, $ringkas));
}
$blok[] = ['halaman' => true];

// ---------------------------------------------------------------- BAB 2
$blok[] = lapd_bab('BAB 2. PELAKSANAAN SUPERVISI');
$blok[] = ['teks' => str_replace('&middot;', '-', laporan_periode_label($f)), 'tengah' => true, 'ukuran' => 20, 'jarak' => 140];

$blok[] = lapd_sub('A. Daftar Guru yang Disupervisi');
if ($guruDaftar === []) {
    $blok[] = ['teks' => 'Belum ada data guru aktif pada sekolah/madrasah ini.', 'miring' => true];
} else {
    $baris = [];
    foreach ($guruDaftar as $i => $g) {
        $baris[] = [
            (string) ($i + 1), (string) $g['nama'],
            trim((string) $g['nip']) !== '' ? (string) $g['nip'] : '-',
            trim((string) $g['mapel']) !== '' ? (string) $g['mapel'] : '-',
            trim((string) $g['jabatan']) !== '' ? (string) $g['jabatan'] : '-',
            (string) (int) $g['jml'],
        ];
    }
    $baris[] = [['Jumlah guru', true], '', '', '', '', [(string) count($guruDaftar), true]];
    $blok[] = ['tabel' => [['No', 'Nama Guru', 'NIP/NUPTK', 'Mata Pelajaran', 'Jabatan', 'Jml Supervisi'], $baris]];
}

$blok[] = lapd_sub('B. Jadwal Supervisi');
if ($jadwal === []) {
    $blok[] = ['teks' => 'Belum ada jadwal supervisi yang tercatat untuk periode ini.', 'miring' => true];
} else {
    $baris = [];
    foreach ($jadwal as $i => $j) {
        $baris[] = [
            (string) ($i + 1), tanggal_id((string) $j['tanggal']), (string) $j['nama_guru'],
            (string) ($j['mapel'] ?: '-'), (string) ($j['kelas'] ?: '-'), (string) ($j['jam_ke'] ?: '-'),
            (string) ($j['supervisor'] ?: '-'), (string) ($j['keterangan'] ?: '-'),
        ];
    }
    $blok[] = ['tabel' => [['No', 'Tanggal', 'Nama Guru', 'Mapel', 'Kelas', 'Jam ke-', 'Supervisor', 'Keterangan'], $baris]];
}

$blok[] = lapd_sub('C. Instrumen Supervisi');
if ($instrumen === []) {
    $blok[] = ['teks' => 'Belum ada menu supervisi yang aktif.', 'miring' => true];
} else {
    $baris = [];
    foreach ($instrumen as $i => $ins) {
        $baris[] = [
            (string) ($i + 1), (string) $ins['nama'],
            trim((string) $ins['deskripsi']) !== '' ? (string) $ins['deskripsi'] : '-',
            (string) (int) $ins['jml_butir'], (string) (int) $ins['jml'],
        ];
    }
    $blok[] = ['tabel' => [['No', 'Menu / Instrumen Supervisi', 'Deskripsi', 'Jumlah Butir', 'Jumlah Supervisi'], $baris]];
}

foreach (['teknik_analisis', 'prosedur'] as $k) {
    $blok = array_merge($blok, lapd_bagian($sid, $k, $ringkas));
}
$blok[] = ['halaman' => true];

// ---------------------------------------------------------------- BAB 3
$blok[] = lapd_bab('BAB 3. HASIL SUPERVISI');
$blok[] = ['teks' => str_replace('&middot;', '-', laporan_periode_label($f)), 'tengah' => true, 'ukuran' => 20, 'jarak' => 140];

$blok[] = lapd_sub('A. Ringkasan Hasil Supervisi per Guru');
if ($guruRingkas === []) {
    $blok[] = ['teks' => 'Belum ada hasil supervisi yang tercatat untuk penyaring ini.', 'miring' => true];
} else {
    $baris = [];
    foreach ($guruRingkas as $i => $g) {
        $baris[] = [
            (string) ($i + 1), (string) $g['nama'],
            trim((string) $g['nip']) !== '' ? (string) $g['nip'] : '-',
            trim((string) $g['mapel']) !== '' ? (string) $g['mapel'] : '-',
            (string) (int) $g['jml'], r1($g['nilai_rata']),
            (string) $g['predikat'] . ' (' . predikat_label((string) $g['predikat']) . ')',
        ];
    }
    $baris[] = [['Jumlah / rata-rata', true], '', '', '', [(string) (int) $ringkas['supervisi'], true],
                [r1($ringkas['rata']), true], [(string) (int) $ringkas['guru'] . ' guru', true]];
    $blok[] = ['tabel' => [['No', 'Nama Guru', 'NIP/NUPTK', 'Mata Pelajaran', 'Jml Supervisi', 'Nilai', 'Predikat'], $baris]];
}

$blok[] = lapd_sub('B. Rincian Hasil Supervisi Setiap Guru');
if ($perGuru === []) {
    $blok[] = ['teks' => 'Belum ada rincian supervisi untuk penyaring ini.', 'miring' => true];
} else {
    foreach ($perGuru as $nama => $rows) {
        $g0 = $rows[0];
        $blok[] = ['teks' => (string) $nama . ' - NIP ' . (trim((string) $g0['nip']) !== '' ? (string) $g0['nip'] : '-')
            . ' - ' . (trim((string) $g0['mapel']) !== '' ? (string) $g0['mapel'] : 'Mata pelajaran -'), 'tebal' => true, 'jarak' => 60];
        $baris = [];
        foreach ($rows as $i => $s) {
            $baris[] = [
                (string) ($i + 1), (string) $s['instrumen'], tanggal_id((string) $s['tanggal']),
                r1($s['total_skor']) . ' / ' . r1($s['skor_maks']), r1($s['nilai']),
                (string) $s['predikat'],
                "Kelebihan: " . (trim((string) $s['kelebihan']) !== '' ? (string) $s['kelebihan'] : '-')
                    . "\nPerbaikan: " . (trim((string) $s['kekurangan']) !== '' ? (string) $s['kekurangan'] : '-')
                    . "\nTindak lanjut: " . (trim((string) $s['tindak_lanjut']) !== '' ? (string) $s['tindak_lanjut'] : '-'),
            ];
        }
        $blok[] = ['tabel' => [['No', 'Menu Supervisi', 'Tanggal', 'Skor', 'Nilai', 'Predikat', 'Catatan Hasil Supervisi'], $baris]];
    }
}
$blok[] = ['halaman' => true];

// ---------------------------------------------------------------- BAB 4
$blok[] = lapd_bab('BAB 4. KESIMPULAN DAN REKOMENDASI');
foreach (['kesimpulan', 'rekomendasi'] as $k) {
    $blok = array_merge($blok, lapd_bagian($sid, $k, $ringkas));
}
$blok = array_merge($blok, lapd_ttd(istilah_sekolah('Kepala Sekolah'), $kepala !== '' ? $kepala : '-', $nipKepala));

$docx = docx_bangun($blok, 'Laporan Hasil Supervisi Akademik ' . $namaSekolah);
$namaBerkas = 'laporan-supervisi-' . laporan_slug($namaSekolah) . '-' . date('Y-m-d') . '.docx';
docx_kirim($namaBerkas, $docx);
