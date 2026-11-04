<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan sederhana kunci-nilai (absen mandiri, dsb.). */
class Pengaturan extends Model
{
    protected $table = 'pengaturan';
    protected $primaryKey = 'kunci';
    public $incrementing = false;
    protected $keyType = 'string';
    public const CREATED_AT = null;
    protected $guarded = [];

    private static ?array $cache = null;

    public static function ambil(string $kunci, mixed $bawaan = null): mixed
    {
        self::$cache ??= static::query()->pluck('nilai', 'kunci')->all();

        return array_key_exists($kunci, self::$cache) && self::$cache[$kunci] !== null && self::$cache[$kunci] !== '' ? self::$cache[$kunci] : $bawaan;
    }

    public static function simpan(array $nilai): void
    {
        foreach ($nilai as $k => $v) {
            static::updateOrCreate(['kunci' => $k], ['nilai' => $v === null ? null : (string) $v]);
        }
        self::$cache = null;
    }

    public static function lupakan(): void
    {
        self::$cache = null;
    }
}
