<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buku kas: pemasukan & pengeluaran selain pembayaran siswa (pembayaran siswa dibaca langsung dari tabel pembayaran).
 * Satu saldo. Transaksi tidak pernah dihapus: pembatalan dicatat (batal_pada, alasan, oleh). Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kas_kategori')) {
            Schema::create('kas_kategori', function (Blueprint $t) {
                $t->id();
                $t->string('nama', 60);
                $t->string('arah', 6);                      // masuk | keluar
                $t->boolean('aktif')->default(true);
                $t->unsignedSmallInteger('urut')->default(50);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('kas_transaksi')) {
            Schema::create('kas_transaksi', function (Blueprint $t) {
                $t->id();
                $t->string('nomor', 30)->unique();          // KM-2026-00001 (masuk) / KK-2026-00001 (keluar)
                $t->date('tanggal')->index();
                $t->string('arah', 6);
                $t->foreignId('kategori_id')->index();
                $t->string('uraian', 255);
                $t->unsignedBigInteger('jumlah');
                $t->string('cara', 10)->default('tunai');   // tunai | transfer
                $t->string('bukti_path')->nullable();       // storage/app/private/kas-bukti/…
                $t->foreignId('user_id')->nullable();
                $t->string('pencatat')->nullable();
                $t->dateTime('batal_pada')->nullable();
                $t->string('batal_alasan', 500)->nullable();
                $t->string('batal_oleh')->nullable();
                $t->timestamps();
            });
        }
        $now = now();
        $bawaan = [['Saldo awal', 'masuk', 1], ['Dana BOS', 'masuk', 10], ['Sumbangan / donasi', 'masuk', 20], ['Pemasukan lain', 'masuk', 90],
            ['Operasional', 'keluar', 10], ['ATK & cetak', 'keluar', 20], ['Honor', 'keluar', 30], ['Kegiatan siswa', 'keluar', 40],
            ['Pemeliharaan', 'keluar', 50], ['Setoran ke yayasan', 'keluar', 60], ['Pengeluaran lain', 'keluar', 90]];
        if (! DB::table('kas_kategori')->exists()) {
            foreach ($bawaan as [$nama, $arah, $urut]) {
                DB::table('kas_kategori')->insert(['nama' => $nama, 'arah' => $arah, 'aktif' => true, 'urut' => $urut, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_transaksi');
        Schema::dropIfExists('kas_kategori');
    }
};
