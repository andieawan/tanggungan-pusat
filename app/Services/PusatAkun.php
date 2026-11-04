<?php

namespace App\Services;

use App\Models\User;

/** Mencocokkan profil dari Pusat (userinfo) dengan akun lokal Tanggungan. */
class PusatAkun
{
    /**
     * @param  array  $info  objek "user" dari Pusat (sub, username, nama, peran[], pegawai{uuid}…)
     * @return array{0:?User,1:?string}  [akun, pesan galat]
     */
    public static function masuk(array $info): array
    {
        $uuid = (string) ($info['sub'] ?? '');
        $username = strtolower(trim((string) ($info['username'] ?? '')));
        if ($uuid === '' || $username === '') {
            return [null, 'Data akun dari Pusat tidak lengkap.'];
        }
        $peran = self::petakanPeran((array) ($info['peran'] ?? []));
        if (! $peran) {
            return [null, 'Akun Anda belum diberi peran di aplikasi Tanggungan Siswa. Hubungi administrator Pusat.'];
        }

        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user) {
            $kandidat = User::whereRaw('LOWER(username) = ?', [$username])->first();
            if ($kandidat && $kandidat->pusat_uuid !== null) {
                return [null, 'Username sudah tertaut ke akun Pusat lain. Hubungi administrator.'];
            }
            $user = $kandidat; // penautan pertama lewat username yang sama (mis. admin lokal darurat)
        }

        if ($user && ! $user->aktif) {
            // Dinonaktifkan otomatis oleh Pusat lalu aksesnya dipulihkan di Pusat (login ini membuktikannya): aktifkan lagi.
            if ($user->pusat_uuid !== null && PusatKlien::baca('dc:'.$user->pusat_uuid) !== null) {
                $user->update(['aktif' => true]);
                PusatKlien::hapus('dc:'.$user->pusat_uuid);
                Audit::catat('Akses dipulihkan Pusat', $user->nama, 'Akun diaktifkan kembali karena akses dipulihkan di Pusat');
            } else {
                return [null, 'Akun Anda dinonaktifkan di aplikasi Tanggungan Siswa. Hubungi administrator.'];
            }
        }

        $data = [
            'pusat_uuid' => $uuid, 'roles' => $peran, 'nama' => (string) ($info['nama'] ?? $username),
            'pegawai_uuid' => $info['pegawai']['uuid'] ?? null, 'siswa_uuid' => $info['siswa']['uuid'] ?? null,
        ];
        if (! empty($info['email'])) {
            $data['email'] = (string) $info['email'];
        }
        if (! $user) {
            $user = User::create($data + ['username' => $username, 'aktif' => true]);
            Audit::catat('Akun dari Pusat', $user->nama, 'Akun dibuat otomatis saat login pertama lewat Pusat, peran: '.implode(', ', $peran));

            return [$user, null];
        }
        $dulu = collect($user->roles ?? [])->sort()->values()->all();
        $user->update($data);
        if ($dulu !== collect($peran)->sort()->values()->all()) {
            Audit::catat('Peran dari Pusat', $user->nama, 'Peran diselaraskan: '.implode(', ', $dulu).' → '.implode(', ', $peran));
        }

        return [$user, null];
    }

    /** @return string[] peran lokal (unik); peran Pusat yang tidak dikenal diabaikan */
    public static function petakanPeran(array $peranPusat): array
    {
        $peta = (array) config('pusat.peran_peta');
        $hasil = [];
        foreach ($peranPusat as $p) {
            if (isset($peta[$p])) {
                $hasil[] = $peta[$p];
            }
        }

        return array_values(array_unique($hasil));
    }

    /** Webhook akun: hanya arah AMAN yang diterapkan otomatis (mencabut akses). */
    public static function cabutBilaPerlu(array $data): bool
    {
        $uuid = (string) ($data['uuid'] ?? '');
        if ($uuid === '') {
            return false;
        }
        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user || ! $user->aktif) {
            return false;
        }
        $dicabut = (array_key_exists('aktif', $data) && $data['aktif'] === false)
            || (array_key_exists('peran', $data) && self::petakanPeran((array) $data['peran']) === []);
        if (! $dicabut) {
            return false;
        }
        $user->update(['aktif' => false]); // sesi aktif keluar pada request berikutnya (AkunAktif)
        PusatKlien::simpan('dc:'.$uuid, 1); // penanda: boleh dipulihkan otomatis bila Pusat memberi akses lagi
        Audit::catat('Akses dicabut Pusat', $user->nama, 'Akun dinonaktifkan karena akses dicabut di Pusat');

        return true;
    }

    /**
     * Login cadangan: simpan salinan hash password dari Pusat (izin "akun_sandi") dan selaraskan username,
     * agar akun Pusat tetap bisa masuk lewat login lokal saat Pusat tidak bisa dihubungi.
     * null = akun masih wajib ganti password awal → login lokal ditutup sampai password diganti di Pusat.
     */
    public static function terapkanSandi(array $data): void
    {
        if (empty($data['uuid']) || ! \Illuminate\Support\Facades\Schema::hasColumn('users', 'password_pusat')) {
            return;
        }
        $user = User::where('pusat_uuid', (string) $data['uuid'])->first();
        if (! $user) {
            return;
        }
        $ubah = [];
        if (array_key_exists('password_hash', $data)) {
            $h = is_string($data['password_hash']) && str_starts_with($data['password_hash'], '$') ? $data['password_hash'] : null;
            if ($user->password_pusat !== $h) {
                $ubah['password_pusat'] = $h;
            }
        }
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        if ($username !== '' && $username !== strtolower((string) $user->username)
            && ! User::whereRaw('LOWER(username) = ?', [$username])->where('id', '!=', $user->id)->exists()) {
            $ubah['username'] = $username;
        }
        if ($ubah) {
            $user->forceFill($ubah)->save();
        }
    }

    /** Cocokkan password dengan salinan dari Pusat (login cadangan). */
    public static function cocokSandiPusat(?User $user, string $plain): bool
    {
        $h = $user && $user->pusat_uuid ? (string) ($user->password_pusat ?? '') : '';
        if ($h === '') {
            return false;
        }
        try {
            return password_verify($plain, $h);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sinkron penuh: akun yang diberi peran di aplikasi ini (Pusat → Akun) dibuat/ditautkan di sini tanpa menunggu
     * login pertama, beserta salinan hash password untuk login cadangan.
     *
     * @return array{baru:int, ubah:int}
     */
    public static function sinkronDaftar(array $daftar): array
    {
        $h = ['baru' => 0, 'ubah' => 0];
        foreach ($daftar as $a) {
            if (empty($a['aktif']) || empty($a['uuid']) || ! self::petakanPeran((array) ($a['peran'] ?? []))) {
                continue;
            }
            $lama = User::where('pusat_uuid', $a['uuid'])->first();
            [$u] = self::masuk([
                'sub' => $a['uuid'], 'username' => $a['username'] ?? '', 'nama' => $a['nama'] ?? null, 'email' => $a['email'] ?? null,
                'peran' => $a['peran'] ?? [], 'pegawai' => ['uuid' => $a['pegawai_uuid'] ?? null], 'siswa' => ['uuid' => $a['siswa_uuid'] ?? null],
            ]);
            if ($u) {
                self::terapkanSandi($a);
                $lama ? null : ($u->wasRecentlyCreated ? $h['baru']++ : $h['ubah']++);
            }
        }

        return $h;
    }
}
