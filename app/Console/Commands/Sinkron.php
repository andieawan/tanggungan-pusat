<?php

namespace App\Console\Commands;

use App\Services\PusatKlien;
use App\Services\PusatSync;
use Illuminate\Console\Command;

class Sinkron extends Command
{
    protected $signature = 'tanggungan:sinkron';

    protected $description = 'Salin kelas, siswa & pegawai dari Pusat sekarang';

    public function handle(): int
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            $this->error('Integrasi Pusat belum aktif.');

            return self::FAILURE;
        }
        try {
            $h = app(PusatSync::class)->jalankan();
        } catch (\Throwable $e) {
            $this->error('Gagal, tidak ada data yang berubah: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(json_encode($h, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
