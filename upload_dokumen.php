<?php
/**
 * Unggah / hapus DOKUMEN MADRASAH yang dikirim kepada pengawas.
 *
 * Permintaan pemilik: "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen ke
 * pengawas dengan inputan: Nama Dokumen (Pilihan: ...), Unggah Berkas, Keterangan."
 * Pilihan pemilik: yang boleh mengunggah HANYA kepala sekolah/madrasah (role `admin`).
 *
 * Jalur UTAMA halaman adalah FORM MULTIPART BIASA (`berkas` + `nama_jenis`/`jenis_id` + `keterangan`),
 * supaya unggahan tetap berjalan walau skrip gagal dimuat. Jalur raw body (`X-CSRF-Token` +
 * `nama_berkas`) tetap didukung untuk klien non-JS/skrip uji.
 *
 * Berkas diteruskan ke media proxy platform dari sisi server (media_upload()); token unggah TIDAK
 * pernah dikirim ke browser. Yang disimpan ke basis data HANYA URL CDN hasil respons
 * (tabel `dokumen_madrasah`).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
// HANYA kepala sekolah/madrasah (admin) — dan Super Admin dalam konteks sekolah (pemilik aplikasi,
// pola yang sama dengan halaman pengelolaan lain). Guru/operator/pengawas ditolak.
if ($u['role'] !== 'admin' && !is_admin()) {
    http_response_code(403);
    exit('Hanya kepala sekolah/madrasah yang dapat mengirim dokumen ke pengawas.');
}
$sekolah = require_sekolah();
$sid = (int) $sekolah['id'];

// Jalur raw body ⇒ $_POST kosong: parameter dibaca dari POST MAUPUN GET.
$param = static function (string $k) {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : $v;
};
$json = ($param('format') === 'json') || isset($_SERVER['HTTP_X_REQUESTED_WITH']);

if (empty($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}
if (empty($_GET['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_GET['csrf'] = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}

$kembali = 'dokumen_kirim.php';

/** Gagal: JSON (bila diminta), kode HTTP untuk kegagalan keamanan, atau flash + kembali. */
$gagal = static function (string $pesan, int $kode = 400) use ($json, $kembali): void {
    if ($json) {
        http_response_code($kode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'pesan' => $pesan]);
        exit;
    }
    if (in_array($kode, [403, 404, 419], true)) {
        http_response_code($kode);
        exit($pesan);
    }
    flash($pesan, 'err');
    redirect($kembali);
};

/** Balas sukses (JSON bila diminta) atau flash + kembali ke daftar dokumen. */
$sukses = static function (string $pesan, array $data = []) use ($json, $kembali): void {
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => true, 'pesan' => $pesan], $data));
        exit;
    }
    flash($pesan);
    redirect($kembali);
};

if (!csrf_ok()) {
    $gagal('Sesi Anda kedaluwarsa. Muat ulang halaman lalu coba lagi.', 419);
}

$aksi = (string) ($param('aksi') ?? 'unggah');

// ---------------- HAPUS satu dokumen ----------------
if ($aksi === 'hapus') {
    $did = (int) $param('id');
    $d = dokumen_by_id($did, $sid);      // WAJIB milik sekolah ini (anti-IDOR)
    if (!$d) {
        $gagal('Dokumen tidak ditemukan pada sekolah/madrasah ini.', 404);
    }
    q('DELETE FROM dokumen_madrasah WHERE id = ? AND sekolah_id = ?', [$did, $sid]);
    $sukses('Dokumen "' . (string) $d['nama_dokumen'] . '" dihapus dari daftar kiriman.');
}

if ($aksi !== 'unggah') {
    $gagal('Aksi tidak dikenal.');
}

// ---------------- Batas mode terbatas: 1 dokumen kiriman ke pengawas ----------------
// Permintaan pemilik: "Lembaga yang belum aktifasi batasi hanya bisa mengirim 1 dokumen saja ke
// pengawas." Ditegakkan di SERVER (bukan hanya di tampilan) sehingga permintaan langsung ke
// endpoint ini pun ditolak. Menghapus dokumen (aksi `hapus`) tetap diperbolehkan.
if (batas_dokumen_tercapai($sid)) {
    aktivasi_halang('dokumen', 'dokumen_kirim.php');
}

// ---------------- Bila total kiriman melebihi post_max_size ----------------
// PHP membuang SELURUH $_POST & $_FILES tanpa pesan → halaman terlihat "tidak terjadi apa-apa".
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $gagal('Ukuran kiriman melebihi batas server (' . ini_get('post_max_size') . '). '
        . 'Pilih berkas yang lebih kecil (batas ' . ukuran_teks(dokumen_batas_server()) . ').');
}

// ---------------- Tahun ajaran dokumen (permintaan pemilik, skema 22) ----------------
// Nilai WAJIB termasuk pilihan yang sah (pilihan beberapa tahun ajaran). Bila kosong/tidak dikenal,
// dipakai tahun ajaran aktif sekolah supaya dokumennya tidak pernah kehilangan periodenya.
$taKirim = dokumen_ta_normalisasi((string) ($param('tahun_ajaran') ?? ''));
if ($taKirim !== '' && !in_array($taKirim, dokumen_tahun_pilihan($sekolah), true)) {
    $gagal('Tahun ajaran "' . $taKirim . '" tidak termasuk pilihan yang tersedia.');
}
if ($taKirim === '') {
    $taKirim = dokumen_ta_normalisasi((string) ($sekolah['tahun_ajaran'] ?? ''));
}

// ---------------- Nama dokumen ----------------
$jenisId = (int) $param('jenis_id');
$namaKirim = komentar_potong((string) ($param('nama') ?? ''), 120);
$keterangan = komentar_potong((string) ($param('keterangan') ?? ''), (int) DOKUMEN_KET_MAKS);

$jenis = $jenisId > 0 ? dokumen_jenis_by_id($jenisId) : null;
if ($jenis && (int) $jenis['aktif'] !== 1) {
    // Jenis yang dinonaktifkan tidak boleh dipakai untuk kiriman baru.
    $gagal('Jenis dokumen "' . (string) $jenis['nama'] . '" sudah dinonaktifkan. Pilih nama dokumen lain.');
}
if ($namaKirim === '' && $jenis) {
    $namaKirim = (string) $jenis['nama'];
}
if ($namaKirim === '') {
    $gagal('Pilih Nama Dokumen terlebih dahulu.');
}

// ---------------- Ambil berkas: multipart (utama) atau raw body (cadangan) ----------------
$namaAsli = '';
$ukuran = 0;
$tmpPath = '';
$sementara = false;

if (!empty($_FILES['berkas']['name'])) {
    $f = $_FILES['berkas'];
    $kode = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($kode !== UPLOAD_ERR_OK) {
        $pesan = ($kode === UPLOAD_ERR_INI_SIZE || $kode === UPLOAD_ERR_FORM_SIZE)
            ? 'Berkas terlalu besar untuk diunggah (batas server ' . ini_get('upload_max_filesize')
              . '). Pilih berkas yang lebih kecil.'
            : 'Gagal mengunggah berkas (kode ' . $kode . '). Silakan coba lagi.';
        $gagal($pesan);
    }
    $namaAsli = (string) $f['name'];
    $ukuran = (int) $f['size'];
    $tmpPath = (string) $f['tmp_name'];
} else {
    $namaAsli = (string) ($param('nama_berkas') ?? '');
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $gagal('Belum ada berkas yang dipilih. Pilih berkas dokumen terlebih dahulu.');
    }
    $ukuran = strlen($raw);
    if ($namaAsli === '') {
        $namaAsli = 'dokumen-madrasah.pdf';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dokmadrasah');
    if ($tmp === false || file_put_contents($tmp, $raw) === false) {
        $gagal('Gagal menyiapkan berkas sementara di server.', 500);
    }
    $tmpPath = $tmp;
    $sementara = true;
}

// ---------------- Validasi (ekstensi + ukuran + ISI berkas) ----------------
$cek = validasi_dokumen($namaAsli, $ukuran, $tmpPath);
if (empty($cek['ok'])) {
    if ($sementara) {
        @unlink($tmpPath);
    }
    $gagal((string) $cek['pesan']);
}

$bytes = file_get_contents($tmpPath);
if ($sementara) {
    @unlink($tmpPath);
}
if ($bytes === false || $bytes === '') {
    $gagal('Gagal membaca berkas di server.', 500);
}

// ---------------- Kirim ke media proxy & simpan URL ----------------
$hasil = media_upload($bytes, $namaAsli);
if (empty($hasil['ok'])) {
    $gagal((string) ($hasil['pesan'] ?? 'Dokumen gagal diunggah.'));
}

q('INSERT INTO dokumen_madrasah (sekolah_id, jenis_id, nama_dokumen, keterangan, url, nama_berkas, ukuran, pengirim, pengirim_id, tahun_ajaran, dibuat)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    [$sid, $jenis ? (int) $jenis['id'] : null, $namaKirim, $keterangan, (string) $hasil['url'],
     $namaAsli, $ukuran, (string) ($u['nama'] ?? ''), (int) $u['id'], $taKirim !== '' ? $taKirim : null,
     date('Y-m-d H:i:s')]);

$sukses('Dokumen "' . $namaKirim . '" berhasil dikirim ke pengawas '
    . ($jenis ? '(' . (string) $jenis['nama'] . ')' : '')
    . ($taKirim !== '' ? ' untuk T.A. ' . $taKirim : '')
    . '. Berkas ' . $namaAsli . ' (' . ukuran_teks($ukuran) . ').',
    ['id' => (int) db()->lastInsertId(), 'url' => (string) $hasil['url'], 'tahun_ajaran' => $taKirim]);
