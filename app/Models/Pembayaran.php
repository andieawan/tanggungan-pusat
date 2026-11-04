<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu kuitansi. Tidak pernah dihapus: pembatalan dicatat (batal_pada, alasan, oleh). */
class Pembayaran extends Model
{
    protected $table = 'pembayaran';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['siswa_id' => 'integer', 'total' => 'integer', 'tanggal' => 'date:Y-m-d'];
    }

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function rinci()
    {
        return $this->hasMany(PembayaranRinci::class);
    }

    public function scopeSah($q)
    {
        return $q->whereNull('batal_pada');
    }

    public function batal(): bool
    {
        return $this->batal_pada !== null;
    }
}
