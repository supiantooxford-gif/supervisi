<?php
/**
 * Fungsi bantu: escaping, konteks sekolah, pengaturan, perhitungan nilai,
 * CSRF, flash message, dan otorisasi berbasis peran.
 *
 * Peran pengguna:
 *   super  = super admin (lintas sekolah, tidak terikat satu sekolah)
 *   admin  = kepala sekolah / pengawas (terikat satu sekolah)
 *   guru   = guru (terikat satu sekolah + satu baris data guru)
 *
 * File ini TIDAK boleh memanggil db() saat di-include (dipanggil lazy).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------- konteks sekolah

/**
 * Sekolah yang sedang aktif.
 * - admin & guru : sekolah tempat akunnya terdaftar
 * - super        : sekolah yang sedang dipilih lewat mode "Kelola sekolah"
 */
function current_sekolah(bool $fresh = false): ?array
{
    static $cache = null;
    static $sudah = false;
    if (!$fresh && $sudah) {
        return $cache;
    }
    $sudah = true;
    $cache = null;

    $u = current_user();
    if (!$u) {
        return null;
    }
    // Konteks sekolah hanya boleh untuk sekolah yang disetujui DAN masih aktif
    // (sekolah yang dinonaktifkan berarti sudah tidak memakai aplikasi lagi).
    if ($u['role'] === 'super' || $u['role'] === 'pengawas') {
        $id = (int) ($_SESSION['ctx_sekolah_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if ($u['role'] === 'pengawas' && !pengawas_punya((int) $u['id'], $id)) {
            unset($_SESSION['ctx_sekolah_id']);
            return null;
        }
        $cache = one('SELECT * FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"', [$id]);
        if (!$cache) {
            unset($_SESSION['ctx_sekolah_id']);
        }
        return $cache;
    }
    $sid = (int) ($u['sekolah_id'] ?? 0);
    if ($sid > 0) {
        $cache = one('SELECT * FROM sekolah WHERE id = ?', [$sid]);
    }
    return $cache;
}

function sekolah_id(): int
{
    $s = current_sekolah();
    return $s ? (int) $s['id'] : 0;
}

function set_sekolah_context(?int $id): void
{
    if ($id === null || $id <= 0) {
        unset($_SESSION['ctx_sekolah_id']);
    } else {
        $_SESSION['ctx_sekolah_id'] = (int) $id;
    }
    current_sekolah(true);
}

function jumlah_sekolah(): int
{
    // Hanya sekolah yang sudah disetujui yang dihitung sebagai sekolah aktif di aplikasi.
    return (int) val('SELECT COUNT(*) FROM sekolah WHERE verifikasi = "disetujui"', [], 0);
}

/**
 * Ringkasan isi sebuah sekolah — dipakai Super Admin sebelum menonaktifkan/menghapus,
 * supaya terlihat jelas data apa saja yang terpengaruh.
 */
function sekolah_dampak(int $sekolahId): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    return [
        'guru'        => (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$sekolahId], 0),
        'guru_aktif'  => (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ? AND aktif = 1', [$sekolahId], 0),
        'akun'        => (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ?', [$sekolahId], 0),
        'akun_aktif'  => (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ? AND aktif = 1', [$sekolahId], 0),
        'supervisi'   => (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ?', [$sekolahId], 0),
        'supervisi_final' => (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ? AND status = "final"', [$sekolahId], 0),
        'penilaian'   => (int) val('SELECT COUNT(*) FROM detail WHERE supervisi_id IN (SELECT id FROM supervisi WHERE sekolah_id = ?)', [$sekolahId], 0),
        // Butir kini GLOBAL: tidak lagi milik satu sekolah, jadi tidak dihitung per sekolah.
        'butir_global' => jumlah_butir_global(false),
        'pengawas'    => (int) val('SELECT COUNT(*) FROM pengawas_sekolah WHERE sekolah_id = ?', [$sekolahId], 0),
        'terakhir'    => val('SELECT MAX(tanggal) FROM supervisi WHERE sekolah_id = ?', [$sekolahId]),
        'admin'       => val('SELECT username FROM users WHERE sekolah_id = ? AND role = "admin" ORDER BY id LIMIT 1', [$sekolahId]),
        'foto'        => (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ? AND foto IS NOT NULL', [$sekolahId], 0),
    ];
}

/** Jumlah pendaftaran sekolah yang menunggu persetujuan Super Admin. */
function jumlah_pendaftaran_menunggu(): int
{
    return (int) val('SELECT COUNT(*) FROM sekolah WHERE verifikasi = "menunggu"', [], 0);
}

// ---------------------------------------------------------------- pengaturan sekolah

/** Peta kunci pengaturan lama -> kolom tabel `sekolah`. */
function setting_kolom(): array
{
    return [
        'nama_sekolah'       => 'nama',
        'npsn'               => 'npsn',
        // IDENTITAS LEMBAGA lengkap (skema 17). WAJIB terdaftar di peta ini: set_setting() HANYA
        // menulis kolom yang ada di sini — kunci yang lupa didaftarkan akan terbuang diam-diam.
        'nsm'                => 'nsm',
        'alamat_sekolah'     => 'alamat',
        'desa'               => 'desa',
        'kecamatan'          => 'kecamatan',
        'kabupaten'          => 'kabupaten',
        'provinsi'           => 'provinsi',
        'telepon_sekolah'    => 'telepon',
        'email_sekolah'      => 'email',
        'website'            => 'website',
        'kepala_sekolah'     => 'kepala_sekolah',
        'nip_kepala_sekolah' => 'nip_kepala_sekolah',
        'tahun_ajaran'       => 'tahun_ajaran',
        'semester'           => 'semester',
        'catatan'            => 'catatan',
        'batas_a'            => 'batas_a',
        'batas_b'            => 'batas_b',
        'batas_c'            => 'batas_c',
        'jenis_sekolah'      => 'jenis',
        'logo_sekolah'       => 'logo',
    ];
}

function settings(bool $fresh = false): array
{
    return current_sekolah($fresh) ?? [];
}

function setting(string $key, string $default = ''): string
{
    $s = current_sekolah();
    if (!$s) {
        return $default;
    }
    $kolom = setting_kolom()[$key] ?? null;
    if ($kolom === null || !array_key_exists($kolom, $s)) {
        return $default;
    }
    $v = $s[$kolom];
    return ($v === null || $v === '') ? $default : (string) $v;
}

function set_setting(string $key, string $value): void
{
    $kolom = setting_kolom()[$key] ?? null;
    $sid = sekolah_id();
    if ($kolom === null || $sid <= 0) {
        return;
    }
    q('UPDATE sekolah SET ' . $kolom . ' = ? WHERE id = ?', [$value, $sid]);
    current_sekolah(true);
}

// ---------------------------------------------------------------- logo sekolah / madrasah

/** URL logo sekolah/madrasah (string kosong bila belum diunggah). */
function logo_sekolah(): string
{
    return trim(setting('logo_sekolah', ''));
}

/** Benar bila logo sudah diunggah. */
function ada_logo(): bool
{
    return logo_sekolah() !== '';
}

// ---------------------------------------------------------------- IDENTITAS LEMBAGA (skema v17)
// Permintaan pemilik: inputan Identitas Madrasah/Sekolah memuat Nama, NPSN, NSM, Alamat
// (Jalan, Dusun), Desa/Kelurahan, Kecamatan, Kabupaten, Provinsi, Telepon, Email, Situs/Web,
// Semester, Nama Kepala Madrasah/Supervisor, dan NIP. Halaman pendaftaran menyesuaikan, dan pada
// semua lembar cetak ditambahkan nama Kecamatan + tanggal di atas nama guru.

/** Nilai identitas yang dianggap TERISI (kosong/spasi/"-"/"–"/"—"/"."/"_" dianggap kosong). */
function identitas_nilai(?string $v): string
{
    $v = trim((string) $v);
    return in_array($v, ['', '-', '–', '—', '.', '_'], true) ? '' : $v;
}

/** NSM (Nomor Statistik Madrasah) — string kosong bila belum diisi. */
function nsm_sekolah(): string
{
    return identitas_nilai(setting('nsm', ''));
}

/**
 * Baris alamat WILAYAH dari SATU BARIS data sekolah (atau baris pendaftaran):
 * "Desa/Kelurahan X, Kec. Y, Kab. Z, Provinsi W". Hanya bagian yang terisi yang dipakai.
 */
function alamat_wilayah_dari(array $r): string
{
    $bagian = [];
    foreach ([['desa', 'Desa/Kelurahan '], ['kecamatan', 'Kec. '],
              ['kabupaten', 'Kab. '], ['provinsi', 'Provinsi ']] as [$kolom, $label]) {
        $v = identitas_nilai((string) ($r[$kolom] ?? ''));
        if ($v !== '') {
            $bagian[] = $label . $v;
        }
    }
    return implode(', ', $bagian);
}

/** Alamat wilayah sekolah yang sedang aktif (dipakai pada kop lembar cetak). */
function alamat_wilayah(): string
{
    return alamat_wilayah_dari([
        'desa'      => setting('desa', ''),
        'kecamatan' => setting('kecamatan', ''),
        'kabupaten' => setting('kabupaten', ''),
        'provinsi'  => setting('provinsi', ''),
    ]);
}

/** Nama KECAMATAN saja (dipakai baris "tempat, tanggal" pada tanda tangan lembar cetak). */
function kecamatan_sekolah(): string
{
    return identitas_nilai(setting('kecamatan', ''));
}

/**
 * Label blok tanda tangan KEPALA sekolah/madrasah — DUA BARIS (permintaan pemilik):
 *   Mengetahui,
 *   Kepala Madrasah
 * Dijadikan dua baris agar NAMA kepala & NAMA guru tercetak SEJAJAR pada lembar cetak yang
 * memuat tanda tangan keduanya (sisi kanan memakai dua baris: baris "tempat, tanggal" + jabatan).
 */
function ttd_mengetahui(): string
{
    return "Mengetahui,\n" . istilah_sekolah('Kepala Sekolah');
}

/**
 * Versi HTML dari label blok tanda tangan kepala (siap dicetak): baris barunya menjadi `<br>`
 * sehingga penanda dua barisnya tetap terbaca walau halaman tidak memproses `<pre>`.
 * Dipakai SEMUA lembar cetak — jangan menulis ulang "Mengetahui, ..." satu baris lagi.
 */
function ttd_mengetahui_html(): string
{
    return str_replace("\n", '<br>', e(ttd_mengetahui()));
}

/**
 * Baris "tempat, tanggal" di atas tanda tangan lembar cetak (permintaan pemilik: "Pada semua bagian
 * cetak tambahkan Nama Kecamatan dan tanggal/tahun"). Contoh: "Mootilango, 30 September 2026".
 * Bila kecamatan belum diisi, hanya tanggalnya yang dicetak (tidak pernah ada tanda hubung janggal).
 */
function ttd_tempat_tanggal(?string $tanggal = null): string
{
    $tanggal = ($tanggal === null || $tanggal === '') ? date('Y-m-d') : $tanggal;
    $tempat = kecamatan_sekolah();
    return $tempat !== '' ? $tempat . ', ' . tanggal_id($tanggal) : tanggal_id($tanggal);
}

/**
 * Baris identitas pada KOP lembar cetak (urutan permintaan pemilik): alamat, wilayah, lalu
 * "email: …, website: …", lalu "NPSN: …, NSM: …, HP: …". Baris yang seluruh isiannya kosong
 * DILEWATI sehingga kop sekolah yang belum melengkapi identitas tampil seperti semula.
 */
function kop_baris_identitas(): array
{
    $baris = [];
    $alamat = identitas_nilai(setting('alamat_sekolah', ''));
    if ($alamat !== '') {
        $baris[] = $alamat;
    }
    $wilayah = alamat_wilayah();
    if ($wilayah !== '') {
        $baris[] = $wilayah;
    }
    $email = identitas_nilai(setting('email_sekolah', ''));
    $situs = identitas_nilai(setting('website', ''));
    if ($email !== '' || $situs !== '') {
        $bagian = [];
        if ($email !== '') {
            $bagian[] = 'email: ' . $email;
        }
        if ($situs !== '') {
            $bagian[] = 'website: ' . $situs;
        }
        $baris[] = implode(', ', $bagian);
    }
    $bagian = [];
    $npsn = identitas_nilai(setting('npsn', ''));
    $nsm = nsm_sekolah();
    $telepon = identitas_nilai(setting('telepon_sekolah', ''));
    if ($npsn !== '') {
        $bagian[] = 'NPSN: ' . $npsn;
    }
    if ($nsm !== '') {
        $bagian[] = 'NSM: ' . $nsm;
    }
    if ($telepon !== '') {
        $bagian[] = 'HP: ' . $telepon;
    }
    if ($bagian) {
        $baris[] = implode(', ', $bagian);
    }
    return $baris;
}

// ---------------------------------------------------------------- istilah Sekolah / Madrasah

/** Jenis sekolah pada konteks aktif: 'umum' atau 'madrasah'. */
function jenis_sekolah(): string
{
    $j = setting('jenis_sekolah', 'umum');
    return $j === 'madrasah' ? 'madrasah' : 'umum';
}

function is_madrasah(): bool
{
    return jenis_sekolah() === 'madrasah';
}

/**
 * Label JENIS sebuah sekolah dari NILAI kolomnya ('umum' / 'madrasah').
 * Dipakai halaman LINTAS sekolah (mis. Semua Dokumen Madrasah milik Super Admin) yang menampilkan
 * jenis tiap baris tanpa memakai konteks sekolah yang sedang aktif.
 */
function jenis_sekolah_teks(?string $jenis): string
{
    return trim((string) $jenis) === 'madrasah' ? 'Madrasah' : 'Sekolah Umum';
}

/**
 * Sesuaikan istilah "Sekolah" menjadi "Madrasah" bila jenis sekolah = madrasah.
 * Hanya kata utuh yang diubah (Sekolah/sekolah/SEKOLAH), baik pada label, teks, maupun judul.
 */
/**
 * Benar bila halaman yang sedang dibuka adalah halaman PENGAWAS.
 *
 * Pengawas membina sekolah umum DAN madrasah sekaligus, jadi seluruh tulisan "Sekolah" pada
 * tampilan/menu-nya memakai istilah "Sekolah/Madrasah" (permintaan pemilik). Dokumen CETAK
 * tidak memakai istilah ini — lembar cetak tetap memakai istilah sekolah yang bersangkutan
 * (mis. "Kepala Madrasah" pada madrasah), karena halaman cetak tidak memakai layout.
 */
function is_istilah_pengawas(): bool
{
    return !empty($GLOBALS['__vs_istilah_pengawas']);
}

function istilah_sekolah(?string $teks): string
{
    $teks = (string) $teks;
    if ($teks === '' || !empty($GLOBALS['__vs_istilah_nonaktif'])) {
        return $teks;
    }
    // Buang penanda tetap_kata() bila ada (isinya tidak diterjemahkan).
    $teks = (string) preg_replace_callback('/\x01(.*?)\x02/s', static fn(array $m): string => $m[1], $teks);

    // Halaman Pengawas: "Sekolah" -> "Sekolah/Madrasah".
    // Lookahead mencegah penggantian ganda pada tulisan yang sudah berbunyi "/Madrasah".
    if (is_istilah_pengawas()) {
        return (string) preg_replace_callback(
            '/(?<![A-Za-z])(SEKOLAH|Sekolah|sekolah)(?![A-Za-z])(?!\s*\/\s*[Mm]adrasah)/u',
            static function (array $m): string {
                if ($m[1] === 'SEKOLAH') {
                    return 'SEKOLAH/MADRASAH';
                }
                return $m[1] === 'Sekolah' ? 'Sekolah/Madrasah' : 'sekolah/madrasah';
            },
            $teks
        );
    }

    if (!is_madrasah()) {
        return $teks;
    }
    return (string) preg_replace_callback(
        '/(?<![A-Za-z])(SEKOLAH|Sekolah|sekolah)(?![A-Za-z])/u',
        static function (array $m): string {
            if ($m[1] === 'SEKOLAH') {
                return 'MADRASAH';
            }
            return $m[1] === 'Sekolah' ? 'Madrasah' : 'madrasah';
        },
        $teks
    );
}

/**
 * Tandai potongan teks agar TIDAK ikut penyesuaian istilah.
 * Dipakai untuk NAMA RESMI sekolah/madrasah: "Sekolah Islam Terpadu Al Hidayah" tetap
 * ditulis apa adanya walau jenisnya madrasah.
 */
function tetap_kata(?string $teks): string
{
    $teks = (string) $teks;
    // Penanda hanya perlu bila ADA penyesuaian istilah yang aktif: madrasah ATAU halaman pengawas.
    // (Kalau tidak, nama resmi sekolah pada halaman pengawas ikut berubah menjadi
    //  "Sekolah/Madrasah Pertama" — pernah terjadi dan sudah diperbaiki.)
    if ($teks === '' || (!is_madrasah() && !is_istilah_pengawas())) {
        return $teks;
    }
    return "\x01" . $teks . "\x02";
}

/**
 * Buang penanda internal tetap_kata() (\x01…\x02) yang mungkin masih tersisa.
 *
 * PENTING: terjemahkan_html() punya jalan keluar cepat `stripos($html,'sekolah') === false`
 * yang mengembalikan HTML APA ADANYA. Pada halaman madrasah yang seluruh teksnya sudah berbunyi
 * "Madrasah" (mis. lembar cetak yang hanya memuat nama madrasah + tanda tangan), kata "sekolah"
 * sering tidak ada sehingga penanda tetap_kata() tidak pernah dibuang dan peramban menggambarnya
 * sebagai KOTAK pada NAMA RESMI sekolah/madrasah. Karena itu setiap jalan keluar WAJIB
 * melewati buang_penanda().
 */
function buang_penanda(?string $html): string
{
    return (string) preg_replace('/[\x01\x02\x03]/', '', (string) $html);
}

/**
 * Jalankan istilah_sekolah() pada seluruh TEKS halaman HTML, tanpa menyentuh isi
 * <script>/<style> maupun nama atribut (mis. name="sekolah[]" tetap utuh).
 * Atribut yang tampil ke pengguna (placeholder/title/alt) ikut disesuaikan.
 */
function terjemahkan_html(string $html): string
{
    // Halaman lintas sekolah (mis. Kelola Sekolah / Pendaftaran / Akun Pengawas) memakai
    // istilah generik "Sekolah" karena mengelola sekolah umum DAN madrasah sekaligus.
    if (!empty($GLOBALS['__vs_istilah_nonaktif'])) {
        return buang_penanda($html);
    }
    // Halaman Pengawas juga perlu diproses walau sekolahnya berjenis umum.
    if ($html === '' || (!is_madrasah() && !is_istilah_pengawas())) {
        return buang_penanda($html);
    }
    if (stripos($html, 'sekolah') === false) {
        return buang_penanda($html);
    }

    // Lindungi potongan yang ditandai tetap_kata() (mis. nama resmi sekolah).
    $lindungi = [];
    $html = (string) preg_replace_callback('/\x01(.*?)\x02/s', static function (array $m) use (&$lindungi): string {
        $kunci = "\x03" . count($lindungi) . "\x03";
        $lindungi[$kunci] = $m[1];
        return $kunci;
    }, $html);

    $bagian = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($bagian === false) {
        return $html;
    }

    $keluar = '';
    $dalamScript = false;
    $dalamStyle = false;
    foreach ($bagian as $i => $potong) {
        if ($i % 2 === 1) {
            $tag = strtolower($potong);
            if (strncmp($tag, '<script', 7) === 0) {
                $dalamScript = true;
            } elseif (strncmp($tag, '</script', 8) === 0) {
                $dalamScript = false;
            } elseif (strncmp($tag, '<style', 6) === 0) {
                $dalamStyle = true;
            } elseif (strncmp($tag, '</style', 7) === 0) {
                $dalamStyle = false;
            } else {
                $potong = (string) preg_replace_callback(
                    '/\b(placeholder|title|alt)="([^"]*)"/i',
                    static fn(array $m): string => $m[1] . '="' . e(istilah_sekolah($m[2])) . '"',
                    $potong
                );
            }
            $keluar .= $potong;
            continue;
        }
        $keluar .= ($dalamScript || $dalamStyle) ? $potong : istilah_sekolah($potong);
    }
    if ($lindungi) {
        $keluar = strtr($keluar, $lindungi);
    }
    return buang_penanda($keluar);
}

// ---------------------------------------------------------------- nilai & predikat

function thresholds(?array $th = null): array
{
    $th ??= (current_sekolah() ?? []);
    return [
        'batas_a' => (float) ($th['batas_a'] ?? 90),
        'batas_b' => (float) ($th['batas_b'] ?? 80),
        'batas_c' => (float) ($th['batas_c'] ?? 70),
    ];
}

function predikat_dari(float $nilai, ?array $th = null): string
{
    $t = thresholds($th);
    if ($nilai >= $t['batas_a']) {
        return 'A';
    }
    if ($nilai >= $t['batas_b']) {
        return 'B';
    }
    if ($nilai >= $t['batas_c']) {
        return 'C';
    }
    return 'D';
}

function predikat_label(string $p): string
{
    switch ($p) {
        case 'A': return 'Amat Baik';
        case 'B': return 'Baik';
        case 'C': return 'Cukup';
        case 'D': return 'Perlu Pembinaan';
        default:  return '-';
    }
}

/**
 * Hitung total, skor maksimal, nilai akhir, dan predikat dari daftar skor.
 * @param array<int,int> $skorPerButir  [butir_id => skor]
 * @param array<int,int> $maksPerButir  [butir_id => skor_maks]
 */
function hitung_nilai(array $skorPerButir, array $maksPerButir): array
{
    $total = 0.0;
    $maks = 0.0;
    foreach ($maksPerButir as $bid => $m) {
        $maks += (float) $m;
        $total += (float) ($skorPerButir[$bid] ?? 0);
    }
    $nilai = $maks > 0 ? round($total / $maks * 100, 2) : 0.0;
    return [
        'total_skor' => $total,
        'skor_maks'  => $maks,
        'nilai'      => $nilai,
        'predikat'   => predikat_dari($nilai),
    ];
}

function tanggal_id(?string $ymd): string
{
    if (!$ymd) {
        return '-';
    }
    $ts = strtotime($ymd);
    if ($ts === false) {
        return (string) $ymd;
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function r1($n): string
{
    return number_format((float) $n, 2, ',', '.');
}

// ---------------------------------------------------------------- log aktivitas & pengguna online
//
// Halaman Log Aktivitas (Super Admin) menampilkan DUA hal:
//   1. pengguna yang SEDANG ONLINE  -> tabel `sesi_aktif` (dianggap online bila aktif ≤ 15 menit lalu)
//   2. RIWAYAT masuk/keluar/gagal masuk -> tabel `log_aktivitas`
// Semua penulisan log dibungkus try/catch: pencatatan TIDAK BOLEH menggagalkan permintaan pengguna.

/** Label peran untuk tampilan. */
function role_label(?string $role): string
{
    switch ((string) $role) {
        case 'super':    return 'Super Admin';
        case 'admin':    return 'Administrator / Kepala Sekolah';
        case 'pengawas': return 'Pengawas Sekolah';
        case 'guru':     return 'Guru';
        default:         return 'Tidak dikenal';
    }
}

/** Jumlah menit tanpa aktivitas sebelum sebuah sesi dianggap sudah tidak online. */
const LOG_MENIT_ONLINE = 15;

/** Alamat IP pemakai. Aplikasi berada di belakang proxy, jadi X-Forwarded-For diutamakan. */
function log_ip(): string
{
    $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '') {
        foreach (explode(',', $fwd) as $bagian) {
            $ip = trim($bagian);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
    }
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return '-';
    }
    return substr($ip, 0, 45);
}

/** Nama peramban + sistem operasi dari User-Agent (ringkas, untuk ditampilkan apa adanya). */
function log_peramban(?string $ua = null): string
{
    $ua = $ua === null ? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') : $ua;
    $ua = trim($ua);
    if ($ua === '') {
        return 'Tidak dikenal';
    }

    // Urutan penting: Edge & Opera memuat kata "Chrome", jadi diperiksa lebih dulu.
    $nama = 'Peramban lain';
    $pola = [
        ['/Edg(?:e|A|iOS)?\/([\d.]+)/i', 'Edge'],
        ['/OPR\/([\d.]+)/i', 'Opera'],
        ['/SamsungBrowser\/([\d.]+)/i', 'Samsung Internet'],
        ['/Firefox\/([\d.]+)/i', 'Firefox'],
        ['/CriOS\/([\d.]+)/i', 'Chrome'],
        ['/Chrome\/([\d.]+)/i', 'Chrome'],
        ['/Version\/([\d.]+).*Safari/i', 'Safari'],
        ['/Safari\/([\d.]+)/i', 'Safari'],
    ];
    foreach ($pola as $p) {
        if (preg_match($p[0], $ua, $m) === 1) {
            $versi = (string) ($m[1] ?? '');
            $utama = $versi !== '' ? explode('.', $versi)[0] : '';
            $nama = $p[1] . ($utama !== '' ? ' ' . $utama : '');
            break;
        }
    }

    $sistem = '';
    if (stripos($ua, 'Android') !== false) {
        $sistem = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua) === 1) {
        $sistem = 'iPhone/iPad';
    } elseif (stripos($ua, 'Windows') !== false) {
        $sistem = 'Windows';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $sistem = 'macOS';
    } elseif (stripos($ua, 'CrOS') !== false) {
        $sistem = 'ChromeOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $sistem = 'Linux';
    }
    // Perangkat bergerak ditandai agar mudah dikenali pada daftar online.
    $hp = preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua) === 1 ? 'HP' : 'Komputer';

    return $nama . ($sistem !== '' ? ' · ' . $sistem : '') . ' · ' . $hp;
}

/** Penanda sesi (hash) — id sesi mentah tidak pernah disimpan di basis data. */
function log_sesi_id(): string
{
    return substr(hash('sha256', session_id()), 0, 32);
}

/** Tanggal & jam yang mudah dibaca, mis. "15 Juni 2026 · 14:32:07". */
function waktu_id(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '') {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    return tanggal_id(date('Y-m-d', $ts)) . ' &middot; ' . date('H:i:s', $ts);
}

/**
 * Tanggal & jam sebagai TEKS BIASA (tanpa entitas HTML), mis. "15 Juni 2026, 14:32:07".
 *
 * Dipakai di tempat yang tidak boleh memuat entitas HTML atau yang tidak memakai tanda "·":
 *  - kalimat biasa yang sudah dilewatkan e() (bila memakai waktu_id(), "&middot;" ikut ter-escape
 *    menjadi "&amp;middot;" sehingga yang TAMPIL di layar justru tulisan "&middot;" — pernah terjadi
 *    pada kartu "Status Aktivasi Lembaga" di aktivasi.php);
 *  - nilai atribut seperti data-confirm.
 */
function waktu_id_polos(?string $dt): string
{
    $dt = trim((string) $dt);
    if ($dt === '') {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    return tanggal_id(date('Y-m-d', $ts)) . ', ' . date('H:i:s', $ts);
}

/**
 * Catat satu aktivitas ke log_aktivitas.
 *
 * @param string      $aktivitas 'masuk' | 'keluar' | 'gagal'
 * @param array|null  $user      baris users (boleh dari current_user()) — null untuk nama yang tidak dikenal
 * @param string      $namaManual nama/username yang diketik saat masuk (untuk percobaan gagal)
 * @param string      $keterangan alasan/penjelasan singkat (mis. "kata sandi salah")
 */
function log_aktivitas_catat(string $aktivitas, ?array $user = null, string $namaManual = '', string $keterangan = ''): void
{
    try {
        $uid = $user ? (int) ($user['id'] ?? 0) : 0;
        $nama = $user ? trim((string) ($user['nama'] ?? '')) : '';
        if ($nama === '') {
            $nama = $namaManual !== '' ? $namaManual : '(nama tidak dikenal)';
        }
        $username = $user ? trim((string) ($user['username'] ?? '')) : $namaManual;
        $role = $user ? (string) ($user['role'] ?? '') : '';
        $sid = $user ? (int) ($user['sekolah_id'] ?? 0) : 0;
        $sekolah = '';
        if ($sid > 0) {
            $sekolah = trim((string) ($user['sekolah_nama'] ?? ''));
            if ($sekolah === '') {
                $sekolah = (string) val('SELECT nama FROM sekolah WHERE id = ?', [$sid], '');
            }
        }
        q('INSERT INTO log_aktivitas (user_id, nama, username, role, sekolah_id, sekolah, aktivitas, keterangan, ip, peramban, waktu)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $uid > 0 ? $uid : null,
            $nama,
            $username !== '' ? $username : null,
            $role !== '' ? $role : null,
            $sid > 0 ? $sid : null,
            $sekolah !== '' ? $sekolah : null,
            $aktivitas,
            $keterangan !== '' ? $keterangan : null,
            log_ip(),
            log_peramban(),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Diabaikan dengan sengaja (lihat catatan di atas bagian ini).
    }
}

/** Mulai sesi: daftarkan sebagai pengguna online + catat aktivitas "masuk". */
function log_sesi_mulai(array $u): void
{
    $_SESSION['__log_ping'] = time();
    try {
        $sid = log_sesi_id();
        $now = date('Y-m-d H:i:s');
        // Sesi dengan penanda sama (mis. masuk ulang di peramban yang sama) dimulai dari awal.
        q('DELETE FROM sesi_aktif WHERE id = ?', [$sid]);
        q('INSERT INTO sesi_aktif (id, user_id, ip, peramban, mulai, terakhir) VALUES (?,?,?,?,?,?)',
            [$sid, (int) $u['id'], log_ip(), log_peramban(), $now, $now]);
    } catch (Throwable $e) {
    }
    log_aktivitas_catat('masuk', $u);
}

/**
 * Perbarui waktu aktif sesi (dipanggil sekali per permintaan dari require_login()).
 * Ditulis paling sering sekali per 60 detik agar tidak membebani basis data.
 */
function log_sesi_ping(): void
{
    $u = current_user();
    if (!$u) {
        return;
    }
    $kini = time();
    if ($kini - (int) ($_SESSION['__log_ping'] ?? 0) < 60) {
        return;
    }
    $_SESSION['__log_ping'] = $kini;
    try {
        $sid = log_sesi_id();
        $now = date('Y-m-d H:i:s');
        $ip = log_ip();
        $pb = log_peramban();
        // Sesi lama (dibuat sebelum fitur ini ada, atau sesi yang baru dibuat) didaftarkan sekalian.
        q('INSERT OR IGNORE INTO sesi_aktif (id, user_id, ip, peramban, mulai, terakhir) VALUES (?,?,?,?,?,?)',
            [$sid, (int) $u['id'], $ip, $pb, $now, $now]);
        q('UPDATE sesi_aktif SET user_id = ?, terakhir = ?, ip = ?, peramban = ? WHERE id = ?',
            [(int) $u['id'], $now, $ip, $pb, $sid]);
    } catch (Throwable $e) {
    }
}

/** Akhiri sesi: catat aktivitas "keluar" + hilangkan dari daftar online. */
function log_sesi_selesai(?array $u): void
{
    try {
        if ($u) {
            log_aktivitas_catat('keluar', $u);
        }
        q('DELETE FROM sesi_aktif WHERE id = ?', [log_sesi_id()]);
    } catch (Throwable $e) {
    }
}

/**
 * Batasi riwayat percobaan masuk yang GAGAL (halaman login publik, jadi bisa dipicu siapa saja).
 * Menyimpan hanya `$maks` baris terbaru; riwayat masuk/keluar tidak tersentuh.
 * Dipanggil setiap kali sebuah percobaan gagal dicatat (hitungannya memakai index `aktivitas`).
 */
function log_batasi_gagal(int $maks = 2000): void
{
    try {
        $jml = (int) val('SELECT COUNT(*) FROM log_aktivitas WHERE aktivitas = "gagal"', [], 0);
        if ($jml > $maks) {
            q('DELETE FROM log_aktivitas WHERE aktivitas = "gagal" AND id NOT IN (
                   SELECT id FROM log_aktivitas WHERE aktivitas = "gagal" ORDER BY id DESC LIMIT ?)', [$maks]);
        }
    } catch (Throwable $e) {
    }
}

/** Buang catatan sesi yang sudah lama tidak aktif (bukan penghapusan riwayat). */
function log_sesi_bersihkan(int $hari = 1): int
{
    try {
        $st = q('DELETE FROM sesi_aktif WHERE terakhir < ?', [date('Y-m-d H:i:s', time() - max(1, $hari) * 86400)]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Syarat SQL sebuah sesi dianggap "sedang online" (tanpa awalan WHERE).
 * Hanya sesi dari akun yang MASIH aktif dan sekolahnya masih aktif & disetujui:
 * akun/sekolah yang sudah dinonaktifkan tidak lagi dihitung online walau sesinya
 * belum sempat ditutup — konsisten dengan aturan "sekolah nonaktif hilang dari ringkasan".
 */
function log_online_syarat(string $alias = 'sx', string $aliasUser = 'u'): string
{
    return $alias . '.terakhir >= ? AND ' . $aliasUser . '.aktif = 1
        AND (' . $aliasUser . '.sekolah_id IS NULL OR EXISTS (
                SELECT 1 FROM sekolah sk WHERE sk.id = ' . $aliasUser . '.sekolah_id
                  AND sk.aktif = 1 AND sk.verifikasi = "disetujui"))';
}

/** Pengguna yang dianggap MASIH online (aktif dalam LOG_MENIT_ONLINE menit terakhir). */
function log_online(int $menit = LOG_MENIT_ONLINE): array
{
    $batas = date('Y-m-d H:i:s', time() - max(1, $menit) * 60);
    return all('SELECT sx.id AS sesi, sx.ip, sx.peramban, sx.mulai, sx.terakhir,
                       u.id AS user_id, u.nama, u.username, u.role, u.sekolah_id,
                       s.nama AS sekolah_nama
                FROM sesi_aktif sx
                JOIN users u ON u.id = sx.user_id
                LEFT JOIN sekolah s ON s.id = u.sekolah_id
                WHERE ' . log_online_syarat() . '
                ORDER BY sx.terakhir DESC, u.nama', [$batas]);
}

/** Berapa pengguna yang sedang online (dipakai lencana pada menu sidebar). */
function log_jumlah_online(int $menit = LOG_MENIT_ONLINE): int
{
    try {
        $batas = date('Y-m-d H:i:s', time() - max(1, $menit) * 60);
        return (int) val('SELECT COUNT(*) FROM sesi_aktif sx JOIN users u ON u.id = sx.user_id
                          WHERE ' . log_online_syarat(), [$batas], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Ringkasan asal sekolah/madrasah seorang pengguna pada daftar log. */
function log_sekolah_label(?string $role, ?string $sekolah): string
{
    $sekolah = trim((string) $sekolah);
    if ($sekolah !== '') {
        return $sekolah;
    }
    switch ((string) $role) {
        case 'super':
            return 'Seluruh sekolah (lintas sekolah)';
        case 'pengawas':
            return 'Sekolah binaan (lintas sekolah)';
        default:
            return '-';
    }
}

// ---------------------------------------------------------------- flash & CSRF

function flash(string $pesan, string $tipe = 'ok'): void
{
    $_SESSION['flash'][] = ['pesan' => $pesan, 'tipe' => $tipe];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t);
}

function csrf_guard(): void
{
    if (!csrf_ok()) {
        http_response_code(419);
        exit('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

// ---------------------------------------------------------------- otorisasi

function current_user(): ?array
{
    static $u = null;
    static $sudah = false;
    if ($sudah) {
        return $u;
    }
    $sudah = true;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        $u = null;
        return null;
    }
    $u = one('SELECT u.*, g.nama AS guru_nama, s.nama AS sekolah_nama
              FROM users u
              LEFT JOIN guru g ON g.id = u.guru_id
              LEFT JOIN sekolah s ON s.id = u.sekolah_id
              WHERE u.id = ? AND u.aktif = 1', [(int) $id]);
    if (!$u) {
        $u = null;
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function is_super(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'super';
}

function is_pengawas(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'pengawas';
}

/** Daftar sekolah binaan seorang pengawas. */
function sekolah_binaan(int $userId, bool $aktifSaja = true): array
{
    if ($userId <= 0) {
        return [];
    }
    return all('SELECT s.* FROM pengawas_sekolah ps JOIN sekolah s ON s.id = ps.sekolah_id
                WHERE ps.user_id = ?' . ($aktifSaja ? ' AND s.aktif = 1' : '') . '
                ORDER BY s.nama', [$userId]);
}

/** Benar bila sekolah tsb termasuk binaan pengawas. */
function pengawas_punya(int $userId, int $sekolahId): bool
{
    if ($userId <= 0 || $sekolahId <= 0) {
        return false;
    }
    return (int) val('SELECT COUNT(*) FROM pengawas_sekolah WHERE user_id = ? AND sekolah_id = ?', [$userId, $sekolahId], 0) > 0;
}

// ------------------------------------------- akun PENGAWAS BINA dari halaman pendaftaran (skema 18)
// Permintaan pemilik: "Pada halaman pendaftaran tambahkan inputan Nama Pengawas Bina dan NIP.
// Posisi dibawah nama kepala sekolah. Nama Pengawas yang diinput otomatis masuk di akun super admin
// di daftar akun pengawas tapi status masih non aktif untuk ditinjau oleh super admin."

/**
 * Jumlah akun pengawas yang diajukan lewat pendaftaran dan BELUM DITINJAU Super Admin
 * (masih nonaktif). Dipakai lencana sidebar & peringatan pada halaman Akun Pengawas.
 */
function pengawas_menunggu_jumlah(): int
{
    return (int) val('SELECT COUNT(*) FROM users WHERE role = "pengawas" AND aktif = 0 AND asal = "pendaftaran"', [], 0);
}

/**
 * Buat (atau tautkan) akun PENGAWAS BINA yang diajukan pada halaman pendaftaran sekolah.
 *
 * Aturan yang dipakai:
 *  - Akun dibuat dengan `aktif = 0` dan `asal = 'pendaftaran'` sehingga muncul pada halaman
 *    **Akun Pengawas** milik Super Admin dengan STATUS NONAKTIF untuk ditinjau. Pendaftar TIDAK
 *    memilih kata sandi: sandinya dibuat acak (tidak pernah ditampilkan) dan Super Admin
 *    menetapkannya saat meninjau (form "Ubah akun & sekolah binaan").
 *  - Sekolah yang didaftarkan langsung ditautkan sebagai **binaannya** (`pengawas_sekolah`),
 *    karena yang diisi pendaftar memang pengawas BINA sekolah itu.
 *  - Bila seorang pengawas dengan NIP (atau nama) yang sama SUDAH terdaftar, akun itu dipakai ulang
 *    — hanya binaannya yang ditambah — supaya satu orang tidak punya beberapa akun
 *    (satu pengawas biasanya membina beberapa sekolah). Status/sandi akun lama TIDAK diubah.
 *
 * WAJIB dipanggil di dalam transaksi pendaftaran (semua tulisannya memakai q()/db() yang sama).
 *
 * @return array{nama:string,username:string,nip:string,baru:bool}|null null bila namanya kosong.
 */
function pengawas_pendaftaran(int $sekolahId, string $nama, string $nip = ''): ?array
{
    $nama = trim($nama);
    if ($sekolahId <= 0 || $nama === '') {
        return null;
    }
    $nip = nip_bersih($nip);

    // Sudah terdaftar? Cocokkan lewat NIP dulu (paling pasti), lalu nama (tanpa peduli besar-kecil).
    $lama = null;
    if ($nip !== '') {
        foreach (all('SELECT * FROM users WHERE role = "pengawas"', []) as $r) {
            if (nip_bersih((string) ($r['nip'] ?? '')) === $nip) {
                $lama = $r;
                break;
            }
        }
    }
    if (!$lama) {
        $lama = one('SELECT * FROM users WHERE role = "pengawas" AND LOWER(TRIM(nama)) = LOWER(?)', [$nama]);
    }

    if ($lama) {
        $uid = (int) $lama['id'];
        $baru = false;
    } else {
        // Nama pengguna unik se-aplikasi: susun dari nama pengawas, tambahkan angka bila sudah dipakai.
        $dasar = username_dari_nama($nama);
        $username = $dasar;
        $n = 1;
        while (one('SELECT id FROM users WHERE username = ?', [$username])) {
            $n++;
            $username = $dasar . $n;
        }
        // Kata sandi acak (tidak pernah ditampilkan) — Super Admin menetapkannya saat meninjau.
        $sandi = bin2hex(random_bytes(8));
        q('INSERT INTO users (username, password_hash, nama, role, sekolah_id, guru_id, aktif, nip, asal, dibuat)
           VALUES (?,?,?,?,NULL,NULL,0,?,?,?)',
            [$username, password_hash($sandi, PASSWORD_DEFAULT), $nama, 'pengawas', $nip, 'pendaftaran', date('Y-m-d H:i:s')]);
        $uid = (int) db()->lastInsertId();
        $baru = true;
    }

    // Binaan: sekolah yang baru didaftarkan (hanya bila sekolahnya memang disetujui & aktif).
    if (one('SELECT id FROM sekolah WHERE id = ? AND verifikasi = "disetujui" AND aktif = 1', [$sekolahId])) {
        q('INSERT OR IGNORE INTO pengawas_sekolah (user_id, sekolah_id, dibuat) VALUES (?,?,?)',
            [$uid, $sekolahId, date('Y-m-d H:i:s')]);
    }

    return [
        'nama'     => (string) ($lama['nama'] ?? $nama),
        'username' => (string) ($lama['username'] ?? $username),
        'nip'      => $baru ? $nip : nip_bersih((string) ($lama['nip'] ?? '')),
        'baru'     => $baru,
    ];
}

/** Admin di dalam konteks sekolah: admin sekolah, atau super admin yang sedang mengelola sekolah. */
function is_admin(): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    return $u['role'] === 'super' && current_sekolah() !== null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['redirect_after'] = $_SERVER['REQUEST_URI'] ?? '';
        $target = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($target)), '/');
        redirect(($dir === '' ? '' : $dir . '/') . 'login.php');
    }
    // Tandai bahwa akun ini masih aktif memakai aplikasi (dasar daftar "sedang online").
    log_sesi_ping();
    // Saat masa aktif habis, SELURUH akun terkunci ke halaman Status & Aktivasi (tempat kolom
    // kode aktifasi tersedia). Data tidak disentuh sama sekali.
    gerbang_aktivasi();
    return $u;
}

function require_super(): array
{
    $u = require_login();
    if ($u['role'] !== 'super') {
        http_response_code(403);
        exit('Halaman ini hanya untuk Super Admin.');
    }
    return $u;
}

function require_pengawas(): array
{
    $u = require_login();
    if ($u['role'] !== 'pengawas') {
        http_response_code(403);
        exit('Halaman ini hanya untuk pengawas sekolah.');
    }
    return $u;
}

/**
 * Pastikan ada sekolah aktif dalam konteks.
 * Super admin yang belum memilih sekolah diarahkan ke halaman Kelola Sekolah.
 */
function require_sekolah(): array
{
    $u = require_login();
    $s = current_sekolah();
    if ($s) {
        return $s;
    }
    if ($u['role'] === 'super') {
        flash('Pilih sekolah yang ingin dikelola terlebih dahulu.', 'warn');
        redirect('sekolah.php');
    }
    if ($u['role'] === 'pengawas') {
        flash('Pilih sekolah binaan yang ingin dilihat rekapnya.', 'warn');
        redirect('index.php');
    }
    http_response_code(403);
    exit('Akun ini belum ditautkan ke sekolah mana pun. Hubungi administrator.');
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] === 'super') {
        require_sekolah();
        return $u;
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Halaman ini hanya untuk administrator/kepala sekolah.');
    }
    require_sekolah();
    return $u;
}

// ---------------------------------------------------------------- data umum

/**
 * Daftar menu supervisi. Menu & butir kini GLOBAL (satu daftar untuk semua sekolah/madrasah),
 * jadi tidak lagi difilter per sekolah.
 */
function instrumen_list(bool $aktifSaja = true): array
{
    return all('SELECT i.*, (SELECT COUNT(*) FROM butir b WHERE b.instrumen_id = i.id AND b.aktif = 1) AS jml_butir,
                       (SELECT COUNT(*) FROM butir b WHERE b.instrumen_id = i.id AND b.aktif = 0) AS jml_nonaktif
                FROM instrumen i' . ($aktifSaja ? ' WHERE i.aktif = 1' : '') . '
                ORDER BY i.urutan, i.id');
}

/** Jumlah butir global (aktif) — sama untuk seluruh sekolah. */
function jumlah_butir_global(bool $aktifSaja = true): int
{
    return (int) val('SELECT COUNT(*) FROM butir' . ($aktifSaja ? ' WHERE aktif = 1' : ''), [], 0);
}

/** Statistik ringkas sebuah sekolah (dipakai dashboard super admin & halaman Kelola Sekolah). */
function sekolah_stat(int $sid): array
{
    $guru = (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ? AND aktif = 1', [$sid], 0);
    $users = (int) val('SELECT COUNT(*) FROM users WHERE sekolah_id = ?', [$sid], 0);
    $supervisi = (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ? AND status = "final"', [$sid], 0);
    $draft = (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ? AND status = "draft"', [$sid], 0);
    $rata = (float) val('SELECT AVG(nilai) FROM supervisi WHERE sekolah_id = ? AND status = "final"', [$sid], 0);
    $terakhir = val('SELECT MAX(tanggal) FROM supervisi WHERE sekolah_id = ?', [$sid]);
    $jadwal = (int) val('SELECT COUNT(*) FROM jadwal WHERE sekolah_id = ?', [$sid], 0);
    $jadwalHari = (int) val('SELECT COUNT(DISTINCT tanggal) FROM jadwal WHERE sekolah_id = ?', [$sid], 0);
    $guruDinilai = (int) val('SELECT COUNT(DISTINCT guru_id) FROM supervisi WHERE sekolah_id = ? AND status = "final"', [$sid], 0);
    return [
        'guru'        => $guru,
        'users'       => $users,
        'supervisi'   => $supervisi,
        'draft'       => $draft,
        'rata'        => $rata,
        'terakhir'    => $terakhir ?: null,
        'jadwal'      => $jadwal,
        'jadwal_hari' => $jadwalHari,
        'guru_dinilai'=> $guruDinilai,
        'cakupan'     => $guru > 0 ? (int) round($guruDinilai / $guru * 100) : 0,
    ];
}

/**
 * Hitung ulang total skor, nilai, dan predikat untuk supervisi tertentu.
 * Dipakai setelah butir diubah/dihapus agar nilai lama tidak salah.
 * Skor yang melebihi skor maksimal baru akan dipangkas lebih dulu.
 *
 * @param array<int,int> $ids daftar id supervisi
 */
function recompute_supervisi(array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $th = thresholds();
    $ubah = 0;
    $upd = db()->prepare('UPDATE supervisi SET total_skor=?, skor_maks=?, nilai=?, predikat=?, diubah=? WHERE id=?');
    $now = date('Y-m-d H:i:s');
    foreach ($ids as $sid) {
        $arsip = (int) val('SELECT COUNT(*) FROM detail WHERE supervisi_id = ? AND butir_id IS NULL', [$sid], 0);
        if ($arsip > 0) {
            // Penilaian HISTORIS (dibuat sebelum butir menjadi global): barisnya menyimpan nama &
            // skor maksimal lama di kolom arsip. Jangan dihitung ulang / dihapus, agar riwayat utuh.
            continue;
        }
        $rows = all('SELECT d.id, d.skor, b.skor_maks FROM detail d JOIN butir b ON b.id = d.butir_id WHERE d.supervisi_id = ?', [$sid]);
        if (!$rows) {
            // Tidak ada butir tersisa sama sekali: penilaian ini tidak bermakna lagi.
            q('DELETE FROM supervisi WHERE id = ?', [$sid]);
            $ubah++;
            continue;
        }
        $total = 0.0;
        $maks = 0.0;
        foreach ($rows as $r) {
            $maksButir = (float) $r['skor_maks'];
            $skor = (float) $r['skor'];
            if ($skor > $maksButir) {
                $skor = $maksButir;
                q('UPDATE detail SET skor = ? WHERE id = ?', [$skor, (int) $r['id']]);
            }
            $total += $skor;
            $maks += $maksButir;
        }
        $nilai = $maks > 0 ? round($total / $maks * 100, 2) : 0.0;
        $upd->execute([$total, $maks, $nilai, predikat_dari($nilai, $th), $now, $sid]);
        $ubah++;
    }
    return $ubah;
}

// ---------------------------------------------------------------- NIP / NUPTK guru

/**
 * Bersihkan isian NIP/NUPTK.
 *
 * Pemilik aplikasi memakai tanda "-" bila guru belum punya NIP, jadi tanda hubung
 * (juga titik/garis bawah) dan kata seperti "tidak ada" diperlakukan sebagai KOSONG.
 * Dengan begitu beberapa guru boleh sama-sama belum punya NIP tanpa dianggap duplikat.
 */
function nip_bersih($v): string
{
    $s = trim((string) $v);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^[-–—_.\s]+$/u', $s) === 1) {
        return '';
    }
    $lower = strtolower($s);
    if (in_array($lower, ['n/a', 'na', 'none', 'null', 'tidak ada', 'belum ada', '-'], true)) {
        return '';
    }
    return $s;
}

/** Usulan nama pengguna dari nama guru (dipakai bila NIP belum ada). */
function username_dari_nama(string $nama): string
{
    $u = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '.', $nama));
    $u = trim((string) preg_replace('/\.+/', '.', $u), '.');
    if ($u === '' || strlen($u) < 3) {
        $u = 'guru';
    }
    return substr($u, 0, 60);
}

/**
 * Nama pengguna unik se-aplikasi untuk seorang guru:
 * memakai NIP bila ada, atau nama guru bila NIP kosong/"-".
 */
function guru_username_unik(array $guru): string
{
    $nip = nip_bersih($guru['nip'] ?? '');
    $base = $nip !== '' ? $nip : username_dari_nama((string) ($guru['nama'] ?? ''));
    $username = $base;
    $n = 1;
    while (one('SELECT id FROM users WHERE username = ?', [$username])) {
        $n++;
        $username = $base . $n;
    }
    return $username;
}

/** Kata sandi awal seorang guru: NIP bila ada (min. 6 karakter), atau 'guru123'. */
function guru_sandi_awal(array $guru): string
{
    $nip = nip_bersih($guru['nip'] ?? '');
    return strlen($nip) >= 6 ? $nip : 'guru123';
}

// ---------------------------------------------------------------- foto profil & unggah berkas

/**
 * Kirim bytes berkas ke media proxy platform. Token dibaca dari berkas
 * .vibecoder-media-token (server-side saja) dan tidak pernah dikembalikan/ditampilkan.
 *
 * @return array{ok:bool,url?:string,pesan?:string}
 */
function media_upload(string $bytes, string $filename): array
{
    if ($bytes === '') {
        return ['ok' => false, 'pesan' => 'Berkas kosong.'];
    }
    $tokenFile = dirname(__DIR__) . '/.vibecoder-media-token';
    if (!is_file($tokenFile) || !is_readable($tokenFile)) {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap (token unggah tidak ditemukan).'];
    }
    $token = trim((string) @file_get_contents($tokenFile));
    if ($token === '') {
        return ['ok' => false, 'pesan' => 'Layanan penyimpanan berkas belum siap (token unggah kosong).'];
    }

    $endpoint = MEDIA_ENDPOINT . '?filename=' . rawurlencode($filename);
    $body = false;
    $code = 0;
    $err = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => ['X-App-Media-Token: ' . $token, 'Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 180,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "X-App-Media-Token: " . $token . "\r\nContent-Type: application/octet-stream\r\n",
            'content'       => $bytes,
            'timeout'       => 180,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $code = (int) $m[1];
                }
            }
        }
        if ($body === false) {
            $err = 'tidak dapat menghubungi layanan unggah';
        }
    }

    if ($body === false || $body === null || $body === '') {
        return ['ok' => false, 'pesan' => 'Gagal mengunggah berkas. ' . trim($err)];
    }

    $json = json_decode((string) $body, true);
    if (is_array($json) && !empty($json['ok']) && !empty($json['url'])) {
        return ['ok' => true, 'url' => (string) $json['url']];
    }

    $pesan = (is_array($json) && !empty($json['error'])) ? (string) $json['error'] : '';
    if ($code === 403) {
        return ['ok' => false, 'pesan' => 'Kuota penyimpanan berkas aplikasi sudah penuh' . ($pesan !== '' ? ' (' . $pesan . ')' : '') . '. Hubungi pengelola aplikasi.'];
    }
    if ($code === 401) {
        return ['ok' => false, 'pesan' => 'Izin unggah aplikasi tidak valid. Hubungi pengelola platform.'];
    }
    return ['ok' => false, 'pesan' => 'Unggahan ditolak: ' . ($pesan !== '' ? $pesan : 'HTTP ' . $code)];
}

/** Validasi sederhana berkas gambar yang diunggah untuk foto profil. */
function validasi_foto(string $namaAsli, int $ukuran, string $tmpPath): array
{
    $ext = strtolower((string) pathinfo($namaAsli, PATHINFO_EXTENSION));
    if (!in_array($ext, FOTO_EKSTENSI, true)) {
        return ['ok' => false, 'pesan' => 'Format foto harus ' . implode(', ', FOTO_EKSTENSI) . ' (berkas Anda: ' . ($ext !== '' ? $ext : 'tanpa ekstensi') . ').'];
    }
    if ($ukuran <= 0) {
        return ['ok' => false, 'pesan' => 'Berkas foto kosong atau gagal diunggah.'];
    }
    if ($ukuran > FOTO_MAKS_BYTE) {
        return ['ok' => false, 'pesan' => 'Ukuran foto maksimal 15 MB (berkas Anda ' . number_format($ukuran / 1048576, 1, ',', '.') . ' MB).'];
    }
    if ($ext !== 'svg') {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return ['ok' => false, 'pesan' => 'Berkas ini sepertinya bukan gambar yang sah.'];
        }
    }
    return ['ok' => true];
}

/** Foto profil pengguna (null bila belum ada). */
function foto_user(?array $u): ?string
{
    $f = $u['foto'] ?? null;
    return ($f !== null && $f !== '') ? (string) $f : null;
}

/** Foto profil guru berdasarkan data guru (diambil dari akun yang tertaut). */
function foto_guru(int $guruId): ?string
{
    if ($guruId <= 0) {
        return null;
    }
    $f = val('SELECT foto FROM users WHERE guru_id = ? AND foto IS NOT NULL AND foto <> "" LIMIT 1', [$guruId]);
    return $f ? (string) $f : null;
}

/** Avatar: foto bila ada, atau inisial nama dengan gradien. */
function avatar_html(string $nama, ?string $foto, int $size = 36, string $kelas = 'avatar'): string
{
    $nama = trim($nama);
    $inisial = e(strtoupper(substr($nama !== '' ? $nama : 'U', 0, 1)));
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(11, (int) round($size / 2.5)) . 'px';
    if ($foto !== null && $foto !== '') {
        return '<span class="' . e($kelas) . ' avatar-img" style="' . $style . '">'
            . '<img src="' . e($foto) . '" alt="' . e($nama) . '" loading="lazy"></span>';
    }
    return '<span class="' . e($kelas) . '" style="' . $style . '">' . $inisial . '</span>';
}

// ---------------------------------------------------------------- bukti foto pengamatan pembelajaran

/**
 * Benar bila menu supervisi ini menyediakan bukti foto pengamatan pembelajaran.
 * Hanya menu bawaan "Supervisi Pengamatan Pembelajaran" (kode `pengamatan`) — sesuai
 * permintaan pemilik. Kode menu tidak dapat diubah setelah dibuat, jadi penjagaan ini stabil.
 */
function supervisi_foto_berlaku(?string $kode): bool
{
    return trim((string) $kode) === SUPERVISI_FOTO_KODE;
}

/** Daftar foto bukti sebuah supervisi (urut nomor tampil). */
function supervisi_foto(int $supervisiId): array
{
    if ($supervisiId <= 0) {
        return [];
    }
    return all('SELECT * FROM supervisi_foto WHERE supervisi_id = ? ORDER BY urutan, id', [$supervisiId]);
}

/** Jumlah foto bukti sebuah supervisi. */
function supervisi_foto_jumlah(int $supervisiId): int
{
    return (int) val('SELECT COUNT(*) FROM supervisi_foto WHERE supervisi_id = ?', [$supervisiId], 0);
}

/** Rapikan nomor urut foto (1..n) setelah sebuah foto dihapus. */
function supervisi_foto_urutkan_ulang(int $supervisiId): void
{
    $n = 0;
    foreach (supervisi_foto($supervisiId) as $f) {
        $n++;
        if ((int) $f['urutan'] !== $n) {
            q('UPDATE supervisi_foto SET urutan = ? WHERE id = ?', [$n, (int) $f['id']]);
        }
    }
}

/** Daftar id supervisi yang memakai butir tertentu. */
function supervisi_pemakai_butir(int $butirId): array
{
    return array_map('intval', array_column(
        all('SELECT DISTINCT d.supervisi_id AS id FROM detail d WHERE d.butir_id = ?', [$butirId]),
        'id'
    ));
}

// ============================================================================================
// AKTIVASI SEKOLAH (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// "Buatkan kode aktifasi untuk sekolah yang mendaftar. Bedanya jika belum diaktifasi tidak semua
//  fitur bisa digunakan atau dibatasi ... Jika melebihi muncul pop up aplikasi belum diaktifasi."
//
// Dua keadaan saja (keputusan pemilik): `dibatasi` → `aktif`. Sekolah yang MENDAFTAR SENDIRI masuk
// `dibatasi`; sekolah lama & sekolah yang dibuat Super Admin berkeadaan `aktif`.
// Yang boleh MENGUBAH keadaan hanya Super Admin; admin sekolah hanya melihat status.
// ============================================================================================

/** Nilai satu pengaturan tingkat APLIKASI (tabel `app_pengaturan`). */
function app_setting(string $kunci, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT nilai FROM app_pengaturan WHERE kunci = ?');
        $st->execute([$kunci]);
        $v = $st->fetchColumn();
    } catch (Throwable $ex) {
        // Tabel belum ada (mis. skema belum menyusul) → pakai nilai bawaan, jangan gagalkan halaman.
        return $default;
    }
    return $v === false || $v === null || (string) $v === '' ? $default : (string) $v;
}

/** Simpan pengaturan tingkat aplikasi. */
function app_setting_set(string $kunci, string $nilai): void
{
    q('INSERT INTO app_pengaturan (kunci, nilai, diubah) VALUES (?,?,?)
       ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai, diubah = excluded.diubah',
        [$kunci, $nilai, date('Y-m-d H:i:s')]);
}

/**
 * Abjad kode aktivasi: Crockford base32 (32 karakter, tanpa huruf I, L, O, U supaya tidak mudah
 * tertukar). Jumlahnya WAJIB tepat 32 karena generator memetakan byte (0-255) ke indeks dengan
 * `% 32` — 256 habis dibagi 32 sehingga pemetaannya seragam/tanpa bias.
 */
function aktivasi_abjad(): string
{
    return '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
}

/**
 * Bentuk baku sebuah kode aktivasi: huruf besar, tanpa tanda hubung/spasi, dan huruf yang mudah
 * tertukar disamakan (O→0, I/L→1) supaya pengguna boleh menulis kode dengan cara apa pun.
 */
function aktivasi_normalisasi(string $teks): string
{
    $bersih = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($teks));
    return strtr($bersih, ['O' => '0', 'I' => '1', 'L' => '1']);
}

/**
 * Kunci rahasia generator kode aktivasi — SENGAJA di BASIS DATA, bukan berkas di `data/`
 * (berkas di folder itu dapat diunduh lewat web; berkas basis data tidak).
 * JANGAN menggantinya setelah kode dibagikan: seluruh kode aktivasi akan berubah.
 */
function aktivasi_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $ada = app_setting('aktivasi_secret');
    if (strlen($ada) >= 32) {
        $secret = $ada;
        return $secret;
    }
    $baru = bin2hex(random_bytes(24));
    try {
        app_setting_set('aktivasi_secret', $baru);
        $secret = $baru;
    } catch (Throwable $ex) {
        $secret = (string) (defined('AKTIVASI_SECRET_CADANGAN') ? AKTIVASI_SECRET_CADANGAN : 'supervisi-cadangan');
    }
    return $secret;
}

/**
 * KODE AKTIVASI sebuah sekolah — UNIK berdasar NAMA sekolah (ditambah id sekolah supaya dua
 * lembaga bernama sama tetap berbeda) dan kunci rahasia aplikasi.
 *
 * DETERMINISTIK: nama/id yang sama selalu menghasilkan kode yang sama, sehingga Super Admin dapat
 * menampilkan/mengirim ulang kode kapan saja tanpa perlu menyimpannya di basis data.
 * Format: 3 kelompok × 4 karakter.
 */
function kode_aktivasi_sekolah(?array $sekolah): string
{
    if (!$sekolah) {
        return '';
    }
    $nama = aktivasi_normalisasi((string) ($sekolah['nama'] ?? ''));
    if ($nama === '') {
        $nama = 'TANPA-NAMA';
    }
    // NOMOR PERIODE ikut dihitung (permintaan pemilik: "jika masa aktif habis bisa menghubungi
    // kembali HD Admin untuk meminta kode aktifasi yang baru"). Karena itu kode untuk periode
    // berikutnya SELALU BERBEDA dan kode lama tidak dapat dipakai lagi setelah dipakai.
    $periode = max(1, (int) ($sekolah['aktivasi_ke'] ?? 1));
    $biner = hash_hmac('sha256', $nama . '|' . (int) ($sekolah['id'] ?? 0) . '|' . $periode, aktivasi_secret(), true);
    $abjad = aktivasi_abjad();
    $jumlah = strlen($abjad);   // 32
    $kode = '';
    for ($i = 0; $i < 12; $i++) {
        // 256 habis dibagi 32 → tidak ada bias pada pemetaan byte ke indeks abjad.
        $kode .= $abjad[ord($biner[$i]) % $jumlah];
    }
    return substr($kode, 0, 4) . '-' . substr($kode, 4, 4) . '-' . substr($kode, 8, 4);
}

/** true bila kode aktivasi yang dimasukkan cocok dengan kode lembaga tersebut. */
function kode_aktivasi_cocok(?array $sekolah, string $masukan): bool
{
    $benar = kode_aktivasi_sekolah($sekolah);
    if ($benar === '' || trim($masukan) === '') {
        return false;
    }
    return hash_equals(aktivasi_normalisasi($benar), aktivasi_normalisasi($masukan));
}

/**
 * Keadaan aktivasi sebuah sekolah:
 *   `aktif`      → seluruh fitur terbuka (dan masa aktifnya belum habis),
 *   `dibatasi`   → belum pernah diaktivasi (mode terbatas), masa uji coba masih berjalan,
 *   `uji_habis`  → BELUM pernah diaktivasi dan masa uji coba 15 hari-nya sudah habis → aplikasi
 *                  terkunci sampai kode aktivasi dimasukkan,
 *   `kadaluarsa` → sudah pernah diaktivasi, tetapi MASA AKTIF 1 TAHUN-nya sudah habis.
 * Tanpa konteks sekolah (mis. Super Admin lintas sekolah) → `aktif` (tidak membatasi apa pun).
 */
function aktivasi_sekolah(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if (!$sekolah) {
        return 'aktif';
    }
    if ((string) ($sekolah['aktivasi'] ?? 'aktif') === 'dibatasi') {
        // Sekolah yang belum diaktivasi hanya diberi waktu terbatas sejak terdaftar (kini 15 hari;
        // pemilik). Setelah itu aplikasi terkunci — tetap perlu kode aktivasi untuk dibuka.
        return aktivasi_uji_habis($sekolah) ? 'uji_habis' : 'dibatasi';
    }
    return aktivasi_habis($sekolah) ? 'kadaluarsa' : 'aktif';
}

/** true bila sekolah yang sedang aktif masih berkeadaan TERBATAS (belum diaktivasi / masa aktif habis). */
function aktivasi_terbatas(?array $sekolah = null): bool
{
    return aktivasi_sekolah($sekolah) !== 'aktif';
}

/** true bila MASA AKTIF sekolah sudah habis (aktivasi masih 'aktif' tetapi batasnya terlampaui). */
function aktivasi_habis(?array $sekolah = null): bool
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    $sampai = trim((string) ($sekolah['aktif_sampai'] ?? ''));
    if ($sampai === '') {
        return false;   // belum diatur (data lama) → dianggap belum habis
    }
    $ts = strtotime($sampai);
    return $ts !== false && $ts <= time();
}

/** Label keadaan aktivasi untuk ditampilkan. */
function aktivasi_label(?array $sekolah = null): string
{
    $keadaan = aktivasi_sekolah($sekolah);
    if ($keadaan === 'kadaluarsa') {
        return 'Masa aktif habis (mode terbatas)';
    }
    if ($keadaan === 'uji_habis') {
        return 'Masa uji coba habis (belum diaktivasi)';
    }
    return $keadaan === 'dibatasi' ? 'Belum diaktivasi (dibatasi)' : 'Aktif';
}

/** Masa aktif satu periode aktivasi, dalam hari (permintaan pemilik: 1 tahun = 365 hari). */
function aktivasi_hari(): int
{
    return max(1, (int) AKTIVASI_HARI_AKTIF);
}

/**
 * Lama MASA UJI COBA bagi sekolah yang belum diaktivasi, dalam hari (permintaan pemilik: 15 hari — semula 30 hari).
 * Hitungannya dimulai saat sekolah mulai terdaftar.
 */
function aktivasi_hari_uji(): int
{
    return max(1, (int) AKTIVASI_HARI_UJI);
}

/**
 * Titik MULAI masa uji coba sebuah sekolah (teks tanggal, kosong bila tidak diketahui).
 *
 * Dipakai `sekolah.uji_mulai` (diisi saat sekolah mendaftar — lihat daftar.php). Sekolah yang
 * sudah ada sebelum fitur ini memakai **tanggal pendaftarannya sendiri** (`tanggal_daftar`,
 * cadangan `dibuat`) sehingga "waktunya disesuaikan dengan waktu mereka mendaftar".
 */
function aktivasi_uji_mulai(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if (!$sekolah) {
        return '';
    }
    foreach (['uji_mulai', 'tanggal_daftar', 'dibuat'] as $kunci) {
        $nilai = trim((string) ($sekolah[$kunci] ?? ''));
        if ($nilai !== '') {
            return $nilai;
        }
    }
    return '';
}

/** Batas AKHIR masa uji coba (teks tanggal, kosong bila titik mulainya tidak diketahui). */
function aktivasi_uji_sampai(?array $sekolah = null): string
{
    $mulai = aktivasi_uji_mulai($sekolah);
    if ($mulai === '') {
        return '';
    }
    $ts = strtotime($mulai);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d H:i:s', $ts + aktivasi_hari_uji() * 86400);
}

/**
 * true bila masa uji coba sekolah ini SUDAH HABIS (dan sekolah itu belum diaktivasi).
 *
 * Hanya berlaku bagi sekolah berkeadaan `dibatasi` — sekolah yang sudah diaktivasi tidak lagi
 * dihitung masa uji cobanya. Bila tanggal pendaftarannya tidak tercatat (`uji_mulai`,
 * `tanggal_daftar`, `dibuat` semuanya kosong), sekolah TIDAK dikunci: lebih baik tetap berjalan
 * dalam mode terbatas daripada mengunci sekolah yang tanggalnya tidak diketahui.
 */
function aktivasi_uji_habis(?array $sekolah = null): bool
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if (!$sekolah || (string) ($sekolah['aktivasi'] ?? 'aktif') !== 'dibatasi') {
        return false;
    }
    $sampai = aktivasi_uji_sampai($sekolah);
    if ($sampai === '') {
        return false;
    }
    $ts = strtotime($sampai);
    return $ts !== false && $ts <= time();
}

/**
 * Ringkasan masa aktif sebuah sekolah — dipakai halaman Status & Aktivasi, banner, dan
 * halaman Kelola Sekolah (Super Admin).
 *
 * Ikut memuat ringkasan MASA UJI COBA 30 HARI bagi sekolah yang belum diaktivasi (kunci berawalan
 * `uji_`) supaya halaman dapat menampilkan hitung mundurnya dari satu sumber yang sama.
 *
 * @return array{keadaan:string,aktif:bool,sampai:string,sampai_ts:int,sisa_detik:int,
 *               sisa_hari:int,habis:bool,aktivasi_pada:string,periode:int,
 *               uji_mulai:string,uji_sampai:string,uji_sampai_ts:int,uji_sisa_detik:int,
 *               uji_sisa_hari:int,uji_habis:bool}
 */
function aktivasi_status(?array $sekolah = null): array
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    $sampai = trim((string) ($sekolah['aktif_sampai'] ?? ''));
    $ts = $sampai !== '' ? (int) strtotime($sampai) : 0;
    $sekarang = time();
    $sisa = ($ts > 0) ? max(0, $ts - $sekarang) : 0;
    $keadaan = aktivasi_sekolah($sekolah);
    // Masa uji coba (kini 15 hari; hanya berarti bagi sekolah yang belum diaktivasi).
    $ujiMulai = aktivasi_uji_mulai($sekolah);
    $ujiSampai = $ujiMulai !== '' ? aktivasi_uji_sampai($sekolah) : '';
    $ujiTs = $ujiSampai !== '' ? (int) strtotime($ujiSampai) : 0;
    $ujiSisa = $ujiTs > 0 ? max(0, $ujiTs - $sekarang) : 0;
    return [
        'keadaan'      => $keadaan,
        'aktif'        => $keadaan === 'aktif',
        'sampai'       => $sampai,
        'sampai_ts'    => $ts,
        'sisa_detik'   => $sisa,
        'sisa_hari'    => $ts > 0 ? (int) ceil($sisa / 86400) : 0,
        'habis'        => $keadaan === 'kadaluarsa',
        'aktivasi_pada' => trim((string) ($sekolah['aktivasi_pada'] ?? '')),
        'periode'      => max(1, (int) ($sekolah['aktivasi_ke'] ?? 1)),
        'uji'          => $keadaan === 'dibatasi' || $keadaan === 'uji_habis',
        'uji_mulai'    => $ujiMulai,
        'uji_sampai'   => $ujiSampai,
        'uji_sampai_ts' => $ujiTs,
        'uji_sisa_detik' => $ujiSisa,
        'uji_sisa_hari' => $ujiTs > 0 ? (int) ceil($ujiSisa / 86400) : 0,
        'uji_habis'    => $keadaan === 'uji_habis',
    ];
}

/**
 * Benar bila aplikasi sekolah dalam konteks TERKUNCI — seluruh akun tidak dapat memakai fitur
 * sampai kode aktivasi dimasukkan.
 *
 * Permintaan pemilik: "Saat masa aktif habis, buat aplikasi otomatis mengunci semua akun tanpa
 * menghilangkan data sebelumnya dan otomatis kolom input kode aktifasi muncul. dan meminta pengguna
 * untuk mengaktifkan kembali dengan menghubungi HD aplikasi."
 *
 * Dua keadaan yang mengunci:
 *   - `kadaluarsa` — sudah pernah diaktivasi, tetapi masa aktif 1 tahun-nya sudah habis;
 *   - `uji_habis`  — BELUM pernah diaktivasi dan masa uji coba (15 hari) sejak terdaftar sudah habis.
 *
 * Beda dengan `dibatasi` (belum pernah diaktivasi, tetapi masa uji cobanya MASIH berjalan):
 * sekolah seperti itu tetap boleh dipakai dalam MODE TERBATAS (1 guru, 1 supervisi per menu,
 * 1 jadwal) — itu keputusan pemilik sebelumnya.
 */
function aktivasi_terkunci(?array $sekolah = null): bool
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if ($sekolah === null) {
        return false;
    }
    return in_array(aktivasi_sekolah($sekolah), ['kadaluarsa', 'uji_habis'], true);
}

/** Berkas yang TETAP boleh dibuka walau aplikasi terkunci (tempat kode dimasukkan & urusan akun sendiri). */
function aktivasi_gerbang_bebas(): array
{
    return ['aktivasi.php', 'logout.php', 'login.php', 'akun.php', 'upload_foto.php'];
}

/** Benar bila permintaan ini datang dari skrip (AJAX/fetch) yang mengharapkan balasan JSON. */
function aktivasi_permintaan_ajax(): bool
{
    if ((string) ($_GET['format'] ?? '') === 'json' || (string) ($_POST['format'] ?? '') === 'json') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $acc = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($acc, 'application/json') !== false && strpos($acc, 'text/html') === false;
}

/** Pesan yang dipakai saat aplikasi terkunci (dipakai banner, gerbang, dan halaman aktivasi). */
function aktivasi_pesan_kunci(?array $sekolah = null): string
{
    $st = aktivasi_status($sekolah);
    if ($st['keadaan'] === 'uji_habis') {
        $kapan = $st['uji_sampai_ts'] > 0 ? ' (masa uji coba berakhir ' . waktu_id_polos($st['uji_sampai']) . ')' : '';
        return 'Masa uji coba ' . (int) aktivasi_hari_uji() . ' hari sudah habis' . $kapan
            . ' dan sekolah/madrasah ini belum diaktivasi, sehingga aplikasi TERKUNCI: seluruh akun '
            . 'tidak dapat memakai fitur sampai kode aktivasi dimasukkan. Seluruh data sebelumnya '
            . 'tetap aman (tidak ada yang dihapus). Hubungi HD Admin Aplikasi untuk meminta kode '
            . 'aktifasi (WA ' . AKTIVASI_WA_ADMIN . ').';
    }
    $kapan = $st['sampai_ts'] > 0 ? ' (masa aktif berakhir ' . waktu_id_polos($st['sampai']) . ')' : '';
    return 'Masa aktif aplikasi sudah habis' . $kapan . ' sehingga aplikasi TERKUNCI: seluruh akun '
        . 'tidak dapat memakai fitur sampai kode aktifasi yang baru dimasukkan. Seluruh data sebelumnya '
        . 'tetap aman (tidak ada yang dihapus). Hubungi HD Admin Aplikasi untuk meminta kode aktifasi '
        . '(WA ' . AKTIVASI_WA_ADMIN . ').';
}

/**
 * GERBANG KUNCI APLIKASI — dipanggil dari require_login(), jadi berlaku untuk SELURUH halaman
 * yang butuh masuk (halaman pengelolaan, laporan, dan semua lembar cetak/ekspor).
 *
 * Yang terkunci:
 *   - akun sekolah yang masa aktifnya habis (admin/kepala, guru, dan operator) → dialihkan ke
 *     `aktivasi.php` tempat kolom kode aktifasi sudah tersedia,
 *   - pengawas yang sedang membuka sekolah binaan tersebut (kode tetap hanya bisa dimasukkan
 *     pengelola sekolah/Super Admin).
 * Yang TIDAK terkunci: **Super Admin** (pemilik aplikasi — harus tetap bisa membuka sekolah mana pun
 * untuk membantu aktivasi), sekolah yang belum pernah diaktivasi (mode terbatas), serta berkas pada
 * `aktivasi_gerbang_bebas()`.
 */
function gerbang_aktivasi(): void
{
    if (PHP_SAPI === 'cli' || !aktivasi_terkunci() || is_super()) {
        return;
    }
    $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($here === '' || in_array($here, aktivasi_gerbang_bebas(), true)) {
        return;
    }
    $pesan = aktivasi_pesan_kunci();
    // Permintaan AJAX/JSON (mis. unggahan berkas) dijawab 403 dengan JSON — bukan dialihkan, supaya
    // klien tidak menerima halaman HTML aktivasi sebagai "jawaban JSON".
    if (aktivasi_permintaan_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['ok' => false, 'terkunci' => true, 'error' => $pesan]));
    }
    flash($pesan, 'err');
    redirect('aktivasi.php?terkunci=1');
}

/** Keadaan aktivasi dalam bentuk teks ringkas untuk banner/pil (istilah seragam di aplikasi). */
function aktivasi_pil_teks(?array $sekolah = null): string
{
    $keadaan = aktivasi_sekolah($sekolah);
    if ($keadaan === 'kadaluarsa') {
        return 'masa aktif habis';
    }
    if ($keadaan === 'uji_habis') {
        return 'masa uji habis';
    }
    return $keadaan === 'dibatasi' ? 'dibatasi' : 'aktif';
}

/**
 * RINGKASAN MASA AKTIF sebuah sekolah untuk ditampilkan pada tabel lintas sekolah
 * (permintaan pemilik: kolom "Masa Aktif" pada tabel Peringkat Sekolah di dasbor Super Admin).
 *
 * @return array{keadaan:string,label:string,kelas:string,ket:string,tanggal:string,sampai:string,sisa_hari:int}
 */
function aktivasi_masa_aktif(?array $sekolah): array
{
    $st = aktivasi_status($sekolah);
    if ($st['keadaan'] === 'kadaluarsa') {
        return [
            'keadaan'   => 'kadaluarsa',
            'label'     => 'Masa aktif habis',
            'kelas'     => 'pill-err',
            'ket'       => 'perlu kode baru',
            'tanggal'   => $st['sampai_ts'] > 0 ? waktu_id_polos($st['sampai']) : '',
            'sampai'    => $st['sampai'],
            'sisa_hari' => 0,
        ];
    }
    if ($st['keadaan'] === 'uji_habis') {
        return [
            'keadaan'   => 'uji_habis',
            'label'     => 'Masa uji habis',
            'kelas'     => 'pill-err',
            'ket'       => 'belum diaktivasi',
            'tanggal'   => $st['uji_sampai_ts'] > 0 ? waktu_id_polos($st['uji_sampai']) : '',
            'sampai'    => $st['uji_sampai'],
            'sisa_hari' => 0,
        ];
    }
    if ($st['keadaan'] === 'dibatasi') {
        return [
            'keadaan'   => 'dibatasi',
            'label'     => 'Belum aktivasi',
            'kelas'     => 'pill-warn',
            'ket'       => $st['uji_sampai_ts'] > 0
                ? 'uji coba sisa ' . (int) $st['uji_sisa_hari'] . ' hari'
                : 'masa uji tidak tercatat',
            'tanggal'   => $st['uji_sampai_ts'] > 0 ? waktu_id_polos($st['uji_sampai']) : '',
            'sampai'    => $st['uji_sampai'],
            'sisa_hari' => (int) $st['uji_sisa_hari'],
        ];
    }
    // AKTIF. Sekolah lama yang belum punya `aktif_sampai` dianggap tanpa batas waktu.
    return [
        'keadaan'   => 'aktif',
        'label'     => 'Aktif',
        'kelas'     => 'pill-ok',
        'ket'       => $st['sampai_ts'] > 0
            ? 'sisa ' . (int) $st['sisa_hari'] . ' hari'
            : 'tanpa batas',
        'tanggal'   => $st['sampai_ts'] > 0 ? waktu_id_polos($st['sampai']) : '',
        'sampai'    => $st['sampai'],
        'sisa_hari' => (int) $st['sisa_hari'],
    ];
}

/**
 * KUNCI URUTAN "Masa Aktif" untuk tabel lintas sekolah (permintaan pemilik: *"urutan paling atas
 * adalah yang masa aktifnya paling banyak"* pada tabel Peringkat Sekolah dasbor Super Admin).
 *
 * Angka yang dikembalikan = sisa waktu dalam DETIK, jadi makin besar makin lama masa aktifnya:
 *   - `aktif` tanpa tanggal batas (sekolah lama)  → PHP_INT_MAX (paling atas, tidak pernah habis);
 *   - `aktif` dengan `aktif_sampai`               → sisa detik masa aktifnya;
 *   - `dibatasi` (uji coba masih berjalan) → sisa detik masa ujinya;
 *   - `dibatasi` tanpa tanggal uji / sudah habis
 *     (`kadaluarsa`, `uji_habis`)                 → -1 (paling bawah, perlu diaktivasi).
 *
 * JANGAN memakai `aktivasi_masa_aktif()['sisa_hari']` untuk mengurutkan: fungsi itu membulatkan ke
 * ATAS per hari sehingga dua sekolah yang sisa waktunya berbeda beberapa jam tampak seri.
 */
function aktivasi_urut_kunci(?array $sekolah): int
{
    $st = aktivasi_status($sekolah);
    if ($st['keadaan'] === 'aktif') {
        return $st['sampai_ts'] > 0 ? max(0, (int) $st['sisa_detik']) : PHP_INT_MAX;
    }
    if ($st['keadaan'] === 'dibatasi') {
        return $st['uji_sampai_ts'] > 0 ? max(0, (int) $st['uji_sisa_detik']) : -1;
    }
    return -1; // kadaluarsa / uji_habis
}

/** Ambang PERINGATAN popup masa aktif akan berakhir, dalam hari (1..N sebelum terkunci). */
function aktivasi_ingat_hari(): int
{
    return max(1, (int) AKTIVASI_INGAT_HARI);
}

/**
 * Data PERINGATAN POPUP masa aktif akan berakhir (permintaan pemilik):
 * "Tambahkan peringatan popup masa aktif akan berakhir untuk Lembaga yang masa aktifnya sisa 7 hari
 *  sampai sisa 1 hari sebelum benar-benar terkunci".
 *
 * Dua keadaan yang diperingatkan (keduanya berakhir dengan aplikasi TERKUNCI):
 *   - `aktif`    → masa aktif 1 tahun hampir habis (`kadaluarsa` bila lewat),
 *   - `dibatasi` → masa uji coba hampir habis (`uji_habis` bila lewat).
 *
 * Mengembalikan `null` bila sekolah tidak berada pada rentang sisa 1..aktivasi_ingat_hari() hari.
 *
 * @return array{jenis:string,judul:string,lead:string,sisa_hari:int,sampai:string,sampai_ts:int,sisa_detik:int}|null
 */
function aktivasi_peringatan(?array $sekolah = null): ?array
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if (!$sekolah) {
        return null;
    }
    $st = aktivasi_status($sekolah);
    $batas = aktivasi_ingat_hari();
    if ($st['keadaan'] === 'aktif' && $st['sampai_ts'] > 0) {
        $sisa = (int) $st['sisa_hari'];
        if ($sisa < 1 || $sisa > $batas) {
            return null;
        }
        return [
            'jenis'      => 'aktif',
            'judul'      => 'Masa Aktif Akan Berakhir',
            'lead'       => 'Masa aktif lembaga ini tinggal ' . $sisa . ' hari lagi (berakhir '
                . waktu_id_polos($st['sampai']) . '). Setelah itu aplikasi kembali ke mode terbatas '
                . 'sampai kode aktivasi yang baru dimasukkan.',
            'sisa_hari'  => $sisa,
            'sampai'     => $st['sampai'],
            'sampai_ts'  => (int) $st['sampai_ts'],
            'sisa_detik' => (int) $st['sisa_detik'],
        ];
    }
    if ($st['keadaan'] === 'dibatasi' && $st['uji_sampai_ts'] > 0) {
        $sisa = (int) $st['uji_sisa_hari'];
        if ($sisa < 1 || $sisa > $batas) {
            return null;
        }
        return [
            'jenis'      => 'uji',
            'judul'      => 'Masa Uji Coba Akan Berakhir',
            'lead'       => 'Lembaga ini belum diaktivasi dan masa uji coba ' . (int) aktivasi_hari_uji()
                . ' hari tinggal ' . $sisa . ' hari lagi (berakhir ' . waktu_id_polos($st['uji_sampai'])
                . '). Setelah itu aplikasi TERKUNCI sampai kode aktivasi dimasukkan.',
            'sisa_hari'  => $sisa,
            'sampai'     => $st['uji_sampai'],
            'sampai_ts'  => (int) $st['uji_sampai_ts'],
            'sisa_detik' => (int) $st['uji_sisa_detik'],
        ];
    }
    return null;
}

/**
 * Siapa yang boleh MEMASUKKAN kode aktivasi & mengaktifkan sekolahnya sendiri.
 *
 * Permintaan pemilik (pembaruan): "Pada menu status dan aktifasi pada pengguna tambahkan fitur untuk
 * memasukkan kode aktifasi yang dikirim oleh super admin. Dan pengguna bisa melakukan aktifasi sesuai
 * dengan kode yang dikirim oleh super admin."
 *
 * Jadi admin/kepala sekolah (pengelola sekolah itu) boleh mengaktifkan sendiri dengan kode yang
 * dikirim Super Admin; Super Admin juga boleh (dalam konteks sekolah). Guru & pengawas tetap hanya
 * MELIHAT status.
 */
function boleh_aktivasi(): bool
{
    $u = current_user();
    if (!$u || current_sekolah() === null) {
        return false;
    }
    return $u['role'] === 'admin' || $u['role'] === 'super';
}

/**
 * Hanya SUPER ADMIN yang boleh MELIHAT/MENYALIN kode aktivasi lembaga dan mengembalikan sekolah ke
 * mode terbatas (kode itulah yang dikirimkannya kepada pengelola sekolah).
 */
function boleh_lihat_kode_aktivasi(): bool
{
    return is_super() && current_sekolah() !== null;
}

// ---------------------------------------------------------------------------------------------
// Batas percobaan memasukkan kode aktivasi (anti tebak). Halaman aktivasi kini bisa dibuka akun
// sekolah, jadi percobaan kode dibatasi per SESI.
// ---------------------------------------------------------------------------------------------
const AKTIVASI_MAKS_COBA = 5;    // maksimal percobaan kode salah
const AKTIVASI_MENIT_COBA = 10;  // dalam rentang menit ini (dihitung sejak percobaan pertama)

/** @return array{0:int,1:int} jumlah percobaan & waktu percobaan pertama (0 = belum ada) */
function aktivasi_coba_ambil(): array
{
    $c = $_SESSION['aktivasi_coba'] ?? null;
    if (!is_array($c)) {
        return [0, 0];
    }
    $n = max(0, (int) ($c['n'] ?? 0));
    $mulai = (int) ($c['mulai'] ?? 0);
    if ($n === 0 || $mulai === 0) {
        return [0, 0];
    }
    if ($mulai + AKTIVASI_MENIT_COBA * 60 < time()) {
        return [0, 0];   // rentangnya sudah lewat → hitungan dimulai lagi
    }
    return [$n, $mulai];
}

/** Sisa percobaan yang masih boleh dilakukan pada rentang ini. */
function aktivasi_coba_sisa(): int
{
    [$n] = aktivasi_coba_ambil();
    return max(0, (int) AKTIVASI_MAKS_COBA - $n);
}

/** true bila percobaan sudah habis (harus menunggu rentang berikutnya). */
function aktivasi_coba_diblokir(): bool
{
    return aktivasi_coba_sisa() <= 0;
}

/** Catat satu percobaan kode yang SALAH. */
function aktivasi_coba_tambah(): void
{
    [$n, $mulai] = aktivasi_coba_ambil();
    $_SESSION['aktivasi_coba'] = ['n' => $n + 1, 'mulai' => $mulai > 0 ? $mulai : time()];
}

/** Kosongkan hitungan percobaan (dipakai setelah aktivasi berhasil). */
function aktivasi_coba_reset(): void
{
    unset($_SESSION['aktivasi_coba']);
}

/**
 * Aktifkan sekolah: seluruh fitur terbuka dengan MASA AKTIF 1 TAHUN (365 hari) — permintaan pemilik:
 * "Pastikan aplikasi aktif dengan terbuka semua fitur dengan masa aktif 1 tahun (365 hari). Buat
 * hitungan mundur. Dan jika masa aktif habis bisa menghubungi kembali HD Admin untuk meminta kode
 * aktifasi yang baru."
 *
 * Sekaligus NAIK ke periode berikutnya (`aktivasi_ke` + 1) sehingga kode aktivasi yang baru saja
 * dipakai TIDAK berlaku lagi — perpanjangan berikutnya memerlukan kode periode baru.
 */
function aktivasi_aktifkan(int $sekolahId): void
{
    // PENTING: nilai teks WAJIB berkutip TUNGGAL. Kutip ganda membuat SQLite membacanya sebagai
    // NAMA KOLOM — tabel `sekolah` punya kolom `aktif`, sehingga `aktivasi = "aktif"` menyimpan
    // angka kolom `aktif` (1), bukan kata "aktif" (jebakan klasik SQLite).
    $sekarang = date('Y-m-d H:i:s');
    $sampai = date('Y-m-d H:i:s', time() + aktivasi_hari() * 86400);
    q("UPDATE sekolah SET aktivasi = 'aktif', aktivasi_pada = ?, aktif_sampai = ?,
              aktivasi_ke = COALESCE(aktivasi_ke, 1) + 1
       WHERE id = ?", [$sekarang, $sampai, $sekolahId]);
}

/** Masa aktif sekolah (tanggal berakhir) — teks kosong bila belum pernah diaktivasi. */
function aktivasi_sampai(int $sekolahId): string
{
    return (string) (val('SELECT aktif_sampai FROM sekolah WHERE id = ?', [$sekolahId]) ?? '');
}

/**
 * PERPANJANG masa aktif sekolah memakai kode aktivasi PERIODE berikutnya.
 *
 * Permintaan pemilik: *"Tampilkan fitur untuk mengisi kode aktifasi perpanjangan di menu status &
 * aktifasi pada akun kepala madrasah untuk menginput kode aktifasi perpanjangan bagi yang sudah
 * melakukan aktifasi pertama."*
 *
 * Aturan:
 *  - masa aktif **DITAMBAHKAN aktivasi_hari() DIHITUNG DARI tanggal berakhir yang sedang berjalan**
 *    (bukan dari hari ini), sehingga perpanjangan lebih awal tidak menghanguskan sisa masa aktif;
 *  - nomor periode naik satu langkah (`aktivasi_ke` + 1) → kode yang baru dipakai langsung tidak
 *    berlaku lagi (perpanjangan berikutnya memerlukan kode periode baru);
 *  - `aktivasi_pada` (jejak aktivasi PERTAMA) TIDAK diubah supaya riwayat awal tetap terbaca;
 *  - nilai teks WAJIB berkutip TUNGGAL (kutip ganda dibaca SQLite sebagai NAMA KOLOM).
 *
 * @return string tanggal berakhir yang baru (Y-m-d H:i:s)
 */
function aktivasi_perpanjang(int $sekolahId): string
{
    $sampai = trim(aktivasi_sampai($sekolahId));
    $ts = $sampai !== '' ? (int) strtotime($sampai) : 0;
    if ($ts <= time()) {
        $ts = time();   // sudah lewat / belum ada → dihitung dari sekarang
    }
    $baru = date('Y-m-d H:i:s', $ts + aktivasi_hari() * 86400);
    q("UPDATE sekolah SET aktivasi = 'aktif', aktif_sampai = ?,
              aktivasi_ke = COALESCE(aktivasi_ke, 1) + 1
       WHERE id = ?", [$baru, $sekolahId]);
    return $baru;
}

/**
 * Kembalikan sekolah ke keadaan terbatas (dipakai Super Admin untuk uji coba/pembatalan).
 *
 * Permintaan pemilik: sekolah yang belum melakukan aktivasi hanya diberi waktu terbatas (kini 15 hari). Karena itu
 * penandaan ini sekaligus MENJALANKAN masa uji coba yang BARU (`uji_mulai` = sekarang) —
 * tanpa itu sekolah yang tanggal pendaftarannya sudah lama akan langsung terkunci begitu
 * dikembalikan ke mode terbatas.
 */
function aktivasi_batasi(int $sekolahId): void
{
    q("UPDATE sekolah SET aktivasi = 'dibatasi', aktivasi_pada = NULL, aktif_sampai = NULL,
              uji_mulai = ? WHERE id = ?", [date('Y-m-d H:i:s'), $sekolahId]);
}

/** Ringkasan batas mode terbatas — dipakai halaman Status & Aktivasi, banner, dan popup. */
function aktivasi_batas_teks(): array
{
    return [
        'guru'      => (int) AKTIVASI_MAKS_GURU . ' data guru',
        'supervisi' => (int) AKTIVASI_MAKS_SUPERVISI_MENU . ' supervisi pada setiap menu supervisi',
        'jadwal'    => (int) AKTIVASI_MAKS_JADWAL . ' baris jadwal supervisi',
        'dokumen'   => (int) AKTIVASI_MAKS_DOKUMEN . ' dokumen kiriman ke pengawas',
        'laporan'   => 'menu Susun Laporan belum dapat dibuka',
        'lain'      => 'impor data guru dan pembuatan akun guru secara massal belum dibuka',
    ];
}

/**
 * Jenis-jenis tindakan yang dibatasi pada mode terbatas, beserta keterangannya.
 * Dipakai untuk popup "Aplikasi Belum Diaktifasi" (aktivasi.php?batas=...).
 */
function aktivasi_batas_jenis(): array
{
    $b = aktivasi_batas_teks();
    return [
        'guru' => [
            'judul' => 'Menambah data guru',
            'pesan' => 'Mode terbatas hanya mengizinkan ' . $b['guru'] . '. Data guru yang sudah ada tetap dapat diubah, dinonaktifkan, atau dihapus.',
        ],
        'supervisi' => [
            'judul' => 'Menambah penilaian supervisi',
            'pesan' => 'Mode terbatas hanya mengizinkan ' . $b['supervisi'] . '. Penilaian yang sudah tersimpan tetap dapat dibuka, diubah, dan dicetak.',
        ],
        'jadwal' => [
            'judul' => 'Menambah jadwal supervisi',
            'pesan' => 'Mode terbatas hanya mengizinkan ' . $b['jadwal'] . '. Baris yang sudah ada tetap dapat diubah dan dicetak.',
        ],
        // Permintaan pemilik: "Lembaga yang belum aktifasi batasi hanya bisa mengirim 1 dokumen
        // saja ke pengawas."
        'dokumen' => [
            'judul' => 'Mengirim dokumen ke pengawas',
            'pesan' => 'Mode terbatas hanya mengizinkan ' . $b['dokumen'] . '. Dokumen yang sudah terkirim '
                . 'tetap dapat dilihat, ditinjau pengawas, dan dihapus untuk diganti dengan dokumen lain.',
        ],
        // Permintaan pemilik: "Menu susun laporan tidak bisa diakses untuk Lembaga yang belum aktifasi."
        'laporan' => [
            'judul' => 'Membuka menu Susun Laporan',
            'pesan' => 'Menu Susun Laporan hanya tersedia setelah aplikasi diaktivasi. ' . ucfirst($b['laporan']) . '.',
        ],
        'impor' => [
            'judul' => 'Impor data guru',
            'pesan' => 'Impor Excel/CSV hanya tersedia setelah aplikasi diaktivasi. ' . ucfirst($b['lain']) . '.',
        ],
        'massal' => [
            'judul' => 'Pembuatan akun guru secara massal',
            'pesan' => 'Pembuatan akun guru sekaligus hanya tersedia setelah aplikasi diaktivasi. ' . ucfirst($b['lain']) . '.',
        ],
    ];
}

/** Jumlah pemakaian yang dibatasi pada sekolah yang sedang aktif. */
function aktivasi_pemakaian(): array
{
    $sid = sekolah_id();
    return [
        'guru'      => (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [$sid], 0),
        'supervisi' => (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ?', [$sid], 0),
        'jadwal'    => (int) val('SELECT COUNT(*) FROM jadwal WHERE sekolah_id = ?', [$sid], 0),
        'dokumen'   => (int) val('SELECT COUNT(*) FROM dokumen_madrasah WHERE sekolah_id = ?', [$sid], 0),
    ];
}

/** true bila penambahan data guru sudah melewati batas mode terbatas. */
function batas_guru_tercapai(): bool
{
    if (!aktivasi_terbatas()) {
        return false;
    }
    $n = (int) val('SELECT COUNT(*) FROM guru WHERE sekolah_id = ?', [sekolah_id()], 0);
    return $n >= (int) AKTIVASI_MAKS_GURU;
}

/** true bila menu supervisi tertentu sudah memiliki penilaian (batas mode terbatas). */
function batas_supervisi_tercapai(int $instrumenId): bool
{
    if (!aktivasi_terbatas()) {
        return false;
    }
    $n = (int) val('SELECT COUNT(*) FROM supervisi WHERE sekolah_id = ? AND instrumen_id = ?', [sekolah_id(), $instrumenId], 0);
    return $n >= (int) AKTIVASI_MAKS_SUPERVISI_MENU;
}

/** true bila jadwal supervisi sudah mencapai batas mode terbatas. */
function batas_jadwal_tercapai(): bool
{
    if (!aktivasi_terbatas()) {
        return false;
    }
    $n = (int) val('SELECT COUNT(*) FROM jadwal WHERE sekolah_id = ?', [sekolah_id()], 0);
    return $n >= (int) AKTIVASI_MAKS_JADWAL;
}

/**
 * true bila DOKUMEN KIRIMAN KE PENGAWAS sudah mencapai batas mode terbatas.
 *
 * Permintaan pemilik: "Lembaga yang belum aktifasi batasi hanya bisa mengirim 1 dokumen saja ke
 * pengawas." Dokumen yang sudah terkirim tetap boleh dilihat/dihapus (menghapus lalu mengirim
 * dokumen pengganti tetap memungkinkan selama jumlahnya tidak melebihi batas).
 */
function batas_dokumen_tercapai(?int $sekolahId = null): bool
{
    if (!aktivasi_terbatas()) {
        return false;
    }
    $sid = $sekolahId !== null && $sekolahId > 0 ? $sekolahId : sekolah_id();
    $n = (int) val('SELECT COUNT(*) FROM dokumen_madrasah WHERE sekolah_id = ?', [$sid], 0);
    return $n >= (int) AKTIVASI_MAKS_DOKUMEN;
}

/**
 * Hentikan tindakan yang melewati batas mode terbatas: beri pesan lalu arahkan ke halaman
 * Status & Aktivasi yang MENAMPILKAN POPUP "Aplikasi Belum Diaktifasi" (permintaan pemilik).
 *
 * @param string $jenis    kunci pada aktivasi_batas_jenis()
 * @param string $kembali  alamat halaman asal (relatif) supaya pengguna dapat kembali
 */
function aktivasi_halang(string $jenis, string $kembali = ''): void
{
    if (!isset(aktivasi_batas_jenis()[$jenis])) {
        $jenis = 'guru';
    }
    flash('Aplikasi belum diaktifasi — ' . aktivasi_batas_jenis()[$jenis]['judul'] . ' dibatasi. '
        . 'Segera lakukan aktifasi agar semua fitur bisa digunakan.', 'warn');
    $q = ['batas' => $jenis];
    if ($kembali !== '') {
        $q['kembali'] = $kembali;
    }
    redirect('aktivasi.php?' . http_build_query($q));
}

// ============================================================================================
// ISI "TENTANG APLIKASI" PADA HALAMAN MASUK (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// "Pada halaman login tepatnya pada bagian tentang aplikasi tambahkan 2 tempat untuk foto
//  (Ukuran 3x4) yang dibawah foto masing-masing ada tempat untuk nama. dan dibawah kedua foto
//  (2 foto sekaligus) tersebut ada tempat untuk mengisi isian keterangan/penjelasan.
//  Foto, nama dan isian keterangan di input melalui halaman super admin"
//
// Disimpan pada tabel `app_pengaturan` (tingkat APLIKASI, bukan per sekolah) karena halaman masuk
// dipakai bersama seluruh sekolah/madrasah. Kunci yang dipakai:
//   tentang_foto_1, tentang_nama_1, tentang_foto_2, tentang_nama_2, tentang_keterangan
// Yang disimpan untuk foto HANYA URL CDN hasil media proxy platform (bukan berkas di server).
// ============================================================================================

/** Kunci pengaturan untuk satu tempat (slot) foto pada halaman masuk: 1 atau 2. */
function tentang_slot_sah(int $slot): int
{
    return $slot === 2 ? 2 : 1;
}

/**
 * Isi blok "Tentang Aplikasi" pada halaman masuk.
 * Mengembalikan ['slot' => [1 => ['foto','nama'], 2 => [...]], 'keterangan' => string,
 *                 'ada' => bool, 'jumlah_foto' => int].
 */
function tentang_isi(): array
{
    $slot = [];
    foreach ([1, 2] as $n) {
        $slot[$n] = [
            'foto' => trim(app_setting('tentang_foto_' . $n)),
            'nama' => trim(app_setting('tentang_nama_' . $n)),
        ];
    }
    $ket = trim(app_setting('tentang_keterangan'));
    $ada = $ket !== '';
    $jumlah = 0;
    foreach ($slot as $s) {
        if ($s['foto'] !== '') {
            $jumlah++;
        }
        if ($s['foto'] !== '' || $s['nama'] !== '') {
            $ada = true;
        }
    }
    return ['slot' => $slot, 'keterangan' => $ket, 'ada' => $ada, 'jumlah_foto' => $jumlah];
}

/**
 * Simpan satu tempat foto pada halaman masuk.
 * Parameter bernilai `null` berarti bagian itu TIDAK diubah (mis. saat hanya mengunggah foto).
 *
 * @param int         $slot 1 atau 2
 * @param string|null $nama nama pemilik foto ('' = dikosongkan)
 * @param string|null $foto URL foto ('' = foto dihapus)
 */
function tentang_simpan_slot(int $slot, ?string $nama, ?string $foto): void
{
    $slot = tentang_slot_sah($slot);
    if ($nama !== null) {
        $nama = trim($nama);
        if (function_exists('mb_substr')) {
            $nama = (string) mb_substr($nama, 0, TENTANG_NAMA_MAKS);
        } elseif (strlen($nama) > TENTANG_NAMA_MAKS) {
            $nama = substr($nama, 0, TENTANG_NAMA_MAKS);
        }
        app_setting_set('tentang_nama_' . $slot, $nama);
    }
    if ($foto !== null) {
        app_setting_set('tentang_foto_' . $slot, trim($foto));
    }
}

/** Simpan isian keterangan/penjelasan di bawah kedua foto pada halaman masuk. */
function tentang_simpan_keterangan(string $teks): void
{
    $teks = trim($teks);
    if (function_exists('mb_substr')) {
        $teks = (string) mb_substr($teks, 0, TENTANG_KET_MAKS);
    } elseif (strlen($teks) > TENTANG_KET_MAKS) {
        $teks = substr($teks, 0, TENTANG_KET_MAKS);
    }
    app_setting_set('tentang_keterangan', $teks);
}

/**
 * Unggah berkas foto "Tentang Aplikasi" (dari $_FILES['foto_<slot>']) ke media proxy platform.
 * Mengembalikan ['ok' => bool, 'url' => string, 'pesan' => string] — yang disimpan ke basis data
 * hanya URL hasil respons.
 */
function tentang_unggah_foto(int $slot): array
{
    $kunci = 'foto_' . tentang_slot_sah($slot);
    $f = $_FILES[$kunci] ?? null;
    if (!is_array($f) || trim((string) ($f['name'] ?? '')) === '') {
        return ['ok' => false, 'url' => '', 'pesan' => 'Tidak ada berkas foto yang dipilih.'];
    }
    $kode = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($kode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'url' => '', 'pesan' => ($kode === UPLOAD_ERR_INI_SIZE || $kode === UPLOAD_ERR_FORM_SIZE)
            ? 'berkasnya melebihi batas server (' . ini_get('upload_max_filesize') . '). Perkecil dulu fotonya.'
            : 'gagal diunggah (kode ' . $kode . '). Silakan coba lagi.'];
    }
    $namaAsli = (string) $f['name'];
    $ukuran = (int) ($f['size'] ?? 0);
    $tmp = (string) ($f['tmp_name'] ?? '');
    $cek = validasi_foto($namaAsli, $ukuran, $tmp);
    if (empty($cek['ok'])) {
        return ['ok' => false, 'url' => '', 'pesan' => (string) $cek['pesan']];
    }
    $bytes = @file_get_contents($tmp);
    if ($bytes === false) {
        return ['ok' => false, 'url' => '', 'pesan' => 'Gagal membaca berkas foto di server.'];
    }
    $hasil = media_upload($bytes, $namaAsli);
    if (empty($hasil['ok'])) {
        return ['ok' => false, 'url' => '', 'pesan' => (string) ($hasil['pesan'] ?? 'Unggahan foto ditolak.')];
    }
    return ['ok' => true, 'url' => (string) $hasil['url'], 'pesan' => ''];
}

// ============================================================================================
// PERMOHONAN KODE AKTIVASI (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// Kartu "Aktivasi Sekolah/Madrasah" pada menu Status & Aktivasi memuat: ajakan menghubungi
// HD Admin + tombol yang langsung membuka WhatsApp dengan pesan yang sudah disiapkan, serta
// nomor rekening tujuan aktivasi beserta keterangannya.
// Nomor WA & nomor rekening ada di inc/config.php supaya mudah diganti bila berganti.
// ============================================================================================

/** Nomor telepon dalam format internasional untuk tautan wa.me (mis. 0852… → 62852…). */
function wa_internasional(string $nomor): string
{
    $d = (string) preg_replace('/\D/', '', $nomor);
    if ($d === '') {
        return '';
    }
    if (strpos($d, '0') === 0) {
        return '62' . substr($d, 1);
    }
    if (strpos($d, '62') !== 0) {
        return '62' . $d;
    }
    return $d;
}

/**
 * Pesan WhatsApp permohonan kode aktivasi. Nama aplikasi diambil dari aplikasi (APP_NAME) dan
 * nama sekolah diambil dari lembaga yang sedang aktif (nama sekolah/madrasah yang didaftarkan).
 */
function aktivasi_pesan_permohonan(?array $sekolah): string
{
    $nama = trim((string) ($sekolah['nama'] ?? ''));
    if ($nama === '') {
        $nama = '-';
    }
    return 'Assalamualaikum HD Admin Aplikasi ' . APP_NAME . '. Kami dari ' . $nama
        . ' ingin melakukan aktifasi aplikasi ' . APP_NAME . '.';
}

/** Tautan WhatsApp (wa.me) untuk mengirim permohonan kode aktivasi. */
function aktivasi_tautan_permohonan(?array $sekolah): string
{
    return 'https://wa.me/' . wa_internasional(aktivasi_wa())
        . '?text=' . rawurlencode(aktivasi_pesan_permohonan($sekolah));
}

// ============================================================================================
// NOMINAL BIAYA AKTIVASI (isi menu Status & Aktivasi disamakan dengan aplikasi e-GURU)
// --------------------------------------------------------------------------------------------
// Halaman Status & Aktivasi menampilkan: nominal yang harus ditransfer, kalimat permintaan
// pembayaran, tombol KIRIM BUKTI TRANSFER ke WhatsApp HD Admin, dan informasi bahwa lembaga yang
// SUDAH pernah diaktifasi hanya perlu membayar biaya LANGGANAN untuk tahun berikutnya.
// Nominalnya dibaca dari tabel `app_pengaturan` dengan konstanta inc/config.php sebagai BAWAAN.
// ============================================================================================

/** Format rupiah: "Rp 1.000.000". */
function rupiah($v): string
{
    return 'Rp ' . number_format((float) $v, 0, ',', '.');
}

/**
 * Pembacaan pengaturan biaya/WA dengan CACHE per permintaan (kartu, tabel, dan kaki halaman
 * memanggilnya berkali-kali pada satu halaman, sedangkan app_setting() selalu menembak basis data).
 */
function aktivasi_setting(string $kunci, string $bawaan, bool $paksa = false): string
{
    static $cache = [];
    if ($paksa) {
        $cache = [];
    }
    if (!array_key_exists($kunci, $cache)) {
        $cache[$kunci] = app_setting($kunci, $bawaan);
    }
    return $cache[$kunci];
}

/** Nominal biaya aktivasi PERTAMA (masa aktif 1 tahun) — bawaan AKTIVASI_BIAYA. */
function aktivasi_biaya_pertama(): int
{
    return max(0, (int) aktivasi_setting('aktivasi_biaya', (string) AKTIVASI_BIAYA));
}

/** Nominal biaya LANGGANAN tahun berikutnya — bawaan AKTIVASI_BIAYA_LANGGANAN. */
function aktivasi_biaya_langganan(): int
{
    return max(0, (int) aktivasi_setting('aktivasi_biaya_langganan', (string) AKTIVASI_BIAYA_LANGGANAN));
}

/**
 * Nomor WA HD Admin tujuan PERMOHONAN kode aktivasi dan BUKTI TRANSFER — bawaan
 * AKTIVASI_WA_PERMOHONAN. Ditampilkan/dipakai apa adanya (bentuk lokal 0…); tautan wa.me-nya
 * dibangun wa_internasional().
 *
 * Nilainya dapat diubah Super Admin lewat menu **Biaya & WA Aktivasi** (biaya_aktivasi.php);
 * konstanta di inc/config.php hanya menjadi BAWAAN. Seluruh halaman WAJIB membacanya lewat fungsi
 * ini — jangan memakai konstantanya langsung, kalau tidak perubahan Super Admin tidak terpakai.
 */
function aktivasi_wa(): string
{
    return trim(aktivasi_setting('aktivasi_wa', AKTIVASI_WA_PERMOHONAN));
}

/**
 * true bila nomor WA-nya masuk akal: hanya angka (boleh didahului + atau 0), 9–15 digit.
 * Tanda baca yang lazim dipakai saat menulis nomor (spasi, -, titik, tanda kurung) DIMA'AFKAN dan
 * disimpan dalam bentuk angka saja.
 */
function aktivasi_wa_sah(string $nomor): bool
{
    $d = (string) preg_replace('/\D/', '', $nomor);
    return $d !== '' && strlen($d) >= 9 && strlen($d) <= 15
        && preg_match('/^\+?[0-9 ().\-]*$/', trim($nomor)) === 1;
}

/** Nomor WA yang sudah dibersihkan (hanya angka) — dipakai saat menyimpan. */
function aktivasi_wa_bersih(string $nomor): string
{
    return (string) preg_replace('/\D/', '', $nomor);
}

/**
 * Simpan pengaturan biaya & nomor WA (menu Super Admin "Biaya & WA Aktivasi").
 * Nilai `null` = jangan diubah, sehingga formulir yang hanya mengirim sebagian isian tidak
 * mengosongkan isian lainnya.
 */
function aktivasi_biaya_simpan(?int $pertama, ?int $langganan, ?string $wa): void
{
    // Segarkan cache agar pemanggilan berikutnya PADA PERMINTAAN YANG SAMA membaca nilai baru
    // (mis. pesan flash yang menyebut nominal & nomor WA barunya).
    aktivasi_setting('', '', true);
    if ($pertama !== null) {
        app_setting_set('aktivasi_biaya', (string) max(0, min(AKTIVASI_BIAYA_MAKS, $pertama)));
    }
    if ($langganan !== null) {
        app_setting_set('aktivasi_biaya_langganan', (string) max(0, min(AKTIVASI_BIAYA_MAKS, $langganan)));
    }
    if ($wa !== null) {
        app_setting_set('aktivasi_wa', substr(aktivasi_wa_bersih($wa), 0, AKTIVASI_WA_MAKS));
    }
}

/** Kembalikan ketiga pengaturan ke nilai bawaan aplikasi (konstanta di inc/config.php). */
function aktivasi_biaya_bawaan(): void
{
    aktivasi_setting('', '', true);
    app_setting_set('aktivasi_biaya', (string) AKTIVASI_BIAYA);
    app_setting_set('aktivasi_biaya_langganan', (string) AKTIVASI_BIAYA_LANGGANAN);
    app_setting_set('aktivasi_wa', AKTIVASI_WA_PERMOHONAN);
}

/** true bila ketiga pengaturan masih sama dengan nilai bawaannya (chip "masih bawaan"). */
function aktivasi_biaya_masih_bawaan(): bool
{
    return aktivasi_biaya_pertama() === (int) AKTIVASI_BIAYA
        && aktivasi_biaya_langganan() === (int) AKTIVASI_BIAYA_LANGGANAN
        && aktivasi_wa() === AKTIVASI_WA_PERMOHONAN;
}

/**
 * Apakah lembaga ini SUDAH PERNAH diaktifasi — sehingga tarif yang berlaku adalah biaya LANGGANAN?
 *
 * Penandanya adalah jejak aktivasi yang SUNGGUH terjadi: kolom `sekolah.aktivasi_pada` sudah terisi
 * ATAU nomor periode (`aktivasi_ke`) sudah melewati 1 — keduanya diisi hanya setelah kode aktivasi
 * berhasil dimasukkan (atau masa aktif diberikan Super Admin dari halaman Kelola Sekolah).
 * Sekolah yang dibuat Super Admin dan belum pernah diaktivasi tetap melihat tarif aktivasi pertama.
 */
function aktivasi_pernah_aktif(?array $status = null, ?array $sekolah = null): bool
{
    $st = $status ?? aktivasi_status($sekolah);
    if ((int) ($st['periode'] ?? 1) > 1) {
        return true;
    }
    $s = $sekolah ?? current_sekolah();
    return trim((string) ($s['aktivasi_pada'] ?? '')) !== '';
}

/** Nominal yang BERLAKU bagi lembaga ini (biaya aktivasi pertama atau biaya langganan). */
function aktivasi_biaya_berlaku(?array $status = null, ?array $sekolah = null): int
{
    return aktivasi_pernah_aktif($status, $sekolah) ? aktivasi_biaya_langganan() : aktivasi_biaya_pertama();
}

/** Label singkat jenis pembayarannya. */
function aktivasi_biaya_label(?array $status = null, ?array $sekolah = null): string
{
    return aktivasi_pernah_aktif($status, $sekolah)
        ? 'Biaya langganan (perpanjangan 1 tahun)'
        : 'Biaya aktivasi (masa aktif 1 tahun)';
}

/**
 * Kalimat permintaan pembayaran — bunyinya mengikuti aplikasi e-GURU, hanya nominalnya mengikuti
 * tarif yang berlaku bagi lembaga itu. Dipakai pada halaman Status & Aktivasi dan pada pesan
 * WhatsApp bukti transfer supaya selalu sama.
 */
function aktivasi_kalimat_biaya(?array $status = null, ?array $sekolah = null): string
{
    return 'Untuk mengaktifasi Lembaga anda silahkan transfer ' . rupiah(aktivasi_biaya_berlaku($status, $sekolah))
        . ' ke ' . AKTIVASI_REKENING . '. Jika sudah melakukan transfer silahkan kirim bukti transfer ke wa.';
}

/** Pesan WhatsApp saat mengirim BUKTI TRANSFER (ke nomor permohonan kode aktivasi yang sama). */
function aktivasi_pesan_bukti(?array $sekolah = null, ?array $status = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    $nama = trim((string) ($sekolah['nama'] ?? ''));
    if ($nama === '') {
        $nama = '-';
    }
    $jenis = aktivasi_pernah_aktif($status, $sekolah) ? 'biaya langganan' : 'biaya aktifasi';
    return 'Assalamualaikum HD Admin Aplikasi ' . APP_NAME . '. Kami dari ' . $nama
        . ' sudah melakukan transfer ' . $jenis . ' ' . rupiah(aktivasi_biaya_berlaku($status, $sekolah))
        . ' ke ' . AKTIVASI_REKENING . '. Berikut kami kirimkan bukti transfernya untuk proses '
        . 'verifikasi aktifasi aplikasi ' . APP_NAME . '.';
}

/** Tautan WhatsApp (wa.me) untuk mengirim bukti transfer ke nomor permohonan kode aktivasi. */
function aktivasi_tautan_bukti(?array $sekolah = null, ?array $status = null): string
{
    return 'https://wa.me/' . wa_internasional(aktivasi_wa())
        . '?text=' . rawurlencode(aktivasi_pesan_bukti($sekolah, $status));
}

// ============================================================================================
// KIRIM WHATSAPP KE LEMBAGA (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// "Nomer HP/telepon pada setiap lembaga di menu profil dan pengaturan buat agar bisa di kirim WA
//  oleh admin. Sehingga mempermudah Super Admin mengirim WA. Isi WA otomatis: Assalamualaikum
//  Bapak/ibu (Nama Kepala Sekolah) dari (Nama Lembaga), Aplikasi Supervisi anda saat ini dalam
//  status (berapa hari atau status masa uji coba habis). Silahkan melakukan aktifasi sebelum akun
//  dihapus secara permanen oleh sistem. Terima kasih."
//
// Nomor yang dipakai = kolom `sekolah.telepon` (diisi lembaga sendiri pada menu Profil &
// Pengaturan, atau oleh Super Admin pada Kelola Sekolah), dengan cadangan `kontak_telepon` dari
// formulir pendaftaran. Tautannya memakai wa.me dan isi pesannya sudah terisi otomatis.
// ============================================================================================

/** Nomor HP/telepon lembaga dalam bentuk internasional (62…) — '' bila belum diisi/tidak masuk akal. */
function wa_lembaga_nomor(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    if (!$sekolah) {
        return '';
    }
    foreach (['telepon', 'kontak_telepon'] as $kolom) {
        $nomor = trim((string) ($sekolah[$kolom] ?? ''));
        if ($nomor === '' || !aktivasi_wa_sah($nomor)) {
            continue;
        }
        $intl = wa_internasional($nomor);
        if ($intl !== '') {
            return $intl;
        }
    }
    return '';
}

/** Nomor HP/telepon lembaga seperti yang tertulis pada data (untuk ditampilkan). */
function wa_lembaga_nomor_teks(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    foreach (['telepon', 'kontak_telepon'] as $kolom) {
        $nomor = trim((string) ($sekolah[$kolom] ?? ''));
        if ($nomor !== '') {
            return $nomor;
        }
    }
    return '';
}

/** Tanggal (tanpa jam) untuk kalimat pesan WhatsApp. */
function wa_tanggal(?string $dt): string
{
    $dt = trim((string) $dt);
    return $dt === '' ? '' : tanggal_id(substr($dt, 0, 10));
}

/**
 * STATUS AKTIVASI dalam bahasa manusia untuk pesan WhatsApp — mis.
 * "aktif dengan sisa masa aktif 120 hari (berakhir 12 Maret 2027)",
 * "masa uji coba 15 hari dengan sisa 4 hari (berakhir 31 Oktober 2026)",
 * "masa uji coba 15 hari sudah HABIS (berakhir 31 Oktober 2026)".
 */
function wa_lembaga_status(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    $st = aktivasi_status($sekolah);
    $ujiHari = (int) aktivasi_hari_uji();
    if ($st['keadaan'] === 'kadaluarsa') {
        $tgl = wa_tanggal($st['sampai']);
        return 'masa aktif ' . (int) aktivasi_hari() . ' hari sudah HABIS' . ($tgl !== '' ? ' (berakhir ' . $tgl . ')' : '')
            . ' — aplikasi terkunci';
    }
    if ($st['keadaan'] === 'uji_habis') {
        $tgl = wa_tanggal($st['uji_sampai']);
        return 'masa uji coba ' . $ujiHari . ' hari sudah HABIS' . ($tgl !== '' ? ' (berakhir ' . $tgl . ')' : '')
            . ' — aplikasi terkunci';
    }
    if ($st['keadaan'] === 'dibatasi') {
        $tgl = wa_tanggal($st['uji_sampai']);
        if ($tgl !== '') {
            return 'masa uji coba ' . $ujiHari . ' hari dengan sisa ' . (int) $st['uji_sisa_hari']
                . ' hari (berakhir ' . $tgl . ')';
        }
        return 'belum diaktivasi (masa uji coba belum tercatat) — mode terbatas';
    }
    $tgl = wa_tanggal($st['sampai']);
    if ($tgl !== '') {
        return 'aktif dengan sisa masa aktif ' . (int) $st['sisa_hari'] . ' hari (berakhir ' . $tgl . ')';
    }
    return 'aktif tanpa batas waktu';
}

/** Isi pesan WhatsApp otomatis untuk seorang kepala sekolah/madrasah. */
function wa_lembaga_pesan(?array $sekolah = null): string
{
    if ($sekolah === null) {
        $sekolah = current_sekolah();
    }
    $nama = trim((string) ($sekolah['nama'] ?? ''));
    if ($nama === '') {
        $nama = '-';
    }
    $kepala = trim((string) ($sekolah['kepala_sekolah'] ?? ''));
    return 'Assalamualaikum ' . ($kepala !== '' ? 'Bapak/Ibu ' . $kepala : 'Bapak/Ibu')
        . ' dari ' . $nama . ', Aplikasi ' . APP_NAME . ' anda saat ini dalam status '
        . wa_lembaga_status($sekolah)
        . '. Silahkan melakukan aktifasi sebelum akun dihapus secara permanen oleh sistem. Terima kasih.';
}

/** Tautan WhatsApp (wa.me) ke nomor lembaga dengan pesan aktivasi otomatis — '' bila nomornya belum ada. */
function wa_lembaga_tautan(?array $sekolah = null): string
{
    $nomor = wa_lembaga_nomor($sekolah);
    if ($nomor === '') {
        return '';
    }
    return 'https://wa.me/' . $nomor . '?text=' . rawurlencode(wa_lembaga_pesan($sekolah));
}

/**
 * Tombol "Kirim WA" siap pakai untuk sebuah lembaga.
 * `$kosongHtml` = penanda yang DICETAK APA ADANYA (HTML) bila nomor HP/teleponnya belum diisi —
 * '' berarti tidak mencetak apa pun (dipakai pada tabel yang sempit). Karena dicetak apa adanya,
 * pemanggil WAJIB meng-escape sendiri teks yang berasal dari basis data.
 */
function wa_lembaga_tombol(
    ?array $sekolah = null,
    string $kelas = 'btn btn-ok btn-sm',
    string $label = 'Kirim WA',
    string $kosongHtml = 'Nomor HP/telepon lembaga belum diisi'
): string {
    $url = wa_lembaga_tautan($sekolah);
    if ($url === '') {
        return $kosongHtml === '' ? '' : '<span class="xs mut">' . $kosongHtml . '</span>';
    }
    $judul = 'Kirim WhatsApp ke kepala sekolah/madrasah — status: ' . wa_lembaga_status($sekolah);
    return '<a class="' . e($kelas) . '" href="' . e($url) . '" target="_blank" rel="noopener noreferrer"'
        . ' title="' . e($judul) . '">' . ikon('whatsapp', 14) . ' ' . e($label) . '</a>';
}
