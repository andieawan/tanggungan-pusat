<?php

namespace App\Http\Controllers;

use App\Models\Kelas;
use App\Models\Pembayaran;
use App\Models\Siswa;
use App\Models\Tagihan;
use App\Services\Akses;
use App\Services\Tanggungan;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Daftar siswa dan kartu tanggungan per siswa; bendahara mencatat pembayaran & keringanan dari kartu ini. */
class SiswaController extends Controller
{
    public function index(Request $request)
    {
        $u = $this->me();
        abort_unless(Akses::punyaAkses($u), 403);
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['aktif', 'lulus', 'keluar', 'semua'], true) ? $request->query('status') : 'aktif';
        $kelasId = $request->integer('kelas');
        $siswa = Akses::siswa($u)->with('kelas')
            ->when($status !== 'semua', fn ($w) => $status === 'keluar' ? $w->whereNotIn('status', ['aktif', 'lulus']) : $w->where('status', $status))
            ->when($kelasId, fn ($w) => $w->where('kelas_id', $kelasId))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")->orWhere('nisn', 'like', "%$q%")))
            ->orderBy('nama')->paginate(30)->withQueryString();
        $kurang = Tagihan::whereIn('siswa_id', $siswa->pluck('id'))->select('tagihan.*')->denganTerbayar()->get()
            ->groupBy('siswa_id')->map(fn ($ts) => $ts->sum(fn ($t) => $t->kurang()));
        $kelas = $u->lihatSemua() ? Kelas::whereNull('dihapus_pada')->orderBy('nama')->get() : $u->kelasWali();

        return view('siswa.index', compact('siswa', 'kurang', 'kelas', 'q', 'status', 'kelasId'));
    }

    public function show(Siswa $siswa)
    {
        $u = $this->me();
        abort_unless(Akses::bolehLihat($u, $siswa), 403);
        $tagihan = Tanggungan::tagihanSiswa($siswa);
        $pembayaran = Pembayaran::with('rinci.tagihan')->where('siswa_id', $siswa->id)->orderByDesc('tanggal')->orderByDesc('id')->get();
        $tahun = Tanggungan::tahunAktif();

        return view('siswa.show', [
            's' => $siswa->load('kelas'), 'tagihan' => $tagihan, 'perTahun' => $tagihan->groupBy('tahun_ajaran')->sortKeysDesc(),
            'ringkas' => Tanggungan::ringkas($tagihan), 'pembayaran' => $pembayaran, 'hari' => Dates::today(), 'tahun' => $tahun,
            'belumAda' => $u->isKasir() && $siswa->status === 'aktif' && ! $tagihan->contains('tahun_ajaran', $tahun),
        ]);
    }

    public function bayar(Request $request, Siswa $siswa)
    {
        $d = $request->validate([
            'tanggal' => 'required|date_format:Y-m-d', 'cara' => 'required|in:tunai,transfer', 'catatan' => 'nullable|string|max:500',
            'jumlah' => 'required|array', 'jumlah.*' => 'nullable|string|max:20',
        ], ['jumlah.required' => 'Isi jumlah bayar pada minimal satu tagihan.']);
        $p = Tanggungan::bayar($this->me(), $siswa, $d['tanggal'], $d['cara'], $d['catatan'] ?? null, $d['jumlah']);

        return redirect()->route('kuitansi.show', $p)->with('success', 'Pembayaran '.Tanggungan::rupiah($p->total).' tercatat. Kuitansi '.$p->nomor.' siap dicetak.');
    }

    public function buatTagihan(Request $request, Siswa $siswa)
    {
        $tahun = Tanggungan::tahunAktif(); // memakai kelas siswa saat ini; tahun lalu lewat impor data lama
        $h = Tanggungan::buatTagihan($tahun, [$siswa->id]);
        if ($h['tanpa_tarif']) {
            return back()->with('error', 'Tarif '.$tahun.' untuk tingkat kelas siswa ini belum diatur. Atur dulu di Pengaturan → Tarif.');
        }

        return back()->with('success', $h['baru'] ? $h['baru'].' tagihan '.$tahun.' dibuat.' : 'Tagihan '.$tahun.' sudah lengkap.');
    }

    public function keringanan(Request $request, Tagihan $tagihan)
    {
        $d = $request->validate(['potongan' => 'required|string|max:20', 'alasan' => 'nullable|string|max:255',
            'semua' => 'nullable|boolean'], [], ['potongan' => 'potongan']);
        $potongan = (int) preg_replace('/\D/', '', $d['potongan']);
        $daftar = collect([$tagihan]);
        if (! empty($d['semua']) && $tagihan->jenis === 'syariah') {
            // Terapkan ke semua bulan syariah tahun ajaran yang sama (mis. bebas iuran setahun).
            $daftar = Tagihan::where(['siswa_id' => $tagihan->siswa_id, 'jenis' => 'syariah', 'tahun_ajaran' => $tagihan->tahun_ajaran])->get();
        }
        foreach ($daftar as $t) {
            Tanggungan::keringanan($this->me(), $t, min($potongan, $t->nominal), (string) ($d['alasan'] ?? ''));
        }

        return back()->with('success', $potongan ? 'Keringanan disimpan untuk '.$daftar->count().' tagihan.' : 'Keringanan dihapus.');
    }
}
