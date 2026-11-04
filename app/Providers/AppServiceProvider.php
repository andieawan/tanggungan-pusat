<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Folder kerja wajib: bila terhapus/tidak ikut terunggah, buat otomatis.
        foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private', 'app/public'] as $d) {
            $path = storage_path($d);
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    public function boot(): void
    {
        // Sambungan antar-aplikasi dari Pusat (Administrasi → Sambungan) menimpa nilai .env.
        \App\Services\Sambungan::terapkan();
        \App\Services\ProfilPusat::terapkan(); // kop surat & kepala sekolah dari Profil Sekolah di Pusat

        // Proxy tepercaya dari config (lihat config/app.php: trusted_proxies).
        if ($proxi = trim((string) config('app.trusted_proxies'))) {
            \Illuminate\Http\Middleware\TrustProxies::at($proxi === '*' ? '*' : array_map('trim', explode(',', $proxi)));
        }
        // Alamat aset/tautan selalu https bila APP_URL https (produksi), walau header proxy tidak terbaca.
        // Matikan dengan APP_FORCE_HTTPS=false (mis. uji lewat http://IP-VM).
        $paksa = config('app.force_https');
        if ($paksa === null ? (app()->environment('production') && str_starts_with((string) config('app.url'), 'https://')) : filter_var($paksa, FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
