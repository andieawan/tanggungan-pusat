<?php

use App\Exceptions\UserError;
use App\Http\Middleware\AkunAktif;
use App\Http\Middleware\HanyaPeran;
use App\Http\Middleware\HeaderKeamanan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy tepercaya (TRUSTED_PROXIES) dipasang di AppServiceProvider dari config: callback ini berjalan
        // sebelum .env dimuat, sehingga env() di sini selalu kosong.
        $middleware->alias(['akun.aktif' => AkunAktif::class, 'peran' => HanyaPeran::class]);
        $middleware->validateCsrfTokens(except: ['api/pusat/webhook']); // dipanggil server Pusat; diamankan tanda tangan HMAC
        $middleware->appendToGroup('web', HeaderKeamanan::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('beranda'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e) => $request->is('api/*') || $request->expectsJson());
        // Pelanggaran aturan bisnis → kembali ke halaman sebelumnya dengan pesan error.
        $exceptions->render(function (UserError $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withInput($request->except(['password', 'berkas']))->with('error', $e->getMessage());
        });
        $exceptions->dontReport(UserError::class);
    })->create();
