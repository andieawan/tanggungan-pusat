<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis tagihan: tahunan (sekali per tahun ajaran, boleh diangsur) atau bulanan (Juli–Juni). */
class JenisTagihan extends Model
{
    protected $table = 'jenis_tagihan';
    protected $guarded = [];

    public const POLA = ['tahunan' => 'Sekali per tahun ajaran (boleh diangsur)', 'bulanan' => 'Per bulan (Juli–Juni)'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'bawaan' => 'boolean', 'urut' => 'integer'];
    }

    public function scopeUrut($q)
    {
        return $q->orderBy('urut')->orderBy('nama');
    }

    public function bulanan(): bool
    {
        return $this->pola === 'bulanan';
    }

    /** Nama pendek untuk label per bulan. */
    public function pendek(): string
    {
        return $this->singkat ?: $this->nama;
    }
}
