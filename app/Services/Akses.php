<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Siapa melihat siswa mana: admin, bendahara, kepsek = semua; wali kelas = siswa kelas perwaliannya saat ini. */
class Akses
{
    public static function siswa(User $u): Builder
    {
        $q = Siswa::query();
        if ($u->lihatSemua()) {
            return $q;
        }
        $kelas = $u->kelasWali()->pluck('id');

        return $kelas->isEmpty() ? $q->whereRaw('1 = 0') : $q->whereIn('kelas_id', $kelas);
    }

    /** Id siswa untuk rekap & riwayat (subquery). */
    public static function siswaRiwayat(User $u)
    {
        return self::siswa($u)->select('siswa.id');
    }

    public static function bolehLihat(User $u, Siswa $s): bool
    {
        return self::siswa($u)->whereKey($s->id)->exists();
    }

    /** Punya sesuatu untuk dilihat di aplikasi ini. */
    public static function punyaAkses(User $u): bool
    {
        return $u->lihatSemua() || $u->kelasWali()->isNotEmpty();
    }
}
