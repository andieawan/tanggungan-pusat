<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Kelas;
use App\Models\PembayaranRinci;
use App\Models\Siswa;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;

/**
 * Impor data lama (sebelum aplikasi dipakai) dari templat Excel per kelas.
 * Lembar DAFTAR ULANG: satu baris per siswa per tahun ajaran (nominal, dibayar / kurang).
 * Lembar SYARIAH: satu baris per siswa per tahun ajaran, kolom JULI…JUNI berisi jumlah dibayar;
 * "DU" = bulan tanpa iuran, "BEBAS" = keringanan penuh, "LUNAS" = dibayar penuh, kosong = belum dibayar.
 * Pembayaran lama dicatat sebagai kuitansi "IMP-…" (sumber impor) bertanggal hari impor.
 */
class ImporLama
{
    public const BULAN_KOLOM = ['juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6];

    /** Templat untuk satu kelas: siswa terisi, satu baris per tahun ajaran sejak kelas X. */
    public static function templat(Kelas $kelas): array
    {
        $tahun = Tanggungan::tahunAktif();
        $tk = Tanggungan::tingkatKelas($kelas);
        $mundur = ['X' => 0, 'XI' => 1, 'XII' => 2][$tk] ?? 0;
        $awal = (int) substr($tahun, 0, 4);
        $tahunTahun = [];
        for ($i = $mundur; $i >= 0; $i--) {
            $tk2 = Tanggungan::TINGKAT[array_search($tk ?: 'X', Tanggungan::TINGKAT, true) - $i] ?? $tk;
            $tahunTahun[] = [($awal - $i).'/'.($awal - $i + 1), $tk && str_starts_with($kelas->nama, $tk) ? $tk2.substr($kelas->nama, strlen($tk)) : $kelas->nama];
        }
        $siswa = Siswa::aktif()->where('kelas_id', $kelas->id)->orderBy('nama')->get();
        $du = [['NIS', 'NAMA', 'TAHUN AJARAN', 'KELAS SAAT ITU', 'NOMINAL', 'DIBAYAR', 'KURANG', 'KETERANGAN']];
        $sy = [array_merge(['NIS', 'NAMA', 'TAHUN AJARAN', 'KELAS SAAT ITU', 'TARIF PER BULAN'], array_map('strtoupper', array_keys(self::BULAN_KOLOM)))];
        foreach ($tahunTahun as [$th, $kn]) {
            foreach ($siswa as $s) {
                $du[] = [(string) $s->nis, $s->nama, $th, $kn, '', '', '', ''];
                $sy[] = array_merge([(string) $s->nis, $s->nama, $th, $kn, ''], array_fill(0, 12, ''));
            }
        }
        $petunjuk = [['PETUNJUK IMPOR DATA LAMA — '.$kelas->nama],
            ['1. Jangan ubah judul kolom. NIS dipakai untuk mencocokkan siswa (bila NIS kosong, nama harus sama persis).'],
            ['2. DAFTAR ULANG: isi NOMINAL (kosong = tarif di aplikasi), lalu DIBAYAR atau KURANG. Baris tanpa isi dilewati.'],
            ['3. SYARIAH: isi jumlah yang dibayar tiap bulan. DU = bulan tanpa iuran (termasuk daftar ulang), BEBAS = dibebaskan, LUNAS = dibayar penuh, kosong = belum dibayar.'],
            ['4. TARIF PER BULAN kosong = tarif di aplikasi (atau Rp'.number_format((int) config('tanggungan.syariah_bawaan'), 0, ',', '.').').'],
            ['5. KELAS SAAT ITU: nama kelas pada tahun ajaran tersebut (untuk rekap lama). Hapus baris tahun yang tidak ada datanya.'],
            ['6. Tagihan yang sudah punya pembayaran di aplikasi tidak ditimpa. Gunakan "Periksa saja" dulu sebelum menyimpan.']];

        return ['PETUNJUK' => ['rows' => $petunjuk, 'tebal' => [0]], 'DAFTAR ULANG' => $du, 'SYARIAH' => $sy];
    }

    /** @return array{ringkas:array, catatan:list<string>} */
    public static function proses(User $u, string $path, string $nama, bool $uji): array
    {
        $lembar = \App\Support\Xlsx::readAllSheets($path, $nama);
        $du = $sy = null;
        foreach ($lembar as $rows) {
            $hdr = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0] ?? []);
            if (in_array('juli', $hdr, true)) {
                $sy = $rows;
            } elseif (in_array('dibayar', $hdr, true) || in_array('kurang', $hdr, true)) {
                $du = $rows;
            }
        }
        if (! $du && ! $sy) {
            throw new UserError('Lembar DAFTAR ULANG / SYARIAH tidak ditemukan. Pakai templat dari halaman ini.');
        }
        $galat = [];
        $catatan = [];
        $rekam = []; // siswa_id => list<[jenis, tahun, tingkat, kelas, bulan, nominal, potongan, alasan, dibayar]>
        $cari = self::pencari();
        $tarif = Tarif::all()->keyBy(fn ($t) => "$t->jenis:$t->tahun_ajaran:$t->tingkat");
        $uang = fn ($v) => (int) preg_replace('/\D/', '', (string) $v);

        foreach ([['du', $du], ['syariah', $sy]] as [$jenis, $rows]) {
            if (! $rows) {
                continue;
            }
            $hdr = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($rows));
            $col = fn (string $n) => array_search($n, $hdr, true);
            foreach ($rows as $i => $r) {
                $baris = ($jenis === 'du' ? 'DAFTAR ULANG' : 'SYARIAH').' baris '.($i + 2);
                $nis = trim((string) ($r[$col('nis')] ?? ''));
                $nm = trim((string) ($r[$col('nama')] ?? ''));
                if ($nis === '' && $nm === '') {
                    continue;
                }
                $th = trim((string) ($r[$col('tahun ajaran')] ?? ''));
                if (! Tanggungan::tahunValid($th)) {
                    $galat[] = "$baris: tahun ajaran \"$th\" tidak valid (contoh 2025/2026).";

                    continue;
                }
                [$s, $e] = $cari($nis, $nm);
                if (! $s) {
                    $galat[] = "$baris: $e";

                    continue;
                }
                $kn = trim((string) ($r[$col('kelas saat itu')] ?? '')) ?: (string) $s->kelas?->nama;
                $tk = Tanggungan::tingkat($kn);
                if ($jenis === 'du') {
                    $nom = $uang($r[$col('nominal')] ?? '');
                    $bayar = trim((string) ($r[$col('dibayar')] ?? ''));
                    $kurang = trim((string) ($r[$col('kurang')] ?? ''));
                    if ($nom === 0 && $bayar === '' && $kurang === '') {
                        continue; // tidak ada data
                    }
                    $nom = $nom ?: (int) ($tarif["du:$th:$tk"]->nominal ?? 0) ?: ($bayar !== '' && $kurang !== '' ? $uang($bayar) + $uang($kurang) : 0);
                    if ($nom <= 0) {
                        $galat[] = "$baris ($s->nama): NOMINAL kosong dan tarif DU $th kelas $tk belum diatur.";

                        continue;
                    }
                    $dibayar = $bayar !== '' ? $uang($bayar) : max(0, $nom - $uang($kurang));
                    if ($dibayar > $nom) {
                        $galat[] = "$baris ($s->nama): dibayar melebihi nominal.";

                        continue;
                    }
                    $rekam[$s->id][] = ['du', $th, $tk, $kn, 0, $nom, 0, null, $dibayar];

                    continue;
                }
                $per = $uang($r[$col('tarif per bulan')] ?? '') ?: (int) ($tarif["syariah:$th:$tk"]->nominal ?? 0) ?: (int) config('tanggungan.syariah_bawaan');
                foreach (self::BULAN_KOLOM as $kolom => $b) {
                    $v = strtoupper(trim((string) ($r[$col($kolom)] ?? '')));
                    if (in_array($v, ['DU', '-'], true)) {
                        continue; // bulan tanpa iuran
                    }
                    if ($v === 'BEBAS') {
                        $rekam[$s->id][] = ['syariah', $th, $tk, $kn, $b, $per, $per, 'Data lama: bebas', 0];

                        continue;
                    }
                    $dibayar = in_array($v, ['LUNAS', 'L', 'V', '✓'], true) ? $per : $uang($v);
                    if ($v !== '' && $dibayar === 0 && ! ctype_digit(str_replace(['.', ',', 'RP', ' '], '', $v))) {
                        $galat[] = "$baris ($s->nama) ".ucfirst($kolom).": isi \"$v\" tidak dikenali.";

                        continue;
                    }
                    if ($dibayar > $per) {
                        $galat[] = "$baris ($s->nama) ".ucfirst($kolom).": dibayar melebihi tarif per bulan.";

                        continue;
                    }
                    $rekam[$s->id][] = ['syariah', $th, $tk, $kn, $b, $per, 0, null, $dibayar];
                }
            }
        }
        if ($galat) {
            throw new UserError("Impor dibatalkan, perbaiki dulu:\n• ".implode("\n• ", array_slice($galat, 0, 25)).(count($galat) > 25 ? "\n• …dan ".(count($galat) - 25).' lainnya' : ''));
        }

        $h = ['siswa' => 0, 'tagihan' => 0, 'dibayar' => 0, 'dilewati' => 0];
        DB::beginTransaction();
        try {
            foreach ($rekam as $sid => $daftar) {
                $siswa = Siswa::find($sid);
                $rinci = [];
                foreach ($daftar as [$jenis, $th, $tk, $kn, $b, $nom, $pot, $alasan, $dibayar]) {
                    $t = Tagihan::where(['siswa_id' => $sid, 'jenis' => $jenis, 'tahun_ajaran' => $th, 'bulan' => $b])->first();
                    if ($t && PembayaranRinci::where('tagihan_id', $t->id)->whereHas('pembayaran', fn ($q) => $q->whereNull('batal_pada'))->exists()) {
                        $h['dilewati']++;
                        $catatan[] = "$siswa->nama · ".Tanggungan::label($jenis, $b, $th).': sudah ada pembayaran, tidak ditimpa.';

                        continue;
                    }
                    $data = ['tingkat' => $tk, 'kelas_nama' => $kn, 'nominal' => $nom, 'potongan' => $pot, 'potongan_alasan' => $alasan];
                    $t ? $t->update($data) : $t = Tagihan::create(['siswa_id' => $sid, 'jenis' => $jenis, 'tahun_ajaran' => $th, 'bulan' => $b] + $data);
                    $h['tagihan']++;
                    if ($dibayar > 0) {
                        $rinci[$t->id] = $dibayar;
                    }
                }
                if ($rinci) {
                    Tanggungan::bayar($u, $siswa, Dates::today(), 'tunai', 'Data lama (impor dari Excel)', $rinci, 'impor');
                    $h['dibayar'] += array_sum($rinci);
                }
                $h['siswa']++;
            }
            $uji ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $uji) {
            Audit::catat('Impor data lama', $u->nama, "$nama · {$h['siswa']} siswa · {$h['tagihan']} tagihan · ".Tanggungan::rupiah($h['dibayar']));
        }

        return ['ringkas' => $h, 'catatan' => array_slice($catatan, 0, 30)];
    }

    /** Cari siswa: NIS (pasti) lalu nama persis (harus satu-satunya). */
    private static function pencari(): \Closure
    {
        $semua = Siswa::with('kelas')->get(['id', 'nis', 'nama', 'kelas_id', 'status']);
        $perNis = $semua->filter(fn ($s) => $s->nis !== '')->keyBy(fn ($s) => trim($s->nis));
        $perNama = $semua->groupBy(fn ($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim($s->nama))));

        return function (string $nis, string $nama) use ($perNis, $perNama) {
            if ($nis !== '') {
                return isset($perNis[$nis]) ? [$perNis[$nis], null] : [null, "NIS $nis tidak dikenal."];
            }
            $cocok = $perNama[mb_strtolower(preg_replace('/\s+/', ' ', $nama))] ?? collect();

            return match ($cocok->count()) {
                1 => [$cocok->first(), null],
                0 => [null, "siswa \"$nama\" tidak ditemukan (isi NIS-nya)."],
                default => [null, "nama \"$nama\" dimiliki beberapa siswa; isi NIS-nya."],
            };
        };
    }
}
