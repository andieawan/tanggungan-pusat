<?php

namespace App\Http\Controllers;

use App\Models\KasKategori;
use App\Models\KasTransaksi;
use App\Services\BukuKas;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Buku kas harian, rekap bulanan per kategori, pencatatan & pembatalan transaksi, bukti, kategori. */
class KasController extends Controller
{
    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private function saring(Request $r): array
    {
        $hari = Dates::today();
        $dari = Dates::valid($r->query('dari')) ? $r->query('dari') : substr($hari, 0, 8).'01';
        $sampai = Dates::valid($r->query('sampai')) ? $r->query('sampai') : $hari;
        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $arah = in_array($r->query('arah'), ['masuk', 'keluar'], true) ? $r->query('arah') : '';
        $kategori = (int) $r->query('kategori') ?: null;

        return [$dari, $sampai, $arah, $kategori];
    }

    public function index(Request $request)
    {
        [$dari, $sampai, $arah, $kategori] = $this->saring($request);

        return view('kas.index', ['b' => BukuKas::buku($dari, $sampai, $arah, $kategori), 'dari' => $dari, 'sampai' => $sampai, 'arah' => $arah,
            'kategori' => $kategori, 'semuaKategori' => KasKategori::orderBy('arah')->orderBy('urut')->orderBy('nama')->get(),
            'cetak' => $request->boolean('cetak')]);
    }

    public function unduh(Request $request)
    {
        [$dari, $sampai, $arah, $kategori] = $this->saring($request);
        $b = BukuKas::buku($dari, $sampai, $arah, $kategori);
        $baris = [['Tanggal', 'Nomor', 'Kategori', 'Uraian', 'Cara', 'Masuk', 'Keluar', 'Saldo', 'Pencatat', 'Status']];
        if (! $b['saring']) {
            $baris[] = [$dari, '', '', 'Saldo awal', '', '', '', $b['awal'], '', ''];
        }
        foreach ($b['baris'] as $r) {
            $baris[] = [$r['tanggal'], $r['nomor'], $r['kategori'], $r['uraian'], $r['cara'], $r['arah'] === 'masuk' ? $r['jumlah'] : '',
                $r['arah'] === 'keluar' ? $r['jumlah'] : '', $b['saring'] ? '' : $r['saldo'], (string) $r['pencatat'], $r['batal'] ? 'DIBATALKAN: '.$r['batal_alasan'] : 'sah'];
        }
        $baris[] = ['', '', '', 'Jumlah', '', $b['masuk'], $b['keluar'], $b['saring'] ? '' : $b['akhir'], '', ''];

        return Xlsx::download("buku_kas_{$dari}_{$sampai}.xlsx", ['Buku kas' => $baris]);
    }

    private function tahun(Request $r): int
    {
        $t = (int) $r->query('tahun');

        return $t >= 2000 && $t <= 2100 ? $t : (int) substr(Dates::today(), 0, 4);
    }

    public function rekap(Request $request)
    {
        return view('kas.rekap', ['r' => BukuKas::rekap($this->tahun($request)), 'bulan' => self::BULAN, 'cetak' => $request->boolean('cetak')]);
    }

    public function rekapUnduh(Request $request)
    {
        $r = BukuKas::rekap($this->tahun($request));
        $kepala = array_merge(['Kategori'], array_values(self::BULAN), ['Total']);
        $baris = [['REKAP KAS '.$r['tahun']], ['Saldo awal tahun', $r['awal']], [], array_merge(['PEMASUKAN'], array_fill(0, 13, '')), $kepala];
        foreach ($r['masuk'] as $k) {
            $baris[] = array_merge([$k['nama']], array_values($k['bulan']), [$k['total']]);
        }
        $baris[] = array_merge(['Total pemasukan'], array_values($r['totalMasuk']), [array_sum($r['totalMasuk'])]);
        $baris[] = [];
        $baris[] = array_merge(['PENGELUARAN'], array_fill(0, 13, ''));
        $baris[] = $kepala;
        foreach ($r['keluar'] as $k) {
            $baris[] = array_merge([$k['nama']], array_values($k['bulan']), [$k['total']]);
        }
        $baris[] = array_merge(['Total pengeluaran'], array_values($r['totalKeluar']), [array_sum($r['totalKeluar'])]);
        $baris[] = [];
        $baris[] = array_merge(['Saldo akhir bulan'], array_values($r['saldo']), ['']);

        return Xlsx::download("rekap_kas_{$r['tahun']}.xlsx", ['Rekap '.$r['tahun'] => ['rows' => $baris, 'tebal' => [0, 3, 4, count($r['masuk']) + 5]]]);
    }

    public function simpan(Request $request)
    {
        $d = $request->validate([
            'tanggal' => 'required|date_format:Y-m-d|before_or_equal:today',
            'kategori_id' => 'required|integer',
            'uraian' => 'required|string|max:255',
            'jumlah' => 'required|string|max:20',
            'cara' => 'required|in:tunai,transfer',
            'bukti' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:'.BukuKas::BUKTI_MAKS_KB,
        ], ['tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.', 'bukti.mimes' => 'Bukti harus foto (JPG/PNG/WEBP) atau PDF.',
            'bukti.max' => 'Bukti maksimal 5 MB.', 'uraian.required' => 'Tulis uraian transaksi.']);
        $t = BukuKas::catat($this->me(), $d, $request->file('bukti'));

        return redirect()->route('kas.index', ['dari' => min($t->tanggal->format('Y-m-d'), substr(Dates::today(), 0, 8).'01')])
            ->with('success', "Transaksi $t->nomor dicatat.");
    }

    public function batal(Request $request, KasTransaksi $transaksi)
    {
        $d = $request->validate(['alasan' => 'required|string|max:500'], ['alasan.required' => 'Tulis alasan pembatalan.']);
        BukuKas::batal($this->me(), $transaksi, $d['alasan']);

        return back()->with('success', "Transaksi $transaksi->nomor dibatalkan (tetap tercatat, tidak dihitung).");
    }

    public function bukti(KasTransaksi $transaksi)
    {
        abort_unless($transaksi->bukti_path && Storage::disk('local')->exists($transaksi->bukti_path), 404);

        return Storage::disk('local')->response($transaksi->bukti_path, $transaksi->nomor.'.'.pathinfo($transaksi->bukti_path, PATHINFO_EXTENSION));
    }

    public function kategori()
    {
        return view('kas.kategori', ['kategori' => KasKategori::orderBy('arah')->orderBy('urut')->orderBy('nama')->get(),
            'dipakai' => KasTransaksi::selectRaw('kategori_id, COUNT(*) n')->groupBy('kategori_id')->pluck('n', 'kategori_id')]);
    }

    public function kategoriSimpan(Request $request)
    {
        $d = $request->validate(['nama' => 'required|string|max:60', 'arah' => 'required|in:masuk,keluar']);
        KasKategori::create($d + ['aktif' => true, 'urut' => 50]);

        return back()->with('success', "Kategori {$d['nama']} ditambahkan.");
    }

    public function kategoriUbah(Request $request, KasKategori $kategori)
    {
        $d = $request->validate(['nama' => 'required|string|max:60', 'urut' => 'nullable|integer|min:0|max:999', 'aktif' => 'boolean']);
        $kategori->update(['nama' => $d['nama'], 'urut' => $d['urut'] ?? $kategori->urut, 'aktif' => (bool) ($d['aktif'] ?? false)]);

        return back()->with('success', "Kategori {$kategori->nama} disimpan.");
    }
}
