<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\JenisTagihan;
use App\Models\KasKategori;
use App\Models\KasTransaksi;
use App\Models\Pembayaran;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buku kas sekolah dengan satu saldo: pembayaran siswa (kuitansi bendahara, otomatis) + transaksi lain yang dicatat
 * manual (dana BOS, belanja, honor, …). Kuitansi/transaksi yang dibatalkan tetap tampil tetapi tidak dihitung.
 */
class BukuKas
{
    public const BUKTI_MAKS_KB = 5120;

    /** Saldo sebelum tanggal $tgl (Y-m-d). */
    public static function saldoSebelum(string $tgl): int
    {
        $kas = KasTransaksi::sah()->where('tanggal', '<', $tgl)
            ->selectRaw("COALESCE(SUM(CASE WHEN arah = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS s")->value('s');
        $siswa = Pembayaran::sah()->where('sumber', 'kasir')->where('tanggal', '<', $tgl)->sum('total');

        return (int) $kas + (int) $siswa;
    }

    /**
     * Transaksi gabungan urut tanggal dengan saldo berjalan.
     * @return array{awal:int, baris:Collection, masuk:int, keluar:int, akhir:int}
     */
    public static function buku(string $dari, string $sampai, string $arah = '', ?int $kategori = null): array
    {
        $kas = KasTransaksi::with('kategori')->whereBetween('tanggal', [$dari, $sampai])
            ->when($arah !== '', fn ($q) => $q->where('arah', $arah))->when($kategori, fn ($q) => $q->where('kategori_id', $kategori))->get()
            ->map(fn (KasTransaksi $t) => ['jenis' => 'kas', 'id' => $t->id, 'tanggal' => $t->tanggal->format('Y-m-d'), 'urut' => $t->created_at?->timestamp ?? 0,
                'nomor' => $t->nomor, 'arah' => $t->arah, 'kategori' => $t->kategori?->nama ?? '-', 'uraian' => $t->uraian, 'jumlah' => $t->jumlah,
                'cara' => $t->cara, 'bukti' => (bool) $t->bukti_path, 'pencatat' => $t->pencatat, 'batal' => $t->batal(), 'batal_alasan' => $t->batal_alasan]);
        $siswa = ($arah === 'keluar' || $kategori) ? collect() : Pembayaran::with('siswa.kelas')->where('sumber', 'kasir')->whereBetween('tanggal', [$dari, $sampai])->get()
            ->map(fn (Pembayaran $p) => ['jenis' => 'siswa', 'id' => $p->id, 'tanggal' => $p->tanggal->format('Y-m-d'), 'urut' => $p->created_at?->timestamp ?? 0,
                'nomor' => $p->nomor, 'arah' => 'masuk', 'kategori' => 'Pembayaran siswa',
                'uraian' => trim(($p->siswa?->nama ?? 'Siswa').' · '.($p->siswa?->kelas?->nama ?? ''), ' ·'), 'jumlah' => $p->total,
                'cara' => $p->cara, 'bukti' => false, 'pencatat' => $p->penerima, 'batal' => $p->batal(), 'batal_alasan' => $p->batal_alasan]);
        $baris = $kas->concat($siswa)->sortBy([['tanggal', 'asc'], ['urut', 'asc'], ['nomor', 'asc']])->values();

        $saring = $arah !== '' || $kategori;
        $awal = $saring ? 0 : self::saldoSebelum($dari);
        $saldo = $awal;
        $masuk = $keluar = 0;
        $baris = $baris->map(function ($r) use (&$saldo, &$masuk, &$keluar) {
            if (! $r['batal']) {
                $r['arah'] === 'masuk' ? $masuk += $r['jumlah'] : $keluar += $r['jumlah'];
                $saldo += $r['arah'] === 'masuk' ? $r['jumlah'] : -$r['jumlah'];
            }
            $r['saldo'] = $saldo;

            return $r;
        });

        return ['awal' => $awal, 'baris' => $baris, 'masuk' => $masuk, 'keluar' => $keluar, 'akhir' => $awal + $masuk - $keluar, 'saring' => (bool) $saring];
    }

    /**
     * Rekap per kategori per bulan (tahun kalender). Pembayaran siswa dipecah per jenis tagihan.
     * @return array{tahun:int, masuk:list<array>, keluar:list<array>, totalMasuk:array, totalKeluar:array, awal:int, saldo:array}
     */
    public static function rekap(int $tahun): array
    {
        $dari = "$tahun-01-01";
        $sampai = "$tahun-12-31";
        $kosong = array_fill(1, 12, 0);
        $baris = ['masuk' => [], 'keluar' => []];
        $tambah = function (string $arah, string $nama, int $bulan, int $n) use (&$baris, $kosong) {
            $baris[$arah][$nama] ??= $kosong;
            $baris[$arah][$nama][$bulan] += $n;
        };

        $jenis = JenisTagihan::pluck('nama', 'kode');
        $rinci = DB::table('pembayaran_rinci as r')->join('pembayaran as p', 'p.id', '=', 'r.pembayaran_id')->join('tagihan as t', 't.id', '=', 'r.tagihan_id')
            ->whereNull('p.batal_pada')->where('p.sumber', 'kasir')->whereBetween('p.tanggal', [$dari, $sampai])
            ->get(['p.tanggal', 't.jenis', 'r.jumlah']);
        foreach ($rinci as $r) {
            $tambah('masuk', 'Pembayaran siswa — '.($jenis[$r->jenis] ?? $r->jenis), (int) substr((string) $r->tanggal, 5, 2), (int) $r->jumlah);
        }
        foreach (KasTransaksi::sah()->with('kategori')->whereBetween('tanggal', [$dari, $sampai])->get() as $t) {
            $tambah($t->arah, $t->kategori?->nama ?? '-', (int) $t->tanggal->format('n'), $t->jumlah);
        }
        // urutan: kategori menurut pengaturan, pembayaran siswa paling atas
        $urut = KasKategori::orderBy('urut')->orderBy('nama')->pluck('nama')->flip();
        $hasil = [];
        foreach (['masuk', 'keluar'] as $arah) {
            $hasil[$arah] = collect($baris[$arah])->map(fn ($b, $nama) => ['nama' => $nama, 'bulan' => $b, 'total' => array_sum($b)])
                ->sortBy(fn ($r) => str_starts_with($r['nama'], 'Pembayaran siswa') ? -1 : ($urut[$r['nama']] ?? 999))->values()->all();
        }
        $totalMasuk = $totalKeluar = $kosong;
        foreach ($hasil['masuk'] as $r) {
            foreach ($r['bulan'] as $b => $n) {
                $totalMasuk[$b] += $n;
            }
        }
        foreach ($hasil['keluar'] as $r) {
            foreach ($r['bulan'] as $b => $n) {
                $totalKeluar[$b] += $n;
            }
        }
        $awal = self::saldoSebelum($dari);
        $saldo = [];
        $s = $awal;
        foreach (range(1, 12) as $b) {
            $s += $totalMasuk[$b] - $totalKeluar[$b];
            $saldo[$b] = $s;
        }

        return ['tahun' => $tahun, 'masuk' => $hasil['masuk'], 'keluar' => $hasil['keluar'], 'totalMasuk' => $totalMasuk, 'totalKeluar' => $totalKeluar,
            'awal' => $awal, 'saldo' => $saldo];
    }

    public static function catat(User $u, array $d, ?UploadedFile $bukti): KasTransaksi
    {
        $k = KasKategori::where('aktif', true)->find($d['kategori_id']) ?? throw new UserError('Pilih kategori yang aktif.');
        $jumlah = (int) preg_replace('/\D/', '', (string) $d['jumlah']);
        if ($jumlah < 1) {
            throw new UserError('Jumlah harus lebih dari 0.');
        }

        return DB::transaction(function () use ($u, $d, $k, $jumlah, $bukti) {
            $awalan = $k->arah === 'masuk' ? 'KM' : 'KK';
            $dasar = $awalan.'-'.substr($d['tanggal'], 0, 4).'-';
            $akhir = KasTransaksi::where('nomor', 'like', $dasar.'%')->lockForUpdate()->orderByDesc('nomor')->value('nomor');
            $t = KasTransaksi::create([
                'nomor' => $dasar.str_pad((string) ($akhir ? (int) substr($akhir, strlen($dasar)) + 1 : 1), 5, '0', STR_PAD_LEFT),
                'tanggal' => $d['tanggal'], 'arah' => $k->arah, 'kategori_id' => $k->id, 'uraian' => mb_substr(trim($d['uraian']), 0, 255),
                'jumlah' => $jumlah, 'cara' => $d['cara'] === 'transfer' ? 'transfer' : 'tunai',
                'bukti_path' => $bukti?->store('kas-bukti', 'local'), 'user_id' => $u->id, 'pencatat' => $u->nama,
            ]);
            Audit::catat('Catat kas '.$k->arah, $u->nama, "$t->nomor · {$k->nama} · ".Tanggungan::rupiah($jumlah)." · {$t->uraian}");

            return $t;
        });
    }

    public static function batal(User $u, KasTransaksi $t, string $alasan): void
    {
        if ($t->batal()) {
            throw new UserError('Transaksi ini sudah dibatalkan.');
        }
        $t->update(['batal_pada' => now(), 'batal_alasan' => mb_substr($alasan, 0, 500), 'batal_oleh' => $u->nama]);
        Audit::catat('Batalkan kas', $u->nama, "$t->nomor · ".Tanggungan::rupiah($t->jumlah)." · alasan: $alasan");
    }
}
