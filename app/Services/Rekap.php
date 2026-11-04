<?php

namespace App\Services;

use App\Models\PembayaranRinci;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Rekap satu kelas satu tahun ajaran, berbentuk seperti lembar Excel bendahara/wali kelas:
 * daftar ulang (angsuran, kurang) dan iuran syariah per semester (per bulan, total, kurang).
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

    /** @return array{siswa: Collection, du: array, semester: array<int,array>} */
    public static function kelas(User $u, string $tahun, string $kelas): array
    {
        $tagihan = Tagihan::with('siswa')->where(['tahun_ajaran' => $tahun, 'kelas_nama' => $kelas])->whereIn('siswa_id', Akses::siswaRiwayat($u))
            ->select('tagihan.*')->denganTerbayar()->get();
        $siswa = $tagihan->pluck('siswa')->filter()->unique('id')->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $per = $tagihan->groupBy('siswa_id');

        // Angsuran daftar ulang: pembayaran sah per tagihan DU, urut tanggal.
        $duIds = $tagihan->where('jenis', 'du')->pluck('id');
        $angsuran = PembayaranRinci::query()->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_rinci.pembayaran_id')
            ->whereIn('pembayaran_rinci.tagihan_id', $duIds)->whereNull('pembayaran.batal_pada')
            ->orderBy('pembayaran.tanggal')->orderBy('pembayaran.id')->get(['pembayaran_rinci.tagihan_id', 'pembayaran_rinci.jumlah'])
            ->groupBy('tagihan_id')->map(fn ($r) => $r->pluck('jumlah')->map(fn ($v) => (int) $v)->all());
        $kolomAngsuran = max(2, $angsuran->map(fn ($a) => count($a))->max() ?? 0);

        $du = [];
        foreach ($siswa as $s) {
            $t = ($per[$s->id] ?? collect())->firstWhere('jenis', 'du');
            $du[$s->id] = $t ? ['t' => $t, 'angsuran' => $angsuran[$t->id] ?? []] : null;
        }
        $semester = [];
        foreach ([1 => [7, 8, 9, 10, 11, 12], 2 => [1, 2, 3, 4, 5, 6]] as $sm => $bulan) {
            $baris = [];
            foreach ($siswa as $s) {
                $sy = ($per[$s->id] ?? collect())->where('jenis', 'syariah')->keyBy('bulan');
                $sel = [];
                foreach ($bulan as $b) {
                    $sel[$b] = $sy[$b] ?? null;
                }
                $ts = collect($sel)->filter();
                $baris[$s->id] = ['sel' => $sel, 'terbayar' => $ts->sum(fn ($t) => (int) $t->terbayar), 'harus' => $ts->sum(fn ($t) => $t->harus()),
                    'kurang' => $ts->sum(fn ($t) => $t->kurang()), 'ada' => $ts->isNotEmpty()];
            }
            $semester[$sm] = ['bulan' => $bulan, 'baris' => $baris];
        }

        return ['siswa' => $siswa, 'du' => $du, 'kolomAngsuran' => $kolomAngsuran, 'semester' => $semester,
            'ringkas' => Tanggungan::ringkas($tagihan), 'wali' => \App\Models\Kelas::where('nama', $kelas)->orderByDesc('id')->value('wali_nama')];
    }

    /** Lembar Excel: kop singkat + tabel, seperti format lama. */
    public static function excel(array $r, string $tahun, string $kelas): array
    {
        $c = config('tanggungan.surat');
        $kop = fn (string $judul) => [[$c['yayasan'].' '.$c['yayasan2']], [$c['nama']], [$judul], ['TAHUN PELAJARAN '.$tahun], ['Kelas : '.$kelas], ['Wali Kelas : '.($r['wali'] ?: '-')], []];
        $sheets = [];

        $hdr = ['NO', 'NIS', 'NAMA', 'L/P', 'NOMINAL', 'KERINGANAN'];
        for ($i = 1; $i <= $r['kolomAngsuran']; $i++) {
            $hdr[] = 'ANGSURAN '.$i;
        }
        $rows = $kop('DAFTAR ULANG');
        $rows[] = array_merge($hdr, ['KURANG', 'KETERANGAN']);
        foreach ($r['siswa']->values() as $i => $s) {
            $d = $r['du'][$s->id];
            $a = $d ? $d['angsuran'] : [];
            $row = [$i + 1, (string) $s->nis, $s->nama, (string) $s->jk, $d ? $d['t']->nominal : null, $d && $d['t']->potongan ? $d['t']->potongan : null];
            for ($k = 0; $k < $r['kolomAngsuran']; $k++) {
                $row[] = $a[$k] ?? null;
            }
            $row[] = $d ? $d['t']->kurang() : null;
            $row[] = ! $d ? 'TANPA TAGIHAN' : ($d['t']->lunas() ? 'LUNAS' : 'TIDAK LUNAS');
            $rows[] = $row;
        }
        $sheets['DAFTAR ULANG'] = ['rows' => $rows, 'tebal' => [0, 1, 2, 3, 7]];

        foreach ($r['semester'] as $sm => $data) {
            $rows = $kop('IURAN SYARIAH SEMESTER '.($sm === 1 ? 'GANJIL' : 'GENAP'));
            $rows[] = array_merge(['NO', 'NIS', 'NAMA', 'L/P'], array_map(fn ($b) => strtoupper(Tanggungan::namaBulan($b)), $data['bulan']), ['TOTAL', 'TARGET', 'KURANG', 'KETERANGAN']);
            foreach ($r['siswa']->values() as $i => $s) {
                $b = $data['baris'][$s->id];
                $row = [$i + 1, (string) $s->nis, $s->nama, (string) $s->jk];
                foreach ($b['sel'] as $t) {
                    $row[] = self::isiSel($t, $b['ada']);
                }
                $rows[] = array_merge($row, [$b['terbayar'], $b['harus'], $b['kurang'], ! $b['ada'] ? 'TANPA TAGIHAN' : ($b['kurang'] === 0 ? 'LUNAS' : 'TIDAK LUNAS')]);
            }
            $sheets['SEMESTER '.$sm] = ['rows' => $rows, 'tebal' => [0, 1, 2, 3, 7]];
        }

        return $sheets;
    }

    /** Isi sel bulan: jumlah terbayar; "DU" = bulan tanpa iuran (termasuk daftar ulang); "BEBAS" = keringanan penuh. */
    public static function isiSel(?Tagihan $t, bool $ada): int|string|null
    {
        if (! $t) {
            return $ada ? 'DU' : null;
        }
        if ($t->harus() === 0) {
            return 'BEBAS';
        }

        return $t->terbayar ? (int) $t->terbayar : null;
    }
}
