<?php

// Penyambungan ke aplikasi Pusat Sekolah (login tunggal + data siswa/kelas). Mati bila PUSAT_ENABLED=false.
return [
    'enabled' => (bool) env('PUSAT_ENABLED', false),
    'url' => (fn (string $u) => $u === '' || preg_match('#^https?://#i', $u) ? $u : 'https://'.$u)(rtrim(trim((string) env('PUSAT_URL', ''), " \t\"'"), '/')),
    'client_id' => env('PUSAT_CLIENT_ID', ''),
    'client_secret' => env('PUSAT_CLIENT_SECRET', ''),
    'webhook_secret' => env('PUSAT_WEBHOOK_SECRET', ''),
    'timeout' => (int) env('PUSAT_TIMEOUT', 15),

    // Pemeriksaan sertifikat HTTPS Pusat. Hanya untuk uji lokal dengan sertifikat buatan sendiri:
    // PUSAT_VERIFY_SSL=false mematikannya (JANGAN di server sungguhan). Lebih baik: PUSAT_CA_BUNDLE=path
    // berkas .pem berisi CA lokal Anda (mis. rootCA.pem dari mkcert/envkit), pemeriksaan tetap aktif.
    'verify_ssl' => filter_var(env('PUSAT_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    'ca_bundle' => env('PUSAT_CA_BUNDLE'),

    // Sinkron kelas, siswa & pegawai otomatis tiap jam (selain webhook). Bawaan mati; tombol Admin → Integrasi selalu ada.
    'sinkron_otomatis' => (bool) env('PUSAT_SINKRON_OTOMATIS', false),

    // Peran di Pusat -> peran lokal Tanggungan. Bendahara mencatat pembayaran; kepsek & wali kelas hanya melihat.
    'peran_peta' => [
        'admin' => 'admin',
        'bendahara' => 'bendahara',
        'kepsek' => 'kepsek',
        'wali_kelas' => 'wali',
    ],
];
