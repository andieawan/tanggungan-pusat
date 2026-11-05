<?php

namespace App\Services;

use App\Models\JenisTagihan;
use App\Models\PembayaranRinci;
use App\Models\Tagihan;
use App\Models\User;

/**
 * Rekap satu kelas satu tahun ajaran, berbentuk seperti lembar Excel bendahara/wali kelas. Satu lembar per jenis
 * tahunan (angsuran, kurang; mis. daftar ulang) dan dua lembar per jenis bulanan (semester ganjil & genap; mis. syariah).
 * Kelas memakai nama yang tersimpan di tagihan, sehingga rekap tahun lalu tetap benar setelah naik kelas/lulus.
 */
class Rekap
{
    /** Kelas yang punya tagihan pada tahun itu (yang terlihat oleh pengguna). */
    public static function daftarKelas(User $u, string $tahun): array
    {
        return Tagihan::where('tahun_ajaran', $tahun)->whereIn('siswa_id', Akses::siswaRiwayat($u))->distinct()
            ->pluck('kelas_nama')->filter()->sort(SORT_NATURAL)->values()->all();
    }

    /** @return array{siswa: \Illuminate\Support\Collection, lembar: list<array>, ringkas: array, wali: ?string} */
    public static function kelas(User $u, string $tahun, string $kelas): array
    {
        $tagihan = Tagihan::with('siswa')->where(['tahun_ajaran' => $tahun, 'kelas_nama' => $kelas])->whereIn('siswa_id', Akses::siswaRiwayat($u))
            ->select('tagihan.*')->denganTerbayar()->get();
        $siswa = $tagihan->pluck('siswa')->filter()->unique('id')->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $per = $tagihan->groupBy('siswa_id');
        $ada = $tagihan->pluck('jenis')->unique();
        $jenis = JenisTagihan::urut()->get()->whereIn('kode', $ada)->values();
        foreach ($ada->diff($jenis->pluck('kode')) as $kode) { // jenis yang sudah dihapus dari daftar: tetap tampil
            $jenis->push(new JenisTagihan(['kode' => $kode, 'nama' => $kode, 'pola' => $tagihan->where('jenis', $kode)->contains(fn ($t) => $t->bulan > 0) ? 'bulanan' : 'tahunan']));
        }

        // Angsuran jenis tahunan: pembayaran sah per tagihan, urut tanggal.
        $tahunanIds = $tagihan->where('bulan', 0)->pluck('id');
        $angsuran = PembayaranRinci::query()->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_rinci.pembayaran_id')
            ->whereIn('pembayaran_rinci.tagihan_id', $tahunanIds)->whereNull('pembayaran.batal_pada')
            ->orderBy('pembayaran.tanggal')->orderBy('pembayaran.id')->get(['pembayaran_rinci.tagihan_id', 'pembayaran_rinci.jumlah'])
            ->groupBy('tagihan_id')->map(fn ($r) => $r->pluck('jumlah')->map(fn ($v) => (int) $v)->all());

        $lembar = [];
        foreach ($jenis as $j) {
            if (! $j->bulanan()) {
                $data = [];
                foreach ($siswa as $s) {
                    $t = ($per[$s->id] ?? collect())->first(fn ($x) => $x->jenis === $j->kode && $x->bulan === 0);
                    $data[$s->id] = $t ? ['t' => $t, 'angsuran' => $angsuran[$t->id] ?? []] : null;
                }
                $lembar[] = ['kunci' => $j->kode, 'judul' => $j->nama, 'pola' => 'tahunan', 'data' => $data,
                    'kolomAngsuran' => max(2, collect($data)->filter()->map(fn ($d) => count($d['angsuran']))->max() ?? 0)];

                continue;
            }
            foreach ([1 => [7, 8, 9, 10, 11, 12], 2 => [1, 2, 3, 4, 5, 6]] as $sm => $bulan) {
                $baris = [];
                foreach ($siswa as $s) {
                    $tj = ($per[$s->id] ?? collect())->where('jenis', $j->kode)->keyBy('bulan');
                    $sel = [];
                    foreach ($bulan as $b) {
                        $sel[$b] = $tj[$b] ?? null;
                    }
                    $ts = collect($sel)->filter();
                    $baris[$s->id] = ['sel' => $sel, 'terbayar' => $ts->sum(fn ($t) => (int) $t->terbayar), 'harus' => $ts->sum(fn ($t) => $t->harus()),
                        'kurang' => $ts->sum(fn ($t) => $t->kurang()), 'ada' => $tj->isNotEmpty()];
                }
                $lembar[] = ['kunci' => $j->kode.':'.$sm, 'judul' => $j->pendek().' semester '.$sm, 'nama' => $j->nama, 'semester' => $sm,
                    'pola' => 'bulanan', 'bulan' => $bulan, 'baris' => $baris];
            }
        }

        return ['siswa' => $siswa, 'lembar' => $lembar, 'ringkas' => Tanggungan::ringkas($tagihan),
            'wali' => \App\Models\Kelas::where('nama', $kelas)->orderByDesc('id')->value('wali_nama')];
    }

    /** Lembar Excel: kop singkat + tabel, seperti format lama. */
    public static function excel(array $r, string $tahun, string $kelas): array
    {
        $c = config('tanggungan.surat');
        $kop = fn (string $judul) => [[$c['yayasan'].' '.$c['yayasan2']], [$c['nama']], [mb_strtoupper($judul)], ['TAHUN PELAJARAN '.$tahun], ['Kelas : '.$kelas], ['Wali Kelas : '.($r['wali'] ?: '-')], []];
        $sheets = [];
        foreach ($r['lembar'] as $l) {
            if ($l['pola'] === 'tahunan') {
                $hdr = ['NO', 'NIS', 'NAMA', 'L/P', 'NOMINAL', 'KERINGANAN'];
                for ($i = 1; $i <= $l['kolomAngsuran']; $i++) {
                    $hdr[] = 'ANGSURAN '.$i;
                }
                $rows = $kop($l['judul']);
                $rows[] = array_merge($hdr, ['KURANG', 'KETERANGAN']);
                foreach ($r['siswa']->values() as $i => $s) {
                    $d = $l['data'][$s->id];
                    $a = $d ? $d['angsuran'] : [];
                    $row = [$i + 1, (string) $s->nis, $s->nama, (string) $s->jk, $d ? $d['t']->nominal : null, $d && $d['t']->potongan ? $d['t']->potongan : null];
                    for ($k = 0; $k < $l['kolomAngsuran']; $k++) {
                        $row[] = $a[$k] ?? null;
                    }
                    $row[] = $d ? $d['t']->kurang() : null;
                    $row[] = ! $d ? 'TANPA TAGIHAN' : ($d['t']->lunas() ? 'LUNAS' : 'TIDAK LUNAS');
                    $rows[] = $row;
                }
            } else {
                $rows = $kop($l['nama'].' semester '.($l['semester'] === 1 ? 'ganjil' : 'genap'));
                $rows[] = array_merge(['NO', 'NIS', 'NAMA', 'L/P'], array_map(fn ($b) => strtoupper(Tanggungan::namaBulan($b)), $l['bulan']), ['TOTAL', 'TARGET', 'KURANG', 'KETERANGAN']);
                foreach ($r['siswa']->values() as $i => $s) {
                    $b = $l['baris'][$s->id];
                    $row = [$i + 1, (string) $s->nis, $s->nama, (string) $s->jk];
                    foreach ($b['sel'] as $t) {
                        $row[] = self::isiSel($t, $b['ada'], explode(':', $l['kunci'])[0]);
                    }
                    $rows[] = array_merge($row, [$b['terbayar'], $b['harus'], $b['kurang'], ! $b['ada'] ? 'TANPA TAGIHAN' : ($b['kurang'] === 0 ? 'LUNAS' : 'TIDAK LUNAS')]);
                }
            }
            $sheets[mb_strtoupper(mb_substr($l['judul'], 0, 31))] = ['rows' => $rows, 'tebal' => [0, 1, 2, 3, 7]];
        }

        return $sheets;
    }

    /** Isi sel bulan: jumlah terbayar; "DU" (syariah) / "-" = bulan tanpa tagihan; "BEBAS" = keringanan penuh. */
    public static function isiSel(?Tagihan $t, bool $ada, string $jenis = 'syariah'): int|string|null
    {
        if (! $t) {
            return $ada ? ($jenis === 'syariah' ? 'DU' : '-') : null;
        }
        if ($t->harus() === 0) {
            return 'BEBAS';
        }

        return $t->terbayar ? (int) $t->terbayar : null;
    }
}
