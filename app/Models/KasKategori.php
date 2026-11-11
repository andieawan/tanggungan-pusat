<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KasKategori extends Model
{
    protected $table = 'kas_kategori';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'urut' => 'integer'];
    }
}
