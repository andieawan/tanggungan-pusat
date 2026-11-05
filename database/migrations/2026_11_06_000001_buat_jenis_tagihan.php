<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis tagihan yang bisa ditambah bendahara (ujian, PKL, wisuda, seragam, …). Pola:
 * tahunan = sekali per tahun ajaran (boleh diangsur), bulanan = per bulan Juli–Juni.
 * Daftar ulang & iuran syariah adalah jenis bawaan. Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jenis_tagihan')) {
            Schema::create('jenis_tagihan', function (Blueprint $t) {
                $t->id();
                $t->string('kode', 20)->unique();
                $t->string('nama', 60);
                $t->string('singkat', 30)->nullable();   // label bulanan: "Syariah Juli 2026"
                $t->string('pola', 10)->default('tahunan');
                $t->boolean('aktif')->default(true);
                $t->boolean('bawaan')->default(false);
                $t->unsignedSmallInteger('urut')->default(50);
                $t->string('keterangan')->nullable();
                $t->timestamps();
            });
        }
        $now = now();
        foreach ([['du', 'Daftar Ulang', 'DU', 'tahunan', 1], ['syariah', 'Iuran Syariah', 'Syariah', 'bulanan', 2]] as [$kode, $nama, $singkat, $pola, $urut]) {
            if (! DB::table('jenis_tagihan')->where('kode', $kode)->exists()) {
                DB::table('jenis_tagihan')->insert(['kode' => $kode, 'nama' => $nama, 'singkat' => $singkat, 'pola' => $pola, 'aktif' => true,
                    'bawaan' => true, 'urut' => $urut, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_tagihan');
    }
};
