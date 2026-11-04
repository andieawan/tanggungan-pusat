<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Batas akses: "kasir" (admin, bendahara: mencatat pembayaran & mengatur tarif), "lihat" (kasir + kepsek: semua data),
 * "admin". Wali kelas hanya melihat kelasnya lewat aturan di App\Services\Akses.
 */
class HanyaPeran
{
    public function handle(Request $request, Closure $next, string $level)
    {
        $u = $request->user();
        $ok = $u && match ($level) {
            'admin' => $u->isAdmin(),
            'kasir' => $u->isKasir(),
            'lihat' => $u->lihatSemua(),
            default => false,
        };
        abort_unless($ok, 403, 'Anda tidak memiliki akses ke bagian ini.');

        return $next($request);
    }
}
