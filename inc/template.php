<?php
/**
 * Template bawaan: instrumen + butir penilaian yang disalin ke setiap sekolah baru.
 * Setiap sekolah menyimpan salinannya sendiri sehingga bebas disesuaikan.
 */
declare(strict_types=1);

function template_instrumen(): array
{
    return [
        ['administrasi', 'Supervisi Administrasi', 'Kelengkapan administrasi pembelajaran dan perangkat pendukung mengajar.', 'folder', 1],
        ['modul_ajar',   'Supervisi Perencanaan Modul Ajar', 'Kualitas perencanaan pembelajaran: modul ajar/RPP, tujuan, langkah, dan asesmen.', 'doc', 2],
        ['pengamatan',   'Supervisi Pengamatan Pembelajaran', 'Observasi pelaksanaan pembelajaran di kelas secara langsung.', 'eye', 3],
        ['penilaian',    'Supervisi Penilaian', 'Perencanaan, pelaksanaan, pengolahan, dan pelaporan hasil penilaian.', 'chart', 4],
    ];
}

function template_butir(): array
{
    return [
        'administrasi' => [
            ['Kalender Pendidikan', 'Ketersediaan dan kesesuaian kalender pendidikan dengan kalender dinas'],
            ['Program Tahunan (Prota)', 'Prota memuat alokasi waktu, materi, dan kompetensi satu tahun ajaran'],
            ['Program Semester (Promes)', 'Promes dijabarkan dari prota dan sesuai kalender pendidikan'],
            ['Silabus / ATP', 'Silabus atau alur tujuan pembelajaran tersusun runtut dan mutakhir'],
            ['Jadwal Pelajaran & Jurnal Mengajar', 'Jadwal resmi dan jurnal harian terisi sesuai pelaksanaan'],
            ['Daftar Hadir Peserta Didik', 'Absensi terisi setiap pertemuan dan terdata rapi'],
            ['Buku/Daftar Nilai', 'Daftar nilai lengkap, terisi, dan mudah ditelusuri'],
            ['Analisis Hasil Penilaian', 'Analisis butir/nilai tersedia sebagai dasar tindak lanjut'],
            ['Program Remedial & Pengayaan', 'Program perbaikan dan pengayaan tersusun serta terdokumentasi'],
            ['Buku Pegangan & Bahan Ajar', 'Referensi/bahan ajar sesuai mapel dan sesuai jenjang'],
            ['Administrasi Bimbingan & Buku Kasus', 'Catatan bimbingan/kasus peserta didik teradministrasi'],
            ['Kerapian & Kemutakhiran Administrasi', 'Dokumen rapi, terarsip, dan diperbarui sesuai kebutuhan'],
        ],
        'modul_ajar' => [
            ['Identitas Modul', 'Identitas modul lengkap: mapel, kelas/fase, alokasi waktu, penyusun'],
            ['Kompetensi Awal & Capaian Pembelajaran', 'CP dan kompetensi awal dirumuskan sesuai fase'],
            ['Rumusan Tujuan Pembelajaran', 'Tujuan pembelajaran terukur, spesifik, dan sesuai CP'],
            ['Profil Pelajar Pancasila', 'Dimensi profil pelajar dipilih relevan dengan materi'],
            ['Sarana, Prasarana & Media', 'Media dan sumber belajar sesuai tujuan dan kondisi sekolah'],
            ['Target Peserta Didik', 'Mengakomodasi peserta didik reguler, kesulitan belajar, dan cerdas istimewa'],
            ['Model & Metode Pembelajaran', 'Model/metode dipilih tepat dan mendukung keterlibatan aktif'],
            ['Kegiatan Pendahuluan', 'Apersepsi, motivasi, dan penyampaian tujuan tertulis jelas'],
            ['Kegiatan Inti', 'Sintaks model pembelajaran dan diferensiasi tergambar rinci'],
            ['Kegiatan Penutup', 'Refleksi, simpulan, dan tindak lanjut tertulis jelas'],
            ['Rancangan Asesmen', 'Asesmen diagnostik, formatif, dan sumatif direncanakan'],
            ['Kriteria Ketercapaian Tujuan (KKTP)', 'Rubrik/indikator ketercapaian disusun terukur'],
            ['Lampiran Pendukung', 'LKPD, bahan bacaan, glosarium, dan daftar pustaka tersedia'],
            ['Kesesuaian Alokasi Waktu', 'Pembagian waktu tiap kegiatan proporsional dan realistis'],
        ],
        'pengamatan' => [
            ['Kegiatan Pendahuluan', 'Salam, doa, presensi, dan penyiapan kesiapan belajar peserta didik'],
            ['Apersepsi & Tujuan Pembelajaran', 'Mengaitkan materi sebelumnya dan menyampaikan tujuan'],
            ['Penguasaan Materi', 'Guru menguasai materi, konsep benar, dan menjawab pertanyaan dengan tepat'],
            ['Penggunaan Media & Teknologi', 'Media/alat bantu/TPACK digunakan efektif mendukung materi'],
            ['Penerapan Model Pembelajaran', 'Sintaks model pembelajaran terlihat dan dijalankan konsisten'],
            ['Pembelajaran Diferensiasi', 'Guru menyesuaikan tugas/dukungan sesuai kebutuhan peserta didik'],
            ['Interaksi & Umpan Balik', 'Tanya jawab, umpan balik, dan penguatan diberikan secara merata'],
            ['Keaktifan Peserta Didik', 'Peserta didik aktif bertanya, berdiskusi, dan mengerjakan tugas'],
            ['Pengelolaan Kelas', 'Kelas terkelola kondusif, disiplin, dan tertib'],
            ['Penilaian Proses di Kelas', 'Guru melakukan penilaian formatif/observasi selama pembelajaran'],
            ['Penguatan Karakter', 'Menanamkan nilai karakter dan dimensi profil pelajar'],
            ['Komunikasi & Penggunaan Bahasa', 'Bahasa jelas, santun, komunikatif, dan mudah dipahami'],
            ['Kegiatan Penutup', 'Refleksi, simpulan, dan penugasan/tindak lanjut dilaksanakan'],
            ['Efektivitas Penggunaan Waktu', 'Waktu pembelajaran digunakan efisien dari awal hingga akhir'],
        ],
        'penilaian' => [
            ['Kisi-Kisi Penilaian', 'Kisi-kisi tersedia dan selaras dengan tujuan pembelajaran'],
            ['Instrumen Penilaian', 'Instrumen sesuai tujuan, tingkat kognitif, dan karakteristik materi'],
            ['Validitas & Kejelasan Instrumen', 'Rumusan soal/tugas jelas, tidak ambigu, dan tidak menyesatkan'],
            ['Keberagaman Bentuk Penilaian', 'Tes, non-tes, praktik, proyek, dan portofolio digunakan variatif'],
            ['Penilaian Sikap', 'Teknik penilaian sikap spiritual dan sosial dilaksanakan'],
            ['Penilaian Pengetahuan', 'Teknik penilaian pengetahuan beragam dan terukur'],
            ['Penilaian Keterampilan', 'Penilaian kinerja/produk/proyek dengan rubrik jelas'],
            ['Bobot & Skor Penilaian', 'Pembobotan dan skor konsisten serta dapat dipertanggungjawabkan'],
            ['Pengolahan Nilai', 'Rumus pengolahan nilai jelas dan hasilnya akuntabel'],
            ['Analisis Hasil Penilaian', 'Dilakukan analisis untuk mengetahui capaian dan kesulitan belajar'],
            ['Program Tindak Lanjut', 'Remedial dan pengayaan dilaksanakan berdasar hasil analisis'],
            ['Pelaporan Hasil Belajar', 'Leger/rapor/e-rapor terisi tepat waktu dan akurat'],
            ['Pemanfaatan Hasil Penilaian', 'Hasil penilaian dipakai memperbaiki perencanaan dan pembelajaran'],
            ['Dokumentasi & Arsip Penilaian', 'Dokumen penilaian terarsip rapi dan dapat diverifikasi'],
        ],
    ];
}

/**
 * Menu & butir penilaian kini GLOBAL — dipakai bersama oleh semua sekolah/madrasah dan
 * hanya dikelola Super Admin (lihat instrumen.php & instrumen_butir.php).
 *
 * Fungsi ini dipertahankan sebagai NO-OP supaya pemanggilan lama tidak perlu dihapus satu per satu,
 * dan selalu mengembalikan 0 (tidak ada salinan yang dibuat untuk sekolah baru).
 */
function provision_sekolah(PDO $pdo, int $sekolahId): int
{
    return 0;
}
