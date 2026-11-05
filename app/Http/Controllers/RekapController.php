<?php

namespace App\Http\Controllers;

use App\Services\Akses;
use App\Services\Rekap;
use App\Services\Tanggungan;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Rekap per kelas seperti lembar Excel: satu lembar per jenis tahunan, dua (semester) per jenis bulanan; bisa diunduh. */
class RekapController extends Controller
{
    private function pilih(Request $r): array
    {
        $u = $this->me();
        abort_unless(Akses::punyaAkses($u), 403);
        $daftarTahun = Tanggungan::daftarTahun();
        $tahun = in_array($r->query('tahun'), $daftarTahun, true) ? $r->query('tahun') : Tanggungan::tahunAktif();
        $kelasDaftar = Rekap::daftarKelas($u, $tahun);
        $kelas = in_array($r->query('kelas'), $kelasDaftar, true) ? $r->query('kelas') : ($kelasDaftar[0] ?? null);

        return [$u, $daftarTahun, $tahun, $kelasDaftar, $kelas];
    }

    public function index(Request $request)
    {
        [$u, $daftarTahun, $tahun, $kelasDaftar, $kelas] = $this->pilih($request);
        $r = $kelas ? Rekap::kelas($u, $tahun, $kelas) : null;
        $kunci = collect($r['lembar'] ?? [])->pluck('kunci');
        $tab = $kunci->contains($request->query('tab')) ? $request->query('tab') : $kunci->first();

        return view('rekap.index', ['daftarTahun' => $daftarTahun, 'tahun' => $tahun, 'kelasDaftar' => $kelasDaftar, 'kelas' => $kelas, 'tab' => $tab,
            'r' => $r, 'l' => collect($r['lembar'] ?? [])->firstWhere('kunci', $tab)]);
    }

    public function unduh(Request $request)
    {
        [$u, , $tahun, , $kelas] = $this->pilih($request);
        abort_unless($kelas, 404);

        return Xlsx::download('TANGGUNGAN_'.str_replace(' ', '_', $kelas).'_'.str_replace('/', '-', $tahun).'.xlsx', Rekap::excel(Rekap::kelas($u, $tahun, $kelas), $tahun, $kelas));
    }
}
