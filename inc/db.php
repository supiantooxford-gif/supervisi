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

/** Naikkan angka ini setiap kali skema berubah agar migrasi ikut dijalankan. */
const SCHEMA_VERSION = '11';

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
    $flag = dirname(DB_FILE) . '/.schema_ok';
    if (is_file($flag) && filesize(DB_FILE) > 0 && trim((string) @file_get_contents($flag)) === SCHEMA_VERSION) {
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
            alamat TEXT,
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
            dibuat TEXT
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

        // ---------- Migrasi basis data lama ----------
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
            $st = $pdo->prepare('INSERT INTO sekolah (nama, npsn, alamat, kepala_sekolah, nip_kepala_sekolah, tahun_ajaran, semester, batas_a, batas_b, batas_c, catatan, aktif, verifikasi, dibuat)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, "disetujui", ?)');
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
