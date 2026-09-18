<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$pengawas = is_pengawas();

// Pengawas boleh mengunduh rekap sekolah binaannya lewat ?sekolah=ID
// (tanpa mengubah konteks). Sekolah lain ditolak.
$sekolah = null;
if ($pengawas && (int) ($_GET['sekolah'] ?? 0) > 0) {
    $minta = (int) $_GET['sekolah'];
    if (!pengawas_punya((int) $u['id'], $minta)) {
        http_response_code(403);
        exit('Sekolah itu bukan sekolah binaan Anda.');
    }
    if ((int) val('SELECT COUNT(*) FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"', [$minta], 0) === 0) {
        http_response_code(403);
        exit('Sekolah itu sudah dinonaktifkan sehingga rekapitulasinya tidak dapat dibuka. Hubungi Super Admin.');
    }
    // Pakai sekolah yang diminta sebagai konteks: ini endpoint unduhan (tidak merender UI),
    // jadi aman memindahkan konteks sesaat supaya istilah istilah_sekolah()/setting() dan
    // kop CSV benar-benar mengikuti sekolah tersebut.
    if (sekolah_id() !== $minta) {
        set_sekolah_context($minta);
    }
    $sekolah = one('SELECT * FROM sekolah WHERE id = ?', [$minta]);
}
if (!$sekolah) {
    $sekolah = require_sekolah();
}
$sid = (int) $sekolah['id'];
$admin = is_admin();
$bolehFilterGuru = $admin || $pengawas;

$fGuru = (int) ($_GET['guru'] ?? 0);
$fIns = (string) ($_GET['instrumen'] ?? '');
$fDari = (string) ($_GET['dari'] ?? '');
$fSampai = (string) ($_GET['sampai'] ?? '');
$fPred = (string) ($_GET['predikat'] ?? '');
$fStatus = (string) ($_GET['status'] ?? 'final');

if ($u['role'] === 'guru') {
    $fGuru = (int) ($u['guru_id'] ?? 0);
}

$w = ['s.sekolah_id = ?'];
$p = [$sid];
if ($fGuru > 0) {
    $w[] = 's.guru_id = ?';
    $p[] = $fGuru;
}
if ($fIns !== '' && one('SELECT id FROM instrumen WHERE kode = ?', [$fIns])) {
    $w[] = 'i.kode = ?';
    $p[] = $fIns;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDari)) {
    $w[] = 's.tanggal >= ?';
    $p[] = $fDari;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fSampai)) {
    $w[] = 's.tanggal <= ?';
    $p[] = $fSampai;
}
if (in_array($fPred, ['A', 'B', 'C', 'D'], true)) {
    $w[] = 's.predikat = ?';
    $p[] = $fPred;
}
if ($fStatus === 'final' || $fStatus === 'draft') {
    $w[] = 's.status = ?';
    $p[] = $fStatus;
}
$where = implode(' AND ', $w);

$rows = all("SELECT s.tanggal, g.nama guru, g.nip, g.mapel, g.jabatan, i.nama instrumen, s.periode, s.supervisor,
                    s.total_skor, s.skor_maks, s.nilai, s.predikat, s.status, s.kelebihan, s.kekurangan, s.tindak_lanjut
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE $where ORDER BY s.tanggal DESC, g.nama", $p);

$namaFile = 'rekap-supervisi-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $namaFile . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// BOM agar Excel membaca UTF-8 dengan benar
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [istilah_sekolah('Sekolah'), (string) ($sekolah['nama'] ?? '-')], ';');  // nama resmi apa adanya
fputcsv($out, ['NPSN', (string) ($sekolah['npsn'] ?? '-')], ';');
fputcsv($out, [istilah_sekolah('Kepala Sekolah'), (string) ($sekolah['kepala_sekolah'] ?? '-')], ';');
fputcsv($out, ['Tahun Ajaran', (string) ($sekolah['tahun_ajaran'] ?? '-') . ' - Semester ' . (string) ($sekolah['semester'] ?? '-')], ';');
fputcsv($out, ['Diunduh oleh', (string) ($u['nama'] ?? '-') . ' (' . ($u['role'] === 'pengawas' ? 'Pengawas Sekolah' : ($u['role'] === 'super' ? 'Super Admin' : ($u['role'] === 'admin' ? 'Administrator sekolah' : 'Guru'))) . ')'], ';');
fputcsv($out, ['Tanggal unduh', date('d/m/Y H:i')], ';');
fputcsv($out, [], ';');

fputcsv($out, [
    'No', 'Tanggal', 'Nama Guru', 'NIP', 'Mata Pelajaran', 'Jabatan', 'Menu Supervisi', 'Periode',
    'Supervisor', 'Total Skor', 'Skor Maksimal', 'Nilai Akhir', 'Predikat', 'Kriteria', 'Status',
    'Kelebihan', 'Kekurangan', 'Tindak Lanjut',
], ';');

$no = 0;
$sumNilai = 0.0;
foreach ($rows as $r) {
    $no++;
    $sumNilai += (float) $r['nilai'];
    fputcsv($out, [
        $no,
        date('d/m/Y', strtotime((string) $r['tanggal'])),
        $r['guru'],
        $r['nip'],
        $r['mapel'],
        $r['jabatan'],
        $r['instrumen'],
        $r['periode'],
        $r['supervisor'],
        $r['total_skor'],
        $r['skor_maks'],
        number_format((float) $r['nilai'], 2, ',', '.'),
        $r['predikat'],
        predikat_label((string) $r['predikat']),
        $r['status'] === 'final' ? 'Final' : 'Draf',
        $r['kelebihan'],
        $r['kekurangan'],
        $r['tindak_lanjut'],
    ], ';');
}

fputcsv($out, [], ';');
fputcsv($out, ['Jumlah data', $no], ';');
fputcsv($out, ['Rata-rata nilai', number_format($no > 0 ? $sumNilai / $no : 0, 2, ',', '.')], ';');

fclose($out);
