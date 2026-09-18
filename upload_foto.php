<?php
/**
 * Unggah / hapus foto profil pengguna (admin, kepala sekolah, guru, super admin).
 *
 * Berkas diteruskan ke media proxy platform dari sisi server (lihat media_upload()
 * di inc/helpers.php). Token unggah TIDAK pernah dikirim ke browser.
 *
 * Menerima dua cara pengiriman:
 *   - form multipart biasa ($_FILES['foto'])
 *   - raw body (fetch/JS) dengan header X-CSRF-Token dan field nama_berkas
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$kembali = 'akun.php';

// Terima CSRF dari field form (multipart) atau header (unggahan fetch raw).
if (empty($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}
csrf_guard();

// ---------- hapus foto ----------
if (($_POST['aksi'] ?? '') === 'hapus') {
    q('UPDATE users SET foto = NULL WHERE id = ?', [(int) $u['id']]);
    flash('Foto profil dihapus. Anda kembali memakai avatar inisial nama.', 'warn');
    redirect($kembali);
}

// ---------- ambil berkas dari server, apa pun cara pengirimannya ----------
$namaAsli = '';
$ukuran = 0;
$tmpPath = '';
$sementara = false; // true bila berkas sementara buatan kita sendiri (perlu dibersihkan)

if (!empty($_FILES['foto']['name'])) {
    $f = $_FILES['foto'];
    $kode = (int) $f['error'];
    if ($kode !== UPLOAD_ERR_OK) {
        $pesan = ($kode === UPLOAD_ERR_INI_SIZE || $kode === UPLOAD_ERR_FORM_SIZE)
            ? 'Foto terlalu besar untuk diunggah (batas server ' . ini_get('upload_max_filesize') . '). Perkecil dulu fotonya.'
            : 'Gagal mengunggah foto (kode ' . $kode . '). Silakan coba lagi.';
        flash($pesan, 'err');
        redirect($kembali);
    }
    $namaAsli = (string) $f['name'];
    $ukuran = (int) $f['size'];
    $tmpPath = (string) $f['tmp_name'];
} else {
    $namaAsli = trim((string) ($_POST['nama_berkas'] ?? ''));
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        flash('Tidak ada berkas yang dikirim. Pilih foto terlebih dahulu.', 'err');
        redirect($kembali);
    }
    $ukuran = strlen($raw);
    if ($namaAsli === '') {
        $namaAsli = 'foto-profil.jpg';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'fotoupload');
    if ($tmp === false || file_put_contents($tmp, $raw) === false) {
        flash('Gagal menyiapkan berkas sementara di server.', 'err');
        redirect($kembali);
    }
    $tmpPath = $tmp;
    $sementara = true;
}

$cek = validasi_foto($namaAsli, $ukuran, $tmpPath);
if (empty($cek['ok'])) {
    if ($sementara) {
        @unlink($tmpPath);
    }
    flash((string) $cek['pesan'], 'err');
    redirect($kembali);
}

$bytes = file_get_contents($tmpPath);
if ($sementara) {
    @unlink($tmpPath);
}
if ($bytes === false) {
    flash('Gagal membaca berkas foto di server.', 'err');
    redirect($kembali);
}

$hasil = media_upload($bytes, $namaAsli);
if (empty($hasil['ok'])) {
    flash((string) ($hasil['pesan'] ?? 'Foto gagal diunggah.'), 'err');
    redirect($kembali);
}

q('UPDATE users SET foto = ? WHERE id = ?', [(string) $hasil['url'], (int) $u['id']]);
flash('Foto profil berhasil diperbarui.');
redirect($kembali);
