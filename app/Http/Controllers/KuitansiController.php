<?php

namespace App\Http\Controllers;

use App\Models\Pembayaran;
use App\Services\Tanggungan;
use App\Support\Dates;
use App\Support\Terbilang;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Kuitansi: daftar penerimaan per rentang tanggal (kas harian), cetak kuitansi, pembatalan dengan alasan. */
class KuitansiController extends Controller
{
    private function saring(Request $r): array
    {
        $hari = Dates::today();
        $dari = Dates::valid($r->query('dari')) ? $r->query('dari') : substr($hari, 0, 8).'01';
        $sampai = Dates::valid($r->query('sampai')) ? $r->query('sampai') : $hari;
        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $q = Pembayaran::with(['siswa.kelas', 'rinci.tagihan'])->where('sumber', 'kasir')
            ->whereBetween('tanggal', [$dari, $sampai])->orderByDesc('tanggal')->orderByDesc('id');

        return [$q, $dari, $sampai];
    }

    public function index(Request $request)
    {
        [$q, $dari, $sampai] = $this->saring($request);
        $semua = (clone $q)->get();

        return view('kuitansi.index', ['daftar' => $q->paginate(50)->withQueryString(), 'dari' => $dari, 'sampai' => $sampai,
            'total' => $semua->reject->batal()->sum('total'), 'tunai' => $semua->reject->batal()->where('cara', 'tunai')->sum('total'),
            'batal' => $semua->filter->batal()->count(),
            'perHari' => $semua->reject->batal()->groupBy(fn ($p) => $p->tanggal->format('Y-m-d'))->map->sum('total')]);
    }

    public function unduh(Request $request)
    {
        [$q, $dari, $sampai] = $this->saring($request);
        $baris = [['Nomor', 'Tanggal', 'NIS', 'Nama', 'Kelas', 'Rincian', 'Cara', 'Jumlah', 'Penerima', 'Status']];
        foreach ($q->get() as $p) {
            $baris[] = [$p->nomor, $p->tanggal->format('Y-m-d'), (string) $p->siswa?->nis, (string) $p->siswa?->nama, (string) $p->siswa?->kelas?->nama,
                $p->rinci->map(fn ($r) => $r->tagihan?->label())->filter()->implode('; '), $p->cara, $p->total, (string) $p->penerima,
                $p->batal() ? 'DIBATALKAN: '.$p->batal_alasan : 'sah'];
        }

        return Xlsx::download("kuitansi_{$dari}_{$sampai}.xlsx", ['Kuitansi' => $baris]);
    }

    public function show(Pembayaran $pembayaran)
    {
        $pembayaran->load(['siswa.kelas', 'rinci.tagihan']);

        return view('kuitansi.show', ['p' => $pembayaran, 'terbilang' => ucfirst(Terbilang::kata($pembayaran->total)).' rupiah']);
    }

    public function batal(Request $request, Pembayaran $pembayaran)
    {
        $d = $request->validate(['alasan' => 'required|string|max:500'], ['alasan.required' => 'Tulis alasan pembatalan.']);
        Tanggungan::batal($this->me(), $pembayaran, $d['alasan']);

        return back()->with('success', 'Kuitansi '.$pembayaran->nomor.' dibatalkan. Tagihan terkait kembali belum terbayar.');
    }
}
