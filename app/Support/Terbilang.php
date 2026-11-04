<?php

namespace App\Support;

/** Angka → kata Bahasa Indonesia untuk kuitansi: 150000 → "seratus lima puluh ribu". */
class Terbilang
{
    private const SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function kata(int $n): string
    {
        if ($n === 0) {
            return 'nol';
        }

        return trim(preg_replace('/\s+/', ' ', self::k($n)));
    }

    private static function k(int $n): string
    {
        return match (true) {
            $n < 12 => self::SATUAN[$n],
            $n < 20 => self::k($n - 10).' belas',
            $n < 100 => self::k(intdiv($n, 10)).' puluh '.self::k($n % 10),
            $n < 200 => 'seratus '.self::k($n - 100),
            $n < 1000 => self::k(intdiv($n, 100)).' ratus '.self::k($n % 100),
            $n < 2000 => 'seribu '.self::k($n - 1000),
            $n < 1000000 => self::k(intdiv($n, 1000)).' ribu '.self::k($n % 1000),
            $n < 1000000000 => self::k(intdiv($n, 1000000)).' juta '.self::k($n % 1000000),
            default => self::k(intdiv($n, 1000000000)).' miliar '.self::k($n % 1000000000),
        };
    }
}
