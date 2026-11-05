<?php

namespace App\Http\Controllers;

use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Services\Akses;
use App\Services\Tanggungan;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Ringkasan tahun ajaran: total tanggungan, terbayar, kekurangan per kelas; penerimaan hari ini (bendahara). */
class BerandaController extends Controller
{
    public function __invoke(Request $request)
    {
        $u = $this->me();
        if (! Akses::punyaAkses($u)) {
            return view('beranda_kosong');
        }
        $daftarTahun = Tanggungan::daftarTahun();
        $tahun = in_array($request->query('tahun'), $daftarTahun, true) ? $request->query('tahun') : Tanggungan::tahunAktif();
        $siswaIds = Akses::siswa($u)->pluck('id');
        $tagihan = Tagihan::where('tahun_ajaran', $tahun)->whereIn('siswa_id', $siswaIds)->select('tagihan.*')->denganTerbayar()->get();

        $kelas = $tagihan->groupBy('kelas_nama')->map(function ($ts, $nama) {
            $per = $ts->groupBy('siswa_id');

            return Tanggungan::ringkas($ts) + ['kelas' => $nama, 'siswa' => $per->count(),
                'siswa_lunas' => $per->filter(fn ($x) => $x->every(fn ($t) => $t->lunas()))->count(),
                'per_jenis' => $ts->groupBy('jenis')->map(fn ($x) => $x->sum(fn ($t) => $t->kurang()))->filter()->all()];
        })->sortKeys(SORT_NATURAL)->values();

        $hari = Dates::today();
        $kas = $u->lihatSemua() ? [
            'hari' => (int) Pembayaran::sah()->where('sumber', 'kasir')->whereDate('tanggal', $hari)->sum('total'),
            'bulan' => (int) Pembayaran::sah()->where('sumber', 'kasir')->where('tanggal', 'like', substr($hari, 0, 7).'%')->sum('total'),
            'terakhir' => Pembayaran::with('siswa.kelas')->where('sumber', 'kasir')->orderByDesc('id')->limit(8)->get(),
        ] : null;

        return view('beranda', [
            'tahun' => $tahun, 'daftarTahun' => $daftarTahun, 'total' => Tanggungan::ringkas($tagihan), 'kelas' => $kelas, 'kas' => $kas, 'hari' => $hari,
            'tarifKosong' => $u->isKasir() && ! Tarif::where('tahun_ajaran', $tahun)->exists(),
            'tanpaTagihan' => $u->isKasir() ? Akses::siswa($u)->aktif()->whereNotNull('kelas_id')->whereDoesntHave('tagihan', fn ($q) => $q->where('tahun_ajaran', $tahun))->count() : 0,
            'wali' => $u->lihatSemua() ? collect() : $u->kelasWali(), 'namaJenis' => Tanggungan::jenis()->map->nama,
        ]);
    }
}
