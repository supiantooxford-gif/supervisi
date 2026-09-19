<?php
/**
 * Konfigurasi dasar aplikasi Supervisi Guru.
 */
declare(strict_types=1);

define('APP_NAME', 'Supervisi Guru');
define('APP_VERSION', '1.4.5');

// Unggahan berkas pengguna lewat media proxy platform (token dibaca server-side
// dari berkas .vibecoder-media-token; JANGAN pernah dikirim ke browser).
define('MEDIA_ENDPOINT', 'http://127.0.0.1:4310/api/app-media/upload');
define('FOTO_MAKS_BYTE', 15 * 1024 * 1024); // 15 MB (batas platform untuk gambar)
define('FOTO_EKSTENSI', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

// Pembuat aplikasi (tampil pada halaman login).
define('APP_AUTHOR', 'Supianto');
define('APP_AUTHOR_JABATAN', 'Kepala MA. Sirojuth Tholibiin Mootilango');

// Lokasi file database SQLite (di dalam folder aplikasi).
define('DB_FILE', __DIR__ . '/../data/supervisi.sqlite');

// Base URL relatif: aplikasi selalu diakses di bawah subfolder-nya sendiri,
// jadi semua tautan memakai path relatif (tanpa leading slash).
define('BASE', '');

date_default_timezone_set('Asia/Jakarta');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Simpan file sesi di dalam folder aplikasi agar tidak bergantung pada
    // folder sesi sistem yang mungkin tidak dapat ditulis.
    $sessDir = __DIR__ . '/../data/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0775, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }
    session_start();
}
