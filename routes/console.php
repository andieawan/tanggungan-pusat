<?php

use Illuminate\Support\Facades\Schedule;

// Cron wajib di VM: * * * * * cd /var/www/tanggungan && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('tanggungan:bersihkan')->dailyAt('02:30');
// Sinkron penuh tiap jam bila dihidupkan, atau bila webhook menandai ada data yang perlu dilengkapi (kelas belum dikenal).
Schedule::command('tanggungan:sinkron')->hourly()->withoutOverlapping(30)->when(function () {
    try {
        return (bool) config('pusat.sinkron_otomatis') || \App\Services\PusatKlien::baca('perlu_sinkron') !== null;
    } catch (\Throwable) {
        return false;
    }
});
// Belum pernah sinkron (baru dipasang): sinkron pertama berjalan otomatis tiap 15 menit sampai berhasil,
// agar daftar kelas/siswa/guru tidak kosong walau admin lupa menekan "Sinkron sekarang".
Schedule::command('tanggungan:sinkron')->everyFifteenMinutes()->withoutOverlapping(30)->when(function () {
    try {
        return \App\Services\PusatKlien::aktif() && \App\Services\PusatKlien::siap() && \App\Services\PusatKlien::baca('sinkron_terakhir') === null;
    } catch (\Throwable) {
        return false;
    }
});
