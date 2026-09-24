<?php
/**
 * Unggah / hapus BUKTI FOTO pengamatan pembelajaran pada satu data supervisi.
 *
 * Dipakai oleh menu "Supervisi Pengamatan Pembelajaran" saja (permintaan pemilik),
 * maksimal SUPERVISI_FOTO_MAKS (2) foto per supervisi.
 *
 * FORMULIR BIASA (multipart) — inilah jalur utama yang dipakai halaman:
 *   - `foto[]`  : satu ATAU BEBERAPA berkas sekaligus (permintaan pemilik: 2 foto sekali kirim)
 *   - `foto`    : satu berkas (kompatibilitas)
 *   - `aksi`    : `unggah` | `hapus`
 *   - `kembali` : `form` (form penilaian) atau `detail` (halaman detail)
 * Balasan: flash + redirect (pola aplikasi ini). Balasan JSON hanya dipakai bila permintaan
 * memuat `format=json` (dipakai skrip uji / klien non-JS) — halaman TIDAK bergantung pada itu.
 * Jalur raw body (`X-CSRF-Token` + `nama_berkas`) tetap didukung untuk satu berkas.
 *
 * Berkas diteruskan ke media proxy platform dari sisi server (media_upload() di
 * inc/helpers.php); token unggah TIDAK pernah dikirim ke browser. Yang disimpan ke
 * basis data hanya URL CDN hasil respons (tabel `supervisi_foto`).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_admin();              // admin/kepala sekolah, atau super admin dalam konteks sekolah
$sid = sekolah_id();

// Jalur raw body membuat $_POST KOSONG, jadi parameter dibaca dari POST MAUPUN GET.
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

$supId = (int) $param('supervisi_id');
$s = $supId > 0
    ? one('SELECT s.id, s.sekolah_id, i.kode FROM supervisi s JOIN instrumen i ON i.id = s.instrumen_id
           WHERE s.id = ? AND s.sekolah_id = ?', [$supId, $sid])
    : null;

// Tujuan kembali selalu DIBANGUN SENDIRI dari nilai enum — nilai dari pengguna tidak dipakai
// langsung sebagai URL (mencegah pengalihan terbuka).
$tempat = $param('kembali') === 'detail' ? 'detail' : 'form';
$kembali = $supId > 0 ? ($tempat === 'detail'
    ? 'supervisi_detail.php?id=' . $supId . '#cardFotoPengamatan'
    : 'supervisi_form.php?kode=' . urlencode((string) ($s['kode'] ?? SUPERVISI_FOTO_KODE)) . '&id=' . $supId . '#cardFotoPengamatan')
    : 'supervisi.php?kode=' . SUPERVISI_FOTO_KODE;

/** Gagal: balas JSON (bila diminta), kode HTTP untuk kesalahan keamanan, atau flash + kembali. */
$gagal = static function (string $pesan, int $kode = 400) use ($json, $kembali): void {
    if ($json) {
        http_response_code($kode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'pesan' => $pesan]);
        exit;
    }
    // Sesi kedaluwarsa / permintaan tidak sah (403, 404, 419) selalu dijawab dengan kode HTTP-nya;
    // hanya kesalahan isian yang dikembalikan ke halaman lewat flash.
    if (in_array($kode, [403, 404, 419], true)) {
        http_response_code($kode);
        exit($pesan);
    }
    flash($pesan, 'err');
    redirect($kembali);
};

/** Balas sukses (JSON bila diminta) atau flash + kembali ke kartu foto. */
$sukses = static function (string $pesan, array $data = []) use ($json, $kembali): void {
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => true, 'pesan' => $pesan], $data));
        exit;
    }
    flash($pesan);
    redirect($kembali);
};

if (!$s) {
    $gagal('Data supervisi tidak ditemukan pada sekolah ini.', 404);
}
if (!supervisi_foto_berlaku((string) $s['kode'])) {
    $gagal('Bukti foto hanya tersedia pada menu Supervisi Pengamatan Pembelajaran.', 403);
}
if (!csrf_ok()) {
    // Tanpa CSRF: 419 (pola yang sama dengan halaman lain), atau JSON bila diminta JS.
    $gagal('Sesi Anda kedaluwarsa. Muat ulang halaman lalu coba lagi.', 419);
}

$aksiv = (string) ($param('aksi') ?? 'unggah');
$maks = (int) SUPERVISI_FOTO_MAKS;

// ---------------- HAPUS satu foto ----------------
if ($aksiv === 'hapus') {
    $fid = (int) $param('id');
    $f = $fid > 0 ? one('SELECT * FROM supervisi_foto WHERE id = ? AND supervisi_id = ?', [$fid, $supId]) : null;
    if (!$f) {
        $gagal('Foto tidak ditemukan.', 404);
    }
    q('DELETE FROM supervisi_foto WHERE id = ? AND supervisi_id = ?', [$fid, $supId]);
    supervisi_foto_urutkan_ulang($supId);
    $sukses('Foto bukti dihapus. Sisa ' . supervisi_foto_jumlah($supId) . ' dari ' . $maks . ' foto.',
        ['jumlah' => supervisi_foto_jumlah($supId)]);
}

if ($aksiv !== 'unggah') {
    $gagal('Aksi tidak dikenal.', 400);
}

// ---------------- Batas maksimal (ditegakkan di SERVER, bukan hanya oleh JavaScript) ----------------
// $_POST & $_FILES kosong padahal ada isi permintaan = melebihi post_max_size (PHP membuangnya
// tanpa pesan) — kejadian nyata saat mengirim 2 foto berukuran besar sekaligus.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $gagal('Ukuran total foto melebihi batas kirim server (' . ini_get('post_max_size') . '). Pilih foto yang lebih kecil, atau unggah satu per satu.');
}

$jumlahAwal = supervisi_foto_jumlah($supId);
if ($jumlahAwal >= $maks) {
    $gagal('Bukti foto sudah mencapai batas maksimal ' . $maks . ' foto. Hapus salah satu foto terlebih dahulu untuk menggantinya.');
}

// ---------------- Kumpulkan berkas: foto[], foto, atau raw body ----------------
$daftar = [];          // tiap butir: nama, ukuran, tmp, err, sementara
$sementaraSemua = [];  // berkas sementara buatan kita (perlu dibersihkan)

if (!empty($_FILES['foto']['name'])) {
    $f = $_FILES['foto'];
    if (is_array($f['name'])) {
        $n = count($f['name']);
        for ($i = 0; $i < $n; $i++) {
            $err = (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $nama = (string) ($f['name'][$i] ?? '');
            if ($err === UPLOAD_ERR_NO_FILE && $nama === '') {
                continue;
            }
            $daftar[] = [
                'nama' => $nama,
                'ukuran' => (int) ($f['size'][$i] ?? 0),
                'tmp' => (string) ($f['tmp_name'][$i] ?? ''),
                'err' => $err,
                'sementara' => false,
            ];
        }
    } else {
        $daftar[] = [
            'nama' => (string) $f['name'],
            'ukuran' => (int) $f['size'],
            'tmp' => (string) $f['tmp_name'],
            'err' => (int) $f['error'],
            'sementara' => false,
        ];
    }
} else {
    // Jalur raw body (satu berkas) — dipakai skrip uji / klien sederhana.
    $namaAsli = trim((string) ($param('nama_berkas') ?? ''));
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $gagal('Tidak ada berkas foto yang dikirim. Pilih foto terlebih dahulu.');
    }
    if ($namaAsli === '') {
        $namaAsli = 'bukti-pengamatan.png';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'supfoto');
    if ($tmp === false || file_put_contents($tmp, $raw) === false) {
        $gagal('Gagal menyiapkan berkas sementara di server.', 500);
    }
    $sementaraSemua[] = $tmp;
    $daftar[] = ['nama' => $namaAsli, 'ukuran' => strlen($raw), 'tmp' => $tmp, 'err' => UPLOAD_ERR_OK, 'sementara' => true];
}

if (!$daftar) {
    $gagal('Tidak ada berkas foto yang dipilih. Pilih foto terlebih dahulu (boleh dua sekaligus).');
}

// ---------------- Simpan berkas satu per satu, maksimal $maks foto ----------------
$berhasil = 0;
$gagalPesan = [];
$dilewatiKarenaBatas = 0;
$tersisa = $maks - $jumlahAwal;
$now = date('Y-m-d H:i:s');

foreach ($daftar as $b) {
    if ($tersisa <= 0) {
        $dilewatiKarenaBatas++;
        continue;
    }
    if ($b['err'] !== UPLOAD_ERR_OK) {
        $pesanSisiServer = ($b['err'] === UPLOAD_ERR_INI_SIZE || $b['err'] === UPLOAD_ERR_FORM_SIZE)
            ? 'terlalu besar untuk diunggah (batas server ' . ini_get('upload_max_filesize') . ')'
            : 'gagal diunggah (kode ' . $b['err'] . ')';
        $gagalPesan[] = $b['nama'] . ': ' . $pesanSisiServer;
        continue;
    }

    $cek = validasi_foto((string) $b['nama'], (int) $b['ukuran'], (string) $b['tmp']);
    if (empty($cek['ok'])) {
        $gagalPesan[] = $b['nama'] . ': ' . $cek['pesan'];
        continue;
    }

    $bytes = @file_get_contents((string) $b['tmp']);
    if ($bytes === false || $bytes === '') {
        $gagalPesan[] = $b['nama'] . ': gagal dibaca di server';
        continue;
    }

    $hasil = media_upload($bytes, (string) $b['nama']);
    if (empty($hasil['ok'])) {
        $gagalPesan[] = $b['nama'] . ': ' . (string) ($hasil['pesan'] ?? 'gagal diunggah');
        continue;
    }

    $urutan = supervisi_foto_jumlah($supId) + 1;
    q('INSERT INTO supervisi_foto (supervisi_id, url, urutan, dibuat) VALUES (?,?,?,?)',
        [$supId, (string) $hasil['url'], $urutan, $now]);
    $berhasil++;
    $tersisa--;
}

// Bersihkan berkas sementara milik kita sendiri (unggahan $_FILES sudah diurus PHP).
foreach ($sementaraSemua as $tmp) {
    @unlink($tmp);
}

$jmlAkhir = supervisi_foto_jumlah($supId);

// ---------------- Pesan hasil ----------------
if ($berhasil <= 0) {
    $pesan = 'Foto gagal diunggah. ' . implode('; ', array_slice($gagalPesan, 0, 3));
    if ($dilewatiKarenaBatas > 0) {
        $pesan .= ' ' . $dilewatiKarenaBatas . ' berkas tidak diproses karena batas maksimal ' . $maks . ' foto sudah tercapai.';
    }
    $gagal($pesan);
}

$pesan = $berhasil . ' bukti foto pengamatan tersimpan (total ' . $jmlAkhir . ' dari ' . $maks . ' foto).';
if ($jmlAkhir >= $maks) {
    $pesan .= ' Batas maksimal sudah tercapai.';
}
if ($dilewatiKarenaBatas > 0) {
    $pesan .= ' ' . $dilewatiKarenaBatas . ' berkas tidak diproses karena batas maksimal ' . $maks . ' foto.';
}
if ($gagalPesan) {
    $pesan .= ' Sebagian gagal: ' . implode('; ', array_slice($gagalPesan, 0, 3));
}

$sukses($pesan, ['jumlah' => $jmlAkhir, 'tersimpan' => $berhasil]);
