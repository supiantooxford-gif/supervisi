<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

$u = require_login();
$super = $u['role'] === 'super';
$sekolah = current_sekolah();

// ============================================================ Z. Pengawas: rekap sekolah binaan (hanya lihat)
if ($u['role'] === 'pengawas') {
    // Memilih sekolah binaan untuk dilihat rekapnya.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        $aksi = (string) ($_POST['aksi'] ?? '');
        $id = (int) ($_POST['sekolah'] ?? 0);
        if ($aksi === 'masuk' && pengawas_punya((int) $u['id'], $id)) {
            set_sekolah_context($id);
            $nm = (string) val('SELECT nama FROM sekolah WHERE id = ?', [$id], '');
            flash('Menampilkan rekap supervisi ' . $nm . '.');
            redirect('laporan.php');
        }
        if ($aksi === 'keluar') {
            set_sekolah_context(null);
            flash('Kembali ke daftar sekolah binaan Anda.');
            redirect('index.php');
        }
        flash('Sekolah itu bukan sekolah binaan Anda.', 'err');
        redirect('index.php');
    }

    // Sekolah yang sudah dinonaktifkan tidak lagi ditampilkan ke pengawas.
    $binaanAktif = sekolah_binaan((int) $u['id'], true);
    $jmlBinaanSemua = count(sekolah_binaan((int) $u['id'], false));
    $binaan = $binaanAktif;
    $binaanNonaktif = $jmlBinaanSemua - count($binaanAktif);

    // Daftar binaan menampilkan sekolah umum DAN madrasah sekaligus, jadi istilahnya
    // "Sekolah/Madrasah" (lihat istilah_sekolah() + is_istilah_pengawas()).
    $labelSekolah = [];
    $avgSekolah = [];
    $predSekolah = [];
    $totGuru = 0;
    $totSuper = 0;
    $totNilai = 0.0;
    $totN = 0;
    $berdata = 0;
    $statPer = [];
    foreach ($binaan as $s) {
        $st = sekolah_stat((int) $s['id']);
        $statPer[(int) $s['id']] = $st;
        $totGuru += $st['guru'];
        $totSuper += $st['supervisi'];
        if ($st['rata'] > 0) {
            $totNilai += $st['rata'];
            $totN++;
            $berdata++;
            $labelSekolah[] = $s['nama'];
            $avgSekolah[] = round($st['rata'], 2);
            $predSekolah[] = predikat_dari($st['rata'], $s);
        }
    }
    $rataGabungan = $totN > 0 ? round($totNilai / $totN, 2) : 0.0;
    $predGabungan = $totN > 0 ? predikat_dari($rataGabungan) : null;

    // Istilah pada daftar binaan: selalu "Sekolah/Madrasah" karena pengawas membina
    // kedua jenis satuan pendidikan (permintaan pemilik).
    $istilahBinaan = 'Sekolah/Madrasah';

    // Komentar/tindak lanjut yang sudah ditulis pengawas ini untuk tiap binaan + jumlah dokumen
    // yang dikirim madrasah (dipakai sebagai penanda pada daftar binaan).
    $komentarSayaPerBinaan = [];
    foreach ($binaan as $s) {
        $k = komentar_pengawas_saya((int) $s['id'], (int) $u['id']);
        if ($k) {
            $komentarSayaPerBinaan[(int) $s['id']] = (string) $k['diubah'];
        }
    }
    $dokumenPerBinaan = dokumen_per_madrasah((int) $u['id']);
  // PROGRES PENGIRIMAN DOKUMEN tiap binaan (permintaan pemilik): penyebutnya jumlah Nama Dokumen
  // yang diisi Super Admin pada menu "Jenis Dokumen (Global)".
  $progressBinaan = dokumen_progress_binaan((int) $u['id'], '');
  $progressRataBinaan = $progressBinaan ? dokumen_progress_rata($progressBinaan) : 0;
  $jmlNamaDokumen = dokumen_jenis_jumlah(true);

    page_top('Rekap Sekolah Binaan', 'index');
    ?>
    <?php if ($sekolah): ?>
      <div class="alert alert-ok">
        <?= ikon('school', 16) ?>
        <span>Anda sedang membuka rekap <b><?= e(tetap_kata((string) $sekolah['nama'])) ?></b>. Semua data yang tampil hanya milik sekolah ini.</span>
        <form method="post" style="margin-left:auto">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="keluar">
          <button class="btn btn-ghost btn-sm" type="submit">Kembali ke daftar binaan</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="grid g4">
      <div class="card stat">
        <div class="stat-ic"><?= ikon('building', 20) ?></div>
        <div>
          <div class="stat-num" style="font-size:1.35rem"><?= count($binaan) ?></div>
          <div class="stat-lbl"><?= e($istilahBinaan) ?> binaan</div>
          <div class="stat-hint"><?= $berdata ?> sudah ada hasil supervisi<?= $binaanNonaktif > 0 ? ' &middot; ' . (int) $binaanNonaktif . ' nonaktif' : '' ?></div>
        </div>
      </div>
      <div class="card stat">
        <div class="stat-ic s"><?= ikon('users', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= $totGuru ?></div><div class="stat-lbl">Guru aktif (binaan)</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic g"><?= ikon('check', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= $totSuper ?></div><div class="stat-lbl">Supervisi final</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic p"><?= ikon('chart', 20) ?></div>
        <div>
          <div class="stat-num" style="font-size:1.35rem"><?= r1($rataGabungan) ?></div>
          <div class="stat-lbl">Rata-rata binaan</div>
          <div class="stat-hint"><?= $predGabungan ? 'predikat ' . e($predGabungan) . ' &middot; ' . e(predikat_label($predGabungan)) : 'belum ada nilai' ?></div>
        </div>
      </div>
    </div>

    <?php if ($binaanNonaktif > 0): ?>
      <div class="alert alert-warn">
        <?= ikon('star', 16) ?>
        <span><?= (int) $binaanNonaktif ?> sekolah binaan Anda sudah <b>dinonaktifkan</b> sehingga tidak ditampilkan lagi.
          Hubungi Super Admin bila sekolah itu masih memakai aplikasi.</span>
      </div>
    <?php endif; ?>

    <?php if (!$binaan): ?>
      <div class="card">
        <div class="empty">
          <?= ikon('eye', 34) ?>
          <h3>Belum ada sekolah binaan</h3>
          <p>Akun Anda belum ditetapkan sekolah binaan oleh Super Admin. Hubungi Super Admin untuk menetapkannya.</p>
          <a class="btn btn-ghost" href="akun.php"><?= ikon('user', 16) ?> Akun Saya</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grid g21">
        <div class="card">
          <div class="card-head"><div><h2>Perbandingan Nilai antar <?= e($istilahBinaan) ?> Binaan</h2><p>Rata-rata hasil supervisi final (skala 0-100).</p></div></div>
          <?php if ($labelSekolah): ?><div class="chart-box tall"><canvas id="chBinaan"></canvas></div>
          <?php else: ?><div class="empty"><p class="sm mut">Belum ada hasil supervisi final pada sekolah binaan Anda.</p></div><?php endif; ?>
        </div>
        <div class="card">
          <div class="card-head"><div><h2>Sebaran Predikat</h2><p>Seluruh supervisi final pada <?= e(strtolower($istilahBinaan)) ?> binaan.</p></div></div>
          <?php
          $distB = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
          foreach ($binaan as $s) {
              foreach (all('SELECT predikat, COUNT(*) c FROM supervisi WHERE sekolah_id = ? AND status = "final" GROUP BY predikat', [(int) $s['id']]) as $r) {
                  if (isset($distB[$r['predikat']])) {
                      $distB[$r['predikat']] += (int) $r['c'];
                  }
              }
          }
          $warna = ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'];
          $distArrB = [];
          foreach (['A', 'B', 'C', 'D'] as $pp) {
              if ($distB[$pp] > 0) {
                  $distArrB[] = ['l' => $pp . ' — ' . predikat_label($pp), 'v' => $distB[$pp], 'c' => $warna[$pp]];
              }
          }
          ?>
          <?php if ($distArrB): ?><div class="chart-box tall"><canvas id="chPredB"></canvas></div>
          <?php else: ?><div class="empty"><p class="sm mut">Belum ada data.</p></div><?php endif; ?>
        </div>
      </div>
      <?php if ($jmlNamaDokumen > 0): ?>
        <?php // PROGRES PENGIRIMAN DOKUMEN seluruh binaan (permintaan pemilik: "progress pengiriman
              // dokumen di akun pengawas dan kepala sekolah ... dari jumlah nama dokumen yang diisi
              // lewat super admin"). ?>
        <div class="card card-tight" id="cardProgressBinaan">
          <div class="card-head">
            <div>
              <h2><?= ikon('chart', 18) ?> Progres Pengiriman Dokumen Binaan</h2>
              <p>Berapa <b>Nama Dokumen</b> yang diminta (diatur Super Admin) sudah dikirim setiap
                sekolah/madrasah binaan Anda.</p>
            </div>
            <div class="flex" style="gap:8px;align-items:center">
              <span class="pill <?= $progressRataBinaan >= 80 ? 'pill-ok' : ($progressRataBinaan >= 40 ? 'pill-warn' : 'pill-err') ?>">
                Rata-rata <?= (int) $progressRataBinaan ?>%</span>
              <a class="btn btn-ghost btn-sm" href="dokumen_madrasah.php#cardProgressPengawas">
                <?= ikon('doc', 14) ?> Rincian di Dokumen Madrasah</a>
            </div>
          </div>
          <div class="progress-daftar">
            <?php foreach ($progressBinaan as $sidP => $pb): $p = $pb['progress']; ?>
              <div class="progress-item <?= $p['persen'] >= 80 ? 'ada' : 'belum' ?>">
                <?= ikon($p['persen'] >= 80 ? 'check' : 'clock', 14) ?>
                <span class="progress-nama"><?= e(tetap_kata((string) $pb['nama'])) ?></span>
                <span class="progress-track sm"><i style="width:<?= (int) $p['persen'] ?>%"></i></span>
                <span class="pill <?= $p['persen'] >= 80 ? 'pill-ok' : ($p['persen'] >= 40 ? 'pill-warn' : 'pill-err') ?>">
                  <?= (int) $p['persen'] ?>%</span>
                <span class="xs mut"><?= (int) $p['terkirim'] ?>/<?= (int) $p['total'] ?> nama dokumen</span>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="hint">Progres dihitung dari jumlah <b>Nama Dokumen</b> pada menu
            <b>Jenis Dokumen (Global)</b> (<?= (int) $jmlNamaDokumen ?> nama) dibandingkan dokumen yang
            benar-benar dikirim madrasah itu.</p>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-head">
          <div>
            <h2>Daftar <?= e($istilahBinaan) ?> Binaan</h2>
            <p>Klik <b>Lihat Rekap</b> untuk rekapan supervisi, <b>Komentar</b> untuk memberi komentar
              tindak lanjut (terkirim ke kepala sekolah/madrasah), <b>Jadwal</b> untuk jadwal supervisi,
              atau <b>Unduh CSV</b> untuk menyimpan rekapan.</p>
          </div>
          <span class="chip"><?= count($binaan) ?> <?= e(strtolower($istilahBinaan)) ?></span>
        </div>
        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Sekolah</th><th style="width:132px">Masa Aktif</th><th class="ctr">Guru</th><th class="ctr">Supervisi</th>
                <th style="width:150px">Rata-rata</th><th class="ctr">Predikat</th><th class="ctr">Cakupan</th>
                <th style="width:150px">Progres Dokumen</th>
                <th>Supervisi terakhir</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($binaan as $s): $st = $statPer[(int) $s['id']]; $rata = $st['rata']; $pred = $rata > 0 ? predikat_dari($rata, $s) : null; ?>
              <tr>
                <td>
                  <b><?= e((string) $s['nama']) ?></b>
                  <span class="tbl-sub">NPSN <?= e((string) ($s['npsn'] ?: '-')) ?> &middot; <?= e((string) ($s['kepala_sekolah'] ?: '-')) ?><?= (int) $s['aktif'] === 0 ? ' &middot; nonaktif' : '' ?></span>
                  <?php if (isset($komentarSayaPerBinaan[(int) $s['id']])): ?>
                    <span class="tbl-sub"><span class="pill pill-ok">sudah dikomentari</span>
                      <?= e(waktu_id_polos($komentarSayaPerBinaan[(int) $s['id']])) ?></span>
                  <?php endif; ?>
                  <?php if (isset($dokumenPerBinaan[(int) $s['id']])): ?>
                    <span class="tbl-sub"><a href="dokumen_madrasah.php?madrasah=<?= (int) $s['id'] ?>"><?= ikon('doc', 12) ?>
                      <?= (int) $dokumenPerBinaan[(int) $s['id']]['jumlah'] ?> dokumen madrasah</a></span>
                  <?php endif; ?>
                </td>
                <?php
                // MASA AKTIF APLIKASI tiap binaan (permintaan pemilik): pengawas perlu tahu masa aktif
                // sekolah/madrasah binaannya masing-masing — masih aktif, masa uji coba, atau sudah habis
                // sehingga aplikasinya terkunci. Datanya dari aktivasi_masa_aktif() (satu sumber dengan
                // tabel Peringkat Sekolah di dasbor Super Admin) — jangan menghitung sisa hari di sini.
                $ma = aktivasi_masa_aktif($s);
                ?>
                <td class="xs" id="masaAktif<?= (int) $s['id'] ?>">
                  <span class="pill <?= e($ma['kelas']) ?>"<?= $ma['tanggal'] !== '' ? ' title="Berakhir ' . e($ma['tanggal']) . '"' : '' ?>><?= e($ma['label']) ?></span>
                  <span class="tbl-sub"><?= e($ma['ket']) ?></span>
                </td>
                <td class="ctr"><?= (int) $st['guru'] ?></td>
                <td class="ctr"><?= (int) $st['supervisi'] ?><?php if ($st['draft'] > 0): ?><span class="tbl-sub">+<?= (int) $st['draft'] ?> draf</span><?php endif; ?></td>
                <td>
                  <?php if ($rata > 0): ?>
                    <b><?= r1($rata) ?></b>
                    <div class="bar-mini mt8"><i class="lv<?= e($pred) ?>" style="width:<?= min(100, $rata) ?>%"></i></div>
                  <?php else: ?><span class="mut xs">belum ada</span><?php endif; ?>
                </td>
                <td class="ctr"><?= $pred ? '<span class="pill pill-' . e($pred) . '">' . e($pred) . '</span>' : '<span class="mut xs">—</span>' ?></td>
                <td class="ctr"><?= (int) $st['cakupan'] ?>%<span class="tbl-sub"><?= (int) $st['guru_dinilai'] ?>/<?= (int) $st['guru'] ?></span></td>
                <td>
                  <?php $pb = $progressBinaan[(int) $s['id']]['progress'] ?? null; ?>
                  <?php if ($pb && (int) $pb['total'] > 0): ?>
                    <b class="xs"><?= (int) $pb['persen'] ?>%</b>
                    <div class="progress-track sm"><i style="width:<?= (int) $pb['persen'] ?>%"></i></div>
                    <span class="tbl-sub"><?= (int) $pb['terkirim'] ?>/<?= (int) $pb['total'] ?> nama dokumen</span>
                  <?php else: ?>
                    <span class="mut xs">belum ada daftar nama dokumen</span>
                  <?php endif; ?>
                </td>
                <td class="xs mut">
                  <?= $st['terakhir'] ? e(tanggal_id((string) $st['terakhir'])) : 'belum ada' ?>
                  <span class="tbl-sub"><?= (int) $st['jadwal'] ?> baris jadwal</span>
                </td>
                <td>
                  <div class="row-actions">
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="aksi" value="masuk"><input type="hidden" name="sekolah" value="<?= (int) $s['id'] ?>">
                      <button class="btn btn-primary btn-sm" type="submit" <?= (int) $s['aktif'] === 0 ? 'disabled' : '' ?>><?= ikon('report', 14) ?> Lihat Rekap</button>
                    </form>
                    <a class="btn btn-ghost btn-sm <?= isset($komentarSayaPerBinaan[(int) $s['id']]) ? 'btn-ok' : '' ?>"
                       href="laporan.php?sekolah=<?= (int) $s['id'] ?>#cardKomentar"
                       title="<?= isset($komentarSayaPerBinaan[(int) $s['id']])
                         ? 'Anda sudah memberi komentar — klik untuk memperbarui'
                         : 'Beri komentar/tindak lanjut untuk sekolah ini' ?>"><?= ikon('message', 14) ?> Komentar</a>
                    <a class="btn btn-ghost btn-sm" href="jadwal.php?sekolah=<?= (int) $s['id'] ?>" title="Lihat jadwal supervisi <?= e(istilah_sekolah('Sekolah')) ?> ini"><?= ikon('calendar', 14) ?> Jadwal</a>
                    <a class="btn btn-ghost btn-sm" href="laporan_export.php?sekolah=<?= (int) $s['id'] ?>" title="Unduh rekapan supervisi sekolah ini (CSV)"><?= ikon('doc', 14) ?> Unduh CSV</a>
                    <a class="btn btn-ghost btn-sm" href="laporan.php?sekolah=<?= (int) $s['id'] ?>" title="Buka rekapan di tab ini"><?= ikon('eye', 14) ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>

    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      <?php if ($labelSekolah): ?>
      var lbl = <?= json_encode($labelSekolah, JSON_UNESCAPED_UNICODE) ?>;
      var val = <?= json_encode($avgSekolah) ?>;
      var col = <?= json_encode(array_map(fn($p) => ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'][$p] ?? '#94a3b8', $predSekolah)) ?>;
      var el = document.getElementById('chBinaan');
      if (el && typeof Chart !== 'undefined') {
        new Chart(el.getContext('2d'), {
          type: 'bar',
          data: { labels: lbl, datasets: [{ label: 'Rata-rata nilai', data: val, backgroundColor: col.map(function (c) { return c + 'cc'; }), borderRadius: 8, maxBarThickness: 46 }] },
          options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 } },
            scales: { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } },
                      x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } } }
        });
      }
      <?php endif; ?>
      <?php if (!empty($distArrB)): ?>
      var db = <?= json_encode($distArrB, JSON_UNESCAPED_UNICODE) ?>;
      VS.donut('chPredB', db.map(function (d) { return d.l; }), db.map(function (d) { return d.v; }), db.map(function (d) { return d.c; }));
      <?php endif; ?>
    });
    </script>
    <?php
    page_bottom();
    exit;
}

// ============================================================ A. Dashboard lintas sekolah (Super Admin)
if ($super && (!$sekolah || isset($_GET['semua']))) {
    $rows = all('SELECT s.*,
        (SELECT COUNT(*) FROM guru g WHERE g.sekolah_id = s.id AND g.aktif = 1) guru,
        (SELECT COUNT(*) FROM users u WHERE u.sekolah_id = s.id) users,
        (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") supervisi,
        (SELECT COUNT(*) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "draft") draft,
        (SELECT AVG(v.nilai) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") rata,
        (SELECT MAX(v.tanggal) FROM supervisi v WHERE v.sekolah_id = s.id) terakhir,
        (SELECT COUNT(DISTINCT v.guru_id) FROM supervisi v WHERE v.sekolah_id = s.id AND v.status = "final") guru_dinilai
        FROM sekolah s WHERE s.verifikasi = "disetujui" AND s.aktif = 1 ORDER BY s.nama');

    $totSekolah = count($rows);
    $totGuru = 0;
    $totFinal = 0;
    $totDraft = 0;
    $totNilai = 0.0;
    $totN = 0;
    $tanpaSuper = 0;
    $labelSekolah = [];
    $avgSekolah = [];
    $predSekolah = [];
    foreach ($rows as $r) {
        $totGuru += (int) $r['guru'];
        $totFinal += (int) $r['supervisi'];
        $totDraft += (int) $r['draft'];
        if ($r['rata'] !== null) {
            $totNilai += (float) $r['rata'];
            $totN++;
            $labelSekolah[] = $r['nama'];
            $avgSekolah[] = round((float) $r['rata'], 2);
            $predSekolah[] = predikat_dari((float) $r['rata'], $r);
        }
        if ((int) $r['supervisi'] === 0) {
            $tanpaSuper++;
        }
    }
    $rataGlobal = $totN > 0 ? round($totNilai / $totN, 2) : 0.0;

    // Perbandingan per menu supervisi antar sekolah
    $menuKode = [];
    foreach (all('SELECT DISTINCT i.kode, i.nama, i.urutan FROM instrumen i ORDER BY i.urutan, i.kode') as $m) {
        $menuKode[$m['kode']] = str_replace('Supervisi ', '', (string) $m['nama']);
    }
    $menuLabel = array_values($menuKode);
    $menuDatasets = [];
    // Palet grafik mengikuti TEMA TAMPILAN yang dipilih Super Admin (dua nada pertama = aksen
    // tema), sehingga grafik tidak lagi selalu berwarna ungu.
    $palet = [tema_warna('accent'), tema_warna('accent2'), '#0ea5e9', '#ec4899'];
    $i = 0;
    foreach (array_keys($menuKode) as $kode) {
        $data = [];
        foreach ($rows as $r) {
            // Menu & butir GLOBAL: cukup cocokkan kode menunya.
            $v = val('SELECT AVG(v.nilai) FROM supervisi v JOIN instrumen i ON i.id = v.instrumen_id
                       WHERE v.sekolah_id = ? AND v.status = "final" AND i.kode = ?',
                [(int) $r['id'], $kode]);
            $data[] = $v !== null ? round((float) $v, 2) : null;
        }
        $menuDatasets[] = ['label' => $menuKode[$kode] ?: $kode, 'data' => $data, 'color' => $palet[$i % count($palet)]];
        $i++;
    }

    $terbaru = all('SELECT v.*, s.nama AS sekolah, g.nama AS guru, i.nama AS instrumen
        FROM supervisi v JOIN sekolah s ON s.id = v.sekolah_id JOIN guru g ON g.id = v.guru_id JOIN instrumen i ON i.id = v.instrumen_id
        ORDER BY v.tanggal DESC, v.id DESC LIMIT 10');

    // Tabel "Peringkat Sekolah" (beserta lembar cetak & unduhan Word-nya) memakai SATU sumber data:
    // peringkat_sekolah() — sudah terurut menurut masa aktif, lengkap dengan totalnya.
    $pk = peringkat_sekolah($rows);

    page_top('Ringkasan Semua Sekolah', 'index');

    // Sekilas siapa yang sedang memakai aplikasi (rinciannya di halaman Log Aktivitas).
    $onlineSekarang = log_online();
    $namaOnline = [];
    foreach ($onlineSekarang as $o) {
        $nm = trim((string) $o['nama']) !== '' ? (string) $o['nama'] : (string) $o['username'];
        if (!in_array($nm, $namaOnline, true)) {
            $namaOnline[] = $nm;
        }
    }
    ?>
    <div class="card card-tight">
      <div class="flex between" style="flex-wrap:wrap;gap:12px;align-items:center">
        <div class="flex" style="gap:12px;align-items:center">
          <span class="stat-ic g"><?= ikon('eye', 18) ?></span>
          <div>
            <div class="flex" style="gap:8px;align-items:center">
              <?php if ($namaOnline): ?><span class="dot-online" title="online"></span><?php endif; ?>
              <b><?= count($onlineSekarang) ?> pengguna online saat ini</b>
            </div>
            <div class="xs mut mt8">
              <?php if ($namaOnline): ?>
                <?= e(implode(', ', array_slice($namaOnline, 0, 4))) ?><?= count($namaOnline) > 4 ? ' dan ' . (count($namaOnline) - 4) . ' lainnya' : '' ?>
              <?php else: ?>
                Tidak ada akun yang aktif dalam <?= (int) LOG_MENIT_ONLINE ?> menit terakhir.
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="flex" style="gap:8px">
          <span class="chip"><?= (int) val('SELECT COUNT(*) FROM log_aktivitas WHERE aktivitas = "masuk" AND waktu >= ?', [date('Y-m-d') . ' 00:00:00'], 0) ?> masuk hari ini</span>
          <a class="btn btn-primary btn-sm" href="log.php"><?= ikon('report', 15) ?> Log Aktivitas</a>
        </div>
      </div>
    </div>

    <div class="grid g4">
      <div class="card stat">
        <div class="stat-ic"><?= ikon('building', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= $totSekolah ?></div><div class="stat-lbl">Sekolah terdaftar</div><div class="stat-hint"><?= $tanpaSuper ?> belum punya hasil supervisi</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic s"><?= ikon('users', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= $totGuru ?></div><div class="stat-lbl">Guru aktif (semua sekolah)</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic g"><?= ikon('check', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= $totFinal ?></div><div class="stat-lbl">Supervisi final</div><div class="stat-hint"><?= $totDraft ?> masih draf</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic p"><?= ikon('chart', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.35rem"><?= r1($rataGlobal) ?></div><div class="stat-lbl">Rata-rata seluruh sekolah</div><div class="stat-hint">dari <?= $totN ?> sekolah berdata</div></div>
      </div>
    </div>

    <div class="grid g21">
      <div class="card">
        <div class="card-head">
          <div><h2>Perbandingan Rata-rata Nilai per Sekolah</h2><p>Skala 0-100, dihitung dari hasil supervisi berstatus final.</p></div>
          <span class="chip"><?= count($labelSekolah) ?> sekolah berdata</span>
        </div>
        <?php if ($labelSekolah): ?><div class="chart-box tall"><canvas id="chSekolah"></canvas></div>
        <?php else: ?><div class="empty"><?= ikon('chart', 32) ?><h3>Belum ada nilai</h3><p>Belum ada hasil supervisi final pada sekolah mana pun.</p></div><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-head"><div><h2>Sebaran Predikat</h2><p>Seluruh hasil supervisi final lintas sekolah.</p></div></div>
        <?php if ($totFinal > 0): ?><div class="chart-box tall"><canvas id="chPredGlobal"></canvas></div>
        <?php else: ?><div class="empty"><p class="sm mut">Belum ada data.</p></div><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div><h2>Perbandingan per Menu Supervisi</h2><p>Rata-rata nilai tiap menu supervisi pada setiap sekolah.</p></div>
      </div>
      <?php if ($labelSekolah && $menuDatasets): ?><div class="chart-box tall" style="height:360px"><canvas id="chMenu"></canvas></div>
      <?php else: ?><div class="empty"><p class="sm mut">Belum ada data untuk dibandingkan.</p></div><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head">
        <div><h2>Peringkat Sekolah</h2><p>Diurutkan dari masa aktif paling lama (paling atas), lalu nama sekolah.</p></div>
        <?php
        // CARI NAMA SEKOLAH (permintaan pemilik): kotak cari di antara judul kartu dan tombol aksi
        // untuk mempercepat pencarian lembaga pada daftar yang panjang. Penyaringan berjalan
        // SAAT DIKETIK (tanpa memuat ulang halaman) lewat initCariSekolah() di assets/app.js —
        // nomor baris ikut dirapikan dan jumlah hasil ditampilkan. Tanpa JavaScript tabel tetap
        // tampil utuh (kotak cari hanya pelengkap, tidak pernah menyembunyikan data secara permanen).
        ?>
        <div class="cari-sekolah">
          <span class="cari-ikon" aria-hidden="true"><?= ikon('search', 15) ?></span>
          <input type="search" id="cariSekolah" autocomplete="off"
                 placeholder="Cari nama sekolah / NPSN / kepala…"
                 aria-label="Cari nama sekolah pada daftar peringkat"
                 aria-controls="tblPeringkat" data-target="tblPeringkat">
          <button type="button" id="cariSekolahBersih" class="cari-bersih" hidden
                  aria-label="Hapus kata kunci pencarian" title="Hapus kata kunci"><?= ikon('close', 13) ?></button>
        </div>
        <?php
        // CETAK & UNDUH WORD (permintaan pemilik, v1.27.0): dua tombol di samping "Kelola Sekolah".
        // Keduanya membaca peringkat_sekolah() — sumber data yang sama dengan tabel di bawah,
        // jadi angka pada lembar cetak/Word tidak mungkin berbeda dari dasbor.
        ?>
        <div class="flex" style="gap:8px;flex-wrap:wrap;justify-content:flex-end">
          <a class="btn btn-ghost btn-sm" href="cetak_peringkat.php" target="_blank" rel="noopener"
             title="Cetak / simpan PDF daftar peringkat sekolah"><?= ikon('print', 15) ?> Cetak</a>
          <a class="btn btn-ghost btn-sm" href="peringkat_docx.php"
             title="Unduh daftar peringkat sekolah sebagai berkas Word (.docx)"><?= ikon('doc', 15) ?> Unduh Word</a>
          <a class="btn btn-primary btn-sm" href="sekolah.php"><?= ikon('building', 15) ?> Kelola Sekolah</a>
        </div>
      </div>
      <?php if ($pk['baris']): ?>
        <div class="cari-info" id="cariInfo" hidden>
          <span id="cariJumlah"></span>
          <button type="button" class="btn btn-ghost btn-sm" id="cariTampilSemua">Tampilkan semua</button>
        </div>
        <div class="table-wrap">
          <table class="tbl" id="tblPeringkat">
            <thead><tr><th style="width:44px">#</th><th>Sekolah</th><th style="width:150px">Masa Aktif</th><th class="ctr">Guru</th><th class="ctr">Supervisi</th><th style="width:150px">Rata-rata</th><th class="ctr">Predikat</th><th class="ctr">Cakupan</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pk['baris'] as $ix => $r):
                $rata = $r['rata_nilai'];
                $cakupan = (int) $r['cakupan'];
                // MASA AKTIF (permintaan pemilik): masa aktif 1 tahun / masa uji coba 15 hari /
                // keadaan sudah habis — supaya Super Admin langsung melihat mana yang perlu diaktivasi.
                $ma = $r['masa'];
                ?>
              <tr>
                <td><b><?= $ix + 1 ?></b></td>
                <?php
                // CATATAN untuk fitur cari (v1.31.1): teks yang dicari diambil dari SEL INI
                // (kolom ke-2 = nama sekolah + baris kecil NPSN/kepala sekolah) oleh
                // `initCariSekolah()` di assets/app.js. Jadi TIDAK perlu penanda tambahan pada
                // markup (dan tag <tr>/<td> sengaja dibiarkan apa adanya supaya pembaca/uji lain
                // yang memecah tabel berdasarkan tag itu tidak terganggu).
                ?>
                <td>
                  <b><?= e((string) $r['nama']) ?></b>
                  <span class="tbl-sub">NPSN <?= e((string) ($r['npsn'] ?: '-')) ?> &middot; <?= e((string) ($r['kepala_sekolah'] ?: '-')) ?><?= (int) $r['aktif'] === 0 ? ' &middot; nonaktif' : '' ?></span>
                </td>
                <td class="xs" id="masaAktif<?= (int) $r['id'] ?>">
                  <span class="pill <?= e($ma['kelas']) ?>"<?= $ma['tanggal'] !== '' ? ' title="Berakhir ' . e($ma['tanggal']) . '"' : '' ?>><?= e($ma['label']) ?></span>
                  <span class="tbl-sub"><?= e($ma['ket']) ?></span>
                </td>
                <td class="ctr"><?= (int) $r['guru'] ?></td>
                <td class="ctr"><?= (int) $r['supervisi'] ?><?php if ((int) $r['draft'] > 0): ?><span class="tbl-sub">+<?= (int) $r['draft'] ?> draf</span><?php endif; ?></td>
                <td>
                  <?php if ($rata !== null): ?>
                    <b><?= r1($rata) ?></b>
                    <div class="bar-mini mt8"><i class="lv<?= e($r['predikat']) ?>" style="width:<?= min(100, $rata) ?>%"></i></div>
                  <?php else: ?><span class="mut xs">belum ada</span><?php endif; ?>
                </td>
                <td class="ctr"><?= $rata !== null ? '<span class="pill pill-' . e($r['predikat']) . '">' . e($r['predikat']) . '</span>' : '<span class="mut xs">—</span>' ?></td>
                <td class="ctr"><?= $cakupan ?>%<span class="tbl-sub"><?= (int) $r['guru_dinilai'] ?>/<?= (int) $r['guru'] ?></span></td>
                <td class="right">
                  <?php
                  // Tiga aksi ringkas (tanpa teks, dengan keterangan `title`) supaya kolom Aksi tetap
                  // sempit dan tinggi baris tabel tidak melar:
                  //   WA    → kirim WhatsApp (nomor HP/telepon dari menu Profil & Pengaturan);
                  //   Kelola→ masuk konteks sekolah;
                  //   Hapus → membuka Zona Berbahaya kartu sekolah di sekolah.php (?hapus=ID); penghapusan
                  //           tetap WAJIB melewati konfirmasi "ketik HAPUS", jadi tidak terhapus sekali klik.
                  ?>
                  <div class="flex" style="gap:6px;justify-content:flex-end;flex-wrap:nowrap">
                    <?= wa_lembaga_tombol($r, 'btn btn-ok btn-sm', '', '<span class="mut xs">&mdash;</span>') ?>
                    <form method="post" action="sekolah.php" style="display:inline">
                      <?= csrf_field() ?><input type="hidden" name="aksi" value="masuk"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit" title="Kelola data sekolah ini" aria-label="Kelola" <?= (int) $r['aktif'] === 0 ? 'disabled' : '' ?>><?= ikon('switch', 15) ?></button>
                    </form>
                    <a class="btn btn-danger btn-sm"
                       href="sekolah.php?hapus=<?= (int) $r['id'] ?>#hapus-<?= (int) $r['id'] ?>"
                       title="Hapus sekolah <?= e((string) $r['nama']) ?> (membuka Zona Berbahaya — wajib ketik HAPUS)"
                       aria-label="Hapus sekolah"
                       data-confirm="Buka Zona Berbahaya sekolah <?= e((string) $r['nama']) ?>? Penghapusan permanen masih memerlukan konfirmasi dengan mengetik HAPUS."><?= ikon('trash', 15) ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php // Baris keadaan kosong saat pencarian tidak menemukan sekolah (dipakai initCariSekolah()). ?>
            <tr id="cariKosong" hidden>
              <td colspan="9">
                <div class="cari-kosong" id="cariKosongTeks">Tidak ada sekolah/madrasah yang cocok dengan kata kunci itu.</div>
              </td>
            </tr>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty">
          <?= ikon('building', 34) ?><h3>Belum ada sekolah</h3>
          <p>Tambahkan sekolah pertama beserta akun adminnya.</p>
          <a class="btn btn-primary" href="sekolah.php"><?= ikon('plus', 16) ?> Tambah Sekolah</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Supervisi Terbaru (Semua Sekolah)</h2><p>Sepuluh kegiatan supervisi paling akhir.</p></div></div>
      <?php if ($terbaru): ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Tanggal</th><th>Sekolah</th><th>Guru</th><th>Menu</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th></tr></thead>
            <tbody>
            <?php foreach ($terbaru as $r): ?>
              <tr>
                <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
                <td><?= e((string) $r['sekolah']) ?></td>
                <td><b><?= e((string) $r['guru']) ?></b></td>
                <td><?= e((string) $r['instrumen']) ?></td>
                <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
                <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty"><p class="sm mut">Belum ada supervisi.</p></div>
      <?php endif; ?>
    </div>

    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      <?php if ($labelSekolah): ?>
      var lbl = <?= json_encode($labelSekolah, JSON_UNESCAPED_UNICODE) ?>;
      var val = <?= json_encode($avgSekolah) ?>;
      var col = <?= json_encode(array_map(fn($p) => ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'][$p] ?? '#94a3b8', $predSekolah)) ?>;
      var el = document.getElementById('chSekolah');
      if (el && typeof Chart !== 'undefined') {
        new Chart(el.getContext('2d'), {
          type: 'bar',
          data: { labels: lbl, datasets: [{ label: 'Rata-rata nilai', data: val, backgroundColor: col.map(function (c) { return c + 'cc'; }), borderRadius: 8, maxBarThickness: 46 }] },
          options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 } },
            scales: { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } },
                      x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } } }
        });
      }
      <?php endif; ?>
      <?php if ($totFinal > 0): ?>
      var dg = <?= json_encode(array_values(array_filter([
          ['l' => 'A — Amat Baik', 'v' => (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "final" AND predikat = "A"', [], 0), 'c' => '#10b981'],
          ['l' => 'B — Baik', 'v' => (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "final" AND predikat = "B"', [], 0), 'c' => '#3b82f6'],
          ['l' => 'C — Cukup', 'v' => (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "final" AND predikat = "C"', [], 0), 'c' => '#f59e0b'],
          ['l' => 'D — Perlu Pembinaan', 'v' => (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "final" AND predikat = "D"', [], 0), 'c' => '#dc2626'],
      ], fn($d) => $d['v'] > 0)), JSON_UNESCAPED_UNICODE) ?>;
      VS.donut('chPredGlobal', dg.map(function (d) { return d.l; }), dg.map(function (d) { return d.v; }), dg.map(function (d) { return d.c; }));
      <?php endif; ?>
      <?php if ($labelSekolah && $menuDatasets): ?>
      var ds = <?= json_encode($menuDatasets, JSON_UNESCAPED_UNICODE) ?>;
      var el2 = document.getElementById('chMenu');
      if (el2 && typeof Chart !== 'undefined') {
        new Chart(el2.getContext('2d'), {
          type: 'bar',
          data: { labels: lbl, datasets: ds.map(function (d) { return { label: d.label, data: d.data, backgroundColor: d.color + 'cc', borderRadius: 6, maxBarThickness: 34 }; }) },
          options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { font: { family: 'Inter', size: 11 }, color: '#635F82', boxWidth: 12, usePointStyle: true, padding: 14 } },
                       tooltip: { backgroundColor: 'rgba(31,27,58,.92)', padding: 10, cornerRadius: 10 } },
            scales: { y: { beginAtZero: true, max: 100, grid: { color: 'rgba(99,95,130,.14)' }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } },
                      x: { grid: { display: false }, ticks: { color: '#635F82', font: { family: 'Inter', size: 11 } } } } }
        });
      }
      <?php endif; ?>
    });
    </script>
    <?php
    page_bottom();
    exit;
}

// ============================================================ B. Dashboard satu sekolah
$sid = require_sekolah()['id'];
$sid = (int) $sid;

if (!$super && $u['role'] === 'guru') {
    // ---------- Dashboard guru: hasil supervisi milik sendiri ----------
    $gid = (int) ($u['guru_id'] ?? 0);
    $guru = $gid ? one('SELECT * FROM guru WHERE id = ? AND sekolah_id = ?', [$gid, $sid]) : null;

    $rows = $gid ? all('SELECT s.*, i.nama AS instrumen, i.kode, i.ikon
                        FROM supervisi s JOIN instrumen i ON i.id = s.instrumen_id
                        WHERE s.guru_id = ? AND s.sekolah_id = ? AND s.status = "final"
                        ORDER BY s.tanggal DESC', [$gid, $sid]) : [];

    $perInstr = [];
    foreach ($rows as $r) {
        $k = $r['kode'];
        if (!isset($perInstr[$k])) {
            $perInstr[$k] = ['nama' => $r['instrumen'], 'nilai' => []];
        }
        $perInstr[$k]['nilai'][] = (float) $r['nilai'];
    }
    $instList = instrumen_list();
    $rataAll = $rows ? round(array_sum(array_map(fn($r) => (float) $r['nilai'], $rows)) / count($rows), 2) : 0;
    $predAll = $rows ? predikat_dari($rataAll) : '-';

    $tren = [];
    for ($i = 7; $i >= 0; $i--) {
        $tren[date('Y-m', strtotime("-$i month"))] = [];
    }
    foreach ($rows as $r) {
        $b = substr((string) $r['tanggal'], 0, 7);
        if (isset($tren[$b])) {
            $tren[$b][] = (float) $r['nilai'];
        }
    }

    $predColors = ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'];
    $distArr = [];
    foreach (['A', 'B', 'C', 'D'] as $p) {
        $c = count(array_filter($rows, fn($r) => $r['predikat'] === $p));
        if ($c > 0) {
            $distArr[] = ['l' => $p . ' — ' . predikat_label($p), 'v' => $c, 'c' => $predColors[$p]];
        }
    }
    $radarLabels = array_map(fn($i) => $i['nama'], array_values($instList));
    $radarData = array_map(function ($i) use ($perInstr) {
        $v = $perInstr[$i['kode']]['nilai'] ?? [];
        return $v ? round(array_sum($v) / count($v), 2) : 0;
    }, array_values($instList));

    page_top('Dashboard Saya', 'index');
    ?>
    <div class="card">
      <div class="flex between">
        <div>
          <h2>Selamat datang, <?= e((string) $u['nama']) ?></h2>
          <p class="sm mut" style="margin:6px 0 0">
            <?= e(tetap_kata((string) ($u['sekolah_nama'] ?? ''))) ?><?= $guru ? ' &middot; ' . e((string) $guru['mapel']) . ' &middot; ' . e((string) $guru['jabatan']) : ' &middot; akun belum ditautkan ke data guru.' ?>
          </p>
        </div>
        <a class="btn btn-ghost" href="laporan.php"><?= ikon('report', 16) ?> Laporan Saya</a>
      </div>
    </div>

    <div class="grid g4">
      <div class="card stat">
        <div class="stat-ic"><?= ikon('check', 20) ?></div>
        <div><div class="stat-num"><?= count($rows) ?></div><div class="stat-lbl">Supervisi Selesai</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic s"><?= ikon('chart', 20) ?></div>
        <div><div class="stat-num"><?= r1($rataAll) ?></div><div class="stat-lbl">Rata-rata Nilai</div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic g"><?= ikon('star', 20) ?></div>
        <div><div class="stat-num"><?= e($predAll) ?></div><div class="stat-lbl">Predikat <?= $predAll !== '-' ? e(predikat_label($predAll)) : '' ?></div></div>
      </div>
      <div class="card stat">
        <div class="stat-ic o"><?= ikon('calendar', 20) ?></div>
        <div><div class="stat-num" style="font-size:1.05rem"><?= $rows ? e(tanggal_id((string) $rows[0]['tanggal'])) : '-' ?></div><div class="stat-lbl">Supervisi Terakhir</div></div>
      </div>
    </div>

    <div class="grid g21">
      <div class="card">
        <div class="card-head"><div><h2>Capaian per Menu Supervisi</h2><p>Nilai rata-rata Anda pada setiap jenis supervisi.</p></div></div>
        <?php if ($rows): ?><div class="chart-box tall"><canvas id="chRadar"></canvas></div>
        <?php else: ?><div class="empty"><h3>Belum ada data</h3><p>Hasil supervisi akan muncul di sini setelah supervisor menilai.</p></div><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-head"><div><h2>Predikat</h2><p>Sebaran predikat supervisi Anda.</p></div></div>
        <?php if ($rows): ?><div class="chart-box tall"><canvas id="chDonut"></canvas></div>
        <?php else: ?><div class="empty"><p class="sm">Belum ada data.</p></div><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Tren Nilai 8 Bulan Terakhir</h2><p>Perkembangan rata-rata nilai supervisi Anda.</p></div></div>
      <div class="chart-box"><canvas id="chTren"></canvas></div>
    </div>

    <div class="card">
      <div class="card-head"><div><h2>Riwayat Supervisi</h2><p>Seluruh hasil supervisi yang sudah final.</p></div></div>
      <?php if (!$rows): ?>
        <div class="empty"><?= ikon('doc', 34) ?><h3>Belum ada supervisi</h3><p>Anda belum memiliki hasil supervisi yang diselesaikan supervisor.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Tanggal</th><th>Jenis Supervisi</th><th>Supervisor</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
                <td><b><?= e((string) $r['instrumen']) ?></b><span class="tbl-sub">Periode <?= e((string) ($r['periode'] ?: '-')) ?></span></td>
                <td><?= e((string) ($r['supervisor'] ?: '-')) ?></td>
                <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
                <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?> &middot; <?= e(predikat_label((string) $r['predikat'])) ?></span></td>
                <td class="right"><a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>">Lihat</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
      window.VS_TH = <?= json_encode(thresholds(), JSON_UNESCAPED_UNICODE) ?>;
      document.addEventListener('DOMContentLoaded', function () {
        <?php if ($rows): ?>
        VS.radar('chRadar', <?= json_encode($radarLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($radarData, JSON_UNESCAPED_UNICODE) ?>);
        var dist = <?= json_encode($distArr, JSON_UNESCAPED_UNICODE) ?>;
        VS.donut('chDonut', dist.map(function (d) { return d.l; }), dist.map(function (d) { return d.v; }), dist.map(function (d) { return d.c; }));
        VS.line('chTren', <?= json_encode(array_keys($tren)) ?>,
          <?= json_encode(array_map(fn($v) => $v ? round(array_sum($v) / count($v), 2) : 0, array_values($tren))) ?>);
        <?php endif; ?>
      });
    </script>
    <?php
    page_bottom();
    exit;
}

// ---------- Dashboard admin sekolah (juga dipakai super admin dalam konteks sekolah) ----------
$totalGuru   = (int) val('SELECT COUNT(*) FROM guru WHERE aktif = 1 AND sekolah_id = ?', [$sid], 0);
$totalSuper  = (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "final" AND sekolah_id = ?', [$sid], 0);
$rataAll     = (float) val('SELECT AVG(nilai) FROM supervisi WHERE status = "final" AND sekolah_id = ?', [$sid], 0);
$totDraft    = (int) val('SELECT COUNT(*) FROM supervisi WHERE status = "draft" AND sekolah_id = ?', [$sid], 0);
$guruBelum   = (int) val('SELECT COUNT(*) FROM guru g WHERE g.aktif = 1 AND g.sekolah_id = ?
        AND NOT EXISTS (SELECT 1 FROM supervisi s WHERE s.guru_id = g.id AND s.status = "final" AND s.sekolah_id = g.sekolah_id)', [$sid], 0);

// Kartu per menu + grafik "Rata-rata Nilai per Menu Supervisi" milik SEKOLAH INI.
// Menu & butir memang global, tetapi angka supervisi WAJIB dibatasi sekolah_id —
// tanpa itu nilai sekolah lain ikut terhitung (pernah menggigit: nilai MA Sirojuth
// Tholibiin Mootilango muncul di dashboard MTs yang belum melakukan supervisi).
$perInstrumen = all('SELECT i.id, i.kode, i.nama, i.ikon, i.deskripsi,
        (SELECT COUNT(*) FROM supervisi s WHERE s.instrumen_id = i.id AND s.status = "final" AND s.sekolah_id = ?) AS jml,
        (SELECT AVG(s.nilai) FROM supervisi s WHERE s.instrumen_id = i.id AND s.status = "final" AND s.sekolah_id = ?) AS rata,
        (SELECT COUNT(DISTINCT s.guru_id) FROM supervisi s WHERE s.instrumen_id = i.id AND s.status = "final" AND s.sekolah_id = ?) AS jml_guru
    FROM instrumen i WHERE i.aktif = 1 ORDER BY i.urutan, i.id', [$sid, $sid, $sid]);

$distribusi = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach (all('SELECT predikat, COUNT(*) c FROM supervisi WHERE status = "final" AND sekolah_id = ? GROUP BY predikat', [$sid]) as $r) {
    if (isset($distribusi[$r['predikat']])) {
        $distribusi[$r['predikat']] = (int) $r['c'];
    }
}

$tren = [];
for ($i = 7; $i >= 0; $i--) {
    $tren[date('Y-m', strtotime("-$i month"))] = [];
}
foreach (all('SELECT substr(tanggal,1,7) b, nilai FROM supervisi WHERE status = "final" AND sekolah_id = ?', [$sid]) as $r) {
    if (isset($tren[$r['b']])) {
        $tren[$r['b']][] = (float) $r['nilai'];
    }
}

// Peringkat & rata-rata per mapel: selain guru milik sekolah ini, baris supervisinya
// juga dipastikan milik sekolah yang sama (s.sekolah_id = g.sekolah_id).
$perGuru = all('SELECT g.id, g.nama, AVG(s.nilai) rata, COUNT(s.id) jml
    FROM guru g JOIN supervisi s ON s.guru_id = g.id AND s.status = "final" AND s.sekolah_id = g.sekolah_id
    WHERE g.sekolah_id = ? GROUP BY g.id ORDER BY rata DESC LIMIT 10', [$sid]);

$perMapel = all('SELECT COALESCE(NULLIF(g.mapel, ""), "Belum diisi") mapel, AVG(s.nilai) rata, COUNT(s.id) jml
    FROM guru g JOIN supervisi s ON s.guru_id = g.id AND s.status = "final" AND s.sekolah_id = g.sekolah_id
    WHERE g.sekolah_id = ? GROUP BY mapel HAVING COUNT(s.id) > 0 ORDER BY rata DESC LIMIT 8', [$sid]);

$terbaru = all('SELECT s.*, g.nama AS guru, i.nama AS instrumen, i.kode
    FROM supervisi s JOIN guru g ON g.id = s.guru_id JOIN instrumen i ON i.id = s.instrumen_id
    WHERE s.sekolah_id = ? ORDER BY s.tanggal DESC, s.id DESC LIMIT 8', [$sid]);

$belumList = all('SELECT g.* FROM guru g WHERE g.aktif = 1 AND g.sekolah_id = ? AND NOT EXISTS
    (SELECT 1 FROM supervisi s WHERE s.guru_id = g.id AND s.status = "final" AND s.sekolah_id = g.sekolah_id) ORDER BY g.nama LIMIT 8', [$sid]);

$predColors = ['A' => '#10b981', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#dc2626'];
$distArr = [];
foreach (['A', 'B', 'C', 'D'] as $p) {
    if ($distribusi[$p] > 0) {
        $distArr[] = ['l' => $p . ' — ' . predikat_label($p), 'v' => $distribusi[$p], 'c' => $predColors[$p]];
    }
}

// Komentar/tindak lanjut pengawas untuk sekolah ini (permintaan pemilik: "terkirim ke akun kepala
// madrasah untuk dilihat hasil komentar atau tindak lanjut tersebut") — jumlah + isi terbaru.
$komentarJml = komentar_madrasah_jumlah($sid);
$komentarTerbaru = komentar_madrasah_terbaru($sid, 3);
// Hasil TINJAUAN PENGAWAS atas dokumen madrasah (disetujui / direvisi / ditolak + keterangan),
// supaya kepala sekolah/madrasah langsung tahu tindak lanjutnya.
$dokTinjau = dokumen_ditinjau_sekolah($sid, 3);
$dokTinjauJml = dokumen_ditinjau_jumlah($sid);

page_top('Dashboard', 'index');
?>
<div class="grid g4">
  <div class="card stat">
    <div class="stat-ic"><?= ikon('users', 20) ?></div>
    <div><div class="stat-num"><?= $totalGuru ?></div><div class="stat-lbl">Guru Aktif</div><div class="stat-hint"><?= $guruBelum ?> belum disupervisi</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic s"><?= ikon('check', 20) ?></div>
    <div><div class="stat-num"><?= $totalSuper ?></div><div class="stat-lbl">Supervisi Selesai</div><div class="stat-hint"><?= $totDraft ?> masih draf</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic g"><?= ikon('chart', 20) ?></div>
    <div><div class="stat-num"><?= r1($rataAll) ?></div><div class="stat-lbl">Rata-rata Nilai</div><div class="stat-hint">Dari <?= $totalSuper ?> supervisi</div></div>
  </div>
  <div class="card stat">
    <div class="stat-ic p"><?= ikon('star', 20) ?></div>
    <?php $dom = 'A'; $max = -1; foreach ($distribusi as $k => $v) { if ($v > $max) { $max = $v; $dom = $k; } } ?>
    <div>
      <div class="stat-num"><?= $max > 0 ? e($dom) : '-' ?></div>
      <div class="stat-lbl">Predikat Terbanyak</div>
      <div class="stat-hint"><?= $max > 0 ? e(predikat_label($dom)) . ' &middot; ' . (int) $max . ' supervisi' : 'Belum ada data' ?></div>
    </div>
  </div>
</div>

<div class="grid g4">
  <?php foreach ($perInstrumen as $i):
      $rata = $i['rata'] !== null ? (float) $i['rata'] : 0;
      $cakupan = $totalGuru > 0 ? round((int) $i['jml_guru'] / $totalGuru * 100) : 0;
      ?>
    <div class="card card-tight">
      <div class="flex" style="gap:10px;align-items:center;margin-bottom:10px">
        <span class="stat-ic" style="width:36px;height:36px;border-radius:11px"><?= ikon((string) $i['ikon'], 17) ?></span>
        <b class="sm" style="line-height:1.25"><?= e((string) $i['nama']) ?></b>
      </div>
      <div class="flex between" style="align-items:baseline">
        <span class="stat-num" style="font-size:1.35rem"><?= r1($rata) ?></span>
        <span class="pill pill-<?= $rata > 0 ? e(predikat_dari($rata)) : 'gray' ?>"><?= $rata > 0 ? e(predikat_dari($rata)) : 'Belum ada' ?></span>
      </div>
      <div class="bar-mini mt8"><i class="lv<?= $rata > 0 ? e(predikat_dari($rata)) : '' ?>" style="width:<?= min(100, max(0, $rata)) ?>%"></i></div>
      <div class="xs mut mt8"><?= (int) $i['jml'] ?> penilaian &middot; <?= $cakupan ?>% guru terjangkau</div>
      <div class="mt16 flex" style="gap:8px">
        <a class="btn btn-primary btn-sm" href="supervisi_form.php?kode=<?= e((string) $i['kode']) ?>"><?= ikon('plus', 15) ?> Nilai</a>
        <a class="btn btn-ghost btn-sm" href="supervisi.php?kode=<?= e((string) $i['kode']) ?>">Daftar</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head">
      <div><h2>Rata-rata Nilai per Menu Supervisi</h2><p>Perbandingan capaian empat jenis supervisi.</p></div>
      <span class="pill pill-gray">Skala 0-100</span>
    </div>
    <div class="chart-box tall"><canvas id="chInstrumen"></canvas></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Distribusi Predikat</h2><p>Sebaran mutu hasil supervisi.</p></div></div>
    <?php if ($distArr): ?><div class="chart-box tall"><canvas id="chDonut"></canvas></div>
    <?php else: ?><div class="empty"><p class="sm mut">Belum ada data.</p></div><?php endif; ?>
  </div>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><div><h2>Tren Rata-rata Nilai</h2><p>Delapan bulan terakhir.</p></div></div>
    <div class="chart-box"><canvas id="chTren"></canvas></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Rata-rata per Mata Pelajaran</h2><p>Delapan mata pelajaran teratas.</p></div></div>
    <?php if ($perMapel): ?><div class="chart-box"><canvas id="chMapel"></canvas></div>
    <?php else: ?><div class="empty"><p class="sm">Belum ada data.</p></div><?php endif; ?>
  </div>
</div>

<div class="grid g21">
  <div class="card">
    <div class="card-head"><div><h2>Peringkat Guru</h2><p>Berdasarkan rata-rata seluruh supervisi (nilai final).</p></div></div>
    <?php if ($perGuru): ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th style="width:44px">#</th><th>Guru</th><th class="ctr">Supervisi</th><th style="width:130px">Nilai rata-rata</th><th class="ctr">Predikat</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($perGuru as $ix => $g): ?>
            <tr>
              <td><b><?= $ix + 1 ?></b></td>
              <td><?= e((string) $g['nama']) ?></td>
              <td class="ctr"><?= (int) $g['jml'] ?></td>
              <td>
                <b><?= r1($g['rata']) ?></b>
                <div class="bar-mini mt8"><i class="lv<?= e(predikat_dari((float) $g['rata'])) ?>" style="width:<?= min(100, (float) $g['rata']) ?>%"></i></div>
              </td>
              <td class="ctr"><span class="pill pill-<?= e(predikat_dari((float) $g['rata'])) ?>"><?= e(predikat_dari((float) $g['rata'])) ?></span></td>
              <td class="right"><a class="btn btn-ghost btn-sm" href="laporan.php?guru=<?= (int) $g['id'] ?>">Rekap</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty"><?= ikon('chart', 34) ?><h3>Belum ada nilai</h3><p>Mulai lakukan supervisi untuk melihat statistik.</p>
        <a class="btn btn-primary" href="supervisi_form.php?kode=administrasi"><?= ikon('plus', 16) ?> Mulai Supervisi</a></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Perlu Ditindaklanjuti</h2><p>Guru yang belum pernah disupervisi.</p></div></div>
    <?php if ($belumList): ?>
      <div class="grid" style="gap:10px">
        <?php foreach ($belumList as $g): ?>
          <div class="flex between" style="background:rgba(255,255,255,.6);border:1px solid rgba(99,95,130,.16);border-radius:12px;padding:10px 12px">
            <div>
              <b class="sm"><?= e((string) $g['nama']) ?></b>
              <div class="xs mut"><?= e((string) ($g['mapel'] ?: '-')) ?> &middot; <?= e((string) ($g['jabatan'] ?: '-')) ?></div>
            </div>
            <a class="btn btn-ghost btn-sm" href="supervisi_form.php?kode=administrasi&guru=<?= (int) $g['id'] ?>">Nilai</a>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt16"><a class="btn btn-ghost btn-sm" href="guru.php?belum=1">Lihat semua (<?= $guruBelum ?>)</a></div>
    <?php else: ?>
      <div class="empty"><?= ikon('check', 34) ?><h3>Semua terjangkau</h3><p>Seluruh guru aktif sudah pernah disupervisi.</p></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h2>Supervisi Terbaru</h2><p>Delapan kegiatan supervisi paling akhir.</p></div>
    <a class="btn btn-ghost btn-sm" href="laporan.php"><?= ikon('report', 15) ?> Laporan lengkap</a>
  </div>
  <?php if ($terbaru): ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Tanggal</th><th>Guru</th><th>Jenis Supervisi</th><th class="ctr">Nilai</th><th class="ctr">Predikat</th><th class="ctr">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($terbaru as $r): ?>
          <tr>
            <td class="nowrap"><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
            <td><b><?= e((string) $r['guru']) ?></b></td>
            <td><?= e((string) $r['instrumen']) ?></td>
            <td class="ctr"><b><?= r1($r['nilai']) ?></b></td>
            <td class="ctr"><span class="pill pill-<?= e((string) $r['predikat']) ?>"><?= e((string) $r['predikat']) ?></span></td>
            <td class="ctr"><span class="pill <?= $r['status'] === 'final' ? 'pill-ok' : 'pill-warn' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draf' ?></span></td>
            <td class="right"><a class="btn btn-ghost btn-sm" href="supervisi_detail.php?id=<?= (int) $r['id'] ?>">Lihat</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty"><?= ikon('doc', 34) ?><h3>Belum ada supervisi</h3><p>Mulai dari salah satu menu supervisi di sidebar.</p></div>
  <?php endif; ?>
</div>

<?php if ($komentarJml > 0): ?>
  <div class="card" id="cardKomentarPengawas">
    <div class="card-head">
      <div>
        <h2><?= ikon('message', 18) ?> Komentar &amp; Tindak Lanjut dari Pengawas</h2>
        <p>Catatan pengawas untuk seluruh hasil rekapan supervisi sekolah/madrasah ini.</p>
      </div>
      <div class="flex">
        <span class="chip"><?= (int) $komentarJml ?> komentar</span>
        <a class="btn btn-ghost btn-sm" href="laporan.php#cardKomentar"><?= ikon('report', 14) ?> Lihat di Laporan</a>
      </div>
    </div>
    <div class="komentar-list">
      <?php foreach ($komentarTerbaru as $k): ?>
        <div class="komentar-item">
          <div class="komentar-head">
            <b><?= e((string) ($k['pengawas'] ?: 'Pengawas')) ?></b>
            <span class="pill pill-gray">Pengawas</span>
            <span class="xs mut"><?= e(waktu_id_polos((string) ($k['diubah'] ?: $k['dibuat']))) ?></span>
          </div>
          <p class="komentar-isi"><?= nl2br(e((string) $k['isi'])) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($komentarJml > count($komentarTerbaru)): ?>
      <p class="hint" style="margin-top:12px">Menampilkan <?= count($komentarTerbaru) ?> komentar terbaru
        dari <?= (int) $komentarJml ?> komentar pengawas.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (($u['role'] === 'admin' || is_super()) && $dokTinjauJml > 0): ?>
  <div class="card" id="cardTinjauanDokumen">
    <div class="card-head">
      <div>
        <h2><?= ikon('doc', 18) ?> Hasil Tinjauan Dokumen oleh Pengawas</h2>
        <p>Status dokumen yang Anda kirim: disetujui, direvisi, atau ditolak — beserta keterangan/alasannya.</p>
      </div>
      <div class="flex">
        <span class="chip"><?= (int) $dokTinjauJml ?> dokumen ditinjau</span>
        <a class="btn btn-ghost btn-sm" href="dokumen_kirim.php#cardHasilTinjauan"><?= ikon('doc', 14) ?> Buka Daftar Dokumen</a>
      </div>
    </div>
    <div class="komentar-list">
      <?php foreach ($dokTinjau as $dt): $st = (string) $dt['status']; ?>
        <div class="komentar-item">
          <div class="komentar-head">
            <b><?= e((string) $dt['nama_dokumen']) ?></b>
            <span class="pill <?= dokumen_status_pil($st) ?>">
              <?= ikon(dokumen_status_ikon($st), 12) ?> <?= e(dokumen_status_label($st)) ?></span>
            <span class="xs mut">oleh <?= e((string) ($dt['peninjau'] ?: 'Pengawas')) ?>,
              <?= e(waktu_id_polos((string) $dt['ditinjau_pada'])) ?></span>
          </div>
          <p class="komentar-isi"><?= trim((string) ($dt['catatan'] ?? '')) !== ''
            ? nl2br(e((string) $dt['catatan']))
            : '<span class="mut">Tanpa keterangan tambahan dari pengawas.</span>' ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($u['role'] === 'admin'): ?>
  <?php
  // PROGRES PENGIRIMAN DOKUMEN (permintaan pemilik): "Tambahkan fitur progress pengiriman dokumen di
  // akun pengawas dan kepala sekolah. Progres dilihat dari jumlah nama dokumen yang diisi lewat
  // super admin." → penyebutnya jumlah Nama Dokumen AKTIF pada menu Jenis Dokumen (Global).
  $progDok = dokumen_progress($sid, dokumen_ta_berlaku('', $sekolah));
  ?>
  <?php if (!$progDok['jenis_kosong']): ?>
    <div class="card card-tight no-print" id="cardProgressDokumenDashboard">
      <div class="card-head">
        <div>
          <h3><?= ikon('chart', 16) ?> Progres Pengiriman Dokumen</h3>
          <p class="sm mut" style="margin:4px 0 0">
            <?php if ($progDok['ta'] !== ''): ?>Tahun Ajaran <?= e($progDok['ta']) ?> &middot; <?php endif; ?>
            <b><?= (int) $progDok['terkirim'] ?></b> dari <b><?= (int) $progDok['total'] ?></b> nama
            dokumen sudah dikirim ke pengawas.</p>
        </div>
        <div class="flex" style="gap:8px;align-items:center">
          <span class="pill <?= $progDok['persen'] >= 80 ? 'pill-ok' : ($progDok['persen'] >= 40 ? 'pill-warn' : 'pill-err') ?>">
            <?= (int) $progDok['persen'] ?>%</span>
          <a class="btn btn-primary btn-sm" href="dokumen_kirim.php#cardProgressDokumen">
            <?= ikon('upload', 15) ?> Buka Kirim Dokumen</a>
        </div>
      </div>
      <div class="progress-track" role="img" aria-label="Progres pengiriman dokumen <?= (int) $progDok['persen'] ?> persen">
        <i style="width:<?= (int) $progDok['persen'] ?>%"></i>
      </div>
      <div class="progress-daftar mt16">
        <?php foreach ($progDok['daftar'] as $pj): ?>
          <div class="progress-item <?= $pj['terkirim'] ? 'ada' : 'belum' ?>">
            <?= ikon($pj['terkirim'] ? 'check' : 'clock', 14) ?>
            <span class="progress-nama"><?= e($pj['nama']) ?></span>
            <?php if ($pj['terkirim']): ?>
              <span class="pill pill-ok"><?= (int) $pj['jumlah'] ?> berkas</span>
            <?php else: ?>
              <span class="pill pill-warn">belum dikirim</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ((int) $progDok['belum'] > 0): ?>
        <p class="hint">Masih ada <?= (int) $progDok['belum'] ?> nama dokumen yang belum dikirim —
          kirim lewat menu <b>Kirim Dokumen ke Pengawas</b>.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card card-tight no-print" id="cardKirimDokumenInfo">
      <div class="flex between">
        <div>
          <h3><?= ikon('send', 16) ?> Kirim Dokumen ke Pengawas</h3>
          <p class="sm mut" style="margin:4px 0 0">Daftar <b>Nama Dokumen</b> belum diisi Super Admin,
            jadi progres belum dapat dihitung. Dokumen tetap dapat dikirim lewat menu di samping.</p>
        </div>
        <a class="btn btn-primary btn-sm" href="dokumen_kirim.php"><?= ikon('upload', 15) ?> Buka Kirim Dokumen</a>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
<script src="assets/vendor/chart.umd.min.js"></script>
<script>
window.VS_TH = <?= json_encode(thresholds(), JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', function () {
  var inst = <?= json_encode(array_map(fn($i) => ['k' => $i['nama'], 'v' => $i['rata'] !== null ? round((float) $i['rata'], 2) : 0], $perInstrumen), JSON_UNESCAPED_UNICODE) ?>;
  VS.bar('chInstrumen', inst.map(function (d) { return d.k; }), inst.map(function (d) { return d.v; }), false);

  var dist = <?= json_encode($distArr, JSON_UNESCAPED_UNICODE) ?>;
  if (dist.length) {
    VS.donut('chDonut', dist.map(function (d) { return d.l; }), dist.map(function (d) { return d.v; }), dist.map(function (d) { return d.c; }));
  }
  VS.line('chTren', <?= json_encode(array_keys($tren)) ?>,
    <?= json_encode(array_map(fn($v) => $v ? round(array_sum($v) / count($v), 2) : 0, array_values($tren))) ?>);
  <?php if ($perMapel): ?>
  VS.bar('chMapel', <?= json_encode(array_map(fn($m) => $m['mapel'], $perMapel), JSON_UNESCAPED_UNICODE) ?>,
    <?= json_encode(array_map(fn($m) => round((float) $m['rata'], 2), $perMapel)) ?>, true);
  <?php endif; ?>
});
</script>
<?php
page_bottom();
