<?php
/**
 * Titik masuk tunggal untuk semua halaman.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
// TEMA TAMPILAN WARNA APLIKASI (permintaan pemilik): pilihan tema oleh Super Admin yang dipakai
// SELURUH halaman (halaman depan/landing sampai halaman akun). Dimuat sebelum db.php karena
// helper-nya (tema_kelas()) dibutuhkan layout/login/daftar sejak markup pertama.
require_once __DIR__ . '/tema.php';
// Komentar pengawas & dokumen madrasah: dimuat SEBELUM db.php supaya ensure_schema() (yang
// memanggil dokumen_jenis_bawaan() saat menyemai jenis dokumen) selalu menemukan fungsinya
// walaupun db() dipanggil pertama kali dari berkas lain.
require_once __DIR__ . '/komentar_dokumen.php';
// OBROLAN (CHAT) — modulnya dipakai layout (kotak mengambang) DAN endpoint `chat.php`, jadi
// dimuat di sini (bukan hanya dari salah satunya).
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/db.php';
// MENU "SUSUN LAPORAN" (permintaan pemilik): template laporan (dapat disunting & disimpan per
// sekolah), penyaring periode/rentang, dan seluruh data yang dipakai halaman susun laporan,
// lembar cetak A4, serta unduhan Word.
require_once __DIR__ . '/laporan.php';
require_once __DIR__ . '/layout.php';
