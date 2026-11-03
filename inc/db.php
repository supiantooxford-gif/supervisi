<?php
/**
 * Lapisan database (SQLite via PDO) + skema, migrasi, dan data awal.
 *
 * Aplikasi ini MULTI-SEKOLAH: hampir semua tabel operasional punya kolom
 * sekolah_id, dan setiap query wajib dibatasi ke sekolah yang sedang aktif.
 *
 * Catatan penting: ensure_schema() dijalankan pada setiap request dan seluruh
 * isinya dibungkus SATU transaksi (BEGIN IMMEDIATE). Autocommit SQLite melakukan
 * fsync per statement sehingga tanpa pembungkusan ini setiap request membayar
 * puluhan fsync walaupun tidak ada perubahan data.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/template.php';
// Modul HALAMAN DEPAN (landing page) — dipakai saat mengisi isi awal halaman depan (landing_seed).
require_once __DIR__ . '/landing.php';

/** Naikkan angka ini setiap kali skema berubah agar migrasi ikut dijalankan. */
const SCHEMA_VERSION = '25';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    ensure_schema($pdo);

    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** Jalankan query dengan prepared statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $r) {
        if (strcasecmp((string) $r['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

/** Tambah kolom bila belum ada (untuk basis data yang sudah terisi data). */
function db_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!db_has_column($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

/** Definisi SQL suatu tabel (dipakai untuk mendeteksi skema lama). */
function db_table_sql(PDO $pdo, string $table): string
{
    $st = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute([$table]);
    return (string) $st->fetchColumn();
}

/**
 * Kolom yang WAJIB ada pada skema terkini, per tabel.
 *
 * PENTING — JANGAN dihapus: penanda `data/.schema_ok` bisa LEBIH BARU daripada isi basis data
 * (mis. tulisan terakhir pada berkas -wal hilang setelah COMMIT). Bila itu terjadi, skema
 * dianggap cocok dan migrasi tidak pernah dijalankan lagi, padahal kolomnya belum ada — akibatnya
 * menyimpan pengaturan gagal dengan "no such column" tanpa jejak yang jelas.
 *
 * Karena itu ensure_schema() hanya berhenti lebih dulu bila penanda cocok DAN seluruh kolom di
 * bawah ini benar-benar ada; kalau tidak, migrasi (yang seluruhnya idempoten) dijalankan ulang
 * sehingga basis data menyusul. Setiap menambah kolom baru lewat db_add_column(), DAFTARKAN juga
 * di sini.
 */
const SCHEMA_KOLOM_WAJIB = [
    'sekolah'          => ['nsm', 'desa', 'kecamatan', 'kabupaten', 'provinsi', 'telepon', 'email', 'website',
                           'jenis', 'logo', 'aktivasi', 'aktif_sampai', 'aktivasi_ke', 'uji_mulai'],
    'users'            => ['foto', 'nip', 'asal', 'sekolah_id'],
    'guru'             => ['sekolah_id'],
    'supervisi'        => ['sekolah_id'],
    'butir'            => ['aktif'],
    'dokumen_madrasah' => ['status', 'tahun_ajaran'],
    // OBROLAN (CHAT, skema 21): satu ruang per sekolah/madrasah binaan. Tabel baru didaftarkan di
    // sini karena `skema_terkini()` memeriksa keberadaan TABEL lebih dulu — dengan begitu penanda
    // skema yang mendahului pembuatan tabel tetap memulihkan dirinya pada permintaan berikutnya.
    'chat_pesan'       => ['sekolah_id', 'user_id', 'isi', 'waktu'],
    'chat_baca'        => ['user_id', 'sekolah_id', 'pesan_id'],
    // HALAMAN DEPAN (LANDING PAGE, skema 19). Karena aplikasi ini hanya punya SCHEMA_KOLOM_WAJIB
    // (tanpa daftar tabel terpisah), TABEL BARU didaftarkan di sini dengan kolom kuncinya —
    // `skema_terkini()` memeriksa keberadaan TABEL lebih dulu, sehingga penanda skema yang
    // mendahului pembuatan tabel tetap memulihkan dirinya sendiri pada permintaan berikutnya.
    'landing_fitur'     => ['ikon', 'judul', 'teks', 'urutan', 'aktif'],
    'landing_unggulan'  => ['ikon', 'judul', 'teks', 'urutan', 'aktif'],
    'landing_testimoni' => ['nama', 'jabatan', 'isi', 'foto', 'urutan', 'aktif'],
    'landing_slider'    => ['judul', 'keterangan', 'foto', 'urutan', 'aktif'],
    'landing_pesan'     => ['nama', 'kontak', 'pesan', 'dibaca'],
    // SUSUN LAPORAN (skema 23): teks laporan supervisi yang disunting kepala sekolah/madrasah,
    // disimpan PER SEKOLAH (kunci = bagian laporan, mis. `latar_belakang`). Tabel baru WAJIB
    // didaftarkan di sini supaya penanda skema yang mendahului pembuatan tabel memulihkan diri.
    'laporan_teks'      => ['sekolah_id', 'kunci', 'isi'],
];

/** Benar bila seluruh kolom SCHEMA_KOLOM_WAJIB benar-benar ada pada basis data. */
function skema_terkini(PDO $pdo): bool
{
    foreach (SCHEMA_KOLOM_WAJIB as $tabel => $kolom) {
        $ada = [];
        try {
            foreach ($pdo->query('PRAGMA table_info(' . $tabel . ')') as $r) {
                $ada[] = strtolower((string) $r['name']);
            }
        } catch (Throwable $e) {
            return false;
        }
        if (!$ada) {
            return false; // tabelnya belum ada (penanda mendahului pembuatan tabel)
        }
        foreach ((array) $kolom as $k) {
            if (!in_array(strtolower((string) $k), $ada, true)) {
                return false;
            }
        }
    }
    return true;
}

/** Nilai kolom pertama dari satu query (untuk dipakai di dalam migrasi). */
function db_val(PDO $pdo, string $sql, array $params = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** Satu baris hasil query (untuk dipakai di dalam migrasi). */
function db_row(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function ensure_schema(PDO $pdo): void
{
    // Penanda cepat: skema + seed hanya dihitung sekali, bukan tiap request.
    // CATATAN: penanda yang cocok saja TIDAK cukup — kolom/tabel wajib ikut diperiksa supaya
    // penanda yang lebih baru daripada isi basis data memulihkan dirinya sendiri (lihat
    // SCHEMA_KOLOM_WAJIB + skema_terkini()).
    $flag = dirname(DB_FILE) . '/.schema_ok';
    if (is_file($flag) && filesize(DB_FILE) > 0 && trim((string) @file_get_contents($flag)) === SCHEMA_VERSION
        && skema_terkini($pdo)) {
        return;
    }

    // Migrasi v2 -> v3 membangun ulang tabel `instrumen` (unique kode harus
    // menjadi per-sekolah). Foreign key harus dimatikan DULU, di luar transaksi,
    // supaya DROP TABLE instrumen tidak menghapus baris `butir` lewat cascade.
    $fkOn = ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn()) === 1;
    if ($fkOn) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // ---------- Tabel ----------
        $pdo->exec('CREATE TABLE IF NOT EXISTS sekolah (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            npsn TEXT,
            -- IDENTITAS LEMBAGA lengkap (skema 17, permintaan pemilik): NSM (khusus madrasah),
            -- alamat yang dipecah per wilayah, telepon, email, dan situs/laman.
            nsm TEXT,
            alamat TEXT,
            desa TEXT,
            kecamatan TEXT,
            kabupaten TEXT,
            provinsi TEXT,
            telepon TEXT,
            email TEXT,
            website TEXT,
            kepala_sekolah TEXT,
            nip_kepala_sekolah TEXT,
            tahun_ajaran TEXT,
            semester TEXT,
            batas_a REAL NOT NULL DEFAULT 90,
            batas_b REAL NOT NULL DEFAULT 80,
            batas_c REAL NOT NULL DEFAULT 70,
            jenis TEXT NOT NULL DEFAULT "umum",
            logo TEXT,
            catatan TEXT,
            aktif INTEGER NOT NULL DEFAULT 1,
            verifikasi TEXT NOT NULL DEFAULT "disetujui",
            kontak_nama TEXT,
            kontak_telepon TEXT,
            kontak_email TEXT,
            tanggal_daftar TEXT,
            alasan_tolak TEXT,
            aktivasi TEXT NOT NULL DEFAULT \'aktif\',
            aktivasi_pada TEXT,
            aktif_sampai TEXT,
            aktivasi_ke INTEGER NOT NULL DEFAULT 1,
            -- MASA UJI COBA bagi sekolah yang BELUM diaktivasi (skema 24; lamanya 15 hari sejak
            -- v25, permintaan pemilik): hitungannya dimulai saat sekolah terdaftar. Kosong = belum
            -- tercatat (sekolah lama memakai `tanggal_daftar`, cadangan `dibuat`).
            uji_mulai TEXT,
            dibuat TEXT
        )');

        // Pengaturan tingkat APLIKASI (bukan per sekolah) — mis. kunci rahasia generator kode
        // aktivasi. SENGAJA di basis data, BUKAN berkas di `data/`: berkas di folder itu dapat
        // diunduh lewat web sehingga rahasianya bisa terbaca siapa pun.
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_pengaturan (
            kunci TEXT PRIMARY KEY,
            nilai TEXT,
            diubah TEXT
        )');

        // Tabel lama (skema <= 2) untuk membaca pengaturan sekolah pertama.
        $pdo->exec('CREATE TABLE IF NOT EXISTS pengaturan (
            kunci TEXT PRIMARY KEY,
            nilai TEXT
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS guru (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            nip TEXT,
            nama TEXT NOT NULL,
            mapel TEXT,
            jabatan TEXT,
            kelas TEXT,
            telepon TEXT,
            aktif INTEGER NOT NULL DEFAULT 1,
            demo INTEGER NOT NULL DEFAULT 0,
            dibuat TEXT
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            nama TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "guru",
            sekolah_id INTEGER REFERENCES sekolah(id) ON DELETE CASCADE,
            guru_id INTEGER REFERENCES guru(id) ON DELETE SET NULL,
            aktif INTEGER NOT NULL DEFAULT 1,
            login_terakhir TEXT,
            foto TEXT,
            -- skema v18: NIP akun (dipakai akun PENGAWAS BINA yang diajukan lewat halaman
            -- pendaftaran sekolah) + asal akun (diisi "pendaftaran" bila dibuat oleh pendaftar).
            nip TEXT,
            asal TEXT,
            dibuat TEXT
        )');

        // Menu supervisi bersifat GLOBAL: satu daftar dipakai semua sekolah/madrasah,
        // hanya dikelola Super Admin (pada basis data lama akan dimigrasikan di bawah).
        $pdo->exec('CREATE TABLE IF NOT EXISTS instrumen (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL,
            deskripsi TEXT,
            ikon TEXT,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS butir (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instrumen_id INTEGER NOT NULL REFERENCES instrumen(id) ON DELETE CASCADE,
            urutan INTEGER NOT NULL DEFAULT 0,
            komponen TEXT NOT NULL,
            indikator TEXT,
            skor_maks INTEGER NOT NULL DEFAULT 4,
            aktif INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS supervisi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            guru_id INTEGER NOT NULL REFERENCES guru(id) ON DELETE CASCADE,
            instrumen_id INTEGER NOT NULL REFERENCES instrumen(id) ON DELETE CASCADE,
            tanggal TEXT NOT NULL,
            periode TEXT,
            supervisor TEXT,
            total_skor REAL NOT NULL DEFAULT 0,
            skor_maks REAL NOT NULL DEFAULT 0,
            nilai REAL NOT NULL DEFAULT 0,
            predikat TEXT,
            kelebihan TEXT,
            kekurangan TEXT,
            tindak_lanjut TEXT,
            status TEXT NOT NULL DEFAULT "draft",
            dibuat TEXT,
            diubah TEXT,
            UNIQUE (guru_id, instrumen_id, tanggal)
        )');

        // Jadwal supervisi: satu baris = satu guru yang dijadwalkan pada satu tanggal.
        $pdo->exec('CREATE TABLE IF NOT EXISTS jadwal (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            tanggal TEXT NOT NULL,
            urutan INTEGER NOT NULL DEFAULT 0,
            guru_id INTEGER REFERENCES guru(id) ON DELETE SET NULL,
            nama_guru TEXT NOT NULL,
            mapel TEXT,
            kelas TEXT,
            jam_ke TEXT,
            supervisor TEXT,
            keterangan TEXT,
            dibuat TEXT
        )');

        // Sekolah binaan pengawas (relasi banyak-ke-banyak: 1 pengawas -> banyak sekolah).
        $pdo->exec('CREATE TABLE IF NOT EXISTS pengawas_sekolah (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            dibuat TEXT,
            UNIQUE (user_id, sekolah_id)
        )');

        // `butir_id` boleh NULL: baris penilaian LAMA tetap disimpan walau butirnya sudah
        // diganti dengan butir global (nama & skor maksimal lamanya disimpan di kolom arsip).
        $pdo->exec('CREATE TABLE IF NOT EXISTS detail (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supervisi_id INTEGER NOT NULL REFERENCES supervisi(id) ON DELETE CASCADE,
            butir_id INTEGER REFERENCES butir(id) ON DELETE CASCADE,
            skor INTEGER NOT NULL DEFAULT 0,
            catatan TEXT,
            komponen_arsip TEXT,
            maks_arsip INTEGER,
            urutan_arsip INTEGER,
            UNIQUE (supervisi_id, butir_id)
        )');

        // ---------- Bukti foto pengamatan pembelajaran (skema 12) ----------
        // Maksimal 2 foto per supervisi (permintaan pemilik), hanya untuk menu
        // "Supervisi Pengamatan Pembelajaran". Yang disimpan HANYA URL CDN hasil
        // media proxy platform — berkasnya tidak pernah disimpan di server aplikasi.
        $pdo->exec('CREATE TABLE IF NOT EXISTS supervisi_foto (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supervisi_id INTEGER NOT NULL REFERENCES supervisi(id) ON DELETE CASCADE,
            url TEXT NOT NULL,
            urutan INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT
        )');

        // ---------- Komentar / tindak lanjut PENGAWAS pada rekapan supervisi (skema 15) ----------
        // Permintaan pemilik: "Pada akun pengawas tambahkan fitur untuk mengomentari seluruh hasil
        // rekapan supervisi setiap madrasah dan terkirim ke akun kepala madrasah untuk dilihat hasil
        // komentar atau tindak lanjut tersebut."
        // Satu baris per (madrasah, pengawas) — pengawas boleh memperbarui komentarnya; kepala
        // madrasah hanya MEMBACA (pilihan pemilik: tanpa balasan).
        $pdo->exec('CREATE TABLE IF NOT EXISTS komentar_pengawas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            pengawas_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            isi TEXT NOT NULL,
            dibuat TEXT,
            diubah TEXT,
            UNIQUE (sekolah_id, pengawas_id)
        )');

        // ---------- JENIS DOKUMEN (GLOBAL, dikelola Super Admin) — skema 15 ----------
        // Permintaan pemilik: "Nama Dokumen (Pilihan: Kurikulum Madrasah (KM), Rencana Kerja
        // Tahunan (RKT), ... ) ... Pilihan nama dokumen bisa dirubah/di edit/ dihapus melalui
        // akun super admin". Daftar ini GLOBAL (dipakai semua sekolah), dipakai sebagai pilihan
        // pada formulir kirim dokumen; nama setiap baris ikut disimpan (snapshot) di dokumennya.
        $pdo->exec('CREATE TABLE IF NOT EXISTS dokumen_jenis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT,
            diubah TEXT
        )');

        // ---------- DOKUMEN MADRASAH → PENGAWAS (skema 15) ----------
        // Permintaan pemilik: "Pada Halaman/akun kepala Madrasah tambahkan fitur untuk kirim dokumen
        // ke pengawas dengan inputan: Nama Dokumen (...), Unggah Berkas, Keterangan." Yang disimpan
        // HANYA URL CDN hasil media proxy platform (berkasnya tidak pernah disimpan di server).
        // Status tinjauan (skema 16, permintaan pemilik): pengawas menilai setiap dokumen —
        // 'menunggu' (belum ditinjau) / 'disetujui' / 'revisi' / 'ditolak' — beserta keterangan
        // atau alasannya. Kolom ini WAJIB ada di definisi CREATE TABLE juga (instalasi baru).
        // TAHUN AJARAN (skema 22, permintaan pemilik): "Pada halaman kepala madrasah/sekolah
        // dibagian kirim dokumen ke pengawas tambahkan tahun ajaran diatas nama dokumen (Ada
        // pilihan beberapa tahun ajaran)." → kolom ini menyimpan tahun ajaran PILIHAN pengirim.
        // Kosong = dokumen lama; tahun ajarannya dihitung dari tanggal unggah (perilaku v20).
        $pdo->exec('CREATE TABLE IF NOT EXISTS dokumen_madrasah (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            jenis_id INTEGER REFERENCES dokumen_jenis(id) ON DELETE SET NULL,
            nama_dokumen TEXT NOT NULL,
            keterangan TEXT,
            url TEXT NOT NULL,
            nama_berkas TEXT,
            ukuran INTEGER,
            pengirim TEXT,
            pengirim_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            status TEXT NOT NULL DEFAULT \'menunggu\',
            catatan TEXT,
            ditinjau_oleh TEXT,
            ditinjau_pada TEXT,
            tahun_ajaran TEXT,
            dibuat TEXT
        )');

        // ---------- OBROLAN (CHAT) — SATU RUANG PER SEKOLAH BINAAN (skema 21) ----------
        // Permintaan pemilik: "Tambahkan fitur obrolan seperti aplikasi e-Guru hanya saja yang bisa
        // mengobrol hanya pengawas bina dan binaannya yang sementara online. Tidak bisa mengobrol
        // dengan yang bukan binaannya."
        // Pilihan pemilik: (1) SATU ruang per sekolah/madrasah binaan; (2) Super Admin ikut;
        // (3) pesan muncul otomatis. Jadi RUANG = satu `sekolah_id` — sekolah milik akun itu,
        // atau sekolah BINAAN bagi pengawas. Penjagaannya di server (chat_ruang_boleh()).
        // Nama/username/peran pengirim ikut disimpan sebagai SNAPSHOT supaya riwayat tetap terbaca
        // walau akunnya kelak dihapus (relasinya SET NULL, pola yang sama dengan log_aktivitas).
        $pdo->exec('CREATE TABLE IF NOT EXISTS chat_pesan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            nama TEXT,
            username TEXT,
            role TEXT,
            isi TEXT NOT NULL,
            waktu TEXT NOT NULL
        )');
        // Penanda "sudah dibaca" PER PENGGUNA PER RUANG (Super Admin & pengawas dengan banyak
        // binaan bisa punya beberapa ruang sekaligus).
        $pdo->exec('CREATE TABLE IF NOT EXISTS chat_baca (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            pesan_id INTEGER NOT NULL DEFAULT 0,
            waktu TEXT,
            PRIMARY KEY (user_id, sekolah_id)
        )');

        // ---------- Log aktivitas & sesi yang sedang berjalan (skema 11) ----------
        // Riwayat masuk/keluar/gagal masuk, dipakai halaman Log Aktivitas (Super Admin).
        // Nama akun & sekolah disimpan sebagai SNAPSHOT supaya riwayat tetap terbaca walau
        // akun/sekolahnya kelak dihapus — relasinya SET NULL, bukan CASCADE.
        $pdo->exec('CREATE TABLE IF NOT EXISTS log_aktivitas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            nama TEXT,
            username TEXT,
            role TEXT,
            sekolah_id INTEGER REFERENCES sekolah(id) ON DELETE SET NULL,
            sekolah TEXT,
            aktivitas TEXT NOT NULL,
            keterangan TEXT,
            ip TEXT,
            peramban TEXT,
            waktu TEXT NOT NULL
        )');

        // Sesi yang masih berjalan → dasar daftar "sedang online". `id` adalah HASH id sesi;
        // id sesi yang sebenarnya tidak pernah disimpan.
        $pdo->exec('CREATE TABLE IF NOT EXISTS sesi_aktif (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            ip TEXT,
            peramban TEXT,
            mulai TEXT NOT NULL,
            terakhir TEXT NOT NULL
        )');

        // ---------- HALAMAN DEPAN (LANDING PAGE, skema 19) ----------
        // Permintaan pemilik: "Buat halaman depan aplikasi menjadi tampilan web landing page yang
        // professional dan menarik yang menampilkan beranda, fitur aplikasi, keunggulan, testimoni,
        // tentang aplikasi, kontak. panel login tetap dipertahankan. Untuk mengisi halaman landing
        // page di isi lewat akun super admin."
        // Isi TEKS-nya disimpan pada tabel `app_pengaturan` (awalan kunci `landing_`), sedangkan
        // daftar berulang & pesan kontak memakai tabel di bawah ini. Semuanya TINGKAT APLIKASI
        // (tanpa sekolah_id) karena halaman depan dipakai bersama semua sekolah/madrasah.
        $pdo->exec('CREATE TABLE IF NOT EXISTS landing_slider (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            judul TEXT NOT NULL,
            keterangan TEXT,
            foto TEXT,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS landing_fitur (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ikon TEXT,
            judul TEXT NOT NULL,
            teks TEXT,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS landing_unggulan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ikon TEXT,
            judul TEXT NOT NULL,
            teks TEXT,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS landing_testimoni (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            jabatan TEXT,
            isi TEXT,
            foto TEXT,
            urutan INTEGER NOT NULL DEFAULT 0,
            aktif INTEGER NOT NULL DEFAULT 1,
            dibuat TEXT
        )');
        // Pesan dari formulir kontak pada halaman depan (dibaca Super Admin lewat menu
        // "Halaman Depan (Landing)"). Tanpa relasi ke tabel lain supaya riwayat tetap utuh.
        $pdo->exec('CREATE TABLE IF NOT EXISTS landing_pesan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nama TEXT NOT NULL,
            kontak TEXT,
            pesan TEXT NOT NULL,
            ip TEXT,
            dibaca INTEGER NOT NULL DEFAULT 0,
            dibuat TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_landing_slider ON landing_slider(urutan, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_landing_fitur ON landing_fitur(urutan, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_landing_unggulan ON landing_unggulan(urutan, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_landing_testimoni ON landing_testimoni(urutan, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_landing_pesan ON landing_pesan(dibaca, id)');

        // ---------- SUSUN LAPORAN (skema 23) ----------
        // Permintaan pemilik: "Pada halaman kepala sekolah/kepala madrasah tambahkan Menu Susun
        // Laporan. Isi dari menu ini adalah membuat dokumen dari kafer, kata pengantar, Bab 1.
        // Pendahuluan (Latar Belakang, Dasar hukum, Tujuan Supervisi, Manfaat Supervisi), Bab 2.
        // Pelaksanaan Supervisi (Daftar tabel guru yang disupervisi, Jadwal Supervisi, Instrumen
        // Supervisi, Teknik Analisis Data, Prosedur Supervisi), Bab 3. Hasil Supervisi (Tampilkan
        // semua hasil supervisi setiap guru), Bab 4. Kesimpulan dan Rekomendasi."
        //
        // Teks yang dapat disunting (latar belakang, dasar hukum, tujuan, manfaat, kata pengantar,
        // teknik analisis, prosedur, kesimpulan, rekomendasi, dan bagian sampul) disimpan PER
        // SEKOLAH sebagai pasangan kunci → isi. Bagian yang belum pernah disunting memakai TEMPLATE
        // BAWAAN dari inc/laporan.php (LAPORAN_TEKS_BAWAAN), jadi dokumen langsung bisa dicetak
        // tanpa mengisi apa pun dan suntingan kepala sekolah dipertahankan untuk cetak berikutnya.
        $pdo->exec('CREATE TABLE IF NOT EXISTS laporan_teks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sekolah_id INTEGER NOT NULL REFERENCES sekolah(id) ON DELETE CASCADE,
            kunci TEXT NOT NULL,
            isi TEXT NOT NULL DEFAULT "",
            diubah TEXT,
            UNIQUE (sekolah_id, kunci)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_laporan_teks ON laporan_teks(sekolah_id, kunci)');

        // Isi awal halaman depan — SEKALI SAJA (penanda `landing_seed`). Sesudah itu seluruh isinya
        // hanya diubah Super Admin; migrasi berikutnya TIDAK pernah menimpanya.
        landing_seed($pdo);
        // Slider beranda (v1.17.0) punya penanda SENDIRI (`landing_slider_seed`) supaya basis data
        // yang sudah pernah menjalankan landing_seed() — termasuk data pemilik — tetap mendapat
        // slidenya, tanpa menimpa suntingan Super Admin sesudahnya.
        landing_slider_seed($pdo);

        // ---------- Migrasi basis data lama ----------
        // STATUS TINJAUAN DOKUMEN (skema 16, permintaan pemilik: "Pada daftar dokumen madrasah yang
        // ada di akun pengawas, pada saat pengawas melihat setiap dokumen tersebut tambahkan fitur:
        // Dokumen disetujui, dokumen direvisi, dokumen ditolak dan tambahkan keterangannya/alasannya").
        // Dokumen LAMA dianggap 'menunggu' (belum ditinjau) — tidak ada nilai yang ditebak.
        db_add_column($pdo, 'dokumen_madrasah', 'status', "TEXT NOT NULL DEFAULT 'menunggu'");
        db_add_column($pdo, 'dokumen_madrasah', 'catatan', 'TEXT');
        db_add_column($pdo, 'dokumen_madrasah', 'ditinjau_oleh', 'TEXT');
        db_add_column($pdo, 'dokumen_madrasah', 'ditinjau_pada', 'TEXT');
        // TAHUN AJARAN dokumen (skema 22, permintaan pemilik). Hanya MENAMBAH kolom; dokumen LAMA
        // bernilai NULL (tidak ada nilai yang ditebak) sehingga tahun ajarannya tetap dihitung
        // dari tanggal unggah seperti sebelumnya.
        db_add_column($pdo, 'dokumen_madrasah', 'tahun_ajaran', 'TEXT');
        $pdo->exec("UPDATE dokumen_madrasah SET status = 'menunggu' WHERE status IS NULL OR TRIM(status) = ''");
        // Nilai tak dikenal → 'menunggu' (jangan pernah menampilkan status yang tidak dimengerti).
        $pdo->exec("UPDATE dokumen_madrasah SET status = 'menunggu' WHERE status NOT IN ('menunggu','disetujui','revisi','ditolak')");
        db_add_column($pdo, 'butir', 'aktif', 'INTEGER NOT NULL DEFAULT 1');
        db_add_column($pdo, 'guru', 'sekolah_id', 'INTEGER');
        db_add_column($pdo, 'users', 'sekolah_id', 'INTEGER');
        // CATATAN: instrumen TIDAK lagi punya kolom sekolah_id (menu & butir global, v10).
        db_add_column($pdo, 'supervisi', 'sekolah_id', 'INTEGER');
        db_add_column($pdo, 'users', 'foto', 'TEXT');
        // Pendaftaran sekolah: sekolah lama otomatis dianggap sudah disetujui.
        db_add_column($pdo, 'sekolah', 'verifikasi', 'TEXT NOT NULL DEFAULT "disetujui"');
        db_add_column($pdo, 'sekolah', 'kontak_nama', 'TEXT');
        db_add_column($pdo, 'sekolah', 'kontak_telepon', 'TEXT');
        db_add_column($pdo, 'sekolah', 'kontak_email', 'TEXT');
        db_add_column($pdo, 'sekolah', 'tanggal_daftar', 'TEXT');
        db_add_column($pdo, 'sekolah', 'alasan_tolak', 'TEXT');
        // Jenis sekolah: "umum" (SMP/SMA/SMK) atau "madrasah" (MI/MTs/MA).
        // Bila madrasah, seluruh istilah "Sekolah" pada aplikasi & cetakan menjadi "Madrasah".
        db_add_column($pdo, 'sekolah', 'jenis', 'TEXT NOT NULL DEFAULT "umum"');
        // Logo sekolah/madrasah untuk kop dokumen cetak (disimpan sebagai URL di media proxy).
        db_add_column($pdo, 'sekolah', 'logo', 'TEXT');
        // AKTIVASI (v13): keadaan `aktif` (semua fitur) atau `dibatasi` (1 guru, 1 supervisi per
        // menu, 1 jadwal). Kolomnya diberi bawaan "aktif" sehingga SELURUH SEKOLAH LAMA ikut
        // aktif — data yang sudah berjalan tidak boleh mendadak terbatasi.
        db_add_column($pdo, 'sekolah', 'aktivasi', "TEXT NOT NULL DEFAULT 'aktif'");
        db_add_column($pdo, 'sekolah', 'aktivasi_pada', 'TEXT');
        // MASA AKTIF 1 TAHUN (v14, permintaan pemilik): "Pastikan aplikasi aktif dengan terbuka
        // semua fitur dengan masa aktif 1 tahun (365 hari) ... jika masa aktif habis bisa
        // menghubungi kembali HD Admin untuk meminta kode aktifasi yang baru."
        //   aktif_sampai  = batas akhir masa aktif (NULL = belum pernah diaktivasi)
        //   aktivasi_ke   = nomor periode aktivasi; ikut dihitung pada kode aktivasi sehingga
        //                   kode BERUBAH setiap periode (kode lama tidak bisa dipakai lagi).
        db_add_column($pdo, 'sekolah', 'aktif_sampai', 'TEXT');
        db_add_column($pdo, 'sekolah', 'aktivasi_ke', 'INTEGER NOT NULL DEFAULT 1');
        // MASA UJI COBA 30 HARI bagi sekolah yang BELUM diaktivasi (skema 24, permintaan pemilik):
        // "sekolah yang belum melakukan aktifasi, selain fiturnya dibatasi waktu penggunaan juga
        // hanya 30 hari" — dihitung dari saat sekolah mulai terdaftar.
        // Hanya MENAMBAH kolom (tidak ada tabel yang dibangun ulang), lalu diisi untuk sekolah
        // yang sudah berkeadaan `dibatasi` memakai tanggal pendaftarannya (lihat blok di bawah).
        db_add_column($pdo, 'sekolah', 'uji_mulai', 'TEXT');
        // AKUN PENGAWAS BINA dari halaman pendaftaran (skema 18, permintaan pemilik): nama & NIP
        // pengawas yang diisi sekolah saat mendaftar disimpan sebagai akun pengawas (aktif = 0,
        // menunggu ditinjau Super Admin). `nip` menyimpan NIP-nya, `asal` menandai akun yang
        // dibuat oleh pendaftar ('pendaftaran'). Hanya MENAMBAH kolom — tidak ada tabel yang
        // dibangun ulang.
        db_add_column($pdo, 'users', 'nip', 'TEXT');
        db_add_column($pdo, 'users', 'asal', 'TEXT');
        // IDENTITAS LEMBAGA lengkap (skema 17, permintaan pemilik): inputan Identitas
        // Madrasah/Sekolah memuat Nama, NPSN, NSM, Alamat (Jalan, Dusun), Desa/Kelurahan,
        // Kecamatan, Kabupaten, Provinsi, Telepon, Email, Situs/Web, Semester, Nama Kepala
        // Madrasah/Supervisor, dan NIP. Hanya MENAMBAH kolom — tidak ada tabel yang dibangun ulang,
        // sehingga sekolah lama tetap utuh (kolom barunya kosong sampai diisi).
        db_add_column($pdo, 'sekolah', 'nsm', 'TEXT');
        db_add_column($pdo, 'sekolah', 'desa', 'TEXT');
        db_add_column($pdo, 'sekolah', 'kecamatan', 'TEXT');
        db_add_column($pdo, 'sekolah', 'kabupaten', 'TEXT');
        db_add_column($pdo, 'sekolah', 'provinsi', 'TEXT');
        db_add_column($pdo, 'sekolah', 'telepon', 'TEXT');
        db_add_column($pdo, 'sekolah', 'email', 'TEXT');
        db_add_column($pdo, 'sekolah', 'website', 'TEXT');
        $pdo->exec('UPDATE sekolah SET aktivasi_ke = 1 WHERE aktivasi_ke IS NULL OR aktivasi_ke < 1');
        $pdo->exec("UPDATE sekolah SET aktivasi = 'aktif' WHERE aktivasi IS NULL OR TRIM(aktivasi) = ''");
        // Nilai yang tidak dikenal diperlakukan AKTIF (tidak pernah membatasi sekolah secara tak
        // sengaja); keadaan `dibatasi` hanya diberikan secara eksplisit oleh daftar.php.
        $pdo->exec("UPDATE sekolah SET aktivasi = 'aktif' WHERE aktivasi NOT IN ('aktif', 'dibatasi')");
        // Sekolah yang SUDAH aktif sebelum fitur masa aktif ada TIDAK boleh mendadak terkunci:
        // semuanya diberi masa aktif penuh AKTIVASI_HARI_AKTIF hari sejak migrasi ini berjalan.
        // Sekolah yang pernah diaktifkan (aktivasi_pada terisi) langsung memakai periode ke-2 supaya
        // kode yang sudah pernah dipakai/dikirim TIDAK berlaku lagi pada perpanjangan berikutnya.
        $batasAwal = date('Y-m-d H:i:s', time() + AKTIVASI_HARI_AKTIF * 86400);
        $st = $pdo->prepare("UPDATE sekolah SET aktif_sampai = ? WHERE aktivasi = 'aktif' AND (aktif_sampai IS NULL OR TRIM(aktif_sampai) = '')");
        $st->execute([$batasAwal]);
        $pdo->exec('UPDATE sekolah SET aktivasi_ke = 2 WHERE aktivasi = \'aktif\' AND aktivasi_pada IS NOT NULL AND TRIM(aktivasi_pada) <> "" AND aktivasi_ke < 2');
        // MASA UJI COBA bagi sekolah yang BELUM diaktivasi (skema 24, permintaan pemilik): "Untuk
        // sekolah yang sudah ada di dalam waktunya disesuaikan dengan waktu mereka mendaftar."
        // Sekolah yang SUDAH ada dan belum diaktivasi (`dibatasi`) diberi titik mulai dari tanggal
        // pendaftarannya sendiri (cadangan: tanggal barisnya dibuat) supaya hitungannya tidak
        // direset ke hari ini. Dijalankan HANYA bila kolomnya masih kosong → idempoten, dan
        // sekolah yang sudah diaktivasi (keadaan `aktif`) tidak boleh tersentuh sama sekali.
        $stUji = $pdo->prepare('UPDATE sekolah SET uji_mulai = COALESCE(
                        NULLIF(TRIM(COALESCE(tanggal_daftar, "")), ""),
                        NULLIF(TRIM(COALESCE(dibuat, "")), ""),
                        ?
                    )
                    WHERE aktivasi = \'dibatasi\' AND (uji_mulai IS NULL OR TRIM(uji_mulai) = "")');
        $stUji->execute([date('Y-m-d H:i:s')]);

        // ---------------------------------------------------------------- v25 (masa uji 15 hari)
        // PERMINTAAN PEMILIK: "Masa aktif untuk pendaftar baru buat 15 hari. dan untuk Sekolah yang
        // sudah terlanjur mendaftar di kurangi waktunya dan disesuaikan kembali" — atas pilihannya:
        // "Beri sisa 3 hari lagi agar sempat aktivasi, lalu terkunci".
        //
        // Pendaftar BARU otomatis 15 hari (AKTIVASI_HARI_UJI); blok ini hanya menyesuaikan sekolah
        // yang SUDAH TERLANJUR MENDAFTAR: sekolah berkeadaan `dibatasi` yang batas 15 harinya sudah
        // LEWAT (atau tersisanya kurang dari AKTIVASI_UJI_SISA_MINIMAL) digeser titik mulainya
        // sehingga batasnya = sekarang + AKTIVASI_UJI_SISA_MINIMAL hari. Sekolah yang sisa waktunya
        // masih >= 3 hari TIDAK digeser (tanggal pendaftarannya tetap dipakai apa adanya), dan
        // sekolah yang sudah `aktif` tidak pernah tersentuh.
        //
        // Penyesuaian ini memakai TITIK ACUAN yang disimpan pada penanda `uji15_sisa` (bukan
        // waktu berjalannya migrasi), sehingga:
        //   - hasilnya SELALU SAMA setiap kali dijalankan (idempoten, tidak memperpanjang 3 hari
        //     berulang-ulang walau ensure_schema() dipanggil lagi karena pemulihan diri), dan
        //   - bila kolom `uji_mulai` sempat hilang lalu diisi ulang oleh blok v24 di atas, titik
        //     yang sama dipulihkan (sekolah "telat" tidak mendadak terkunci).
        $sisaMin = max(1, (int) AKTIVASI_UJI_SISA_MINIMAL);
        $hariUji = max(1, (int) AKTIVASI_HARI_UJI);
        $stPenanda = $pdo->prepare('SELECT nilai FROM app_pengaturan WHERE kunci = ?');
        $stPenanda->execute(['uji15_sisa']);
        $isiPenanda = (string) ($stPenanda->fetchColumn() ?: '');
        $acuTs = $isiPenanda === '' ? time() : (int) strtotime(explode('|', $isiPenanda)[0]);
        if ($acuTs <= 0) {
            $acuTs = time();
        }
        $batasMin = $acuTs + $sisaMin * 86400;
        $baris = $pdo->query("SELECT id, uji_mulai, tanggal_daftar, dibuat FROM sekolah
                              WHERE aktivasi = 'dibatasi'")->fetchAll(PDO::FETCH_ASSOC);
        $stGeser = $pdo->prepare('UPDATE sekolah SET uji_mulai = ? WHERE id = ? AND aktivasi = \'dibatasi\'');
        $digeser = 0;
        foreach ($baris as $b) {
            $mulai = '';
            foreach (['uji_mulai', 'tanggal_daftar', 'dibuat'] as $kunci) {
                $nilai = trim((string) ($b[$kunci] ?? ''));
                if ($nilai !== '') { $mulai = $nilai; break; }
            }
            $tsMulai = $mulai === '' ? false : strtotime($mulai);
            // Tanggal pendaftaran tidak diketahui → TIDAK disentuh sama sekali. Sekolah seperti itu
            // memang sengaja tidak pernah dikunci (lebih aman daripada mengunci tanpa dasar).
            if ($tsMulai === false) {
                continue;
            }
            if ($tsMulai + $hariUji * 86400 >= $batasMin) {
                continue; // masih punya waktu >= 3 hari → biarkan sesuai tanggal mendaftar
            }
            // Geser titik mulai sehingga batasnya tepat $sisaMin hari sejak titik acuan.
            $mulaiBaru = date('Y-m-d H:i:s', $batasMin - $hariUji * 86400);
            if ($mulai === $mulaiBaru) {
                continue;
            }
            $stGeser->execute([$mulaiBaru, (int) $b['id']]);
            $digeser++;
        }
        if ($isiPenanda === '') {
            $pdo->prepare('INSERT INTO app_pengaturan (kunci, nilai, diubah) VALUES (?,?,?)
                           ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai, diubah = excluded.diubah')
                ->execute(['uji15_sisa', date('Y-m-d H:i:s', $acuTs) . '|' . $digeser]);
        }
        $pdo->exec('UPDATE sekolah SET jenis = "umum" WHERE jenis IS NULL OR jenis = ""');
        $pdo->exec('UPDATE sekolah SET verifikasi = "disetujui" WHERE verifikasi IS NULL OR verifikasi = ""');

        // Sekolah pertama: dibuat dari `pengaturan` lama bila ada, atau nilai bawaan.
        $jmlSekolah = (int) $pdo->query('SELECT COUNT(*) FROM sekolah')->fetchColumn();
        if ($jmlSekolah === 0) {
            $lama = [];
            foreach ($pdo->query('SELECT kunci, nilai FROM pengaturan') as $r) {
                $lama[(string) $r['kunci']] = (string) $r['nilai'];
            }
            $ambil = static function (array $a, string $k, string $d) {
                return (isset($a[$k]) && $a[$k] !== '') ? $a[$k] : $d;
            };
            // Sekolah pertama langsung diberi MASA AKTIF penuh (365 hari) supaya hitung mundur
            // & masa aktif juga berlaku pada instalasi BARU (bukan hanya hasil migrasi).
            $st = $pdo->prepare("INSERT INTO sekolah (nama, npsn, alamat, kepala_sekolah, nip_kepala_sekolah, tahun_ajaran, semester, batas_a, batas_b, batas_c, catatan, aktif, verifikasi, aktivasi, aktif_sampai, aktivasi_ke, dibuat)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'disetujui', 'aktif', ?, 1, ?)");
            $st->execute([
                $ambil($lama, 'nama_sekolah', 'Sekolah Pertama'),
                $ambil($lama, 'npsn', '-'),
                $ambil($lama, 'alamat_sekolah', '-'),
                $ambil($lama, 'kepala_sekolah', '-'),
                $ambil($lama, 'nip_kepala_sekolah', '-'),
                $ambil($lama, 'tahun_ajaran', date('Y') . '/' . (date('Y') + 1)),
                $ambil($lama, 'semester', 'Ganjil'),
                (float) $ambil($lama, 'batas_a', '90'),
                (float) $ambil($lama, 'batas_b', '80'),
                (float) $ambil($lama, 'batas_c', '70'),
                $lama ? 'Data hasil migrasi dari versi satu-sekolah.' : null,
                date('Y-m-d H:i:s', time() + AKTIVASI_HARI_AKTIF * 86400),
                date('Y-m-d H:i:s'),
            ]);
        }

        $sid0 = (int) $pdo->query('SELECT MIN(id) FROM sekolah')->fetchColumn();

        // Isi sekolah_id yang masih kosong (seluruh data lama milik sekolah pertama).
        $pdo->exec('UPDATE guru SET sekolah_id = ' . $sid0 . ' WHERE sekolah_id IS NULL');
        $pdo->exec('UPDATE users SET sekolah_id = ' . $sid0 . ' WHERE sekolah_id IS NULL');
        $pdo->exec('UPDATE supervisi SET sekolah_id = ' . $sid0 . ' WHERE sekolah_id IS NULL');
        $pdo->exec('UPDATE supervisi SET sekolah_id = (SELECT g.sekolah_id FROM guru g WHERE g.id = supervisi.guru_id) WHERE sekolah_id IS NULL');


        // ---------- Index ----------
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_guru_sekolah ON guru(sekolah_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_sekolah ON users(sekolah_id)');
        $pdo->exec('DROP INDEX IF EXISTS idx_instrumen_sekolah');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supervisi_guru ON supervisi(guru_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supervisi_sekolah ON supervisi(sekolah_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supervisi_instrumen ON supervisi(instrumen_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_detail_supervisi ON detail(supervisi_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supervisi_foto ON supervisi_foto(supervisi_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_komentar_sekolah ON komentar_pengawas(sekolah_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dokumen_jenis_urut ON dokumen_jenis(urutan, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dokumen_sekolah ON dokumen_madrasah(sekolah_id, dibuat)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dokumen_status ON dokumen_madrasah(sekolah_id, status)');
        // Obrolan: pencarian pesan per ruang (sekolah) & pembersihan penanda baca per pengguna.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_ruang ON chat_pesan(sekolah_id, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_baca_user ON chat_baca(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_butir_instrumen ON butir(instrumen_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jadwal_sekolah_tanggal ON jadwal(sekolah_id, tanggal)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pengawas_user ON pengawas_sekolah(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pengawas_sekolah ON pengawas_sekolah(sekolah_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_waktu ON log_aktivitas(waktu)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_aktivitas ON log_aktivitas(aktivitas)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sesi_aktif_terakhir ON sesi_aktif(terakhir)');

        // ---------- v9 -> v10: menu & butir menjadi GLOBAL (satu daftar untuk semua sekolah) ----------
        // Data butir lama tidak dimusnahkan: tabel lama dipindahkan ke `*_arsip`, dan nama/skor
        // maksimal butir lama disalin ke kolom arsip pada `detail` supaya riwayat penilaian tetap terbaca.
        // Penanda basis data "versi per-sekolah": tabel instrumen masih punya kolom sekolah_id.
        //
        // PENTING: data lama TIDAK diarsipkan dengan ALTER TABLE ... RENAME TO, karena SQLite ikut
        // menulis ulang definisi FOREIGN KEY tabel lain yang menunjuk tabel itu (detail → butir_arsip,
        // supervisi → instrumen_arsip) sehingga muncul ratusan pelanggaran foreign key.
        // Cara yang benar: salin dulu isinya ke tabel arsip (CREATE TABLE ... AS SELECT),
        // baru DROP tabel lama dan buat tabel global dengan NAMA YANG SAMA.
        $sqlIns = db_table_sql($pdo, 'instrumen');
        if (stripos($sqlIns, 'sekolah_id') !== false) {
            // (a) detail: pindahkan butir_id yang TIDAK boleh NULL -> bangun ulang + simpan arsip
            db_add_column($pdo, 'detail', 'komponen_arsip', 'TEXT');
            db_add_column($pdo, 'detail', 'maks_arsip', 'INTEGER');
            db_add_column($pdo, 'detail', 'urutan_arsip', 'INTEGER');
            $pdo->exec('UPDATE detail SET
                    komponen_arsip = COALESCE(komponen_arsip, (SELECT b.komponen FROM butir b WHERE b.id = detail.butir_id)),
                    maks_arsip     = COALESCE(maks_arsip,     (SELECT b.skor_maks FROM butir b WHERE b.id = detail.butir_id)),
                    urutan_arsip   = COALESCE(urutan_arsip,   (SELECT b.urutan   FROM butir b WHERE b.id = detail.butir_id))');
            $pdo->exec('CREATE TABLE detail_baru (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supervisi_id INTEGER NOT NULL REFERENCES supervisi(id) ON DELETE CASCADE,
                butir_id INTEGER REFERENCES butir(id) ON DELETE CASCADE,
                skor INTEGER NOT NULL DEFAULT 0,
                catatan TEXT,
                komponen_arsip TEXT,
                maks_arsip INTEGER,
                urutan_arsip INTEGER,
                UNIQUE (supervisi_id, butir_id)
            )');
            $pdo->exec('INSERT INTO detail_baru (id, supervisi_id, butir_id, skor, catatan, komponen_arsip, maks_arsip, urutan_arsip)
                        SELECT id, supervisi_id, NULL, skor, catatan, komponen_arsip, maks_arsip, urutan_arsip FROM detail');
            $pdo->exec('DROP TABLE detail');
            $pdo->exec('ALTER TABLE detail_baru RENAME TO detail');

            // (b) salin menu & butir lama ke tabel arsip (isinya utuh, tanpa constraint)
            if (db_table_sql($pdo, 'instrumen_arsip') === '') {
                $pdo->exec('CREATE TABLE instrumen_arsip AS SELECT * FROM instrumen');
            }
            if (db_table_sql($pdo, 'butir_arsip') === '') {
                $pdo->exec('CREATE TABLE butir_arsip AS SELECT * FROM butir');
            }

            // (c) ganti tabel lama dengan tabel global (nama tabel tetap sama, jadi FOREIGN KEY
            //     pada supervisi/detail tetap menunjuk tabel yang benar)
            $pdo->exec('DROP TABLE instrumen');
            $pdo->exec('DROP TABLE butir');
            $pdo->exec('CREATE TABLE instrumen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kode TEXT NOT NULL UNIQUE,
                nama TEXT NOT NULL,
                deskripsi TEXT,
                ikon TEXT,
                urutan INTEGER NOT NULL DEFAULT 0,
                aktif INTEGER NOT NULL DEFAULT 1
            )');
            $pdo->exec('CREATE TABLE butir (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrumen_id INTEGER NOT NULL REFERENCES instrumen(id) ON DELETE CASCADE,
                urutan INTEGER NOT NULL DEFAULT 0,
                komponen TEXT NOT NULL,
                indikator TEXT,
                skor_maks INTEGER NOT NULL DEFAULT 4,
                aktif INTEGER NOT NULL DEFAULT 1
            )');

            // (d) isi dari template
            $insI = $pdo->prepare('INSERT INTO instrumen (kode, nama, deskripsi, ikon, urutan, aktif) VALUES (?,?,?,?,?,1)');
            $bI = $pdo->prepare('INSERT INTO butir (instrumen_id, urutan, komponen, indikator, skor_maks, aktif) VALUES (?,?,?,?,4,1)');
            $templateButir = template_butir();
            foreach (template_instrumen() as $i) {
                $insI->execute([$i[0], $i[1], $i[2], $i[3], $i[4]]);
                $iidBaru = (int) $pdo->lastInsertId();
                $u = 0;
                foreach (($templateButir[$i[0]] ?? []) as $b) {
                    $u++;
                    $bI->execute([$iidBaru, $u, $b[0], $b[1]]);
                }
            }

            // (e) menu khusus sekolah yang belum ada di master tetap dibuat (agar data lama tidak rusak)
            foreach ($pdo->query('SELECT DISTINCT kode FROM instrumen_arsip') as $r) {
                $kode = (string) $r['kode'];
                if ((int) db_val($pdo, 'SELECT COUNT(*) FROM instrumen WHERE kode = ?', [$kode]) === 0) {
                    $asal = db_row($pdo, 'SELECT * FROM instrumen_arsip WHERE kode = ? LIMIT 1', [$kode]);
                    if ($asal) {
                        $insI->execute([$kode, $asal['nama'], $asal['deskripsi'], $asal['ikon'], $asal['urutan']]);
                    }
                }
            }

            // (f) supervisi lama dipindahkan ke menu global dengan kode yang sama
            $pdo->exec('UPDATE supervisi SET instrumen_id = (
                    SELECT n.id FROM instrumen n
                    JOIN instrumen_arsip o ON o.kode = n.kode
                    WHERE o.id = supervisi.instrumen_id
                ) WHERE instrumen_id IN (SELECT id FROM instrumen_arsip)');
        }

        // ---------- Jenis dokumen bawaan (sekali saja, tidak menimpa perubahan Super Admin) ----------
        // Nama-nama ini persis seperti permintaan pemilik; setelah ada, daftar hanya diubah lewat
        // halaman Kelola Jenis Dokumen (INSERT OR IGNORE pada nama yang sama).
        if ((int) $pdo->query('SELECT COUNT(*) FROM dokumen_jenis')->fetchColumn() === 0) {
            $stJ = $pdo->prepare('INSERT INTO dokumen_jenis (nama, urutan, aktif, dibuat) VALUES (?,?,1,?)');
            $u = 0;
            // Memakai KONSTANTA (bukan fungsi modul) supaya inc/db.php tetap dapat dijalankan
            // sendiri tanpa memuat inc/komentar_dokumen.php.
            foreach (DOKUMEN_JENIS_BAWAAN as $nm) {
                $u += 10;
                $stJ->execute([$nm, $u, date('Y-m-d H:i:s')]);
            }
        }

        // Menu & butir sekarang GLOBAL: tidak ada lagi salinan per sekolah (provision_sekolah() no-op).
        $adaMenu = (int) $pdo->query('SELECT COUNT(*) FROM instrumen')->fetchColumn();
        if ($adaMenu === 0) {
            $insI = $pdo->prepare('INSERT INTO instrumen (kode, nama, deskripsi, ikon, urutan, aktif) VALUES (?,?,?,?,?,1)');
            $bI = $pdo->prepare('INSERT INTO butir (instrumen_id, urutan, komponen, indikator, skor_maks, aktif) VALUES (?,?,?,?,4,1)');
            $templateButir = template_butir();
            foreach (template_instrumen() as $i) {
                $insI->execute([$i[0], $i[1], $i[2], $i[3], $i[4]]);
                $iidBaru = (int) $pdo->lastInsertId();
                $u = 0;
                foreach (($templateButir[$i[0]] ?? []) as $b) {
                    $u++;
                    $bI->execute([$iidBaru, $u, $b[0], $b[1]]);
                }
            }
        }

        // ---------- Data contoh (hanya untuk basis data yang benar-benar baru) ----------
        if ((int) $pdo->query('SELECT COUNT(*) FROM guru')->fetchColumn() === 0) {
            seed_demo($pdo, $sid0);
        }

        // Pengawas juga lintas sekolah (sekolahnya ditentukan lewat tabel pengawas_sekolah).
        $pdo->exec('UPDATE users SET sekolah_id = NULL WHERE role = "pengawas"');

        // Super admin bersifat lintas sekolah: jangan pernah terikat satu sekolah
        // (kalau terikat, menghapus sekolah itu akan ikut menghapus akunnya).
        $pdo->exec('UPDATE users SET sekolah_id = NULL WHERE role = "super"');

        // ---------- Akun super admin ----------
        $adaSuper = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "super"')->fetchColumn();
        if ($adaSuper === 0) {
            $kandidat = $pdo->query('SELECT id FROM users WHERE username = "supianto"')->fetchColumn();
            if (!$kandidat) {
                $kandidat = $pdo->query('SELECT id FROM users WHERE username = "admin" ORDER BY id LIMIT 1')->fetchColumn();
            }
            if ($kandidat) {
                $st = $pdo->prepare('UPDATE users SET role = "super", sekolah_id = NULL WHERE id = ?');
                $st->execute([(int) $kandidat]);
            } else {
                $pdo->prepare('INSERT INTO users (username, password_hash, nama, role, sekolah_id, aktif, dibuat) VALUES (?, ?, ?, "super", NULL, 1, ?)')
                    ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Super Admin', date('Y-m-d H:i:s')]);
            }
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        if ($fkOn) {
            @$pdo->exec('PRAGMA foreign_keys = ON');
        }
        throw $e;
    }

    if ($fkOn) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    @file_put_contents($flag, SCHEMA_VERSION);
}

/** Data contoh: 6 guru + supervisi lengkap, supaya dashboard langsung berisi grafik. */
function seed_demo(PDO $pdo, int $sekolahId): void
{
    $now = date('Y-m-d H:i:s');
    $guru = [
        ['197804122006041002', 'Dra. Siti Aminah', 'Bahasa Indonesia', 'Guru Madya'],
        ['198203152008011003', 'Budi Santoso, S.Pd.', 'Matematika', 'Guru Muda'],
        ['198711202010012004', 'Rina Kartika, S.Pd.', 'IPA', 'Guru Muda'],
        ['199001012014031005', 'Ahmad Fauzi, S.Pd.', 'IPS', 'Guru Pertama'],
        ['199205172015042006', 'Dewi Lestari, S.Pd.', 'Bahasa Inggris', 'Guru Pertama'],
        ['198509232011011007', 'Hendra Wijaya, S.Pd.', 'PJOK', 'Guru Muda'],
    ];
    $gI = $pdo->prepare('INSERT INTO guru (sekolah_id, nip, nama, mapel, jabatan, aktif, demo, dibuat) VALUES (?, ?, ?, ?, ?, 1, 1, ?)');
    $ids = [];
    foreach ($guru as $g) {
        $gI->execute([$sekolahId, $g[0], $g[1], $g[2], $g[3], $now]);
        $ids[] = (int) $pdo->lastInsertId();
    }

    // Menu & butir kini GLOBAL (dipakai bersama semua sekolah).
    $kode2id = [];
    foreach ($pdo->query('SELECT id, kode FROM instrumen') as $r) {
        $kode2id[$r['kode']] = (int) $r['id'];
    }
    $butirMap = [];
    foreach ($pdo->query('SELECT id, instrumen_id, skor_maks FROM butir ORDER BY instrumen_id, urutan') as $r) {
        $butirMap[(int) $r['instrumen_id']][] = $r;
    }

    $sI = $pdo->prepare('INSERT INTO supervisi (sekolah_id, guru_id, instrumen_id, tanggal, periode, supervisor, total_skor, skor_maks, nilai, predikat, kelebihan, kekurangan, tindak_lanjut, status, dibuat, diubah)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "final", ?, ?)');
    $dI = $pdo->prepare('INSERT INTO detail (supervisi_id, butir_id, skor, catatan) VALUES (?, ?, ?, ?)');
    $th = ['batas_a' => 90.0, 'batas_b' => 80.0, 'batas_c' => 70.0];

    $profil = [
        [0.95, 0.90, 0.88, 0.92],
        [0.80, 0.78, 0.82, 0.75],
        [0.88, 0.85, 0.90, 0.86],
        [0.68, 0.70, 0.65, 0.72],
        [0.78, 0.82, 0.80, 0.79],
        [0.85, 0.80, 0.83, 0.81],
    ];
    $kodeUrut = ['administrasi', 'modul_ajar', 'pengamatan', 'penilaian'];
    $bulanSet = [3, 2, 1, 0];
    foreach ($ids as $gi => $gid) {
        foreach ($kodeUrut as $ki => $kode) {
            if (!isset($kode2id[$kode])) {
                continue;
            }
            $bulanLalu = $bulanSet[($gi + $ki) % 4];
            $ts = strtotime('-' . $bulanLalu . ' month');
            $tanggal = date('Y-m-', $ts) . str_pad((string) (5 + (($gi * 3 + $ki) % 20)), 2, '0', STR_PAD_LEFT);
            $iid = $kode2id[$kode];
            $target = $profil[$gi][$ki];
            $total = 0.0;
            $maks = 0.0;
            $det = [];
            foreach (($butirMap[$iid] ?? []) as $bi => $b) {
                $maks += (float) $b['skor_maks'];
                $f = max(0.4, min(1.0, $target + (($bi % 3) - 1) * 0.06));
                $skor = (int) max(1, min((int) $b['skor_maks'], round($f * (float) $b['skor_maks'])));
                $total += $skor;
                $det[] = $skor;
            }
            $nilai = $maks > 0 ? round($total / $maks * 100, 2) : 0.0;
            $pred = nilai_predikat_sederhana($nilai, $th);
            $sI->execute([
                $sekolahId, $gid, $iid, $tanggal,
                date('Y', $ts) . ' ' . (date('n', $ts) <= 6 ? 'Ganjil' : 'Genap'),
                'Kepala Sekolah', $total, $maks, $nilai, $pred,
                'Perangkat tersusun dan dokumen pendukung tersedia.',
                'Beberapa dokumen belum diperbarui pada periode terakhir.',
                'Melengkapi dan memperbarui dokumen pada rapat MGMP berikutnya.',
                $now, $now,
            ]);
            $sid = (int) $pdo->lastInsertId();
            $no = 0;
            foreach (($butirMap[$iid] ?? []) as $b) {
                $skor = $det[$no];
                $catatan = $skor >= (int) $b['skor_maks'] ? 'Sudah baik dan konsisten.' : ($skor <= 2 ? 'Perlu pembenahan dan pendampingan.' : 'Perlu ditingkatkan.');
                $dI->execute([$sid, (int) $b['id'], $skor, $catatan]);
                $no++;
            }
        }
    }
}

/** Perhitungan predikat versi mandiri (tidak memakai helper agar tidak ada ketergantungan melingkar). */
function nilai_predikat_sederhana(float $nilai, array $th): string
{
    if ($nilai >= (float) $th['batas_a']) {
        return 'A';
    }
    if ($nilai >= (float) $th['batas_b']) {
        return 'B';
    }
    if ($nilai >= (float) $th['batas_c']) {
        return 'C';
    }
    return 'D';
}
