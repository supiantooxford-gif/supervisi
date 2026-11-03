<?php
/**
 * Modul MENU "SUSUN LAPORAN" (permintaan pemilik, skema v23).
 *
 * Permintaan pemilik: *"Pada halaman kepala sekolah/kepala madrasah tambahkan Menu Susun Laporan.
 * Isi dari menu ini adalah membuat dokumen dari kafer, kata pengantar, Bab 1. Pendahuluan (Latar
 * Belakang, Dasar hukum, Tujuan Supervisi, Manfaat Supervisi), Bab 2. Pelaksanaan Supervisi
 * (Daftar tabel guru yang disupervisi, Jadwal Supervisi, Instrumen Supervisi, Teknik Analisis Data,
 * Prosedur Supervisi), Bab 3. Hasil Supervisi (Tampilkan semua hasil supervisi setiap guru),
 * Bab 4. Kesimpulan dan Rekomendasi. Paling bawah tanda tangan Kepala Madrasah yang diatas tanda
 * tangannya nama tempat, tanggal, tahun."*
 *
 * Pilihan pemilik (jangan diubah tanpa bertanya):
 *  1. Bagian teks memakai TEMPLATE BAWAAN otomatis, dapat disunting dan disimpan PER SEKOLAH
 *     (suntingannya dipakai lagi pada setiap cetak).
 *  2. Bab 3 memuat seluruh supervisi sekolah itu, dapat disaring menurut PERIODE/SEMESTER yang
 *     tercatat DAN rentang tanggal.
 *  3. Bab 3 menampilkan RINGKASAN PER GURU + RINCIAN tiap supervisi (menu, nilai, predikat, catatan).
 *  4. Dokumen dicetak A4 (Print / Simpan PDF) DAN dapat diunduh sebagai Word (.docx).
 *
 * Teks disimpan pada tabel `laporan_teks` (sekolah_id + kunci) — bagian yang belum pernah disunting
 * memakai konstanta LAPORAN_TEKS_BAWAAN di bawah ini sehingga dokumen langsung siap cetak.
 */
declare(strict_types=1);

/** Baris "tempat, tanggal" pada bab 4 selalu memakai tanggal saat mencetak. */

/** Panjang maksimal satu bagian laporan. */
const LAPORAN_TEKS_MAKS = 8000;

/**
 * Bagian-bagian laporan: kunci => [label, kelompok, jenis, bawaan].
 *  - kelompok : judul kartu pada halaman penyuntingan
 *  - jenis    : `paragraf` (baris kosong = paragraf baru) atau `daftar` (tiap baris = butir bernomor)
 *
 * Isi bawaan boleh memuat PENANDA yang diisi otomatis saat dicetak (lihat laporan_teks_siap()):
 * {sekolah} {kepala} {nip_kepala} {tahun_ajaran} {semester} {tanggal} {batas_a} {batas_b} {batas_c}
 */
function laporan_bagian(): array
{
    return [
        // ---------------------------------------------------------------- SAMPUL (KAFER)
        'sampul_judul' => [
            'label' => 'Judul sampul (kafer)', 'kelompok' => 'sampul', 'jenis' => 'baris',
            'bawaan' => 'LAPORAN HASIL SUPERVISI AKADEMIK',
        ],
        'sampul_subjudul' => [
            'label' => 'Anak judul pada sampul', 'kelompok' => 'sampul', 'jenis' => 'baris',
            'bawaan' => 'Disusun sebagai bentuk pertanggungjawaban pelaksanaan supervisi akademik '
                . 'di {sekolah} pada Tahun Ajaran {tahun_ajaran} Semester {semester}.',
        ],
        'sampul_penyusun' => [
            'label' => 'Keterangan penyusun', 'kelompok' => 'sampul', 'jenis' => 'baris',
            'bawaan' => 'Kepala Sekolah',
        ],

        // ---------------------------------------------------------------- KATA PENGANTAR
        'kata_pengantar' => [
            'label' => 'Kata Pengantar', 'kelompok' => 'kata_pengantar', 'jenis' => 'paragraf',
            'bawaan' => "Puji syukur kami panjatkan kepada Tuhan Yang Maha Esa atas selesainya "
                . "penyusunan laporan hasil supervisi akademik di {sekolah}.\n\n"
                . "Laporan ini disusun sebagai bentuk pertanggungjawaban atas pelaksanaan supervisi "
                . "akademik pada Tahun Ajaran {tahun_ajaran} Semester {semester}. Supervisi akademik "
                . "dilaksanakan untuk membantu guru mengembangkan kemampuan mengelola proses "
                . "pembelajaran, sehingga mutu pembelajaran yang diterima peserta didik terus "
                . "meningkat.\n\n"
                . "Kami menyampaikan terima kasih kepada seluruh guru yang telah bekerja sama serta "
                . "kepada semua pihak yang membantu kelancaran kegiatan supervisi ini. Kami menyadari "
                . "laporan ini masih memiliki kekurangan, karena itu saran dan masukan yang membangun "
                . "sangat kami harapkan demi perbaikan pelaksanaan supervisi pada periode berikutnya.\n\n"
                . "Semoga laporan ini bermanfaat bagi peningkatan mutu pendidikan di {sekolah}.",
        ],

        // ---------------------------------------------------------------- BAB 1
        'latar_belakang' => [
            'label' => 'A. Latar Belakang', 'kelompok' => 'bab1', 'jenis' => 'paragraf',
            'bawaan' => "Guru merupakan komponen utama dalam proses pembelajaran. Mutu pembelajaran "
                . "yang diterima peserta didik sangat ditentukan oleh kemampuan guru merencanakan, "
                . "melaksanakan, dan menilai pembelajaran. Karena itu upaya peningkatan kemampuan "
                . "guru harus dilakukan secara terus-menerus dan terencana.\n\n"
                . "Supervisi akademik adalah kegiatan pembinaan yang dilakukan kepala sekolah/madrasah "
                . "terhadap guru, baik secara individual maupun kelompok, dengan tujuan membantu guru "
                . "memperbaiki dan meningkatkan kualitas pembelajarannya. Supervisi tidak dimaksudkan "
                . "untuk menilai kekurangan guru, melainkan untuk menemukan kekuatan yang perlu "
                . "dipertahankan dan area perbaikan yang perlu ditindaklanjuti bersama.\n\n"
                . "Pada Tahun Ajaran {tahun_ajaran} Semester {semester}, {sekolah} melaksanakan "
                . "supervisi akademik terhadap {jumlah_guru} guru. Kegiatan dilaksanakan melalui "
                . "tahap perencanaan, pelaksanaan pengamatan, analisis hasil, serta tindak lanjut "
                . "berupa pembinaan. Seluruh hasilnya kami susun dalam laporan ini agar dapat "
                . "digunakan sebagai dasar pengambilan keputusan dan penyusunan program pembinaan "
                . "selanjutnya.",
        ],
        'dasar_hukum' => [
            'label' => 'B. Dasar Hukum', 'kelompok' => 'bab1', 'jenis' => 'daftar',
            'bawaan' => "Undang-Undang Nomor 20 Tahun 2003 tentang Sistem Pendidikan Nasional.\n"
                . "Undang-Undang Nomor 14 Tahun 2005 tentang Guru dan Dosen.\n"
                . "Peraturan Pemerintah Nomor 19 Tahun 2005 tentang Standar Nasional Pendidikan.\n"
                . "Peraturan Menteri Pendidikan Nasional Nomor 16 Tahun 2007 tentang Standar "
                . "Kualifikasi Akademik dan Kompetensi Guru.\n"
                . "Peraturan Menteri Pendidikan dan Kebudayaan Nomor 6 Tahun 2018 tentang Penugasan "
                . "Guru sebagai Kepala Sekolah.\n"
                . "Program Kerja Pengawasan Akademik {sekolah} Tahun Ajaran {tahun_ajaran}.",
        ],
        'tujuan' => [
            'label' => 'C. Tujuan Supervisi', 'kelompok' => 'bab1', 'jenis' => 'daftar',
            'bawaan' => "Mengetahui kemampuan guru dalam menyusun perencanaan pembelajaran.\n"
                . "Mengetahui kemampuan guru dalam melaksanakan proses pembelajaran.\n"
                . "Mengetahui kemampuan guru dalam melaksanakan penilaian dan memanfaatkan hasilnya.\n"
                . "Menemukan kekuatan dan area perbaikan pembelajaran setiap guru.\n"
                . "Menentukan bentuk pembinaan dan tindak lanjut yang tepat bagi setiap guru.\n"
                . "Menyediakan data sebagai dasar penilaian kinerja guru dan penyusunan program "
                . "pengembangan sekolah.",
        ],
        'manfaat' => [
            'label' => 'D. Manfaat Supervisi', 'kelompok' => 'bab1', 'jenis' => 'daftar',
            'bawaan' => "Bagi guru: memperoleh umpan balik yang objektif serta bantuan profesional "
                . "untuk memperbaiki proses pembelajarannya.\n"
                . "Bagi kepala sekolah/madrasah: memperoleh data yang akurat mengenai kondisi "
                . "pembelajaran sebagai dasar pembinaan dan pengambilan keputusan.\n"
                . "Bagi peserta didik: memperoleh layanan pembelajaran yang lebih bermutu karena "
                . "guru terus dibina.\n"
                . "Bagi sekolah: terwujudnya budaya perbaikan yang berkelanjutan dan meningkatnya "
                . "mutu lulusan.",
        ],

        // ---------------------------------------------------------------- BAB 2
        'teknik_analisis' => [
            'label' => 'D. Teknik Analisis Data', 'kelompok' => 'bab2', 'jenis' => 'paragraf',
            'bawaan' => "Analisis data dilakukan terhadap skor butir penilaian pada instrumen "
                . "supervisi. Setiap butir diberi skor 1 sampai 4 sesuai rubrik, kemudian skor seluruh "
                . "butir dijumlahkan dan dibandingkan dengan skor maksimal instrumen.\n\n"
                . "Nilai akhir supervisi dihitung dengan rumus: Nilai = (Jumlah skor perolehan : "
                . "Jumlah skor maksimal) x 100.\n\n"
                . "Nilai akhir selanjutnya dikonversi menjadi predikat sesuai ambang yang berlaku di "
                . "{sekolah}, yaitu A (Amat Baik) mulai {batas_a}, B (Baik) mulai {batas_b}, "
                . "C (Cukup) mulai {batas_c}, dan D (Kurang) di bawah {batas_c}. Hasil analisis "
                . "dituangkan pada Bab 3, dan tiap guru memperoleh catatan kekuatan, area perbaikan, "
                . "serta rekomendasi tindak lanjut.",
        ],
        'prosedur' => [
            'label' => 'E. Prosedur Supervisi', 'kelompok' => 'bab2', 'jenis' => 'daftar',
            'bawaan' => "Tahap persiapan: menyusun program supervisi, menyiapkan instrumen, dan "
                . "menetapkan jadwal kunjungan bersama guru.\n"
                . "Tahap pengamatan: mengamati perangkat pembelajaran dan pelaksanaan pembelajaran "
                . "di kelas sesuai butir instrumen.\n"
                . "Tahap pencatatan: mencatat skor setiap butir serta kelebihan dan area perbaikan "
                . "yang ditemukan selama pengamatan.\n"
                . "Tahap analisis: menghitung nilai akhir dan menetapkan predikat setiap guru.\n"
                . "Tahap umpan balik: menyampaikan hasil supervisi kepada guru secara terbuka dan "
                . "membahas bersama bentuk perbaikannya.\n"
                . "Tahap tindak lanjut: melaksanakan pembinaan, pemantauan, dan supervisi ulang bila "
                . "diperlukan, serta mendokumentasikan seluruh hasilnya.",
        ],

        // ---------------------------------------------------------------- BAB 4
        'kesimpulan' => [
            'label' => 'A. Kesimpulan', 'kelompok' => 'bab4', 'jenis' => 'paragraf',
            'bawaan' => "Pelaksanaan supervisi akademik di {sekolah} pada Tahun Ajaran "
                . "{tahun_ajaran} Semester {semester} berjalan sesuai rencana. Dari {jumlah_supervisi} "
                . "kegiatan supervisi terhadap {jumlah_guru} guru diperoleh nilai rata-rata "
                . "{nilai_rata} dengan predikat paling banyak {predikat_terbanyak}.\n\n"
                . "Secara umum guru telah mampu menyusun perencanaan pembelajaran dan melaksanakan "
                . "pembelajaran dengan baik. Area yang masih perlu diperbaiki terutama terletak pada "
                . "pemanfaatan hasil penilaian untuk perbaikan pembelajaran serta variasi metode dan "
                . "media pembelajaran yang melibatkan peserta didik secara aktif.",
        ],
        'rekomendasi' => [
            'label' => 'B. Rekomendasi', 'kelompok' => 'bab4', 'jenis' => 'daftar',
            'bawaan' => "Guru melengkapi dan menyempurnakan perangkat pembelajaran sebelum "
                . "pembelajaran dilaksanakan.\n"
                . "Guru memanfaatkan hasil penilaian untuk merancang perbaikan dan program remedial "
                . "serta pengayaan.\n"
                . "Sekolah mengadakan kegiatan berbagi praktik baik antar guru secara berkala untuk "
                . "meningkatkan variasi metode dan media pembelajaran.\n"
                . "Kepala sekolah/madrasah melaksanakan supervisi lanjutan secara berkala, khususnya "
                . "bagi guru yang memerlukan pendampingan.\n"
                . "Hasil supervisi dijadikan salah satu dasar penilaian kinerja guru dan penyusunan "
                . "program kerja sekolah pada periode berikutnya.",
        ],
    ];
}

/** Judul kelompok pada halaman penyuntingan (urutan tampil). */
function laporan_kelompok(): array
{
    return [
        'sampul'         => ['judul' => 'Sampul (Kafer)',
                             'ket' => 'Isi halaman sampul laporan. Judul boleh diubah sesuai kebiasaan sekolah/madrasah.'],
        'kata_pengantar' => ['judul' => 'Kata Pengantar',
                             'ket' => 'Ditulis setelah halaman sampul, ditandatangani Bersama sampul.'],
        'bab1'           => ['judul' => 'Bab 1. Pendahuluan',
                             'ket' => 'Latar Belakang, Dasar Hukum, Tujuan Supervisi, dan Manfaat Supervisi.'],
        'bab2'           => ['judul' => 'Bab 2. Pelaksanaan Supervisi',
                             'ket' => 'Bagian teks saja. Tabel daftar guru, jadwal, dan instrumen diisi otomatis dari data aplikasi.'],
        'bab3'           => ['judul' => 'Bab 3. Hasil Supervisi',
                             'ket' => 'Seluruh hasil supervisi setiap guru ditampilkan otomatis dari data aplikasi.'],
        'bab4'           => ['judul' => 'Bab 4. Kesimpulan dan Rekomendasi',
                             'ket' => 'Kesimpulan dan rekomendasi hasil supervisi.'],
    ];
}

/**
 * Penanda yang diisi otomatis saat laporan dicetak. Ditampilkan pada halaman penyuntingan agar
 * kepala sekolah/madrasah tahu penanda mana yang boleh dipakai di dalam teksnya.
 */
function laporan_penanda(): array
{
    return [
        '{sekolah}'            => 'Nama sekolah/madrasah',
        '{kepala}'             => 'Nama kepala sekolah/madrasah',
        '{tahun_ajaran}'       => 'Tahun ajaran pada Profil & Pengaturan',
        '{semester}'           => 'Semester pada Profil & Pengaturan',
        '{tanggal}'            => 'Tanggal saat laporan dicetak',
        '{batas_a}'            => 'Ambang predikat A',
        '{batas_b}'            => 'Ambang predikat B',
        '{batas_c}'            => 'Ambang predikat C',
        '{jumlah_guru}'        => 'Banyak guru pada laporan',
        '{jumlah_supervisi}'   => 'Banyak kegiatan supervisi pada laporan',
        '{nilai_rata}'         => 'Nilai rata-rata hasil supervisi',
        '{predikat_terbanyak}' => 'Predikat yang paling banyak diperoleh guru',
    ];
}

/** Benar bila kunci itu bagian laporan yang sah. */
function laporan_kunci_sah(string $kunci): bool
{
    return array_key_exists($kunci, laporan_bagian());
}

/**
 * Semua teks laporan sekolah ini apa adanya dari basis data (kunci => isi).
 * Dibaca SEKALI per permintaan (cache) karena satu halaman bisa meminta belasan bagian sekaligus.
 */
function laporan_teks_tersimpan(int $sekolahId, bool $fresh = false): array
{
    static $cache = [];
    if ($sekolahId <= 0) {
        return [];
    }
    if (!$fresh && isset($cache[$sekolahId])) {
        return $cache[$sekolahId];
    }
    $out = [];
    foreach (all('SELECT kunci, isi FROM laporan_teks WHERE sekolah_id = ?', [$sekolahId]) as $r) {
        $out[(string) $r['kunci']] = (string) $r['isi'];
    }
    $cache[$sekolahId] = $out;
    return $out;
}

/** Isi satu bagian laporan: suntingan sekolah bila ada, kalau tidak template bawaan. */
function laporan_teks_mentah(int $sekolahId, string $kunci): string
{
    $bagian = laporan_bagian();
    if (!isset($bagian[$kunci])) {
        return '';
    }
    $rows = laporan_teks_tersimpan($sekolahId);
    return array_key_exists($kunci, $rows) ? $rows[$kunci] : (string) $bagian[$kunci]['bawaan'];
}

/** Benar bila bagian itu sudah disunting sekolah (berbeda dari template bawaan). */
function laporan_teks_disunting(int $sekolahId, string $kunci): bool
{
    $rows = laporan_teks_tersimpan($sekolahId);
    return array_key_exists($kunci, $rows);
}

/** Simpan satu bagian laporan. Isi kosong DISIMPAN (berarti bagian itu sengaja dikosongkan). */
function laporan_teks_simpan(int $sekolahId, string $kunci, string $isi): void
{
    if ($sekolahId <= 0 || !laporan_kunci_sah($kunci)) {
        return;
    }
    q('INSERT INTO laporan_teks (sekolah_id, kunci, isi, diubah) VALUES (?, ?, ?, datetime())
       ON CONFLICT (sekolah_id, kunci) DO UPDATE SET isi = excluded.isi, diubah = excluded.diubah',
        [$sekolahId, $kunci, $isi]);
    laporan_teks_tersimpan($sekolahId, true);
}

/** Kembalikan SELURUH bagian laporan sekolah ini ke template bawaan. */
function laporan_teks_reset(int $sekolahId): void
{
    if ($sekolahId > 0) {
        q('DELETE FROM laporan_teks WHERE sekolah_id = ?', [$sekolahId]);
        laporan_teks_tersimpan($sekolahId, true);
    }
}

/** Ringkas teks: rapikan baris baru berlebih dan batasi panjangnya. */
function laporan_teks_bersih(string $teks, int $maks = LAPORAN_TEKS_MAKS): string
{
    $teks = str_replace(["\r\n", "\r"], "\n", $teks);
    $teks = preg_replace("/\n{3,}/", "\n\n", $teks) ?? $teks;
    $teks = trim($teks);
    if (function_exists('mb_substr')) {
        if (mb_strlen($teks, 'UTF-8') > $maks) {
            $teks = mb_substr($teks, 0, $maks, 'UTF-8');
        }
    } elseif (strlen($teks) > $maks) {
        $teks = substr($teks, 0, $maks);
    }
    return $teks;
}

/**
 * Pisahkan teks menjadi PARAGRAF. Aturannya: baris kosong = paragraf baru, sedangkan satu baris
 * baru di dalam paragraf tetap dipertahankan sebagai baris (dipakai untuk daftar bernomor).
 */
function laporan_paragraf(string $teks): array
{
    $teks = str_replace(["\r\n", "\r"], "\n", trim($teks));
    if ($teks === '') {
        return [];
    }
    $hasil = [];
    $kini = '';
    foreach (explode("\n", $teks) as $baris) {
        if (trim($baris) === '') {
            if (trim($kini) !== '') {
                $hasil[] = trim($kini);
            }
            $kini = '';
            continue;
        }
        $kini .= ($kini === '' ? '' : "\n") . rtrim($baris);
    }
    if (trim($kini) !== '') {
        $hasil[] = trim($kini);
    }
    return $hasil;
}

/** Daftar butir dari teks: tiap baris yang tidak kosong menjadi satu butir. */
function laporan_butir(string $teks): array
{
    $out = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $teks)) as $baris) {
        $baris = trim(preg_replace('/^\s*[-*\x{2022}]\s*/u', '', $baris) ?? $baris);
        $baris = preg_replace('/^\s*\d+[.)]\s*/', '', $baris) ?? $baris;
        $baris = trim($baris);
        if ($baris !== '') {
            $out[] = $baris;
        }
    }
    return $out;
}

// =============================================================================================
// PENYARING: PERIODE/SEMESTER + RENTANG TANGGAL (pilihan pemilik)
// =============================================================================================

/** Daftar periode yang benar-benar tercatat pada supervisi sekolah ini (urut terbaru). */
function laporan_periode_ada(int $sekolahId): array
{
    $rows = all('SELECT DISTINCT periode FROM supervisi
                 WHERE sekolah_id = ? AND periode IS NOT NULL AND TRIM(periode) <> ""
                 ORDER BY periode DESC', [$sekolahId]);
    return array_values(array_filter(array_map(fn($r) => trim((string) $r['periode']), $rows)));
}

/** Periode bawaan: yang cocok dengan tahun ajaran + semester sekolah, kalau tidak ada → kosong. */
function laporan_periode_bawaan(int $sekolahId): string
{
    $ta = trim(setting('tahun_ajaran', ''));
    $sem = trim(setting('semester', ''));
    $ingin = trim($ta . ' ' . $sem);
    return in_array($ingin, laporan_periode_ada($sekolahId), true) ? $ingin : '';
}

/** Tanggal sah (YYYY-MM-DD) atau string kosong. */
function laporan_tanggal_sah(string $t): string
{
    $t = trim($t);
    if ($t === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) !== 1) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', $t));
    return checkdate($m, $d, $y) ? $t : '';
}

/**
 * Baca penyaring dari sebuah array (biasanya $_GET) dan sahkan isinya:
 * periode harus salah satu periode yang tercatat (selain itu diabaikan), tanggal harus sah,
 * dan rentang terbalik otomatis ditukar.
 */
function laporan_filter(array $src, int $sekolahId): array
{
    $periode = trim((string) ($src['periode'] ?? ''));
    if ($periode !== '' && !in_array($periode, laporan_periode_ada($sekolahId), true)) {
        $periode = '';
    }
    $dari = laporan_tanggal_sah((string) ($src['dari'] ?? ''));
    $sampai = laporan_tanggal_sah((string) ($src['sampai'] ?? ''));
    if ($dari !== '' && $sampai !== '' && $dari > $sampai) {
        [$dari, $sampai] = [$sampai, $dari];
    }
    return ['periode' => $periode, 'dari' => $dari, 'sampai' => $sampai];
}

/**
 * Query string penyaring (dipakai tautan cetak & unduh Word).
 *
 * `periode` SELALU disertakan — termasuk saat kosong. Sebabnya: kekosongan itu berarti
 * "seluruh periode", sedangkan alamat TANPA parameter periode justru diartikan "pakai periode
 * bawaan sekolah". Tanpa penanda eksplisit, pilihan "Semua data" akan berubah kembali menjadi
 * periode bawaan ketika halaman cetak dibuka.
 */
function laporan_query(array $f): string
{
    $q = ['periode' => (string) ($f['periode'] ?? '')];
    foreach (['dari', 'sampai'] as $k) {
        if (($f[$k] ?? '') !== '') {
            $q[$k] = (string) $f[$k];
        }
    }
    return '?' . http_build_query($q);
}

/** Label periode laporan, mis. "Periode 2026/2027 Ganjil · 1 Juli 2026 s.d. 31 Desember 2026". */
function laporan_periode_label(array $f): string
{
    $bagian = [];
    $bagian[] = ($f['periode'] ?? '') !== '' ? 'Periode ' . $f['periode'] : 'Seluruh periode yang tercatat';
    if (($f['dari'] ?? '') !== '' || ($f['sampai'] ?? '') !== '') {
        $dari = $f['dari'] !== '' ? tanggal_id($f['dari']) : 'awal pencatatan';
        $sampai = $f['sampai'] !== '' ? tanggal_id($f['sampai']) : 'sekarang';
        $bagian[] = $dari . ' s.d. ' . $sampai;
    }
    return implode(' &middot; ', $bagian);
}

// =============================================================================================
// DATA LAPORAN (diambil HANYA untuk sekolah yang sedang aktif — anti kebocoran antar sekolah)
// =============================================================================================

/** Klausa WHERE + parameter untuk tabel supervisi sesuai penyaring. */
function laporan_supervisi_where(int $sekolahId, array $f): array
{
    $w = ['s.sekolah_id = ?'];
    $p = [$sekolahId];
    if (($f['periode'] ?? '') !== '') {
        $w[] = 's.periode = ?';
        $p[] = $f['periode'];
    }
    if (($f['dari'] ?? '') !== '') {
        $w[] = 's.tanggal >= ?';
        $p[] = $f['dari'];
    }
    if (($f['sampai'] ?? '') !== '') {
        $w[] = 's.tanggal <= ?';
        $p[] = $f['sampai'];
    }
    return [implode(' AND ', $w), $p];
}

/** Daftar guru AKTIF sekolah ini (target supervisi) beserta jumlah supervisi pada penyaring. */
function laporan_guru_daftar(int $sekolahId, array $f): array
{
    [$w, $p] = laporan_supervisi_where($sekolahId, $f);
    return all('SELECT g.id, g.nama, g.nip, g.mapel, g.jabatan,
                       (SELECT COUNT(*) FROM supervisi s WHERE s.guru_id = g.id AND ' . $w . ') AS jml
                FROM guru g
                WHERE g.sekolah_id = ? AND g.aktif = 1
                ORDER BY g.nama', array_merge($p, [$sekolahId]));
}

/** Jadwal supervisi sekolah ini (mengikuti rentang tanggal bila diisi). */
function laporan_jadwal(int $sekolahId, array $f): array
{
    $w = ['sekolah_id = ?'];
    $p = [$sekolahId];
    if (($f['dari'] ?? '') !== '') {
        $w[] = 'tanggal >= ?';
        $p[] = $f['dari'];
    }
    if (($f['sampai'] ?? '') !== '') {
        $w[] = 'tanggal <= ?';
        $p[] = $f['sampai'];
    }
    return all('SELECT * FROM jadwal WHERE ' . implode(' AND ', $w) . ' ORDER BY tanggal, urutan, id', $p);
}

/** Daftar instrumen/menu supervisi (global) + jumlah butir aktif & jumlah supervisi pada penyaring. */
function laporan_instrumen(int $sekolahId, array $f): array
{
    [$w, $p] = laporan_supervisi_where($sekolahId, $f);
    return all('SELECT i.id, i.nama, i.kode, i.deskripsi,
                       (SELECT COUNT(*) FROM butir b WHERE b.instrumen_id = i.id AND b.aktif = 1) AS jml_butir,
                       (SELECT COUNT(*) FROM supervisi s WHERE s.instrumen_id = i.id AND ' . $w . ') AS jml
                FROM instrumen i WHERE i.aktif = 1 ORDER BY i.urutan, i.id', $p);
}

/** RINGKASAN PER GURU: jumlah supervisi, rata-rata nilai, predikat. */
function laporan_guru_ringkas(int $sekolahId, array $f): array
{
    [$w, $p] = laporan_supervisi_where($sekolahId, $f);
    $rows = all('SELECT g.nama, g.nip, g.mapel, g.jabatan,
                        COUNT(s.id) AS jml,
                        AVG(s.nilai) AS rata,
                        COUNT(DISTINCT s.instrumen_id) AS jml_menu,
                        MAX(s.nilai) AS tertinggi,
                        MIN(s.nilai) AS terendah
                 FROM supervisi s JOIN guru g ON g.id = s.guru_id
                 WHERE ' . $w . '
                 GROUP BY s.guru_id
                 ORDER BY g.nama', $p);
    $th = thresholds();
    foreach ($rows as &$r) {
        $r['nilai_rata'] = round((float) $r['rata'], 2);
        $r['predikat'] = predikat_dari((float) $r['nilai_rata'], $th);
    }
    unset($r);
    return $rows;
}

/** RINCIAN tiap supervisi (menu, nilai, predikat, catatan) urut nama guru lalu tanggal. */
function laporan_supervisi_list(int $sekolahId, array $f): array
{
    [$w, $p] = laporan_supervisi_where($sekolahId, $f);
    return all('SELECT s.id, s.tanggal, s.periode, s.supervisor, s.total_skor, s.skor_maks, s.nilai,
                       s.predikat, s.kelebihan, s.kekurangan, s.tindak_lanjut,
                       g.nama AS guru, g.nip, g.mapel, i.nama AS instrumen, i.kode
                FROM supervisi s
                JOIN guru g ON g.id = s.guru_id
                JOIN instrumen i ON i.id = s.instrumen_id
                WHERE ' . $w . '
                ORDER BY g.nama, s.tanggal, s.id', $p);
}

/** Angka ringkas laporan: jumlah guru disupervisi, jumlah supervisi, rata-rata, predikat terbanyak. */
function laporan_ringkas(int $sekolahId, array $f): array
{
    $ringkas = laporan_guru_ringkas($sekolahId, $f);
    $jml = 0;
    $total = 0.0;
    $predikat = [];
    foreach ($ringkas as $r) {
        $jml += (int) $r['jml'];
        $total += (float) $r['rata'];
        $p = (string) $r['predikat'];
        $predikat[$p] = ($predikat[$p] ?? 0) + 1;
    }
    $terbanyak = '-';
    if ($predikat !== []) {
        arsort($predikat);
        $terbanyak = (string) array_key_first($predikat) . ' (' . predikat_label((string) array_key_first($predikat)) . ')';
    }
    $jumlahGuru = count($ringkas);
    return [
        'guru'      => $jumlahGuru,
        'supervisi' => $jml,
        'rata'      => $jumlahGuru > 0 ? round($total / $jumlahGuru, 2) : 0.0,
        'predikat'  => $terbanyak,
        'per_predikat' => $predikat,
    ];
}

// =============================================================================================
// TEKS SIAP CETAK (penanda diisi otomatis)
// =============================================================================================

/**
 * Isi bagian laporan siap cetak: template/suntingan + PENANDA yang diisi otomatis sesuai data
 * sekolah & angka laporan. Dipakai halaman cetak maupun unduhan Word supaya keduanya identik.
 */
function laporan_teks_siap(int $sekolahId, string $kunci, array $ringkas = [], array $ganti = []): string
{
    $teks = laporan_teks_mentah($sekolahId, $kunci);
    if ($teks === '') {
        return '';
    }
    $kepala = trim(setting('kepala_sekolah', ''));
    $peta = [
        '{sekolah}'            => setting('nama_sekolah', ''),
        '{kepala}'             => $kepala !== '' ? $kepala : '-',
        '{nip_kepala}'         => setting('nip_kepala_sekolah', ''),
        '{tahun_ajaran}'       => setting('tahun_ajaran', '-'),
        '{semester}'           => setting('semester', '-'),
        '{tanggal}'            => tanggal_id(date('Y-m-d')),
        '{batas_a}'            => setting('batas_a', '90'),
        '{batas_b}'            => setting('batas_b', '80'),
        '{batas_c}'            => setting('batas_c', '70'),
        '{jumlah_guru}'        => isset($ringkas['guru']) ? (string) $ringkas['guru'] : '-',
        '{jumlah_supervisi}'   => isset($ringkas['supervisi']) ? (string) $ringkas['supervisi'] : '-',
        '{nilai_rata}'         => isset($ringkas['rata']) ? r1($ringkas['rata']) : '-',
        '{predikat_terbanyak}' => (string) ($ringkas['predikat'] ?? '-'),
    ];
    foreach ($ganti as $k => $v) {
        $peta[(string) $k] = (string) $v;
    }
    return str_replace(array_keys($peta), array_values($peta), $teks);
}

/** Nama berkas aman dari nama sekolah/madrasah (untuk unduhan Word). */
function laporan_slug(string $teks): string
{
    $teks = strtolower(trim($teks));
    $teks = preg_replace('/[^a-z0-9]+/', '-', $teks) ?? '';
    return trim($teks, '-') !== '' ? trim($teks, '-') : 'laporan';
}

/**
 * Satu bagian teks laporan sebagai HTML (paragraf atau daftar bernomor) — dipakai lembar cetak
 * A4. Teks pengguna SELALU di-escape; hanya penandanya yang diisi otomatis.
 */
function laporan_html_bagian(int $sekolahId, string $kunci, array $ringkas = []): string
{
    $bagian = laporan_bagian();
    if (!isset($bagian[$kunci])) {
        return '';
    }
    $teks = laporan_teks_siap($sekolahId, $kunci, $ringkas);
    if (trim($teks) === '') {
        return '';
    }
    $out = '<h3 class="lap-sub">' . e((string) $bagian[$kunci]['label']) . '</h3>';
    if ($bagian[$kunci]['jenis'] === 'daftar') {
        $out .= '<ol class="lap-daftar">';
        foreach (laporan_butir($teks) as $b) {
            $out .= '<li>' . e($b) . '</li>';
        }
        $out .= '</ol>';
        return $out;
    }
    foreach (laporan_paragraf($teks) as $p) {
        $out .= '<p class="lap-par">' . nl2br(e($p)) . '</p>';
    }
    return $out;
}

/** Kelompokkan rincian supervisi per guru (urutan sudah nama guru lalu tanggal). */
function laporan_per_guru(array $list): array
{
    $out = [];
    foreach ($list as $r) {
        $out[(string) $r['guru']][] = $r;
    }
    return $out;
}
