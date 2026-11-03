<?php
/**
 * HELPER: KOMENTAR / TINDAK LANJUT PENGAWAS & DOKUMEN MADRASAH → PENGAWAS.
 *
 * Permintaan pemilik:
 *  1. "Pada akun pengawas tambahkan fitur untuk mengomentari seluruh hasil rekapan supervisi setiap
 *     madrasah dan terkirim ke akun kepala madrasah untuk dilihat hasil komentar atau tindak lanjut
 *     tersebut." → pilihan pemilik: SATU komentar untuk seluruh rekapan per madrasah, dan kepala
 *     madrasah HANYA membaca (tidak membalas).
 *  2. "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen ke pengawas dengan
 *     inputan: Nama Dokumen (Pilihan: Kurikulum Madrasah (KM), Rencana Kerja Tahunan (RKT),
 *     Rencana Kerja Jangka Menengah (RKJM), Laporan Supervisi, EDM/RKAM, PKG & PTK), Unggah Berkas,
 *     Keterangan. Pilihan nama dokumen bisa dirubah/di edit/ dihapus melalui akun super admin."
 *     → pilihan pemilik: yang boleh mengunggah HANYA kepala madrasah/sekolah (role admin).
 *  3. "Hasil unggah dokumen akan masuk di akun pengawas di menu baru (Dokumen Madrasah) yang isinya
 *     hasil unggahan di setiap madrasah. Di filter/terkumpul berdasarkan nama madrasah agar tidak
 *     campur antara madrasah satu dengan lainnya."
 *     → pilihan pemilik: SATU tabel dengan kolom Madrasah + saringan per madrasah.
 *
 * Berkas dokumen diteruskan ke media proxy platform dari sisi server (media_upload()); yang
 * disimpan ke basis data HANYA URL CDN hasil respons.
 */
declare(strict_types=1);

// ============================================================================================
// 1. KOMENTAR / TINDAK LANJUT PENGAWAS
// ============================================================================================

/** Daftar jenis dokumen bawaan (dipakai sekali saat tabel `dokumen_jenis` masih kosong). */
function dokumen_jenis_bawaan(): array
{
    return DOKUMEN_JENIS_BAWAAN;
}

/** Semua komentar pengawas untuk sebuah madrasah (urut pengawas, terbaru diperbarui lebih dulu). */
function komentar_pengawas_list(int $sekolahId): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    return all('SELECT k.*, u.nama AS pengawas, u.username
                FROM komentar_pengawas k
                LEFT JOIN users u ON u.id = k.pengawas_id
                WHERE k.sekolah_id = ?
                ORDER BY k.diubah DESC, k.dibuat DESC, k.id DESC', [$sekolahId]);
}

/** Komentar MILIK seorang pengawas pada satu madrasah (null bila belum ada). */
function komentar_pengawas_saya(int $sekolahId, int $pengawasId): ?array
{
    if ($sekolahId <= 0 || $pengawasId <= 0) {
        return null;
    }
    return one('SELECT * FROM komentar_pengawas WHERE sekolah_id = ? AND pengawas_id = ?', [$sekolahId, $pengawasId]);
}

/** Satu komentar berdasarkan id — WAJIB dibatasi madrasah (anti-IDOR). */
function komentar_pengawas_by_id(int $id, int $sekolahId): ?array
{
    if ($id <= 0 || $sekolahId <= 0) {
        return null;
    }
    return one('SELECT * FROM komentar_pengawas WHERE id = ? AND sekolah_id = ?', [$id, $sekolahId]);
}

/** Jumlah madrasah yang sudah dikomentari seorang pengawas (dipakai lencana/ringkasan). */
function komentar_pengawas_jumlah(int $pengawasId): int
{
    if ($pengawasId <= 0) {
        return 0;
    }
    return (int) val('SELECT COUNT(*) FROM komentar_pengawas WHERE pengawas_id = ?', [$pengawasId], 0);
}

/** Jumlah komentar yang diterima sebuah madrasah (dipakai lencana di dashboard kepala madrasah). */
function komentar_madrasah_jumlah(int $sekolahId): int
{
    if ($sekolahId <= 0) {
        return 0;
    }
    return (int) val('SELECT COUNT(*) FROM komentar_pengawas WHERE sekolah_id = ?', [$sekolahId], 0);
}

/** Komentar terbaru (maks $maks) yang diterima sebuah madrasah — untuk dashboard kepala madrasah. */
function komentar_madrasah_terbaru(int $sekolahId, int $maks = 3): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    $maks = max(1, min(20, $maks));
    return all('SELECT k.*, u.nama AS pengawas
                FROM komentar_pengawas k
                LEFT JOIN users u ON u.id = k.pengawas_id
                WHERE k.sekolah_id = ?
                ORDER BY k.diubah DESC, k.dibuat DESC, k.id DESC
                LIMIT ' . $maks, [$sekolahId]);
}

/** Potong teks komentar/keterangan sesuai batas (multi-byte bila tersedia). */
function komentar_potong(string $teks, int $maks): string
{
    $teks = trim($teks);
    if (function_exists('mb_substr')) {
        return (string) mb_substr($teks, 0, $maks);
    }
    return strlen($teks) > $maks ? substr($teks, 0, $maks) : $teks;
}

/**
 * Simpan (tambah ATAU perbarui) komentar seorang pengawas untuk satu madrasah.
 * Satu baris per (madrasah, pengawas) — pengawas boleh memperbarui komentarnya kapan saja.
 */
function komentar_pengawas_simpan(int $sekolahId, int $pengawasId, string $isi): void
{
    $isi = komentar_potong($isi, (int) KOMENTAR_MAKS);
    $now = date('Y-m-d H:i:s');
    $ada = komentar_pengawas_saya($sekolahId, $pengawasId);
    if ($ada) {
        q('UPDATE komentar_pengawas SET isi = ?, diubah = ? WHERE id = ?', [$isi, $now, (int) $ada['id']]);
        return;
    }
    q('INSERT INTO komentar_pengawas (sekolah_id, pengawas_id, isi, dibuat, diubah) VALUES (?,?,?,?,?)',
        [$sekolahId, $pengawasId, $isi, $now, $now]);
}

/** Hapus komentar MILIK pengawas itu sendiri pada satu madrasah. */
function komentar_pengawas_hapus(int $sekolahId, int $pengawasId): bool
{
    $ada = komentar_pengawas_saya($sekolahId, $pengawasId);
    if (!$ada) {
        return false;
    }
    q('DELETE FROM komentar_pengawas WHERE id = ? AND sekolah_id = ? AND pengawas_id = ?',
        [(int) $ada['id'], $sekolahId, $pengawasId]);
    return true;
}

// ============================================================================================
// 2. JENIS DOKUMEN (GLOBAL, dikelola Super Admin)
// ============================================================================================

/** Daftar jenis dokumen (aktif saja atau semua), urut tampil. */
function dokumen_jenis_list(bool $aktifSaja = true): array
{
    return all('SELECT * FROM dokumen_jenis' . ($aktifSaja ? ' WHERE aktif = 1' : '') . '
                ORDER BY urutan, id');
}

/** Satu jenis dokumen berdasarkan id. */
function dokumen_jenis_by_id(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM dokumen_jenis WHERE id = ?', [$id]) : null;
}

/** Jumlah jenis dokumen (dipakai lencana sidebar Super Admin). */
function dokumen_jenis_jumlah(bool $aktifSaja = false): int
{
    return (int) val('SELECT COUNT(*) FROM dokumen_jenis' . ($aktifSaja ? ' WHERE aktif = 1' : ''), [], 0);
}

/** Benar bila nama jenis dokumen sudah dipakai (tanpa peduli huruf besar/kecil). */
function dokumen_jenis_nama_terpakai(string $nama, int $kecualiId = 0): bool
{
    $nama = trim($nama);
    if ($nama === '') {
        return false;
    }
    return (int) val('SELECT COUNT(*) FROM dokumen_jenis WHERE nama = ? COLLATE NOCASE AND id <> ?',
        [$nama, $kecualiId], 0) > 0;
}

/** Nomor urut berikutnya untuk jenis dokumen baru (kelipatan 10, diletakkan paling akhir). */
function dokumen_jenis_urutan_baru(): int
{
    return (int) val('SELECT COALESCE(MAX(urutan), 0) + 10 FROM dokumen_jenis', [], 10);
}

/** Geser urutan jenis dokumen ($arah = -1 naik, +1 turun) — menulis ulang 10, 20, 30, … */
function dokumen_jenis_geser(int $id, int $arah): void
{
    $arah = $arah < 0 ? -1 : 1;
    $daftar = dokumen_jenis_list(false);
    $pos = -1;
    foreach ($daftar as $i => $r) {
        if ((int) $r['id'] === $id) {
            $pos = $i;
            break;
        }
    }
    if ($pos < 0) {
        return;
    }
    $tukar = $pos + $arah;
    if ($tukar < 0 || $tukar >= count($daftar)) {
        return;
    }
    $a = $daftar[$pos];
    $daftar[$pos] = $daftar[$tukar];
    $daftar[$tukar] = $a;
    $u = 0;
    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach ($daftar as $r) {
            $u += 10;
            q('UPDATE dokumen_jenis SET urutan = ? WHERE id = ?', [$u, (int) $r['id']]);
        }
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
}

// ============================================================================================
// 3. DOKUMEN MADRASAH → PENGAWAS
// ============================================================================================

/**
 * Batas ukuran berkas yang BENAR-BENAR dapat diterima server ini.
 * Mengikuti batas platform (gambar 15 MB, dokumen 50 MB) lalu dipersempit oleh batas PHP
 * (upload_max_filesize & post_max_size) — batas inilah yang ditampilkan pada formulir.
 */
function dokumen_batas_server(): int
{
    $keB = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $satuan = strtolower(substr($v, -1));
        $angka = (float) $v;
        if ($satuan === 'g') {
            $angka *= 1024;
        } elseif ($satuan === 'k') {
            $angka /= 1024;
        } elseif ($satuan === 'm') {
            // sudah MB
        } else {
            $angka /= 1048576;   // nilai dalam byte
        }
        return (int) round($angka * 1048576);
    };
    $unggah = $keB((string) ini_get('upload_max_filesize'));
    $post = $keB((string) ini_get('post_max_size'));
    $batas = (int) DOKUMEN_MAKS_ARSIP;
    if ($unggah > 0) {
        $batas = min($batas, $unggah);
    }
    if ($post > 0) {
        // Sisakan ruang untuk isian lain pada formulir (multipart) — jangan pas di batas.
        $batas = min($batas, max(262144, $post - 1048576));
    }
    return max(262144, $batas);
}

/** Ukuran berkas dalam bentuk teks yang enak dibaca (mis. "1,4 MB"). */
function ukuran_teks(int $byte): string
{
    if ($byte <= 0) {
        return '-';
    }
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    if ($byte >= 1024) {
        return number_format($byte / 1024, 0, ',', '.') . ' KB';
    }
    return $byte . ' B';
}

/** Ekstensi yang diterima untuk unggahan dokumen (gambar + arsip dokumen). */
function dokumen_ekstensi(): array
{
    return array_merge(DOKUMEN_EKSTENSI_GAMBAR, DOKUMEN_EKSTENSI_ARSIP);
}

/**
 * Validasi berkas dokumen: ekstensi diizinkan, ukuran wajar, dan ISI berkas diperiksa supaya
 * berkas yang hanya DIGANTI NAMANYA ditolak (gambar → getimagesize, pdf → "%PDF",
 * docx/xlsx/pptx/zip → diawali "PK", csv/txt → harus teks yang terbaca).
 *
 * @return array{ok:bool,pesan:string,jenis:string}
 */
function validasi_dokumen(string $namaAsli, int $ukuran, string $tmpPath): array
{
    $ext = strtolower((string) pathinfo($namaAsli, PATHINFO_EXTENSION));
    $gambar = in_array($ext, DOKUMEN_EKSTENSI_GAMBAR, true);
    $arsip = in_array($ext, DOKUMEN_EKSTENSI_ARSIP, true);
    if (!$gambar && !$arsip) {
        return ['ok' => false, 'jenis' => '', 'pesan' => 'Format berkas .' . ($ext !== '' ? $ext : '(tanpa ekstensi)')
            . ' belum didukung. Pakai ' . strtoupper(implode(', ', dokumen_ekstensi())) . '.'];
    }
    if ($ukuran <= 0) {
        return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas kosong atau gagal diunggah.'];
    }
    $batas = $gambar ? min((int) DOKUMEN_MAKS_GAMBAR, dokumen_batas_server()) : dokumen_batas_server();
    if ($ukuran > $batas) {
        return ['ok' => false, 'jenis' => '', 'pesan' => 'Ukuran berkas ' . ukuran_teks($ukuran)
            . ' melebihi batas ' . ukuran_teks($batas) . '. Perkecil atau pilih berkas lain.'];
    }
    // ---- Pemeriksaan ISI berkas (anti "ganti nama") ----
    $kepala = (string) @file_get_contents($tmpPath, false, null, 0, 8);
    if ($ext === 'pdf') {
        if (strncmp($kepala, '%PDF', 4) !== 0) {
            return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas PDF ini sepertinya rusak atau bukan PDF yang sah.'];
        }
    } elseif (in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true)) {
        // Berkas Office/zip asli diawali "PK" DAN berukuran jauh lebih besar dari beberapa byte;
        // berkas 2 byte ("PK" saja) jelas bukan berkas sah.
        if (strncmp($kepala, 'PK', 2) !== 0 || $ukuran < 512) {
            return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas .' . $ext . ' ini sepertinya rusak atau bukan berkas Office yang sah.'];
        }
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        $isi = (string) @file_get_contents($tmpPath);
        if ($isi === '' || strpos($isi, "\0") !== false) {
            return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas .' . $ext . ' ini sepertinya bukan berkas teks yang sah.'];
        }
    } else {
        // Gambar: isinya diperiksa (kecuali SVG yang berbasis teks).
        if ($ext !== 'svg' && @getimagesize($tmpPath) === false) {
            return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas ini sepertinya bukan gambar yang sah.'];
        }
        if ($ext === 'svg' && strpos($isi ?? (string) @file_get_contents($tmpPath), '<svg') === false) {
            return ['ok' => false, 'jenis' => '', 'pesan' => 'Berkas SVG ini sepertinya bukan gambar vektor yang sah.'];
        }
    }
    return ['ok' => true, 'jenis' => $gambar ? 'gambar' : 'dokumen', 'pesan' => ''];
}

/**
 * Daftar dokumen madrasah.
 *
 * @param array $f kunci yang dikenali: `sekolah` (id madrasah), `jenis` (id jenis), `cari` (kata kunci)
 * @param int   $batas jumlah maksimal baris (0 = semua)
 */
function dokumen_list(array $f = [], int $batas = 0): array
{
    $w = [];
    $p = [];
    if ((int) ($f['sekolah'] ?? 0) > 0) {
        $w[] = 'd.sekolah_id = ?';
        $p[] = (int) $f['sekolah'];
    }
    if ((int) ($f['jenis'] ?? 0) > 0) {
        $w[] = 'd.jenis_id = ?';
        $p[] = (int) $f['jenis'];
    }
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(dokumen_status_list()[$status])) {
        $w[] = 'd.status = ?';
        $p[] = $status;
    }
    $cari = trim((string) ($f['cari'] ?? ''));
    if ($cari !== '') {
        $w[] = '(d.nama_dokumen LIKE ? OR d.keterangan LIKE ? OR d.nama_berkas LIKE ? OR s.nama LIKE ?)';
        $like = '%' . $cari . '%';
        array_push($p, $like, $like, $like, $like);
    }
    $sql = 'SELECT d.*, s.nama AS madrasah, j.nama AS jenis
            FROM dokumen_madrasah d
            JOIN sekolah s ON s.id = d.sekolah_id
            LEFT JOIN dokumen_jenis j ON j.id = d.jenis_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . ' ORDER BY s.nama COLLATE NOCASE, d.dibuat DESC, d.id DESC';
    if ($batas > 0) {
        $sql .= ' LIMIT ' . max(1, min(2000, $batas));
    }
    return all($sql, $p);
}

/** Dokumen milik SATU madrasah (dipakai kepala madrasah pada halaman kirim dokumen). */
function dokumen_list_sekolah(int $sekolahId, string $cari = '', string $status = ''): array
{
    return dokumen_list(['sekolah' => $sekolahId, 'cari' => $cari, 'status' => $status]);
}

/** Dokumen dari madrasah binaan seorang pengawas (urut madrasah lalu terbaru). */
function dokumen_binaan(int $pengawasId, array $f = []): array
{
    $binaan = array_map(static fn(array $s): int => (int) $s['id'], sekolah_binaan($pengawasId, true));
    if (!$binaan) {
        return [];
    }
    $saring = (int) ($f['sekolah'] ?? 0);
    $status = (string) ($f['status'] ?? '');
    if ($saring > 0 && in_array($saring, $binaan, true)) {
        return dokumen_list(['sekolah' => $saring, 'jenis' => (int) ($f['jenis'] ?? 0),
            'cari' => (string) ($f['cari'] ?? ''), 'status' => $status]);
    }
    // Tanpa saringan madrasah: seluruh binaan (satu tabel, kolom Madrasah sebagai pemisah).
    $hasil = [];
    foreach ($binaan as $sid) {
        foreach (dokumen_list(['sekolah' => $sid, 'jenis' => (int) ($f['jenis'] ?? 0),
            'cari' => (string) ($f['cari'] ?? ''), 'status' => $status]) as $d) {
            $hasil[] = $d;
        }
    }
    usort($hasil, static function (array $a, array $b): int {
        $c = strcasecmp((string) $a['madrasah'], (string) $b['madrasah']);
        return $c !== 0 ? $c : strcmp((string) $b['dibuat'], (string) $a['dibuat']);
    });
    return $hasil;
}

/** Jumlah dokumen madrasah (opsional dibatasi satu madrasah). */
function dokumen_jumlah(int $sekolahId = 0): int
{
    if ($sekolahId > 0) {
        return (int) val('SELECT COUNT(*) FROM dokumen_madrasah WHERE sekolah_id = ?', [$sekolahId], 0);
    }
    return (int) val('SELECT COUNT(*) FROM dokumen_madrasah', [], 0);
}

/** Satu dokumen berdasarkan id — WAJIB dibatasi madrasah bila id madrasah diberikan (anti-IDOR). */
function dokumen_by_id(int $id, int $sekolahId = 0): ?array
{
    if ($id <= 0) {
        return null;
    }
    if ($sekolahId > 0) {
        return one('SELECT * FROM dokumen_madrasah WHERE id = ? AND sekolah_id = ?', [$id, $sekolahId]);
    }
    return one('SELECT * FROM dokumen_madrasah WHERE id = ?', [$id]);
}

/** Ringkasan jumlah dokumen per madrasah (untuk saringan & lencana). */
function dokumen_per_madrasah(int $pengawasId): array
{
    $hasil = [];
    foreach (sekolah_binaan($pengawasId, true) as $s) {
        $n = dokumen_jumlah((int) $s['id']);
        if ($n > 0) {
            $hasil[(int) $s['id']] = ['nama' => (string) $s['nama'], 'jumlah' => $n];
        }
    }
    return $hasil;
}

/** Jumlah SELURUH dokumen dari madrasah binaan seorang pengawas (dipakai lencana sidebar). */
function dokumen_jumlah_binaan(int $pengawasId): int
{
    if ($pengawasId <= 0) {
        return 0;
    }
    $n = 0;
    foreach (sekolah_binaan($pengawasId, true) as $s) {
        $n += dokumen_jumlah((int) $s['id']);
    }
    return $n;
}

// ============================================================================================
// STATUS TINJAUAN DOKUMEN (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// "Pada daftar dokumen madrasah yang ada di akun pengawas, pada saat pengawas melihat setiap
//  dokumen tersebut tambahkan fitur: Dokumen disetujui, dokumen direvisi, dokumen ditolak dan
//  tambahkan keterangannya/alasannya."
//
// Empat keadaan; `menunggu` = belum ditinjau (bawaan untuk dokumen baru & dokumen lama).
// ============================================================================================

/** Daftar status tinjauan: kunci → label yang tampil. */
function dokumen_status_list(): array
{
    return [
        'menunggu'  => 'Belum Ditinjau',
        'disetujui' => 'Dokumen Disetujui',
        'revisi'    => 'Dokumen Direvisi',
        'ditolak'   => 'Dokumen Ditolak',
    ];
}

/** Label satu status (nilai tak dikenal → label 'menunggu'). */
function dokumen_status_label(string $status): string
{
    $daftar = dokumen_status_list();
    return $daftar[$status] ?? $daftar['menunggu'];
}

/** Kelas pil tema untuk satu status (pill-ok / pill-warn / pill-err / pill-gray). */
function dokumen_status_pil(string $status): string
{
    if ($status === 'disetujui') {
        return 'pill-ok';
    }
    if ($status === 'revisi') {
        return 'pill-warn';
    }
    if ($status === 'ditolak') {
        return 'pill-err';
    }
    return 'pill-gray';
}

/** Ikon status (dipakai pada pil/ringkasan). */
function dokumen_status_ikon(string $status): string
{
    if ($status === 'disetujui') {
        return 'check';
    }
    if ($status === 'ditolak') {
        return 'alert';
    }
    return $status === 'revisi' ? 'edit' : 'clock';
}

/**
 * Alasan WAJIB untuk status yang menuntut perbaikan/penolakan.
 * Pilihan pemilik tidak disebutkan; alasannya jelas: pengawas harus menjelaskan apa yang harus
 * direvisi atau mengapa ditolak. Untuk 'disetujui' keterangan bersifat OPSIONAL (boleh dikosongkan).
 */
function dokumen_status_wajib_catatan(string $status): bool
{
    return $status === 'revisi' || $status === 'ditolak';
}

/** Status bawaan untuk dokumen baru. */
function dokumen_status_awal(): string
{
    return 'menunggu';
}

/**
 * Simpan hasil tinjauan pengawas atas satu dokumen.
 * Status 'menunggu' = mengembalikan dokumen ke keadaan belum ditinjau (alasan ikut dikosongkan).
 *
 * @return bool true bila dokumen memang ada (dan tersimpan)
 */
function dokumen_tinjau(int $dokumenId, string $status, string $catatan, string $oleh): bool
{
    $d = dokumen_by_id($dokumenId);
    if (!$d) {
        return false;
    }
    if (!isset(dokumen_status_list()[$status])) {
        $status = dokumen_status_awal();
    }
    $catatan = komentar_potong($catatan, (int) DOKUMEN_CATATAN_MAKS);
    $now = date('Y-m-d H:i:s');
    if ($status === dokumen_status_awal()) {
        q('UPDATE dokumen_madrasah SET status = ?, catatan = NULL, ditinjau_oleh = NULL, ditinjau_pada = NULL
           WHERE id = ?', [$status, $dokumenId]);
        return true;
    }
    q('UPDATE dokumen_madrasah SET status = ?, catatan = ?, ditinjau_oleh = ?, ditinjau_pada = ?
       WHERE id = ?', [$status, $catatan, $oleh, $now, $dokumenId]);
    return true;
}

/** Ringkasan jumlah dokumen per status: ['menunggu' => n, 'disetujui' => n, …]. */
function dokumen_ringkas_status(array $daftar): array
{
    $hasil = array_fill_keys(array_keys(dokumen_status_list()), 0);
    foreach ($daftar as $d) {
        $st = (string) ($d['status'] ?? dokumen_status_awal());
        if (!isset($hasil[$st])) {
            $st = dokumen_status_awal();
        }
        $hasil[$st]++;
    }
    return $hasil;
}

/** Jumlah dokumen berstatus tertentu pada SELURUH madrasah binaan seorang pengawas. */
function dokumen_jumlah_status_binaan(int $pengawasId, string $status = ''): int
{
    if ($pengawasId <= 0) {
        return 0;
    }
    $binaan = array_map(static fn(array $s): int => (int) $s['id'], sekolah_binaan($pengawasId, true));
    if (!$binaan) {
        return 0;
    }
    $tanda = implode(',', array_fill(0, count($binaan), '?'));
    $p = $binaan;
    $sql = 'SELECT COUNT(*) FROM dokumen_madrasah WHERE sekolah_id IN (' . $tanda . ')';
    if ($status !== '' && isset(dokumen_status_list()[$status])) {
        $sql .= ' AND status = ?';
        $p[] = $status;
    }
    return (int) val($sql, $p, 0);
}

/**
 * Dokumen yang SUDAH ditinjau dari SATU madrasah (dipakai kartu di dasbor kepala
 * sekolah/madrasah supaya hasil tinjauan pengawas langsung terlihat).
 */
function dokumen_ditinjau_sekolah(int $sekolahId, int $maks = 5): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    $maks = max(1, min(20, $maks));
    // Nama peninjau diambil dari kolom `ditinjau_oleh` (SNAPSHOT nama pengawas) — tidak perlu
    // di-JOIN ke tabel users karena pengawas boleh saja kelak dihapus.
    return all('SELECT d.*, d.ditinjau_oleh AS peninjau
                FROM dokumen_madrasah d
                WHERE d.sekolah_id = ? AND d.status <> ?
                ORDER BY d.ditinjau_pada DESC, d.id DESC
                LIMIT ' . $maks, [$sekolahId, dokumen_status_awal()]);
}

/** Jumlah dokumen yang sudah ditinjau pada satu madrasah. */
function dokumen_ditinjau_jumlah(int $sekolahId): int
{
    if ($sekolahId <= 0) {
        return 0;
    }
    return (int) val('SELECT COUNT(*) FROM dokumen_madrasah WHERE sekolah_id = ? AND status <> ?',
        [$sekolahId, dokumen_status_awal()], 0);
}

/** Ikon sederhana menurut jenis berkas (dipakai pada daftar dokumen). */
function dokumen_ikon(string $namaBerkas): string
{
    $ext = strtolower((string) pathinfo($namaBerkas, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        return 'eye';
    }
    if (in_array($ext, ['xlsx', 'csv'], true)) {
        return 'chart';
    }
    return 'doc';
}

// ============================================================================================
// SEMUA DOKUMEN MADRASAH — dikelompokkan per SEKOLAH & per TAHUN AJARAN (halaman Super Admin)
// --------------------------------------------------------------------------------------------
// Permintaan pemilik: "Pada halaman super admin tambahkan menu untuk menampilkan seluruh dokumen
// yang diunggah kepala sekolah dan ditampilkan per sekolah per tahun ajaran."
// Pilihan pemilik (jangan diubah tanpa bertanya): tahun ajaran dihitung dari **TANGGAL UNGGAH
// dokumen** dengan periode **1 Juli – 30 Juni** (bukan dari tahun ajaran aktif sekolah).
// ============================================================================================

/**
 * Tahun ajaran sebuah TANGGAL unggah dengan periode 1 Juli – 30 Juni.
 * Contoh: 2025-09-10 → "2025/2026"; 2026-06-30 → "2025/2026"; 2026-07-01 → "2026/2027".
 * Mengembalikan '' bila tanggalnya tidak dikenali.
 */
function dokumen_tahun_ajaran(?string $tanggal): string
{
    $tanggal = trim((string) $tanggal);
    if ($tanggal === '') {
        return '';
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return '';
    }
    $tahun = (int) date('Y', $ts);
    $awal = (int) date('n', $ts) >= 7 ? $tahun : $tahun - 1;
    return $awal . '/' . ($awal + 1);
}

/** Tanggal (Y-m-d) awal sebuah tahun ajaran "2025/2026" → "2025-07-01" ('' bila tidak sah). */
function dokumen_ta_awal(string $ta): string
{
    if (!preg_match('/^(\d{4})\/(\d{4})$/', trim($ta), $m)) {
        return '';
    }
    return $m[1] . '-07-01';
}

/** Tanggal (Y-m-d) akhir sebuah tahun ajaran "2025/2026" → "2026-06-30" ('' bila tidak sah). */
function dokumen_ta_akhir(string $ta): string
{
    if (!preg_match('/^(\d{4})\/(\d{4})$/', trim($ta), $m)) {
        return '';
    }
    return $m[2] . '-06-30';
}

/** Keterangan rentang tahun ajaran, mis. "1 Juli 2025 – 30 Juni 2026" ('' bila tidak sah). */
function dokumen_ta_rentang(string $ta): string
{
    $awal = dokumen_ta_awal($ta);
    $akhir = dokumen_ta_akhir($ta);
    if ($awal === '' || $akhir === '') {
        return '';
    }
    return tanggal_id($awal) . ' – ' . tanggal_id($akhir);
}

/**
 * Seluruh dokumen dari SEMUA sekolah dengan tambahan keterangan tahun ajaran (dihitung dari
 * tanggal unggah). Filter yang dikenali: `sekolah`, `tahun` (mis. "2025/2026"), `jenis`,
 * `status`, `cari`. Urut: sekolah (A→Z), lalu dokumen terbaru lebih dulu.
 */
function dokumen_semua(array $f = []): array
{
    $w = [];
    $p = [];
    if ((int) ($f['sekolah'] ?? 0) > 0) {
        $w[] = 'd.sekolah_id = ?';
        $p[] = (int) $f['sekolah'];
    }
    $jenis = (int) ($f['jenis'] ?? 0);
    if ($jenis > 0) {
        $w[] = 'd.jenis_id = ?';
        $p[] = $jenis;
    }
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(dokumen_status_list()[$status])) {
        $w[] = 'd.status = ?';
        $p[] = $status;
    }
    // Tahun ajaran: kolom PILIHAN pengirim dipakai bila ada (permintaan pemilik, skema 22);
    // dokumen LAMA yang belum punya tahun ajaran tetap dihitung dari TANGGAL UNGGAH
    // (periode 1 Juli – 30 Juni) supaya perilaku sebelumnya tidak berubah.
    $ta = dokumen_ta_normalisasi((string) ($f['tahun'] ?? ''));
    if ($ta !== '') {
        $awal = dokumen_ta_awal($ta);
        $akhir = dokumen_ta_akhir($ta);
        if ($awal !== '' && $akhir !== '') {
            $w[] = "(d.tahun_ajaran = ? OR (IFNULL(TRIM(d.tahun_ajaran), '') = ''
                     AND substr(IFNULL(d.dibuat, ''), 1, 10) BETWEEN ? AND ?))";
            $p[] = $ta;
            $p[] = $awal;
            $p[] = $akhir;
        }
    }
    $cari = trim((string) ($f['cari'] ?? ''));
    if ($cari !== '') {
        $w[] = '(d.nama_dokumen LIKE ? OR d.keterangan LIKE ? OR d.nama_berkas LIKE ? OR s.nama LIKE ? OR d.pengirim LIKE ?)';
        $like = '%' . $cari . '%';
        array_push($p, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT d.*, s.nama AS madrasah, s.jenis AS jenis_sekolah, s.tahun_ajaran AS ta_sekolah,
                   j.nama AS jenis
            FROM dokumen_madrasah d
            JOIN sekolah s ON s.id = d.sekolah_id
            LEFT JOIN dokumen_jenis j ON j.id = d.jenis_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . ' ORDER BY s.nama COLLATE NOCASE, substr(IFNULL(d.dibuat, ""), 1, 10) DESC, d.id DESC';
    $baris = all($sql, $p);
    foreach ($baris as $i => $d) {
        // Tahun ajaran PILIHAN pengirim lebih diutamakan; kalau kosong dihitung dari tanggal unggah.
        $t = dokumen_ta_normalisasi((string) ($d['tahun_ajaran'] ?? ''));
        $baris[$i]['ta'] = $t !== '' ? $t : dokumen_tahun_ajaran((string) ($d['dibuat'] ?? ''));
    }
    return $baris;
}

/**
 * Susun baris `dokumen_semua()` menjadi kelompok: SEKOLAH → TAHUN AJARAN → baris dokumen.
 * Tahun ajaran di dalam tiap sekolah urut terbaru lebih dulu.
 *
 * @return array<int,array{nama:string, jenis:string, ta:array<string,array{baris:array,status:array}>}>
 */
function dokumen_urut_sekolah_tahun(array $baris): array
{
    $kelompok = [];
    foreach ($baris as $d) {
        $sid = (int) $d['sekolah_id'];
        $ta = (string) ($d['ta'] ?? '');
        if ($ta === '') {
            $ta = 'Tidak tercatat';
        }
        if (!isset($kelompok[$sid])) {
            $kelompok[$sid] = ['nama' => (string) $d['madrasah'],
                'jenis' => (string) ($d['jenis_sekolah'] ?? 'umum'), 'ta' => []];
        }
        $kelompok[$sid]['ta'][$ta]['baris'][] = $d;
    }
    // Urut tahun ajaran terbaru lebih dulu ('Tidak tercatat' selalu di akhir).
    foreach ($kelompok as $sid => $data) {
        $ta = $data['ta'];
        uksort($ta, static function (string $a, string $b): int {
            if ($a === 'Tidak tercatat') {
                return 1;
            }
            if ($b === 'Tidak tercatat') {
                return -1;
            }
            return strcmp($b, $a);
        });
        $kelompok[$sid]['ta'] = $ta;
    }
    // Urut sekolah menurut nama.
    uasort($kelompok, static fn(array $a, array $b): int => strcasecmp($a['nama'], $b['nama']));
    return $kelompok;
}

/**
 * Daftar tahun ajaran yang benar-benar punya dokumen (urut terbaru lebih dulu).
 */
function dokumen_tahun_ada(int $sekolahId = 0): array
{
    $sql = 'SELECT dibuat, tahun_ajaran FROM dokumen_madrasah' . ($sekolahId > 0 ? ' WHERE sekolah_id = ?' : '');
    $ta = [];
    foreach (all($sql, $sekolahId > 0 ? [$sekolahId] : []) as $r) {
        // Tahun ajaran PILIHAN pengirim dipakai bila ada; kalau kosong, dihitung dari tanggal unggah.
        $t = dokumen_ta_normalisasi((string) ($r['tahun_ajaran'] ?? ''));
        if ($t === '') {
            $t = dokumen_tahun_ajaran((string) ($r['dibuat'] ?? ''));
        }
        if ($t !== '') {
            $ta[$t] = true;
        }
    }
    $kunci = array_keys($ta);
    rsort($kunci);
    return $kunci;
}

// ============================================================================================
// TAHUN AJARAN DOKUMEN + PROGRES PENGIRIMAN (permintaan pemilik)
// --------------------------------------------------------------------------------------------
// 1. "Pada halaman kepala madrasah/sekolah dibagian kirim dokumen ke pengawas tambahkan tahun
//    ajaran diatas nama dokumen (Ada pilihan beberapa tahun ajaran)."
// 2. "Tambahkan fitur progress pengiriman dokumen di akun pengawas dan kepala sekolah. Progres
//    dilihat dari jumlah nama dokumen yang diisi lewat super admin."
//
// Jadi PENYEBUT progres = jumlah **Nama Dokumen** yang aktif (tabel `dokumen_jenis`, diisi Super
// Admin lewat menu "Jenis Dokumen (Global)"), dan PEMBILANGnya = berapa di antaranya yang sudah
// dikirim sekolah/madrasah itu. Aturan periode mengikuti kebiasaan aplikasi ini: dokumen yang
// belum punya tahun ajaran (data LAMA, sebelum skema 22) tetap ikut dihitung pada periode mana pun.
// ============================================================================================

/** Normalkan tahun ajaran ke bentuk "YYYY/YYYY"; selain itu → '' (tidak ada nilai yang ditebak). */
function dokumen_ta_normalisasi(string $ta): string
{
    $ta = trim($ta);
    if (preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $ta, $m) && (int) $m[2] === (int) $m[1] + 1) {
        return $m[1] . '/' . $m[2];
    }
    return '';
}

/**
 * Pilihan TAHUN AJARAN untuk formulir kirim dokumen (beberapa tahun, terbaru lebih dulu).
 * Dasarnya tahun ajaran aktif sekolah, diperluas beberapa tahun ke belakang & ke depan, ditambah
 * tahun ajaran yang sudah pernah dipakai pada dokumen sekolah itu (agar pilihan lama tidak hilang).
 */
function dokumen_tahun_pilihan(?array $sekolah = null, int $mundur = 3, int $maju = 1): array
{
    $ta = [];
    $aktif = dokumen_ta_normalisasi((string) ($sekolah['tahun_ajaran'] ?? ''));
    if ($aktif === '') {
        $aktif = dokumen_ta_normalisasi((string) setting('tahun_ajaran', ''));
    }
    if ($aktif === '') {
        $aktif = date('Y') . '/' . (date('Y') + 1);
    }
    $awal = (int) substr($aktif, 0, 4);
    for ($i = -abs($mundur); $i <= abs($maju); $i++) {
        $t = ($awal + $i) . '/' . ($awal + $i + 1);
        $ta[$t] = true;
    }
    $sid = (int) ($sekolah['id'] ?? 0);
    $sql = 'SELECT DISTINCT tahun_ajaran FROM dokumen_madrasah WHERE tahun_ajaran IS NOT NULL';
    $p = [];
    if ($sid > 0) {
        $sql .= ' AND sekolah_id = ?';
        $p[] = $sid;
    }
    foreach (all($sql, $p) as $r) {
        $t = dokumen_ta_normalisasi((string) $r['tahun_ajaran']);
        if ($t !== '') {
            $ta[$t] = true;
        }
    }
    $kunci = array_keys($ta);
    rsort($kunci);
    return $kunci;
}

/** Tahun ajaran yang berlaku untuk keperluan tampilan: pilihan dari pengguna, atau tahun aktif sekolah. */
function dokumen_ta_berlaku(string $dariPengguna, ?array $sekolah = null): string
{
    $ta = dokumen_ta_normalisasi($dariPengguna);
    if ($ta !== '') {
        return $ta;
    }
    $aktif = dokumen_ta_normalisasi((string) ($sekolah['tahun_ajaran'] ?? ''));
    if ($aktif === '') {
        $aktif = dokumen_ta_normalisasi((string) setting('tahun_ajaran', ''));
    }
    return $aktif;
}

/**
 * Apakah sebuah baris dokumen termasuk periode tahun ajaran $ta?
 *
 * SATU aturan yang dipakai bersama oleh halaman kepala sekolah/madrasah, halaman pengawas,
 * halaman Super Admin, dan perhitungan PROGRES:
 *   • kolom `tahun_ajaran` yang TERISI (dipilih pengirim, skema 22) menjadi penentu;
 *   • bila kosong (dokumen LAMA), dipakai TANGGAL UNGGAH-nya (periode 1 Juli – 30 Juni) —
 *     perilaku sebelum fitur ini ada, sehingga data lama tetap masuk kelompok yang benar.
 * $ta kosong = seluruh periode (selalu cocok).
 */
function dokumen_ta_cocok(array $d, string $ta): bool
{
    $ta = dokumen_ta_normalisasi($ta);
    if ($ta === '') {
        return true;
    }
    $punya = dokumen_ta_normalisasi((string) ($d['tahun_ajaran'] ?? ''));
    if ($punya !== '') {
        return $punya === $ta;
    }
    return dokumen_tahun_ajaran((string) ($d['dibuat'] ?? '')) === $ta;
}

/**
 * PROGRES PENGIRIMAN DOKUMEN sebuah sekolah/madrasah.
 *
 * @param string $tahunAjaran '' = seluruh tahun ajaran; selain itu dibatasi periode tersebut
 *                            (dokumen yang belum punya tahun ajaran tetap ikut dihitung).
 * @return array{ta:string,total:int,terkirim:int,belum:int,persen:int,
 *               daftar:array<int,array{id:int,nama:string,jumlah:int,terkirim:bool,ditinjau:int}>,
 *               lainnya:int,jenis_kosong:bool}
 */
function dokumen_progress(int $sekolahId, string $tahunAjaran = ''): array
{
    $ta = dokumen_ta_normalisasi($tahunAjaran);
    $jenis = $sekolahId > 0 ? dokumen_jenis_list(true) : [];

    // Ambil seluruh dokumen sekolah itu, lalu saring periodenya dengan ATURAN YANG SAMA seperti
    // halaman daftar (dokumen_ta_cocok) supaya angka progres selalu = yang tampil di daftar.
    $dok = [];
    if ($sekolahId > 0) {
        foreach (all('SELECT id, jenis_id, nama_dokumen, status, tahun_ajaran, dibuat
                      FROM dokumen_madrasah WHERE sekolah_id = ?', [$sekolahId]) as $d) {
            if (dokumen_ta_cocok($d, $ta)) {
                $dok[] = $d;
            }
        }
    }

    $daftar = [];
    $terkirim = 0;
    $idJenis = [];
    foreach ($jenis as $j) {
        $idJenis[(int) $j['id']] = true;
        $jumlah = 0;
        $ditinjau = 0;
        $nama = (string) $j['nama'];
        foreach ($dok as $d) {
            // Cocokkan lewat jenis_id; dokumen yang jenisnya sudah dihapus (jenis_id NULL) dicocokkan
            // lewat NAMA SNAPSHOT-nya supaya progresnya tetap terbaca.
            $cocok = (int) ($d['jenis_id'] ?? 0) === (int) $j['id']
                || ((int) ($d['jenis_id'] ?? 0) === 0 && strcasecmp(trim((string) $d['nama_dokumen']), trim($nama)) === 0);
            if ($cocok) {
                $jumlah++;
                if ((string) ($d['status'] ?? dokumen_status_awal()) !== dokumen_status_awal()) {
                    $ditinjau++;
                }
            }
        }
        if ($jumlah > 0) {
            $terkirim++;
        }
        $daftar[] = ['id' => (int) $j['id'], 'nama' => $nama, 'jumlah' => $jumlah,
            'terkirim' => $jumlah > 0, 'ditinjau' => $ditinjau];
    }
    // Dokumen yang jenisnya sudah tidak ada / sudah dinonaktifkan tetap dilaporkan agar tidak
    // "hilang" dari pandangan pengguna (tidak dihitung pada progres).
    $lainnya = 0;
    foreach ($dok as $d) {
        $jid = (int) ($d['jenis_id'] ?? 0);
        if ($jid === 0) {
            $adaNama = false;
            foreach ($jenis as $j) {
                if (strcasecmp(trim((string) $d['nama_dokumen']), trim((string) $j['nama'])) === 0) {
                    $adaNama = true;
                    break;
                }
            }
            if (!$adaNama) {
                $lainnya++;
            }
        } elseif (!isset($idJenis[$jid])) {
            $lainnya++;
        }
    }
    $total = count($jenis);
    $persen = $total > 0 ? (int) round($terkirim / $total * 100) : 0;
    return [
        'ta' => $ta,
        'total' => $total,
        'terkirim' => $terkirim,
        'belum' => max(0, $total - $terkirim),
        'persen' => $persen,
        'daftar' => $daftar,
        'lainnya' => $lainnya,
        'jenis_kosong' => $total === 0,
    ];
}

/** Progres pengiriman SELURUH sekolah binaan seorang pengawas (kunci = id sekolah). */
function dokumen_progress_binaan(int $pengawasId, string $tahunAjaran = ''): array
{
    $hasil = [];
    foreach (sekolah_binaan($pengawasId, true) as $s) {
        $hasil[(int) $s['id']] = ['nama' => (string) $s['nama'], 'sekolah' => $s,
            'progress' => dokumen_progress((int) $s['id'], $tahunAjaran)];
    }
    return $hasil;
}

/** Rata-rata persen progres dari sekumpulan hasil dokumen_progress()/dokumen_progress_binaan(). */
function dokumen_progress_rata(array $kumpulan): int
{
    $n = 0;
    $jml = 0;
    foreach ($kumpulan as $r) {
        $p = $r['progress'] ?? $r;
        $n += (int) ($p['persen'] ?? 0);
        $jml++;
    }
    return $jml > 0 ? (int) round($n / $jml) : 0;
}
