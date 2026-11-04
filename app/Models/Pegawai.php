<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Salinan guru & staf dari Pusat (hanya dibaca; wali kelas). */
class Pegawai extends Model
{
    protected $table = 'pegawai';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function scopeAktif($q)
    {
        return $q->where('aktif', true);
    }

    public function label(): string
    {
        return $this->jabatan ?: ($this->jenis === 'staf' ? 'Staf' : 'Guru');
    }
}
