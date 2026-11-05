<?php

namespace App\Models;

use App\Services\Tanggungan;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu tanggungan siswa: jenis tahunan satu tahun ajaran (bulan = 0, mis. DU) atau jenis bulanan satu bulan (mis. syariah).
 * Terbayar dihitung dari rincian pembayaran yang tidak dibatalkan (lihat scope denganTerbayar).
 */
class Tagihan extends Model
{
    protected $table = 'tagihan';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['siswa_id' => 'integer', 'bulan' => 'integer', 'nominal' => 'integer', 'potongan' => 'integer', 'terbayar' => 'integer'];
    }

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function rinci()
    {
        return $this->hasMany(PembayaranRinci::class);
    }

    /** Tambahkan kolom "terbayar" (jumlah pembayaran sah). */
    public function scopeDenganTerbayar($q)
    {
        return $q->addSelect(['terbayar' => PembayaranRinci::query()->selectRaw('COALESCE(SUM(pembayaran_rinci.jumlah), 0)')
            ->join('pembayaran', 'pembayaran.id', '=', 'pembayaran_rinci.pembayaran_id')
            ->whereColumn('pembayaran_rinci.tagihan_id', 'tagihan.id')->whereNull('pembayaran.batal_pada')]);
    }

    /** Urutan wajar: tahun ajaran, DU dulu, lalu bulan Juli → Juni. */
    public function scopeUrut($q)
    {
        return $q->orderBy('tahun_ajaran')->orderByRaw("CASE WHEN jenis = 'du' THEN 0 WHEN bulan = 0 THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN bulan >= 7 THEN bulan - 6 WHEN bulan = 0 THEN 0 ELSE bulan + 6 END')->orderBy('jenis');
    }

    public function harus(): int
    {
        return max(0, $this->nominal - $this->potongan);
    }

    public function kurang(): int
    {
        return max(0, $this->harus() - (int) ($this->terbayar ?? 0));
    }

    public function lunas(): bool
    {
        return $this->kurang() === 0;
    }

    public function label(): string
    {
        return Tanggungan::label($this->jenis, $this->bulan, $this->tahun_ajaran);
    }
}
