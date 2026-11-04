<?php

namespace App\Http\Controllers;

use App\Exceptions\UserError;
use App\Models\Kelas;
use App\Services\ImporLama;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Impor data tanggungan lama dari Excel: unduh templat per kelas (siswa sudah terisi), isi, lalu unggah. */
class ImporController extends Controller
{
    public function index()
    {
        return view('impor.index', ['kelas' => Kelas::whereNull('dihapus_pada')->orderBy('nama')->get()]);
    }

    public function templat(Request $request)
    {
        $kelas = Kelas::findOrFail($request->integer('kelas'));

        return Xlsx::download('TEMPLAT_DATA_LAMA_'.str_replace(' ', '_', $kelas->nama).'.xlsx', ImporLama::templat($kelas));
    }

    public function unggah(Request $request)
    {
        $request->validate(['berkas' => 'required|file|max:5120|mimes:xlsx,csv,txt', 'uji' => 'nullable|boolean'],
            ['berkas.required' => 'Pilih berkas Excel hasil isian templat.', 'berkas.mimes' => 'Berkas harus .xlsx.']);
        $f = $request->file('berkas');
        $uji = $request->boolean('uji');
        try {
            $h = ImporLama::proses($this->me(), $f->getRealPath(), $f->getClientOriginalName(), $uji);
        } catch (UserError $e) {
            $baris = explode("\n", $e->getMessage());

            return back()->with('error', array_shift($baris))->with('galat', array_map(fn ($x) => ltrim($x, '• '), $baris));
        }
        $r = $h['ringkas'];
        $pesan = ($uji ? 'Periksa saja (belum disimpan): ' : 'Impor selesai: ').$r['siswa'].' siswa, '.$r['tagihan'].' tagihan, pembayaran lama '
            .\App\Services\Tanggungan::rupiah($r['dibayar']).($r['dilewati'] ? ', '.$r['dilewati'].' tagihan dilewati' : '').'.';

        return back()->with($uji ? 'info' : 'success', $pesan)->with('galat', $h['catatan']);
    }
}
