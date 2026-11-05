<?php

namespace App\Http\Controllers;

use App\Models\JenisTagihan;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Services\Audit;
use App\Services\Tanggungan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Pengaturan tarif per tahun ajaran & tingkat, lalu pembuatan tagihan untuk semua siswa aktif. */
class TarifController extends Controller
{
    private function tahun(Request $r): string
    {
        return Tanggungan::tahunValid($r->input('tahun')) ? $r->input('tahun') : Tanggungan::tahunAktif();
    }

    public function index(Request $request)
    {
        $tahun = $this->tahun($request);
        $aktif = Tanggungan::tahunAktif();
        $awal = (int) substr($aktif, 0, 4);
        $pilihan = collect(Tanggungan::daftarTahun())->push(($awal + 1).'/'.($awal + 2))->unique()->sortDesc()->values();
        $tarif = Tarif::where('tahun_ajaran', $tahun)->get()->keyBy(fn ($t) => "$t->jenis:$t->tingkat");
        $jumlah = Tagihan::where('tahun_ajaran', $tahun)->selectRaw('tingkat, jenis, count(*) as n, count(distinct siswa_id) as siswa')->groupBy('tingkat', 'jenis')->get()
            ->keyBy(fn ($r) => "$r->jenis:$r->tingkat");

        return view('tarif.index', ['tahun' => $tahun, 'pilihan' => $pilihan, 'tarif' => $tarif, 'jumlah' => $jumlah,
            'jenis' => JenisTagihan::where('aktif', true)->urut()->get(),
            'bawaan' => ! Tarif::where('tahun_ajaran', $tahun)->exists()]);
    }

    public function simpan(Request $request)
    {
        $tahun = $this->tahun($request);
        $d = $request->validate([
            'nominal' => 'array', 'nominal.*' => 'array', 'nominal.*.*' => 'nullable|string|max:12',
            'bebas' => 'array', 'bebas.*' => 'array', 'bebas.*.*' => 'array', 'bebas.*.*.*' => 'integer|between:1,12', 'perbarui' => 'nullable|boolean',
        ]);
        $jenis = JenisTagihan::where('aktif', true)->pluck('pola', 'kode');
        $ubah = 0;
        DB::transaction(function () use ($d, $tahun, $jenis, &$ubah) {
            foreach (Tanggungan::TINGKAT as $tk) {
                foreach ($jenis as $kodeJenis => $pola) {
                    $n = (int) preg_replace('/\D/', '', (string) ($d['nominal'][$kodeJenis][$tk] ?? ''));
                    $kunci = ['jenis' => $kodeJenis, 'tahun_ajaran' => $tahun, 'tingkat' => $tk];
                    if ($n <= 0) {
                        Tarif::where($kunci)->delete();

                        continue;
                    }
                    Tarif::updateOrCreate($kunci, ['nominal' => $n, 'bulan_bebas' => $pola === 'bulanan' ? array_values(array_map('intval', $d['bebas'][$kodeJenis][$tk] ?? [])) : null]);
                    if (! empty($d['perbarui'])) {
                        // Hanya tagihan yang belum dibayar sama sekali yang ikut nominal baru.
                        $ubah += Tagihan::where($kunci)->where('nominal', '!=', $n)->whereDoesntHave('rinci', fn ($q) => $q->whereHas('pembayaran', fn ($p) => $p->whereNull('batal_pada')))
                            ->whereRaw('potongan <= ?', [$n])->update(['nominal' => $n]);
                    }
                }
            }
        });
        Audit::catat('Simpan tarif', $this->me()->nama, $tahun.($ubah ? " · $ubah tagihan diperbarui" : ''));

        return redirect()->route('tarif.index', ['tahun' => $tahun])->with('success', 'Tarif '.$tahun.' tersimpan.'.($ubah ? " $ubah tagihan yang belum dibayar ikut diperbarui." : ''));
    }

    public function terapkan(Request $request)
    {
        $tahun = $this->tahun($request);
        if ($tahun !== Tanggungan::tahunAktif()) {
            return back()->with('error', 'Tagihan otomatis hanya untuk tahun ajaran aktif ('.Tanggungan::tahunAktif().'), karena memakai kelas siswa saat ini. Data tahun sebelumnya diisi lewat Impor data lama.');
        }
        if (! Tarif::where('tahun_ajaran', $tahun)->exists()) {
            return back()->with('error', 'Simpan tarif '.$tahun.' dulu.');
        }
        $h = Tanggungan::buatTagihan($tahun);
        Audit::catat('Buat tagihan', $this->me()->nama, "$tahun · {$h['baru']} tagihan baru");
        $pesan = $h['baru'] ? $h['baru'].' tagihan '.$tahun.' dibuat.' : 'Semua siswa aktif sudah punya tagihan '.$tahun.'.';
        if ($h['tanpa_tarif']) {
            $pesan .= ' Dilewati karena tarif tingkatnya belum ada: '.collect($h['tanpa_tarif'])->map(fn ($n, $t) => "$n siswa tingkat $t")->implode(', ').'.';
        }

        return back()->with($h['tanpa_tarif'] ? 'warning' : 'success', $pesan);
    }
}
