<?php

use App\Http\Controllers\Admin\IntegrasiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\ImporController;
use App\Http\Controllers\JenisController;
use App\Http\Controllers\KuitansiController;
use App\Http\Controllers\PusatSsoController;
use App\Http\Controllers\PusatWebhookController;
use App\Http\Controllers\RekapController;
use App\Http\Controllers\SiswaController;
use App\Http\Controllers\TarifController;
use Illuminate\Support\Facades\Route;

Route::get('/logo-sekolah', \App\Http\Controllers\LogoController::class)->middleware('throttle:120,1')->name('logo.sekolah');

// Penerima webhook dari Pusat Sekolah (tanda tangan HMAC; tanpa CSRF — lihat bootstrap/app.php).
Route::post('/api/pusat/webhook', PusatWebhookController::class)->middleware('throttle:120,1');

// Login: warga sekolah lewat Pusat (SSO); admin darurat dengan username + password.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::get('/sso/login', [PusatSsoController::class, 'mulai'])->middleware('throttle:30,1')->name('sso.login');
Route::get('/sso/callback', [PusatSsoController::class, 'callback'])->middleware('throttle:30,1')->name('sso.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'akun.aktif'])->group(function () {
    Route::get('/', BerandaController::class)->name('beranda');

    // Siswa & kartu tanggungan (wali kelas: siswa kelasnya; lainnya: semua)
    Route::get('/siswa', [SiswaController::class, 'index'])->name('siswa.index');
    Route::get('/siswa/{siswa}', [SiswaController::class, 'show'])->whereNumber('siswa')->name('siswa.show');
    Route::get('/rekap', [RekapController::class, 'index'])->name('rekap.index');
    Route::get('/rekap/unduh', [RekapController::class, 'unduh'])->name('rekap.unduh');

    Route::middleware('peran:lihat')->group(function () {
        Route::get('/kuitansi', [KuitansiController::class, 'index'])->name('kuitansi.index');
        Route::get('/kuitansi/unduh', [KuitansiController::class, 'unduh'])->name('kuitansi.unduh');
        Route::get('/kuitansi/{pembayaran}', [KuitansiController::class, 'show'])->whereNumber('pembayaran')->name('kuitansi.show');
    });

    // Bendahara (dan admin): mencatat pembayaran, keringanan, tarif, impor data lama
    Route::middleware('peran:kasir')->group(function () {
        Route::post('/siswa/{siswa}/bayar', [SiswaController::class, 'bayar'])->whereNumber('siswa')->middleware('throttle:60,1')->name('siswa.bayar');
        Route::post('/siswa/{siswa}/tagihan', [SiswaController::class, 'buatTagihan'])->whereNumber('siswa')->name('siswa.tagihan');
        Route::put('/tagihan/{tagihan}/keringanan', [SiswaController::class, 'keringanan'])->whereNumber('tagihan')->name('siswa.keringanan');
        Route::post('/kuitansi/{pembayaran}/batal', [KuitansiController::class, 'batal'])->whereNumber('pembayaran')->name('kuitansi.batal');

        Route::get('/tarif', [TarifController::class, 'index'])->name('tarif.index');
        Route::put('/tarif', [TarifController::class, 'simpan'])->name('tarif.simpan');
        Route::post('/tarif/terapkan', [TarifController::class, 'terapkan'])->middleware('throttle:10,1')->name('tarif.terapkan');
        Route::get('/jenis-tagihan', [JenisController::class, 'index'])->name('jenis.index');
        Route::post('/jenis-tagihan', [JenisController::class, 'simpan'])->name('jenis.simpan');
        Route::put('/jenis-tagihan/{jenis}', [JenisController::class, 'ubah'])->whereNumber('jenis')->name('jenis.ubah');

        Route::get('/impor', [ImporController::class, 'index'])->name('impor.index');
        Route::get('/impor/templat', [ImporController::class, 'templat'])->name('impor.templat');
        Route::post('/impor', [ImporController::class, 'unggah'])->middleware('throttle:10,1')->name('impor.unggah');
    });

    Route::middleware('peran:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/integrasi', [IntegrasiController::class, 'index'])->name('integrasi');
        Route::post('/integrasi/uji-pusat', [IntegrasiController::class, 'ujiPusat'])->middleware('throttle:10,1')->name('integrasi.pusat');
        Route::post('/integrasi/sinkron', [IntegrasiController::class, 'sinkron'])->middleware('throttle:5,1')->name('integrasi.sinkron');
    });
});
