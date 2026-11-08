<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Akses HTTP ke Pusat (server-ke-server, Basic client_id:client_secret). */
class PusatKlien
{
    public static function aktif(): bool
    {
        return config('pusat.enabled') && config('pusat.url') !== '' && config('pusat.client_id') !== '' && config('pusat.client_secret') !== '';
    }

    /** Migrasi sudah dijalankan. */
    public static function siap(): bool
    {
        return Schema::hasColumn('users', 'pusat_uuid') && Schema::hasTable('pusat_state');
    }

    /** Cek cepat Pusat hidup (dipakai sebelum mengarahkan ke login Pusat). */
    public static function hidup(): bool
    {
        try {
            return self::http()->timeout(4)->connectTimeout(3)->get('/api/v1/ping')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function http(): PendingRequest
    {
        return Http::baseUrl(config('pusat.url'))
            ->withOptions(['verify' => config('pusat.ca_bundle') ?: (bool) config('pusat.verify_ssl')])
            ->withBasicAuth(config('pusat.client_id'), config('pusat.client_secret'))
            ->acceptJson()
            ->timeout(config('pusat.timeout'));
    }

    /**
     * Ambil SEMUA baris sebuah sumber (halaman demi halaman).
     *
     * @return array<int,array>
     */
    public static function semua(string $sumber, ?string $jalur = null): array
    {
        $jalur ??= '/api/v1/master/'.$sumber;
        $baris = [];
        $hal = 1;
        $total = 0;
        do {
            $res = self::http()->get($jalur, ['per_page' => 500, 'page' => $hal]);
            if (! $res->successful()) {
                $pesan = $res->json('error.pesan') ?: 'HTTP '.$res->status();
                throw new RuntimeException("Pusat menolak permintaan {$sumber}: {$pesan}");
            }
            array_push($baris, ...($res->json('data') ?? []));
            $terakhir = (int) $res->json('meta.halaman_terakhir', 1);
            $total = (int) $res->json('meta.total', count($baris));
            $hal++;
        } while ($hal <= $terakhir);

        if (count($baris) !== $total) {
            throw new RuntimeException("Data {$sumber} dari Pusat tidak lengkap ({$total} diharapkan, ".count($baris).' diterima). Coba lagi.');
        }

        return $baris;
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        DB::table('pusat_state')->updateOrInsert(
            ['kunci' => $kunci],
            ['nilai' => is_scalar($nilai) || $nilai === null ? $nilai : json_encode($nilai), 'updated_at' => now('UTC')->format('Y-m-d H:i:s')],
        );
    }

    public static function baca(string $kunci, mixed $bawaan = null): mixed
    {
        $row = DB::table('pusat_state')->where('kunci', $kunci)->first();

        return $row ? $row->nilai : $bawaan;
    }

    public static function hapus(string $kunci): void
    {
        DB::table('pusat_state')->where('kunci', $kunci)->delete();
    }

    /**
     * Lapor hasil sinkron ke Pusat (kolom "Sinkron terakhir" di menu Aplikasi Pusat + notifikasi admin).
     * Tidak pernah menggagalkan sinkron; Pusat versi lama yang belum punya jalurnya cukup membalas 404.
     */
    public static function lapor(bool $ok, array $hasil = [], string $galat = '', ?string $pemicu = null): void
    {
        try {
            self::http()->timeout(5)->post('/api/v1/sinkron/laporan', [
                'ok' => $ok,
                'pemicu' => $pemicu ?? (app()->runningInConsole() ? 'otomatis' : 'manual'),
                'hasil' => $hasil,
                'galat' => mb_substr($galat, 0, 1000),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Tombol "Sinkronkan" di Pusat: sinkron penuh LANGSUNG setelah jawaban webhook terkirim ke Pusat
     * (PHP-FPM menutup koneksi dulu, jadi Pusat tidak ikut menunggu). Gagal → tanda "perlu sinkron" tetap ada
     * dan penjadwal mencoba lagi ±1 menit kemudian. Hanya satu sinkron langsung berjalan pada satu waktu.
     */
    public static function sinkronSetelahJawab(callable $jalankan): void
    {
        app()->terminating(function () use ($jalankan) {
            if (! \Illuminate\Support\Facades\Cache::add('pusat-sinkron-langsung', 1, 600)) {
                return;
            }
            @set_time_limit(600);
            ignore_user_abort(true);
            try {
                $jalankan();
            } catch (\Throwable $e) {
                report($e); // hasil gagal sudah dilaporkan ke Pusat oleh PusatSync
            } finally {
                \Illuminate\Support\Facades\Cache::forget('pusat-sinkron-langsung');
            }
        });
    }
}
