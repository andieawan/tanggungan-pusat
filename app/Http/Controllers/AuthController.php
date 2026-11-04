<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\PusatKlien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Login: utama lewat Pusat (SSO); admin lokal darurat dengan password (dibuat via `php artisan tanggungan:buat-admin`). */
class AuthController extends Controller
{
    public function form()
    {
        return Auth::check() ? redirect()->route('beranda') : view('auth.login');
    }

    public function login(Request $request)
    {
        abort_unless(config('tanggungan.login_lokal'), 404);
        $data = $request->validate(['username' => 'required|string|max:191', 'password' => 'required|string|max:255']);
        $username = strtolower(trim($data['username']));
        $key = 'login:'.sha1($request->ip().'|'.$username);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan login gagal. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.']);
        }
        $user = User::where('username', $username)->first();
        // Hash::check selalu dijalankan (waktu respons seragam); akun tanpa password (akun Pusat) tidak bisa masuk di sini.
        $lokal = Hash::check($data['password'], $user?->password ?: Hash::make(bin2hex(random_bytes(8)))) && $user && $user->password;
        $ok = ($lokal || \App\Services\PusatAkun::cocokSandiPusat($user, $data['password'])) && $user && $user->aktif; // akun Pusat: login cadangan
        if (! $ok) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['username' => 'Username atau password salah, atau akun nonaktif.']);
        }
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        Audit::catat('Login', $user->nama, 'Login lokal');

        return redirect()->intended(route('beranda'));
    }

    public function logout(Request $request)
    {
        $viaPusat = (bool) $request->session()->get('via_pusat');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($viaPusat && PusatKlien::aktif()) {
            // Keluar juga dari Pusat (mencabut token), lalu kembali ke halaman login.
            return redirect()->away(config('pusat.url').'/sso/logout?'.http_build_query([
                'client_id' => config('pusat.client_id'),
                'post_logout_redirect_uri' => route('login'),
            ]));
        }

        return redirect()->route('login')->with('success', 'Anda sudah keluar.');
    }
}
