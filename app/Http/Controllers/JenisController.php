<?php

namespace App\Http\Controllers;

use App\Models\JenisTagihan;
use App\Models\Tagihan;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Jenis tagihan: bawaan (daftar ulang, syariah) + tambahan bendahara (ujian, PKL, wisuda, seragam, …). */
class JenisController extends Controller
{
    public function index()
    {
        $jenis = JenisTagihan::urut()->get();
        $dipakai = Tagihan::selectRaw('jenis, count(*) as n')->groupBy('jenis')->pluck('n', 'jenis');

        return view('jenis.index', compact('jenis', 'dipakai'));
    }

    private function aturan(): array
    {
        return ['nama' => 'required|string|max:60', 'singkat' => 'nullable|string|max:30', 'keterangan' => 'nullable|string|max:255',
            'urut' => 'nullable|integer|min:0|max:999', 'aktif' => 'nullable|boolean'];
    }

    public function simpan(Request $request)
    {
        $d = $request->validate($this->aturan() + ['pola' => ['required', Rule::in(array_keys(JenisTagihan::POLA))]],
            ['nama.required' => 'Nama jenis tagihan wajib diisi.']);
        $dasar = Str::limit(Str::slug($d['nama'], '_'), 16, '') ?: 'jenis';
        $kode = $dasar;
        for ($i = 2; JenisTagihan::where('kode', $kode)->exists(); $i++) {
            $kode = $dasar.'_'.$i;
        }
        if (JenisTagihan::whereRaw('LOWER(nama) = ?', [mb_strtolower(trim($d['nama']))])->exists()) {
            return back()->withInput()->with('error', 'Jenis "'.$d['nama'].'" sudah ada.');
        }
        $j = JenisTagihan::create(['kode' => $kode, 'nama' => trim($d['nama']), 'singkat' => $d['singkat'] ?? null, 'pola' => $d['pola'],
            'keterangan' => $d['keterangan'] ?? null, 'urut' => $d['urut'] ?? 50, 'aktif' => true, 'bawaan' => false]);
        Audit::catat('Tambah jenis tagihan', $this->me()->nama, "$j->nama ($j->pola)");

        return redirect()->route('tarif.index')->with('success', 'Jenis "'.$j->nama.'" ditambahkan. Isi tarifnya per tingkat di bawah, lalu buat tagihan.');
    }

    public function ubah(Request $request, JenisTagihan $jenis)
    {
        $d = $request->validate($this->aturan());
        $jenis->update(['nama' => trim($d['nama']), 'singkat' => $d['singkat'] ?? null, 'keterangan' => $d['keterangan'] ?? null,
            'urut' => $d['urut'] ?? $jenis->urut, 'aktif' => (bool) ($d['aktif'] ?? false)]);
        Audit::catat('Ubah jenis tagihan', $this->me()->nama, "$jenis->nama · ".($jenis->aktif ? 'aktif' : 'nonaktif'));

        return back()->with('success', 'Jenis "'.$jenis->nama.'" disimpan.'.($jenis->aktif ? '' : ' Nonaktif: tidak dibuatkan tagihan baru; tagihan lama tetap tercatat.'));
    }
}
