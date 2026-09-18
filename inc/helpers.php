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
        'alamat_sekolah'     => 'alamat',
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
 * Jalankan istilah_sekolah() pada seluruh TEKS halaman HTML, tanpa menyentuh isi
 * <script>/<style> maupun nama atribut (mis. name="sekolah[]" tetap utuh).
 * Atribut yang tampil ke pengguna (placeholder/title/alt) ikut disesuaikan.
 */
function terjemahkan_html(string $html): string
{
    // Halaman lintas sekolah (mis. Kelola Sekolah / Pendaftaran / Akun Pengawas) memakai
    // istilah generik "Sekolah" karena mengelola sekolah umum DAN madrasah sekaligus.
    if (!empty($GLOBALS['__vs_istilah_nonaktif'])) {
        return $html;
    }
    // Halaman Pengawas juga perlu diproses walau sekolahnya berjenis umum.
    if ($html === '' || (!is_madrasah() && !is_istilah_pengawas())) {
        return $html;
    }
    if (stripos($html, 'sekolah') === false) {
        return $html;
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
    return $keluar;
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

/** Daftar id supervisi yang memakai butir tertentu. */
function supervisi_pemakai_butir(int $butirId): array
{
    return array_map('intval', array_column(
        all('SELECT DISTINCT d.supervisi_id AS id FROM detail d WHERE d.butir_id = ?', [$butirId]),
        'id'
    ));
}
