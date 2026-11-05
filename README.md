# Tanggungan Siswa — SMKS Islam Bustanul Ulum

Aplikasi mandiri untuk tanggungan keuangan siswa: **daftar ulang** (boleh diangsur), **iuran syariah** bulanan, dan
**jenis tagihan lain** yang ditambah bendahara (ujian, PKL, wisuda, seragam, …; tahunan atau bulanan).
Bendahara mencatat pembayaran dan mencetak kuitansi; kepala sekolah melihat semua; wali kelas melihat kelasnya.
Rekap per kelas berbentuk seperti lembar Excel lama dan bisa diunduh. Data lama bisa diimpor dari templat Excel per kelas.

Siswa, kelas, dan guru disalin dari Pusat Sekolah (login SSO). Siswa yang lulus atau keluar **tidak pernah dihapus**,
sehingga riwayat tanggungannya tetap tersimpan.

Pemasangan: [docs/PASANG-TANGGUNGAN.md](docs/PASANG-TANGGUNGAN.md). Laravel 13, PHP 8.3+, MariaDB.
