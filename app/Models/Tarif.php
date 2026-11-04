<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tarif satu jenis tanggungan untuk satu tahun ajaran & tingkat. DU: setahun; syariah: per bulan. */
class Tarif extends Model
{
    protected $table = 'tarif';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['nominal' => 'integer', 'bulan_bebas' => 'array'];
    }
}
