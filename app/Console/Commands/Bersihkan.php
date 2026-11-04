<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Bersihkan extends Command
{
    protected $signature = 'tanggungan:bersihkan';

    protected $description = 'Hapus catatan webhook lama (> 7 hari) dan audit > 5 tahun';

    public function handle(): int
    {
        $w = DB::table('pusat_webhook')->where('created_at', '<', now('UTC')->subDays(7)->format('Y-m-d H:i:s'))->delete();
        $a = DB::table('audit_log')->where('waktu', '<', now('UTC')->subYears(5)->format('Y-m-d H:i:s'))->delete();
        $this->info("webhook: $w, audit: $a");

        return self::SUCCESS;
    }
}
