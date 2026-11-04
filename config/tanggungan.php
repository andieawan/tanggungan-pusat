<?php

// Konfigurasi aplikasi Tanggungan Siswa (daftar ulang & iuran syariah).
return [
    'school_name' => env('SCHOOL_NAME', 'SMK Islam Bustanul Ulum Pakusari'),
    'timezone' => env('TANGGUNGAN_TIMEZONE', 'Asia/Jakarta'),

    // Login username+password hanya untuk admin lokal darurat (saat Pusat mati). Warga sekolah masuk lewat Pusat.
    'login_lokal' => (bool) env('TANGGUNGAN_LOGIN_LOKAL', true),

    // Awalan nomor kuitansi: KW-2026-00001
    'awalan_kuitansi' => env('KUITANSI_AWALAN', 'KW'),
    // Nama bendahara di kuitansi bila akun pencatat tidak punya nama lengkap.
    'bendahara' => env('BENDAHARA_NAMA', ''),
    // Tarif bawaan saat tahun ajaran baru belum diatur (bisa diubah di menu Tarif).
    'syariah_bawaan' => (int) env('SYARIAH_PER_BULAN', 30000),

    // Identitas pada kop (sama dengan BK/TU/PKL; ditimpa profil sekolah dari Pusat)
    'surat' => [
        'yayasan' => env('SURAT_YAYASAN', 'YAYASAN PENDIDIKAN ISLAM'),
        'yayasan2' => env('SURAT_YAYASAN2', '“ BUSTANUL ULUM ”'),
        'nama' => env('SURAT_NAMA', 'SMK ISLAM BUSTANUL ULUM PAKUSARI'),
        'nss' => env('SURAT_NSS', '342052423288'),
        'npsn' => env('SURAT_NPSN', '20570966'),
        'bidang' => env('SURAT_BIDANG', 'Kelompok Bisnis Manajemen dan Teknologi Informasi Komunikasi'),
        'alamat' => env('SURAT_ALAMAT', 'Jl. Himalaya No. 17 Telp. (0331) 7255753 Kode Pos. 68181 Pakusari – Jember'),
        'kota' => env('SURAT_KOTA', 'Pakusari'),
        'kepsek' => env('SURAT_KEPSEK', 'MUHAMMAD MUSLIM, S.Pd., Gr'),
        'kepsek_nuptk' => env('SURAT_KEPSEK_NUPTK', '7951767668130072'),
    ],
];
