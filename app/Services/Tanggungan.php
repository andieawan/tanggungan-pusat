<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\JenisTagihan;
use App\Models\Kelas;
use App\Models\Pembayaran;
use App\Models\PembayaranRinci;
use App\Models\Siswa;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aturan tanggungan siswa: tarif → tagihan per siswa → pembayaran (kuitansi) → rincian per tagihan.
 * Jenis tagihan diatur di tabel jenis_tagihan (bawaan: daftar ulang = tahunan, iuran syariah = bulanan; bendahara bisa
 * menambah ujian, PKL, wisuda, dst.). Tahunan: satu tagihan per tahun ajaran (bulan = 0), boleh diangsur.
 */
class Tanggungan
{
    /** @return Collection<string,JenisTagihan> kode => jenis (semua, termasuk nonaktif — untuk label data lama) */
    public static function jenis(): Collection
    {
        return JenisTagihan::urut()->get()->keyBy('kode');
    }

    /** Urutan bulan dalam satu tahun ajaran. */
    public const BULAN_TA = [7, 8, 9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    public const TINGKAT = ['X', 'XI', 'XII'];

    public static function tahunAktif(): string
    {
        $t = (string) (PusatKlien::baca('tahun_aktif') ?: Kelas::where('tahun_aktif', true)->value('tahun_ajaran'));
        if (preg_match('#^\d{4}/\d{4}$#', $t)) {
            return $t;
        }
        [$y, $m] = array_map('intval', explode('-', substr(Dates::today(), 0, 7)));
        $awal = $m >= 7 ? $y : $y - 1;

        return $awal.'/'.($awal + 1);
    }

    /** Tahun ajaran yang dikenal (tagihan, tarif, aktif), terbaru dulu. */
    public static function daftarTahun(): array
    {
        $t = collect([self::tahunAktif()])->merge(Tagihan::distinct()->pluck('tahun_ajaran'))->merge(Tarif::distinct()->pluck('tahun_ajaran'));

        return $t->filter(fn ($x) => preg_match('#^\d{4}/\d{4}$#', (string) $x))->unique()->sortDesc()->values()->all();
    }

    public static function tahunValid(mixed $t): bool
    {
        return is_string($t) && preg_match('#^(\d{4})/(\d{4})$#', $t, $m) && (int) $m[2] === (int) $m[1] + 1;
    }

    /** "X", "10", "XI RPL 1" … → X / XI / XII, atau '' bila tidak dikenali. */
    public static function tingkat(?string $v): string
    {
        $v = strtoupper(trim((string) $v));
        $peta = ['10' => 'X', '11' => 'XI', '12' => 'XII'];
        if (isset($peta[$v])) {
            return $peta[$v];
        }
        if (preg_match('/^(XII|XI|X)\b/', $v, $m)) {
            return $m[1];
        }

        return '';
    }

    public static function tingkatKelas(?Kelas $k): string
    {
        return $k ? (self::tingkat($k->tingkat) ?: self::tingkat($k->nama)) : '';
    }

    /** Tahun kalender suatu bulan dalam tahun ajaran "2026/2027": Juli–Desember 2026, Januari–Juni 2027. */
    public static function tahunKalender(string $tahunAjaran, int $bulan): int
    {
        $awal = (int) substr($tahunAjaran, 0, 4);

        return $bulan >= 7 ? $awal : $awal + 1;
    }

    public static function semester(int $bulan): int
    {
        return $bulan >= 7 ? 1 : 2;
    }

    public static function namaBulan(int $bulan): string
    {
        return Dates::MONTHS[$bulan] ?? '';
    }

    public static function label(string $jenis, int $bulan, string $tahun): string
    {
        $j = self::namaJenis();

        return $bulan > 0 ? ($j[$jenis]['pendek'] ?? $jenis).' '.self::namaBulan($bulan).' '.self::tahunKalender($tahun, $bulan) : ($j[$jenis]['nama'] ?? $jenis).' '.$tahun;
    }

    /** @return array<string,array{nama:string,pendek:string}> */
    private static function namaJenis(): array
    {
        static $peta = null;
        if ($peta === null || app()->runningUnitTests()) {
            $peta = JenisTagihan::all()->mapWithKeys(fn ($j) => [$j->kode => ['nama' => $j->nama, 'pendek' => $j->pendek()]])->all();
        }

        return $peta;
    }

    public static function rupiah(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    // ------------------------------------------------------------ tagihan

    /**
     * Buat tagihan yang belum ada untuk siswa aktif menurut tarif tahun ajaran itu. Tagihan yang sudah ada tidak diubah.
     * @param  int[]|null  $siswaIds  batasi ke siswa tertentu
     * @return array{baru:int, tanpa_tarif:array<string,int>}
     */
    public static function buatTagihan(string $tahun, ?array $siswaIds = null): array
    {
        $aktif = JenisTagihan::where('aktif', true)->pluck('pola', 'kode');
        $tarif = Tarif::where('tahun_ajaran', $tahun)->whereIn('jenis', $aktif->keys())->get()->groupBy('tingkat');
        $siswa = Siswa::aktif()->with('kelas')->whereNotNull('kelas_id')->when($siswaIds !== null, fn ($q) => $q->whereIn('id', $siswaIds))->get();
        $ada = Tagihan::where('tahun_ajaran', $tahun)->whereIn('siswa_id', $siswa->pluck('id'))->get(['siswa_id', 'jenis', 'bulan'])
            ->mapWithKeys(fn ($t) => ["$t->siswa_id:$t->jenis:$t->bulan" => true]);
        $baru = 0;
        $tanpa = [];
        $now = now();
        $baris = [];
        foreach ($siswa as $s) {
            $tk = self::tingkatKelas($s->kelas);
            $tt = $tarif[$tk] ?? collect();
            if ($tt->isEmpty()) {
                $tanpa[$tk ?: '?'] = ($tanpa[$tk ?: '?'] ?? 0) + 1;

                continue;
            }
            foreach ($tt as $tr) {
                $bulan = $aktif[$tr->jenis] === 'bulanan' ? array_values(array_diff(self::BULAN_TA, array_map('intval', $tr->bulan_bebas ?? []))) : [0];
                foreach ($bulan as $b) {
                    if (isset($ada["$s->id:$tr->jenis:$b"])) {
                        continue;
                    }
                    $baris[] = ['siswa_id' => $s->id, 'jenis' => $tr->jenis, 'tahun_ajaran' => $tahun, 'tingkat' => $tk, 'kelas_nama' => (string) $s->kelas->nama,
                        'bulan' => $b, 'nominal' => $tr->nominal, 'potongan' => 0, 'created_at' => $now, 'updated_at' => $now];
                    $baru++;
                }
            }
        }
        foreach (array_chunk($baris, 500) as $c) {
            Tagihan::insert($c);
        }

        return ['baru' => $baru, 'tanpa_tarif' => $tanpa];
    }

    /** Tagihan siswa beserta terbayar, urut. */
    public static function tagihanSiswa(Siswa $s): Collection
    {
        return Tagihan::where('siswa_id', $s->id)->select('tagihan.*')->denganTerbayar()->urut()->get();
    }

    /** @return array{harus:int, terbayar:int, kurang:int, potongan:int, jumlah:int, lunas:int} */
    public static function ringkas(Collection $tagihan): array
    {
        return [
            'harus' => $tagihan->sum(fn ($t) => $t->harus()), 'terbayar' => $tagihan->sum(fn ($t) => (int) $t->terbayar),
            'kurang' => $tagihan->sum(fn ($t) => $t->kurang()), 'potongan' => $tagihan->sum('potongan'),
            'jumlah' => $tagihan->count(), 'lunas' => $tagihan->filter(fn ($t) => $t->lunas())->count(),
        ];
    }

    // ------------------------------------------------------------ pembayaran

    /**
     * Catat satu pembayaran (satu kuitansi) untuk beberapa tagihan siswa.
     * @param  array<int,int|string>  $rinci  tagihan_id => jumlah
     */
    public static function bayar(User $u, Siswa $s, string $tanggal, string $cara, ?string $catatan, array $rinci, string $sumber = 'kasir'): Pembayaran
    {
        if (! Dates::valid($tanggal) || Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal bayar tidak valid atau di masa depan.');
        }
        if (! in_array($cara, ['tunai', 'transfer'], true)) {
            throw new UserError('Cara bayar harus tunai atau transfer.');
        }
        $rinci = collect($rinci)->map(fn ($v) => (int) preg_replace('/\D/', '', (string) $v))->filter(fn ($v) => $v > 0);
        if ($rinci->isEmpty()) {
            throw new UserError('Isi jumlah bayar pada minimal satu tagihan.');
        }

        return DB::transaction(function () use ($u, $s, $tanggal, $cara, $catatan, $rinci, $sumber) {
            $tagihan = Tagihan::whereIn('tagihan.id', $rinci->keys())->select('tagihan.*')->denganTerbayar()->lockForUpdate()->get()->keyBy('id');
            foreach ($rinci as $id => $jumlah) {
                $t = $tagihan[$id] ?? null;
                if (! $t || $t->siswa_id !== $s->id) {
                    throw new UserError('Tagihan tidak ditemukan untuk siswa ini.');
                }
                if ($jumlah > $t->kurang()) {
                    throw new UserError($t->label().': jumlah bayar '.self::rupiah($jumlah).' melebihi kekurangan '.self::rupiah($t->kurang()).'.');
                }
            }
            $p = Pembayaran::create([
                'nomor' => self::nomorBaru($sumber === 'impor' ? 'IMP' : (string) config('tanggungan.awalan_kuitansi', 'KW'), $tanggal),
                'siswa_id' => $s->id, 'tanggal' => $tanggal, 'total' => $rinci->sum(), 'cara' => $cara, 'sumber' => $sumber,
                'catatan' => $catatan ? mb_substr($catatan, 0, 500) : null, 'user_id' => $u->id,
                'penerima' => $u->nama ?: (string) config('tanggungan.bendahara'),
            ]);
            foreach ($rinci as $id => $jumlah) {
                PembayaranRinci::create(['pembayaran_id' => $p->id, 'tagihan_id' => $id, 'jumlah' => $jumlah]);
            }
            Audit::catat($sumber === 'impor' ? 'Impor pembayaran lama' : 'Catat pembayaran', $u->nama, "$p->nomor · {$s->nama} · ".self::rupiah($p->total));

            return $p;
        });
    }

    /** Nomor urut per tahun kalender: KW-2026-00001. */
    private static function nomorBaru(string $awalan, string $tanggal): string
    {
        $dasar = $awalan.'-'.substr($tanggal, 0, 4).'-';
        $akhir = Pembayaran::where('nomor', 'like', $dasar.'%')->lockForUpdate()->orderByDesc('nomor')->value('nomor');
        $n = $akhir ? (int) substr($akhir, strlen($dasar)) + 1 : 1;

        return $dasar.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public static function batal(User $u, Pembayaran $p, string $alasan): void
    {
        if ($p->batal()) {
            throw new UserError('Kuitansi ini sudah dibatalkan.');
        }
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < 5) {
            throw new UserError('Tulis alasan pembatalan (minimal 5 huruf).');
        }
        $p->update(['batal_pada' => now(), 'batal_alasan' => mb_substr($alasan, 0, 500), 'batal_oleh' => $u->nama]);
        Audit::catat('Batalkan kuitansi', $u->nama, "$p->nomor · ".self::rupiah($p->total)." · $alasan");
    }

    /** Keringanan / beasiswa / bebas: potongan pada tagihan (0 = hapus keringanan). */
    public static function keringanan(User $u, Tagihan $t, int $potongan, string $alasan): void
    {
        $alasan = trim($alasan);
        if ($potongan < 0 || $potongan > $t->nominal) {
            throw new UserError('Potongan harus antara 0 dan '.self::rupiah($t->nominal).'.');
        }
        if ($potongan > 0 && $alasan === '') {
            throw new UserError('Tulis alasan keringanan (mis. beasiswa, yatim, kesepakatan komite).');
        }
        $terbayar = (int) (Tagihan::whereKey($t->id)->select('tagihan.*')->denganTerbayar()->value('terbayar') ?? 0);
        if ($t->nominal - $potongan < $terbayar) {
            throw new UserError($t->label().' sudah dibayar '.self::rupiah($terbayar).'; potongan terlalu besar.');
        }
        $t->update(['potongan' => $potongan, 'potongan_alasan' => $potongan ? mb_substr($alasan, 0, 255) : null]);
        Audit::catat('Keringanan', $u->nama, $t->siswa?->nama.' · '.$t->label().' · potongan '.self::rupiah($potongan).($alasan ? " · $alasan" : ''));
    }
}
