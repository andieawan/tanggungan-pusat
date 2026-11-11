<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu transaksi buku kas (selain pembayaran siswa). Tidak pernah dihapus: pembatalan dicatat. */
class KasTransaksi extends Model
{
    protected $table = 'kas_transaksi';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['tanggal' => 'date:Y-m-d', 'jumlah' => 'integer', 'kategori_id' => 'integer'];
    }

    public function kategori()
    {
        return $this->belongsTo(KasKategori::class);
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
