<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'password_pusat', 'remember_token'];

    private array $cache = [];

    protected function casts(): array
    {
        return ['roles' => 'array', 'aktif' => 'boolean', 'password' => 'hashed'];
    }

    public function hasRole(string ...$r): bool
    {
        return (bool) array_intersect($r, $this->roles ?? []);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** Admin atau bendahara: mencatat pembayaran, keringanan, tarif, impor. */
    public function isKasir(): bool
    {
        return $this->hasRole('admin', 'bendahara');
    }

    /** Melihat semua kelas & siswa (kasir + kepala sekolah). */
    public function lihatSemua(): bool
    {
        return $this->hasRole('admin', 'bendahara', 'kepsek');
    }

    /** Data pegawai milik akun ini (untuk wali kelas). */
    public function pegawai(): ?Pegawai
    {
        return $this->ingat('pegawai', fn () => $this->pegawai_uuid ? Pegawai::where('pusat_uuid', $this->pegawai_uuid)->first() : null);
    }

    /** Kelas aktif yang diwalikan akun ini. */
    public function kelasWali()
    {
        return $this->ingat('wali', fn () => $this->hasRole('wali') && $this->pegawai_uuid
            ? Kelas::where('wali_uuid', $this->pegawai_uuid)->whereNull('dihapus_pada')->orderBy('nama')->get() : collect());
    }

    private function ingat(string $k, callable $f): mixed
    {
        if (! array_key_exists($k, $this->cache)) {
            $this->cache[$k] = $f();
        }

        return $this->cache[$k];
    }

    public function roleLabel(): string
    {
        $m = ['admin' => 'Admin', 'bendahara' => 'Bendahara', 'kepsek' => 'Kepala Sekolah', 'wali' => 'Wali Kelas'];

        return implode(', ', array_map(fn ($r) => $m[$r] ?? $r, $this->roles ?? []));
    }
}
