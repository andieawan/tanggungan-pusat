<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Salinan siswa dari Pusat. Tidak pernah dihapus: siswa lulus/keluar tetap tersimpan beserta tanggungannya. */
class Siswa extends Model
{
    protected $table = 'siswa';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tanggal_lahir' => 'date:Y-m-d'];
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function tagihan()
    {
        return $this->hasMany(Tagihan::class);
    }

    public function pembayaran()
    {
        return $this->hasMany(Pembayaran::class);
    }

    public function scopeAktif($q)
    {
        return $q->where('status', 'aktif');
    }
}
