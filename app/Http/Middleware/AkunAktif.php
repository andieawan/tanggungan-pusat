<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Akun yang dinonaktifkan (mis. akses dicabut di Pusat) langsung keluar pada request berikutnya. */
class AkunAktif
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && ! Auth::user()->aktif) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Akun Anda dinonaktifkan. Hubungi administrator.');
        }

        return $next($request);
    }
}
