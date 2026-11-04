<?php

namespace App\Http\Controllers;

use App\Services\PusatAkun;
use App\Services\PusatKlien;
use App\Services\PusatSync;
use App\Support\PusatTanda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Penerima webhook Pusat. Aman: tanda tangan HMAC + batas waktu 5 menit + idempoten (X-Pusat-Delivery).
 * Pencabutan akses akun diterapkan langsung; perubahan kelas/siswa/pegawai langsung disalin dari muatan webhook
 * (hanya kolom yang dikirim, tanpa menghapus apa pun).
 */
class PusatWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap() || config('pusat.webhook_secret') === '') {
            return response()->json(['ok' => false], 404);
        }
        $badan = $request->getContent();
        $waktu = (int) $request->header('X-Pusat-Timestamp');
        if (! PusatTanda::verifikasi((string) config('pusat.webhook_secret'), $waktu, $badan, (string) $request->header('X-Pusat-Signature'))) {
            return response()->json(['ok' => false, 'error' => 'tanda_tangan_salah'], 401);
        }

        $id = substr((string) $request->header('X-Pusat-Delivery'), 0, 64);
        $event = substr((string) $request->header('X-Pusat-Event'), 0, 60);
        if ($id === '' || $event === '') {
            return response()->json(['ok' => false, 'error' => 'header_kurang'], 422);
        }
        // Catat nomor kiriman DAN proses dalam satu transaksi: bila proses gagal, catatan ikut batal sehingga
        // pengiriman ulang dari Pusat diproses lagi (bukan dianggap duplikat lalu hilang).
        $baru = DB::transaction(function () use ($id, $event, $request) {
            $baru = DB::table('pusat_webhook')->insertOrIgnore(['delivery_id' => $id, 'event' => $event, 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
            if (! $baru) {
                return false;
            }
            $data = (array) $request->json('data', []);
            if ($event === 'sambungan.diperbarui') {
                \App\Services\Sambungan::tarik(); // gagal → transaksi batal → Pusat mengirim ulang
            } elseif ($event === 'profil.diperbarui') {
                \App\Services\ProfilPusat::tarik();
            } elseif (in_array($event, ['akun.diperbarui', 'akses.diperbarui'], true)) {
                PusatAkun::cabutBilaPerlu($data);
                PusatAkun::terapkanSandi($data); // login cadangan
            } elseif (isset($data['uuid'])) {
                if (str_starts_with($event, 'kelas.')) {
                    PusatSync::upsertKelas($data);
                    PusatSync::tandaiTahunAktif();
                } elseif (str_starts_with($event, 'siswa.')) {
                    PusatSync::upsertSiswa($data);
                } elseif (str_starts_with($event, 'pegawai.')) {
                    PusatSync::upsertPegawai($data);
                } elseif (str_starts_with($event, 'tahun_ajaran.') && ! empty($data['aktif']) && ! empty($data['nama'])) {
                    PusatKlien::simpan('tahun_aktif', (string) $data['nama']);
                    PusatSync::tandaiTahunAktif();
                }
            }

            return true;
        });
        if (! $baru) {
            return response()->json(['ok' => true, 'duplikat' => true]);
        }
        DB::table('pusat_webhook')->where('created_at', '<', now('UTC')->subDays(7)->format('Y-m-d H:i:s'))->delete();

        return response()->json(['ok' => true]);
    }
}
