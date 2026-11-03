<?php
/**
 * ENDPOINT OBROLAN (chat) — dipakai kotak mengambang di pojok kanan bawah.
 *
 * Permintaan pemilik: "Tambahkan fitur obrolan seperti aplikasi e-Guru hanya saja yang bisa mengobrol
 * hanya pengawas bina dan binaannya yang sementara online. Tidak bisa mengobrol dengan yang bukan
 * binaannya." → SATU RUANG PER SEKOLAH/MADRASAH BINAAN (lihat inc/chat.php), Super Admin ikut.
 *
 * Selalu membalas **JSON** (`Content-Type: application/json`), kecuali kegagalan keamanan yang tetap
 * memakai kode HTTP yang benar:
 *   • belum masuk             → 302 (dialihkan ke halaman masuk oleh require_login());
 *   • ruang bukan haknya      → 403 + JSON  ← inilah penjagaan "bukan binaannya tidak bisa mengobrol";
 *   • POST tanpa CSRF         → 419 + JSON;
 *   • isi kosong / aksi ngawur → 400 + JSON;
 *   • terlalu banyak pesan     → 429 + JSON.
 *
 * Aksi:
 *   GET  ?aksi=ambil&sejak=ID[&baca=1]  → pesan baru pada ruang yang sedang dibuka
 *   GET  ?aksi=ruang&sekolah=ID         → pindah ruang (hanya ruang yang boleh dibuka pengguna)
 *   POST  aksi=kirim (+csrf, isi)       → menyimpan satu pesan pada ruang yang sedang dibuka
 *
 * `baca=1` dikirim kotak obrolan saat panelnya TERBUKA: pesan yang tampil langsung ditandai terbaca.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

/** Balasan JSON + kode HTTP. */
function chat_json(array $data, int $kode = 200): void
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$u = require_login();          // mencatat "online" (log_sesi_ping) + gerbang aktivasi/pengawas
$uid = (int) $u['id'];
$aksi = (string) ($_POST['aksi'] ?? $_GET['aksi'] ?? 'ambil');
$sekolahDiminta = (int) ($_POST['sekolah'] ?? $_GET['sekolah'] ?? 0);

/**
 * Rangkai balasan lengkap untuk satu ruang: daftar pesan (bila ada yang baru), lencana belum dibaca,
 * daftar ruang (dengan hitungan per ruang), dan anggota ruang yang sedang online.
 */
$balas = static function (array $u, int $ruangId, array $pesanBaru, int $sejak) use ($uid): array {
    $ruang = chat_ruang_saya($u);
    $pesan = [];
    foreach ($pesanBaru as $p) {
        $pesan[] = chat_baris($p, $uid);
    }
    $terakhir = $sejak;
    foreach ($pesanBaru as $p) {
        if ((int) $p['id'] > $terakhir) {
            $terakhir = (int) $p['id'];
        }
    }
    $online = chat_online_ruang($ruangId);
    return [
        'ok' => true,
        'ruang' => ['id' => $ruangId, 'nama' => chat_ruang_nama($ruangId)],
        'ruang_list' => array_map(static function (array $r) use ($uid): array {
            return ['id' => (int) $r['id'], 'nama' => (string) $r['nama'],
                    'binaan' => !empty($r['binaan']), 'belum' => chat_belum_dibaca($uid, (int) $r['id'])];
        }, $ruang),
        'pesan' => $pesan,
        'terakhir' => $terakhir,
        'belum' => chat_belum_dibaca($uid, $ruangId),
        'belum_total' => chat_belum_total($uid, $ruang),
        'online' => count($online),
        'online_nama' => array_map(static fn(array $o): string => (string) $o['nama'], $online),
        'saya' => ['id' => $uid, 'nama' => (string) $u['nama']],
    ];
};

// ------------------------------------------------------------------ kirim pesan
if ($aksi === 'kirim') {
    // Endpoint ini bisa dipanggil tanpa JavaScript (klien uji / formulir biasa). Tanpa CSRF → 419.
    if (!csrf_ok()) {
        chat_json(['ok' => false, 'pesan_galat' => 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'], 419);
    }
    $ruangId = $sekolahDiminta > 0 ? $sekolahDiminta : chat_ruang_kini($u);
    // Ruang yang bukan haknya (mis. pengawas menulis ke sekolah yang bukan binaannya) → 403.
    if (!chat_ruang_boleh($u, $ruangId)) {
        chat_json(['ok' => false, 'pesan_galat' =>
            'Anda hanya dapat mengobrol pada ruang sekolah/madrasah Anda sendiri atau binaan Anda.'], 403);
    }
    if (chat_sisa_kuota() <= 0) {
        chat_json(['ok' => false, 'pesan_galat' =>
            'Terlalu banyak pesan dalam satu menit. Tunggu sebentar sebelum mengirim lagi.'], 429);
    }
    $isi = (string) ($_POST['isi'] ?? '');
    if (trim($isi) === '') {
        chat_json(['ok' => false, 'pesan_galat' => 'Pesan masih kosong.'], 400);
    }
    $baru = chat_kirim($isi, $u, $ruangId);
    if ($baru === null) {
        chat_json(['ok' => false, 'pesan_galat' => 'Pesan tidak dapat disimpan.'], 400);
    }
    chat_catat_kuota();
    chat_tandai_dibaca($uid, $ruangId, (int) $baru['id']);   // pesan sendiri otomatis "terbaca"
    $data = $balas($u, $ruangId, [], (int) $baru['id']);
    $data['pesan'] = [chat_baris($baru, $uid)];
    $data['terakhir'] = (int) $baru['id'];
    chat_json($data);
}

// ------------------------------------------------------------------ pindah ruang
if ($aksi === 'ruang') {
    if ($sekolahDiminta <= 0 || !chat_ruang_pilih($sekolahDiminta, $u)) {
        chat_json(['ok' => false, 'pesan_galat' =>
            'Ruang obrolan itu bukan ruang sekolah/madrasah Anda atau binaan Anda.'], 403);
    }
    $pesan = chat_pesan_list($sekolahDiminta, 0, CHAT_MUAT);
    if ((int) ($_GET['baca'] ?? 0) === 1 && $pesan) {
        chat_tandai_dibaca($uid, $sekolahDiminta, (int) end($pesan)['id']);
    }
    chat_json($balas($u, $sekolahDiminta, $pesan, 0));
}

if ($aksi !== 'ambil') {
    chat_json(['ok' => false, 'pesan_galat' => 'Permintaan tidak dikenal.'], 400);
}

// ------------------------------------------------------------------ ambil pesan baru
$ruangId = $sekolahDiminta > 0 ? $sekolahDiminta : chat_ruang_kini($u);
if (!chat_ruang_boleh($u, $ruangId)) {
    chat_json(['ok' => false, 'pesan_galat' => 'Ruang obrolan tidak tersedia untuk akun Anda.'], 403);
}
$sejak = max(0, (int) ($_GET['sejak'] ?? 0));
$baris = chat_pesan_list($ruangId, $sejak);

// Panel obrolan sedang terbuka → yang tampil dianggap sudah dibaca.
if ((int) ($_GET['baca'] ?? 0) === 1 && $baris) {
    chat_tandai_dibaca($uid, $ruangId, (int) end($baris)['id']);
}

chat_json($balas($u, $ruangId, $baris, $sejak));
