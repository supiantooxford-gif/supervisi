<?php
/**
 * OBROLAN (CHAT) — SATU RUANG PER SEKOLAH/MADRASAH BINAAN.
 *
 * Permintaan pemilik: *"Tambahkan fitur obrolan seperti aplikasi e-Guru hanya saja yang bisa
 * mengobrol hanya pengawas bina dan binaannya yang sementara online. Tidak bisa mengobrol dengan
 * yang bukan binaannya."*
 *
 * Pilihan pemilik (jangan diubah tanpa bertanya):
 *   1. **SATU ruang per sekolah/madrasah binaan** — semua akun sekolah itu (kepala & guru) bersama
 *      pengawas pembinanya dalam satu percakapan;
 *   2. **Super Admin ikut** (boleh melihat & mengobrol di semua ruang);
 *   3. pesan baru muncul otomatis tiap beberapa detik, sebagai **kotak mengambang** di pojok kanan
 *      bawah pada semua halaman.
 *
 * Jadi **RUANG = satu `sekolah_id`**:
 *   • akun sekolah (role `admin`/`guru`) → ruang sekolahnya sendiri;
 *   • pengawas                          → ruang setiap sekolah BINAANNYA (`pengawas_sekolah`);
 *   • Super Admin                       → seluruh ruang sekolah aktif.
 * Inilah yang membuat "tidak bisa mengobrol dengan yang bukan binaannya" benar-benar berlaku:
 * ruangnya tidak pernah ada di daftar pengguna yang bukan anggotanya. Penjagaannya di SERVER
 * (`chat_ruang_boleh()` + `chat_ruang_kini()`), bukan hanya disembunyikan di tampilan.
 *
 * Modul ini di-require dari `inc/init.php` karena dipakai layout (kotak mengambang) DAN endpoint
 * `chat.php`.
 */
declare(strict_types=1);

/** Batas panjang satu pesan (karakter) — dijaga di SERVER, bukan hanya di peramban. */
const CHAT_MAKS = 500;
/** Jumlah pesan yang dikirim pada pemuatan pertama (per ruang). */
const CHAT_MUAT = 50;
/** Berapa pesan terbaru yang DISIMPAN per ruang (yang lebih lama dipangkas otomatis). */
const CHAT_SIMPAN_MAKS = 800;
/** Jeda antar-permintaan (detik) pada kotak mengambang — "otomatis tiap beberapa detik". */
const CHAT_JEDA_DETIK = 5;
/** Batas jumlah pesan yang boleh dikirim satu sesi dalam satu menit (pengaman anti-spam sederhana). */
const CHAT_MAKS_MENIT = 20;

// ============================================================================================
// RUANG (sekolah/madrasah binaan)
// ============================================================================================

/** Benar bila sekolah boleh dipakai sebagai ruang obrolan (aktif & sudah disetujui). */
function chat_sekolah_sah(int $sekolahId): bool
{
    if ($sekolahId <= 0) {
        return false;
    }
    return (int) val('SELECT COUNT(*) FROM sekolah WHERE id = ? AND aktif = 1 AND verifikasi = "disetujui"',
        [$sekolahId], 0) > 0;
}

/**
 * Daftar SEMUA ruang yang boleh dibuka seorang pengguna: [['id'=>, 'nama'=>, 'binaan'=>bool], …].
 * Urut nama sekolah; `binaan` = true bila ruang itu sekolah BINAAN (untuk pengawas).
 */
function chat_ruang_saya(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u) {
        return [];
    }
    $role = (string) $u['role'];
    $hasil = [];

    if ($role === 'pengawas') {
        foreach (sekolah_binaan((int) $u['id'], true) as $s) {
            if (chat_sekolah_sah((int) $s['id'])) {
                $hasil[] = ['id' => (int) $s['id'], 'nama' => (string) $s['nama'], 'binaan' => true];
            }
        }
        return $hasil;
    }

    if ($role === 'super') {
        foreach (all('SELECT id, nama FROM sekolah WHERE aktif = 1 AND verifikasi = "disetujui"
                      ORDER BY nama COLLATE NOCASE') as $s) {
            $hasil[] = ['id' => (int) $s['id'], 'nama' => (string) $s['nama'], 'binaan' => false];
        }
        return $hasil;
    }

    $sid = (int) ($u['sekolah_id'] ?? 0);
    if ($sid > 0 && chat_sekolah_sah($sid)) {
        $nama = (string) val('SELECT nama FROM sekolah WHERE id = ?', [$sid], '');
        $hasil[] = ['id' => $sid, 'nama' => $nama !== '' ? $nama : 'Sekolah', 'binaan' => false];
    }
    return $hasil;
}

/** Benar bila pengguna itu boleh membuka ruang (sekolah) tertentu. */
function chat_ruang_boleh(?array $u, int $sekolahId): bool
{
    $u = $u ?? current_user();
    if (!$u || $sekolahId <= 0) {
        return false;
    }
    return in_array($sekolahId, array_map(static fn(array $r): int => (int) $r['id'], chat_ruang_saya($u)), true);
}

/**
 * Ruang yang sedang dibuka pengguna.
 * Nilai terakhir disimpan di SESI supaya tetap sama saat berpindah halaman; nilainya SELALU
 * divalidasi ulang — ruang yang tidak boleh dibuka tidak akan pernah dipakai.
 */
function chat_ruang_kini(?array $u = null): int
{
    $u = $u ?? current_user();
    $ruang = chat_ruang_saya($u);
    if (!$ruang) {
        unset($_SESSION['chat_ruang']);
        return 0;
    }
    $kini = (int) ($_SESSION['chat_ruang'] ?? 0);
    if (!chat_ruang_boleh($u, $kini)) {
        $kini = (int) $ruang[0]['id'];
        $_SESSION['chat_ruang'] = $kini;
    }
    return $kini;
}

/**
 * Pindah ruang obrolan. Mengembalikan false bila ruangnya tidak boleh dibuka pengguna ini
 * (penjagaan utama "tidak bisa mengobrol dengan yang bukan binaannya").
 */
function chat_ruang_pilih(int $sekolahId, ?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!chat_ruang_boleh($u, $sekolahId)) {
        return false;
    }
    $_SESSION['chat_ruang'] = $sekolahId;
    return true;
}

/** Nama sekolah sebuah ruang ('' bila tidak ditemukan). */
function chat_ruang_nama(int $sekolahId): string
{
    return (string) val('SELECT nama FROM sekolah WHERE id = ?', [$sekolahId], '');
}

// ============================================================================================
// PESAN
// ============================================================================================

/** Isi pesan yang sudah dirapikan (spasi berlebih dirapatkan, dipotong sesuai batas). */
function chat_isi_bersih(string $isi): string
{
    $isi = (string) preg_replace('/[ \t]+/u', ' ', trim($isi));
    $isi = (string) preg_replace('/\n{3,}/', "\n\n", $isi);
    if (strlen($isi) > CHAT_MAKS) {
        // Sisakan ruang untuk tanda "…" supaya hasil akhirnya TIDAK melebihi batas.
        $potong = substr($isi, 0, CHAT_MAKS - 3);
        // Jangan memotong di tengah karakter UTF-8.
        while ($potong !== '' && !preg_match('//u', $potong)) {
            $potong = substr($potong, 0, -1);
        }
        $isi = rtrim($potong) . '…';
    }
    return $isi;
}

/**
 * Simpan satu pesan obrolan pada ruang (sekolah) tertentu.
 * Mengembalikan baris pesan yang baru disimpan (siap dikirim sebagai JSON) atau null bila isinya
 * kosong / ruangnya tidak boleh dibuka pengguna itu.
 */
function chat_kirim(string $isi, ?array $u = null, int $sekolahId = 0): ?array
{
    $u = $u ?? current_user();
    if (!$u) {
        return null;
    }
    $sekolahId = $sekolahId > 0 ? $sekolahId : chat_ruang_kini($u);
    if (!chat_ruang_boleh($u, $sekolahId)) {
        return null;
    }
    $isi = chat_isi_bersih($isi);
    if ($isi === '') {
        return null;
    }
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO chat_pesan (sekolah_id, user_id, nama, username, role, isi, waktu)
       VALUES (?,?,?,?,?,?,?)',
        [$sekolahId, (int) $u['id'], (string) $u['nama'], (string) $u['username'],
         (string) $u['role'], $isi, $now]);
    $id = (int) db()->lastInsertId();
    // Pangkas pesan lama supaya tabel tidak tumbuh tanpa batas (dijalankan jarang: tiap 50 pesan).
    if ($id % 50 === 0) {
        chat_pangkas($sekolahId);
    }
    return ['id' => $id, 'sekolah_id' => $sekolahId, 'user_id' => (int) $u['id'],
            'nama' => (string) $u['nama'], 'username' => (string) $u['username'],
            'role' => (string) $u['role'], 'isi' => $isi, 'waktu' => $now];
}

/** Buang pesan paling lama pada SATU ruang sehingga hanya CHAT_SIMPAN_MAKS pesan terbaru tersimpan. */
function chat_pangkas(int $sekolahId): void
{
    try {
        q('DELETE FROM chat_pesan
           WHERE sekolah_id = ? AND id <= (SELECT IFNULL(MAX(id),0) FROM chat_pesan WHERE sekolah_id = ?) - ?',
            [$sekolahId, $sekolahId, CHAT_SIMPAN_MAKS]);
    } catch (Throwable $e) {
        // Kegagalan pemangkasan tidak boleh menggagalkan pengiriman pesan.
    }
}

/**
 * Daftar pesan satu ruang.
 * @param int $sejak id pesan terakhir yang SUDAH dimiliki pemanggil (0 = ambil yang terbaru saja)
 */
function chat_pesan_list(int $sekolahId, int $sejak = 0, int $batas = CHAT_MUAT): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    $batas = max(1, min(200, $batas));
    if ($sejak > 0) {
        return all('SELECT * FROM chat_pesan WHERE sekolah_id = ? AND id > ? ORDER BY id LIMIT ' . $batas,
            [$sekolahId, $sejak]);
    }
    $baris = all('SELECT * FROM chat_pesan WHERE sekolah_id = ? ORDER BY id DESC LIMIT ' . $batas, [$sekolahId]);
    return array_reverse($baris);
}

/** id pesan terakhir sebuah ruang (0 bila belum ada pesan). */
function chat_id_terakhir(int $sekolahId): int
{
    if ($sekolahId <= 0) {
        return 0;
    }
    try {
        return (int) val('SELECT IFNULL(MAX(id),0) FROM chat_pesan WHERE sekolah_id = ?', [$sekolahId], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** id pesan TERAKHIR yang dibaca seorang pengguna pada satu ruang (0 = belum pernah membaca). */
function chat_baca_id(int $userId, int $sekolahId): int
{
    if ($userId <= 0 || $sekolahId <= 0) {
        return 0;
    }
    return (int) val('SELECT pesan_id FROM chat_baca WHERE user_id = ? AND sekolah_id = ?',
        [$userId, $sekolahId], 0);
}

/** Tandai pesan sampai id tertentu sudah dibaca (dipakai saat kotak obrolan terbuka). */
function chat_tandai_dibaca(int $userId, int $sekolahId, int $pesanId): void
{
    if ($userId <= 0 || $sekolahId <= 0 || $pesanId <= 0 || $pesanId <= chat_baca_id($userId, $sekolahId)) {
        return;
    }
    q('INSERT INTO chat_baca (user_id, sekolah_id, pesan_id, waktu) VALUES (?,?,?,?)
       ON CONFLICT(user_id, sekolah_id) DO UPDATE SET pesan_id = excluded.pesan_id, waktu = excluded.waktu',
        [$userId, $sekolahId, $pesanId, date('Y-m-d H:i:s')]);
}

/** Jumlah pesan BELUM DIBACA seorang pengguna pada satu ruang (pesan sendiri tidak dihitung). */
function chat_belum_dibaca(int $userId, int $sekolahId): int
{
    if ($userId <= 0 || $sekolahId <= 0) {
        return 0;
    }
    try {
        return (int) val('SELECT COUNT(*) FROM chat_pesan
                          WHERE sekolah_id = ? AND id > ? AND IFNULL(user_id,0) <> ?',
            [$sekolahId, chat_baca_id($userId, $sekolahId), $userId], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Jumlah pesan belum dibaca dari SELURUH ruang pengguna (dipakai lencana tombol obrolan). */
function chat_belum_total(int $userId, array $ruang): int
{
    $n = 0;
    foreach ($ruang as $r) {
        $n += chat_belum_dibaca($userId, (int) $r['id']);
    }
    return $n;
}

/** Label peran ringkas pada daftar obrolan ("Super Admin", "Pengawas", "Kepala", "Guru"). */
function chat_peran_label(?string $role): string
{
    switch ((string) $role) {
        case 'super':
            return 'Super Admin';
        case 'pengawas':
            return 'Pengawas Bina';
        case 'admin':
            return istilah_sekolah('Kepala Sekolah');
        default:
            return 'Guru';
    }
}

/**
 * Baris pesan siap dipakai lapisan tampilan/JSON: melengkapi peran, penanda "milik saya", dan jam.
 */
function chat_baris(array $p, int $userId = 0): array
{
    $uid = (int) ($p['user_id'] ?? 0);
    return [
        'id' => (int) $p['id'],
        'user_id' => $uid,
        'nama' => (string) ($p['nama'] ?? ''),
        'username' => (string) ($p['username'] ?? ''),
        'role' => (string) ($p['role'] ?? ''),
        'peran' => chat_peran_label((string) ($p['role'] ?? '')),
        'isi' => (string) ($p['isi'] ?? ''),
        'waktu' => (string) ($p['waktu'] ?? ''),
        'jam' => chat_jam((string) ($p['waktu'] ?? '')),
        'saya' => $uid > 0 && $uid === $userId,
    ];
}

/** Jam:menit dari waktu database ('' bila tidak dikenali). */
function chat_jam(string $waktu): string
{
    $ts = strtotime($waktu);
    return $ts === false ? '' : date('H:i', $ts);
}

/**
 * Jumlah pesan yang boleh dikirim lagi pada menit ini (pengaman sederhana anti-spam per sesi).
 * Dikembalikan SISA kuota; 0 berarti sudah penuh.
 */
function chat_sisa_kuota(): int
{
    $kini = time();
    $jendela = (int) ($_SESSION['chat_jendela'] ?? 0);
    $jumlah = (int) ($_SESSION['chat_jumlah'] ?? 0);
    if ($kini - $jendela >= 60) {
        $_SESSION['chat_jendela'] = $kini;
        $_SESSION['chat_jumlah'] = 0;
        $jumlah = 0;
    }
    return max(0, CHAT_MAKS_MENIT - $jumlah);
}

/** Catat satu pesan terkirim untuk hitungan kuota per menit. */
function chat_catat_kuota(): void
{
    $_SESSION['chat_jumlah'] = (int) ($_SESSION['chat_jumlah'] ?? 0) + 1;
}

// ============================================================================================
// ANGGOTA RUANG YANG SEDANG ONLINE
// ============================================================================================

/**
 * Syarat SQL "pengguna ini anggota ruang (sekolah) tsb": akun sekolahnya, pengawas yang membina
 * sekolah itu, atau Super Admin (boleh masuk semua ruang sesuai pilihan pemilik).
 */
function chat_sql_anggota(string $aliasUser, string $placeholderSekolah = '?'): string
{
    return '(' . $aliasUser . '.role = \'super\' OR ' . $aliasUser . '.sekolah_id = ' . $placeholderSekolah . '
             OR EXISTS (SELECT 1 FROM pengawas_sekolah cps
                        WHERE cps.user_id = ' . $aliasUser . '.id AND cps.sekolah_id = ' . $placeholderSekolah . '))';
}

/** Daftar pengguna yang SEDANG ONLINE pada satu ruang (dipakai keterangan "N online"). */
function chat_online_ruang(int $sekolahId, int $menit = LOG_MENIT_ONLINE): array
{
    if ($sekolahId <= 0) {
        return [];
    }
    try {
        $batas = date('Y-m-d H:i:s', time() - max(1, $menit) * 60);
        return all('SELECT DISTINCT u.id, u.nama, u.username, u.role
                    FROM sesi_aktif sx
                    JOIN users u ON u.id = sx.user_id
                    WHERE ' . log_online_syarat() . ' AND ' . chat_sql_anggota('u') . '
                    ORDER BY u.nama', [$batas, $sekolahId, $sekolahId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Jumlah anggota ruang yang sedang online. */
function chat_online_jumlah(int $sekolahId): int
{
    return count(chat_online_ruang($sekolahId));
}

// ============================================================================================
// KOTAK MENGAMBANG
// ============================================================================================

/** Apakah kotak obrolan perlu dicetak pada halaman ini? */
function chat_kotak_tampil(): bool
{
    if (!current_user()) {
        return false;
    }
    // Tidak punya ruang sama sekali (mis. akun tanpa sekolah) → tidak ada yang bisa dikerjakan.
    if (!chat_ruang_saya()) {
        return false;
    }
    // Saat aplikasi TERKUNCI (masa aktif habis) obrolan ikut mati — halaman lain pun dialihkan.
    $s = current_sekolah();
    return !($s && aktivasi_terkunci($s));
}

/**
 * KOTAK OBROLAN MENGAMBANG (pojok kanan bawah) — dicetak `page_bottom()` sehingga tampil di SEMUA
 * halaman berpengguna (halaman cetak tidak memakai layout, jadi tidak ikut tercetak).
 *
 * Isi pesan awal dicetak SERVER (tetap terbaca walau skrip gagal dimuat) dan dibungkus `tetap_kata()`
 * supaya tulisan yang diketik pengguna TIDAK ikut penyesuaian istilah.
 */
function chat_kotak(): void
{
    if (!chat_kotak_tampil()) {
        return;
    }
    $u = current_user();
    $uid = (int) $u['id'];
    $ruang = chat_ruang_saya($u);
    $ruangId = chat_ruang_kini($u);
    if ($ruangId <= 0) {
        return;
    }
    $pesan = chat_pesan_list($ruangId, 0, CHAT_MUAT);
    $belum = chat_belum_dibaca($uid, $ruangId);
    $belumTotal = chat_belum_total($uid, $ruang);
    $terakhir = $pesan ? (int) end($pesan)['id'] : 0;
    $online = chat_online_ruang($ruangId);
    $namaRuang = tetap_kata(chat_ruang_nama($ruangId));
    ?>
<div class="chat-kotak" id="chatKotak"
     data-terakhir="<?= (int) $terakhir ?>"
     data-ruang="<?= (int) $ruangId ?>"
     data-belum="<?= (int) $belumTotal ?>"
     data-online="<?= count($online) ?>"
     data-jeda="<?= (int) CHAT_JEDA_DETIK * 1000 ?>"
     data-maks="<?= (int) CHAT_MAKS ?>"
     data-url="chat.php">
  <button class="chat-tombol" type="button" id="chatTombol" aria-expanded="false"
          aria-controls="chatPanel" title="Obrolan dengan pengawas bina &amp; binaan">
    <?= ikon('chat', 22) ?>
    <span class="chat-lencana" id="chatLencana"<?= $belumTotal > 0 ? '' : ' hidden' ?>><?= (int) $belumTotal ?></span>
  </button>

  <div class="chat-panel" id="chatPanel" role="dialog" aria-label="Obrolan" hidden>
    <div class="chat-kepala">
      <div>
        <b><?= ikon('chat', 15) ?> Obrolan</b>
        <span class="xs" id="chatStatus">Ruang <b><?= e($namaRuang) ?></b> &middot; <b><?= count($online) ?></b> online</span>
      </div>
      <button class="chat-tutup" type="button" id="chatTutup" title="Tutup"><?= ikon('close', 16) ?></button>
    </div>

    <?php if (count($ruang) > 1): ?>
      <div class="chat-ruang">
        <?php // Label TIDAK memakai kata "Sekolah/Madrasah": halaman Super Admin memang tidak boleh
              // memuat istilah itu (halaman lintas sekolah memakai istilah generik "Sekolah"). ?>
        <label for="chatRuang">Pilih ruang obrolan</label>
        <select id="chatRuang">
          <?php foreach ($ruang as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $ruangId ? 'selected' : '' ?>>
              <?= e(tetap_kata((string) $r['nama'])) ?><?= !empty($r['binaan']) ? ' (binaan)' : '' ?>
              <?php $rb = chat_belum_dibaca($uid, (int) $r['id']); ?><?= $rb > 0 ? ' — ' . (int) $rb . ' baru' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="xs mut">Obrolan dipisah per ruang &mdash; tidak tercampur dengan ruang lainnya.</div>
      </div>
    <?php endif; ?>

    <div class="chat-isi" id="chatIsi" aria-live="polite">
      <?php if (!$pesan): ?>
        <p class="chat-kosong" id="chatKosong">Belum ada obrolan di ruang ini. Sapa pengawas bina atau
          binaan Anda yang sedang online.</p>
      <?php else: ?>
        <?php foreach ($pesan as $p): $pb = chat_baris($p, $uid); ?>
          <div class="chat-pesan<?= $pb['saya'] ? ' saya' : '' ?>" data-id="<?= (int) $pb['id'] ?>">
            <?php if (!$pb['saya']): ?>
              <span class="chat-dari"><?= e($pb['nama']) ?> &middot; <?= e($pb['peran']) ?></span>
            <?php endif; ?>
            <span class="chat-gelembung"><?= nl2br(e(tetap_kata($pb['isi']))) ?></span>
            <span class="chat-jam"><?= e($pb['jam']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($online): ?>
      <p class="chat-catatan" id="chatOnlineNama">Sedang online:
        <?= e(implode(', ', array_map(static fn(array $o): string => (string) $o['nama'], array_slice($online, 0, 6)))) ?>
        <?= count($online) > 6 ? ' dan ' . (count($online) - 6) . ' lainnya' : '' ?>.</p>
    <?php endif; ?>

    <form class="chat-form" id="chatForm" method="post" action="chat.php">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="kirim">
      <textarea id="chatIsian" name="isi" rows="2" maxlength="<?= (int) CHAT_MAKS ?>"
                aria-label="Tulis pesan obrolan"
                placeholder="Tulis pesan… (Enter untuk kirim)"></textarea>
      <div class="chat-aksi">
        <span class="xs mut" id="chatSisa"><?= (int) CHAT_MAKS ?> karakter</span>
        <button class="btn btn-primary btn-sm" type="submit" id="chatKirim"><?= ikon('send', 14) ?> Kirim</button>
      </div>
    </form>
    <p class="chat-catatan xs mut">
      Pesan tampil otomatis setiap <?= (int) CHAT_JEDA_DETIK ?> detik &middot; ruang obrolan hanya berisi
      pengawas pembina &amp; warga binaannya.
    </p>
  </div>
</div>
    <?php
}
