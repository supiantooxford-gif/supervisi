<?php
/**
 * Konfigurasi dasar aplikasi Supervisi Guru.
 */
declare(strict_types=1);

define('APP_NAME', 'Supervisi Guru');
define('APP_VERSION', '1.26.1');

// Unggahan berkas pengguna lewat media proxy platform (token dibaca server-side
// dari berkas .vibecoder-media-token; JANGAN pernah dikirim ke browser).
define('MEDIA_ENDPOINT', 'http://127.0.0.1:4310/api/app-media/upload');
define('FOTO_MAKS_BYTE', 15 * 1024 * 1024); // 15 MB (batas platform untuk gambar)
define('FOTO_EKSTENSI', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

// ---------------------------------------------------------------------------------------------
// KOMENTAR / TINDAK LANJUT PENGAWAS & DOKUMEN MADRASAH (permintaan pemilik)
// ---------------------------------------------------------------------------------------------
// "Pada akun pengawas tambahkan fitur untuk mengomentari seluruh hasil rekapan supervisi setiap
//  madrasah dan terkirim ke akun kepala madrasah untuk dilihat hasil komentar atau tindak lanjut."
// "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen ke pengawas ... Pilihan
//  nama dokumen bisa dirubah/di edit/ dihapus melalui akun super admin."
define('KOMENTAR_MAKS', 3000);   // panjang maksimal satu komentar pengawas
// Status TINJAUAN dokumen oleh pengawas (permintaan pemilik): disetujui / direvisi / ditolak,
// masing-masing dengan keterangan atau alasan. 'menunggu' = belum ditinjau.
define('DOKUMEN_CATATAN_MAKS', 1000); // panjang maksimal keterangan/alasan tinjauan
define('DOKUMEN_KET_MAKS', 1500); // panjang maksimal keterangan pada dokumen

// Jenis dokumen BAWAAN (dipakai sekali saat tabel `dokumen_jenis` masih kosong). Setelah itu
// daftarnya hanya diubah Super Admin lewat halaman Kelola Jenis Dokumen.
define('DOKUMEN_JENIS_BAWAAN', [
    'Kurikulum Madrasah (KM)',
    'Rencana Kerja Tahunan (RKT)',
    'Rencana Kerja Jangka Menengah (RKJM)',
    'Laporan Supervisi',
    'EDM/RKAM',
    'PKG & PTK',
]);

// Unggahan dokumen: mengikuti batas PLATFORM (dokumen 50 MB, gambar 15 MB) yang kemudian
// dipersempit lagi oleh batas server (upload_max_filesize/post_max_size) lewat dokumen_batas_server().
define('DOKUMEN_EKSTENSI_GAMBAR', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('DOKUMEN_EKSTENSI_ARSIP', ['pdf', 'docx', 'xlsx', 'pptx', 'csv', 'txt', 'zip']);
define('DOKUMEN_MAKS_GAMBAR', 15 * 1024 * 1024);   // 15 MB (batas platform untuk gambar)
define('DOKUMEN_MAKS_ARSIP', 50 * 1024 * 1024);    // 50 MB (batas platform untuk dokumen)

// Bukti foto pengamatan pembelajaran (permintaan pemilik): hanya pada menu bawaan
// "Supervisi Pengamatan Pembelajaran" (kode `pengamatan`), maksimal 2 foto per supervisi.
define('SUPERVISI_FOTO_KODE', 'pengamatan');
define('SUPERVISI_FOTO_MAKS', 2);

// Isi "Tentang Aplikasi" pada HALAMAN MASUK (permintaan pemilik): dua tempat foto ukuran 3x4,
// masing-masing dengan nama di bawahnya, ditambah satu isian keterangan/penjelasan di bawah
// kedua foto. Seluruh isinya diinput Super Admin pada halaman `tentang.php` dan disimpan pada
// tabel `app_pengaturan` (tingkat aplikasi — halaman masuk dipakai semua sekolah/madrasah).
define('TENTANG_NAMA_MAKS', 80);    // panjang maksimal nama pada tiap tempat foto
define('TENTANG_KET_MAKS', 1500);   // panjang maksimal keterangan/penjelasan
define('TENTANG_FOTO_RASIO', '3:4'); // ukuran foto 3x4 (potret) — informasi pada halaman Super Admin

// ---------------------------------------------------------------------------------------------
// AKTIVASI SEKOLAH (permintaan pemilik): "Buatkan kode aktifasi untuk sekolah yang mendaftar.
// Bedanya jika belum diaktifasi tidak semua fitur bisa digunakan atau dibatasi. Pembatasan pada
// hanya bisa input 1 data guru dan hanya bisa melakukan satu kali supervisi pada setiap menu
// supervisi. Jika melebihi muncul pop up aplikasi belum diaktifasi."
//
// Sekolah yang MENDAFTAR SENDIRI (daftar.php) masuk keadaan `dibatasi`; sekolah yang dibuat
// Super Admin dan seluruh sekolah LAMA (sebelum fitur ini) berkeadaan `aktif` supaya data yang
// sudah berjalan tidak ikut terbatasi. Batas di bawah hanya berlaku pada keadaan `dibatasi`.
define('AKTIVASI_MAKS_GURU', 1);            // maksimal data guru
define('AKTIVASI_MAKS_SUPERVISI_MENU', 1);  // maksimal supervisi per menu supervisi
define('AKTIVASI_MAKS_JADWAL', 1);          // maksimal baris jadwal supervisi
// Permintaan pemilik (pembaruan): "Lembaga yang belum aktifasi batasi hanya bisa mengirim 1 dokumen
// saja ke pengawas" dan "Menu susun laporan tidak bisa diakses untuk Lembaga yang belum aktifasi".
define('AKTIVASI_MAKS_DOKUMEN', 1);         // maksimal dokumen kiriman ke pengawas
// MASA AKTIF aplikasi setelah diaktivasi (permintaan pemilik): "Pastikan aplikasi aktif dengan
// terbuka semua fitur dengan masa aktif 1 tahun (365 hari). Buat hitungan mundur. Dan jika masa
// aktif habis bisa menghubungi kembali HD Admin untuk meminta kode aktifasi yang baru."
// Setelah masa ini habis, aplikasi kembali ke mode terbatas sampai kode aktivasi PERIODE
// BERIKUTNYA dimasukkan (kode setiap periode berbeda).
define('AKTIVASI_HARI_AKTIF', 365);

// MASA UJI COBA sekolah yang BELUM diaktivasi (permintaan pemilik). Hitungannya dimulai SAAT
// SEKOLAH MENDAFTAR ("Untuk sekolah yang sudah ada di dalam waktunya disesuaikan dengan waktu
// mereka mendaftar"), sehingga selain fiturnya dibatasi, waktu penggunaannya juga dibatasi.
//
// PEMBARUAN PERMINTAAN PEMILIK ("Masa aktif untuk pendaftar baru buat 15 hari"): masa uji coba
// DIPERSINGKAT dari 30 hari menjadi 15 hari.
// Hitungannya dari `sekolah.uji_mulai` (diisi saat mendaftar) — sekolah lama memakai tanggal
// pendaftarannya masing-masing (`tanggal_daftar`, cadangan `dibuat`). Setelah masa uji habis tanpa
// aktivasi, aplikasi TERKUNCI sampai kode aktivasi dimasukkan (state `uji_habis`).
define('AKTIVASI_HARI_UJI', 15);

// SISA WAKTU bagi sekolah yang SUDAH TERLANJUR MENDAFTAR saat masa uji dipersingkat 30 -> 15 hari
// (pilihan pemilik: "Beri sisa 3 hari lagi agar sempat aktivasi, lalu terkunci").
// Sekolah berkeadaan `dibatasi` yang batas 15 harinya sudah LEWAT (atau tersisanya kurang dari
// angka ini) digeser titik mulainya oleh migrasi v25 sehingga batasnya = SEKARANG + 3 hari.
// Hanya dipakai migrasi (sekali jalan, dijaga penanda `uji15_sisa`), bukan untuk pendaftar baru.
define('AKTIVASI_UJI_SISA_MINIMAL', 3);

// PERINGATAN POPUP masa aktif/uji coba akan berakhir (permintaan pemilik): "Tambahkan peringatan
// popup masa aktif akan berakhir untuk Lembaga yang masa aktifnya sisa 7 hari sampai sisa 1 hari
// sebelum benar-benar terkunci." Popup tampil pada rentang sisa hari 1..AKTIVASI_INGAT_HARI.
define('AKTIVASI_INGAT_HARI', 7);

// Nomor WA Admin aplikasi (ditampilkan pada petunjuk aktivasi). Ubah di sini bila berganti.
define('AKTIVASI_WA_ADMIN', '085394726089');

// ---------------------------------------------------------------------------------------------
// PERMOHONAN KODE AKTIVASI — kartu "Aktivasi Sekolah/Madrasah" pada menu Status & Aktivasi
// (permintaan pemilik): "Pada menu status & Aktifasi pada bagian Aktivasi Sekolah/Madrasah
// tambahkan 'Hubungi HD Admin untuk melakukan aktifasi. Kirim permohonan kode aktifasi (pada saat
// klik permohonan otomatis akan diarahkan ke WA 085240102180 dengan pesan WA: Assalamualaikum
// HD Admin Aplikasi <nama aplikasi> Kami dari <nama sekolah> ingin melakukan aktifasi aplikasi
// <nama aplikasi>.)' ... tambahkan Nomer Rekening 'Bank Mandiri 1500010135547. An. Supianto' dan
// ... keterangan 'Pastikan anda transfer ke nomer rekening tersebut untuk proses aktifasi dan
// verikasi. Diluar rekening tersebut kami tidak bertanggungjawab dan aplikasi anda tidak akan aktif'"
//
// Nomor WA ditulis dalam bentuk LOKAL (0…) di sini; diubah ke format internasional
// (wa.me) oleh wa_internasional(). Ubah di sini bila nomornya berganti.
define('AKTIVASI_WA_PERMOHONAN', '085240102180');
// Nomor rekening tujuan aktivasi (ditampilkan apa adanya pada kartu itu).
define('AKTIVASI_REKENING', 'Bank Mandiri 1500010135547. An. Supianto');
// Keterangan di bawah nomor rekening (teks permintaan pemilik, apa adanya).
define('AKTIVASI_REKENING_KET', 'Pastikan anda transfer ke nomer rekening tersebut untuk proses aktifasi dan verikasi. Diluar rekening tersebut kami tidak bertanggungjawab dan aplikasi anda tidak akan aktif');
// Kunci cadangan generator kode aktivasi (dipakai hanya bila tabel `app_pengaturan` tidak bisa
// ditulis). Nilai utamanya dibuat acak & disimpan di BASIS DATA — JANGAN di berkas `data/`
// karena berkas di folder itu dapat diunduh lewat web.
define('AKTIVASI_SECRET_CADANGAN', 'supervisi-guru-aktivasi-cadangan-2026');

// ---------------------------------------------------------------------------------------------
// NOMINAL BIAYA AKTIVASI pada menu Status & Aktivasi.
// Permintaan pemilik: isi menu Status & Aktivasi disamakan dengan aplikasi e-GURU, yang memuat
// "nominal aktifasi" (biaya aktivasi pertama), nomor rekening tujuan transfer, tombol kirim bukti
// transfer ke WhatsApp HD Admin, dan info "biaya langganan untuk tahun depan" bila lembaga sudah
// pernah diaktifasi.
//   • AKTIVASI_BIAYA           = biaya AKTIVASI PERTAMA (masa aktif 1 tahun / 365 hari);
//   • AKTIVASI_BIAYA_LANGGANAN = biaya LANGGANAN tahun berikutnya (perpanjangan masa aktif).
// Nilai di sini adalah BAWAAN. Halaman WAJIB membacanya lewat aktivasi_biaya_pertama() /
// aktivasi_biaya_langganan() (inc/helpers.php) — jangan menulis angka mati di halaman — supaya
// nominalnya dapat diubah tanpa menyentuh halaman.
define('AKTIVASI_BIAYA', 1000000);
define('AKTIVASI_BIAYA_LANGGANAN', 500000);
// Batas kewajaran nominal (Rp 1 miliar) — pengaman salah ketik.
define('AKTIVASI_BIAYA_MAKS', 1000000000);
// Batas panjang nomor WA HD Admin yang boleh disimpan (hanya angka).
define('AKTIVASI_WA_MAKS', 15);

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
