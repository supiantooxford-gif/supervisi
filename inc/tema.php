<?php
/**
 * TEMA TAMPILAN WARNA APLIKASI (permintaan pemilik).
 *
 * "Pada halaman super admin tambahkan fitur untuk mengganti tema tampilan warna aplikasi secara
 *  keseluruhan, dari halaman depan (landing) hingga halaman akun. Minimal ada 5 pilihan tema yang
 *  bisa dipilih oleh super admin."
 *
 * Cara kerjanya SENGAJA sesederhana mungkin supaya aman:
 *   1. Seluruh warna "identitas" aplikasi dipusatkan pada variabel CSS di `:root`
 *      (`assets/style.css`) — latar halaman, tiga bulatan dekorasi, warna aksen (2 nada), warna
 *      teks aksen gelap, warna tint tabel, dan bayangan.
 *   2. Setiap tema hanyalah BLOK PENIMPA variabel tersebut (`body.tema-<kunci>`), jadi tata letak,
 *      bentuk, huruf, dan seluruh fungsi aplikasi TIDAK berubah — hanya rona warnanya.
 *   3. Pilihan disimpan TINGKAT APLIKASI (tabel `app_pengaturan`, kunci `tema_app`) sehingga
 *      berlaku untuk semua halaman (halaman depan/landing, halaman masuk, halaman pendaftaran,
 *      halaman cetak) dan semua sekolah/madrasah sekaligus.
 *
 * Nama kelas `body` dihasilkan `tema_kelas()` dan dipasang oleh `page_top()` (inc/layout.php),
 * `login.php`, `daftar.php`, serta halaman cetak mandiri.
 */
declare(strict_types=1);

/** Kunci tema bawaan aplikasi (indigo/violet — nilai asli di `:root`). */
const TEMA_BAWAAN = 'indigo';

/**
 * Daftar tema yang bisa dipilih Super Admin: kunci => [nama, keterangan, tiga warna contoh].
 *
 * Warna contoh dipakai untuk pratinjau (2 nada aksen + latar halaman) pada halaman pemilihan tema.
 * Urutan array = urutan tampil pada halaman.
 */
function tema_daftar(): array
{
    return [
        'indigo' => [
            'nama'  => 'Indigo Violet',
            'ket'   => 'Tema bawaan aplikasi: ungu indigo lembut dengan aksen violet. Netral dan mudah dibaca.',
            'warna' => ['#4F46E5', '#8B5CF6', '#F3F0FF'],
        ],
        'hijau' => [
            'nama'  => 'Hijau Zamrud',
            'ket'   => 'Hijau tua bernuansa lembaga/madrasah, tenang dan formal. Cocok untuk tampilan resmi.',
            'warna' => ['#0B6B45', '#12855A', '#EDF7F1'],
        ],
        'biru' => [
            'nama'  => 'Biru Navy',
            'ket'   => 'Biru tua yang tegas dan profesional, banyak dipakai aplikasi administrasi.',
            'warna' => ['#1E40AF', '#1D4ED8', '#EEF3FF'],
        ],
        'maroon' => [
            'nama'  => 'Maroon Emas',
            'ket'   => 'Merah maroon dengan aksen keemasan — kesan khidmat untuk lembaga pendidikan Islam.',
            'warna' => ['#7F1D2E', '#A16207', '#FBF1F2'],
        ],
        'tosca' => [
            'nama'  => 'Tosca Segar',
            'ket'   => 'Tosca/hijau kebiruan yang segar dan ringan, enak dipandang dalam waktu lama.',
            'warna' => ['#116B64', '#0F766E', '#ECF7F6'],
        ],
        'emas' => [
            'nama'  => 'Hijau Emas Khidmat',
            'ket'   => 'Hijau tua dengan garis emas di atas latar krem seperti kertas — kesan resmi '
                     . 'madrasah, sama seperti halaman laporan cetak.',
            'warna' => ['#0B5D3B', '#A8801A', '#FBF6E9'],
        ],
    ];
}

/** Kunci tema yang sedang berlaku (hasil validasi; tema tak dikenal → bawaan). */
function tema_app(): string
{
    $kunci = trim((string) app_setting('tema_app', TEMA_BAWAAN));
    return array_key_exists($kunci, tema_daftar()) ? $kunci : TEMA_BAWAAN;
}

/**
 * Nama kelas untuk `<body>` — dipasang pada SETIAP halaman supaya temanya berlaku menyeluruh
 * (halaman depan/landing, halaman masuk, halaman pendaftaran, halaman aplikasi, dan lembar cetak).
 */
function tema_kelas(): string
{
    return 'tema-' . tema_app();
}

/** Apakah tema yang berlaku BUKAN tema bawaan (dipakai lencana "diubah" pada menu). */
function tema_masih_bawaan(): bool
{
    return tema_app() === TEMA_BAWAAN;
}

/** Nama tema yang sedang berlaku (untuk ditampilkan). */
function tema_nama(): string
{
    $d = tema_daftar();
    return $d[tema_app()]['nama'];
}

/**
 * Warna aksen tema yang berlaku — dipakai PHP (mis. palet grafik Chart.js pada halaman ringkasan
 * lintas sekolah) supaya grafik ikut berwarna tema, bukan ungu yang dipatok di kode.
 */
function tema_warna(string $kunci = 'accent'): string
{
    $peta = [
        'indigo' => ['accent' => '#4F46E5', 'accent2' => '#8B5CF6'],
        'hijau'  => ['accent' => '#0B6B45', 'accent2' => '#12855A'],
        'biru'   => ['accent' => '#1E40AF', 'accent2' => '#1D4ED8'],
        'maroon' => ['accent' => '#7F1D2E', 'accent2' => '#A16207'],
        'tosca'  => ['accent' => '#116B64', 'accent2' => '#0F766E'],
        'emas'   => ['accent' => '#0B5D3B', 'accent2' => '#A8801A'],
    ];
    $tema = tema_app();
    return $peta[$tema][$kunci] ?? $peta[TEMA_BAWAAN][$kunci];
}

/**
 * Simpan pilihan tema. Kunci tak dikenal DITOLAK (tidak pernah disimpan apa adanya) supaya kelas
 * `body` tidak pernah berisi nilai sembarang.
 */
function tema_simpan(string $kunci): bool
{
    if (!array_key_exists($kunci, tema_daftar())) {
        return false;
    }
    app_setting_set('tema_app', $kunci);
    return true;
}

/** Kembalikan tema ke bawaan aplikasi (Indigo Violet). */
function tema_bawaan_kembalikan(): void
{
    app_setting_set('tema_app', TEMA_BAWAAN);
}
