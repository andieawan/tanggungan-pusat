<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembayaranRinci extends Model
{
    protected $table = 'pembayaran_rinci';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['jumlah' => 'integer', 'tagihan_id' => 'integer', 'pembayaran_id' => 'integer'];
    }

    public function tagihan()
    {
        return $this->belongsTo(Tagihan::class);
    }

    public function pembayaran()
    {
        return $this->belongsTo(Pembayaran::class);
    }
}
