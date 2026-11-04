<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function catat(string $aksi, ?string $pelaku = null, string $rincian = ''): void
    {
        AuditLog::create([
            'waktu' => now('UTC')->format('Y-m-d H:i:s'), 'aksi' => $aksi,
            'pelaku' => $pelaku ?? (auth()->user()?->nama ?? 'Sistem'), 'rincian' => mb_substr($rincian, 0, 1000),
        ]);
        $simpan = AuditLog::orderByDesc('id')->skip(19999)->value('id');
        if ($simpan) {
            AuditLog::where('id', '<', $simpan)->delete(); // simpan 20.000 log terbaru (catatan keuangan)
        }
    }
}
