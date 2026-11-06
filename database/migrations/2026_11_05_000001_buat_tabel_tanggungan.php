<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema Tanggungan Siswa. Kelas, siswa, dan pegawai disalin dari Pusat (tidak pernah dihapus: siswa lulus/keluar tetap
 * tersimpan); sisanya milik aplikasi: tarif, tagihan per siswa, pembayaran (kuitansi) dan rinciannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('username', 60)->unique();
            $t->string('nama');
            $t->string('email')->nullable();
            $t->string('password')->nullable();           // admin lokal darurat
            $t->string('password_pusat', 255)->nullable(); // login cadangan: hash password dari Pusat
            $t->json('roles')->nullable();                // admin | bendahara | kepsek | wali
            $t->boolean('aktif')->default(true);
            $t->string('pusat_uuid', 36)->nullable()->unique();
            $t->string('pegawai_uuid', 36)->nullable()->index();
            $t->string('siswa_uuid', 36)->nullable()->index();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        // ---- Salinan data induk dari Pusat ----
        Schema::create('kelas', function (Blueprint $t) {
            $t->id();
            $t->string('pusat_uuid', 36)->unique();
            $t->string('nama');
            $t->string('tingkat', 10)->nullable();
            $t->string('jurusan', 30)->nullable();
            $t->string('tahun_ajaran', 20)->nullable();
            $t->boolean('tahun_aktif')->default(false);
            $t->string('wali_uuid', 36)->nullable()->index();
            $t->string('wali_nama')->nullable();
            $t->dateTime('pusat_diperbarui')->nullable();
            $t->dateTime('dihapus_pada')->nullable();
            $t->timestamps();
        });
        Schema::create('siswa', function (Blueprint $t) {
            $t->id();
            $t->string('pusat_uuid', 36)->unique();
            $t->string('nis', 30)->index();
            $t->string('nisn', 20)->nullable();
            $t->string('nama');
            $t->string('jk', 1)->nullable();
            $t->foreignId('kelas_id')->nullable()->index();
            $t->string('status', 16)->default('aktif')->index();
            $t->string('tempat_lahir', 100)->nullable();
            $t->date('tanggal_lahir')->nullable();
            $t->string('no_hp', 30)->nullable();
            $t->string('no_hp_ortu', 30)->nullable();
            $t->string('nama_ortu')->nullable();
            $t->dateTime('pusat_diperbarui')->nullable();
            $t->timestamps();
        });
        Schema::create('pegawai', function (Blueprint $t) {
            $t->id();
            $t->string('pusat_uuid', 36)->unique();
            $t->string('nip', 30)->nullable();
            $t->string('nama');
            $t->string('jenis', 20)->nullable();
            $t->string('jabatan')->nullable();
            $t->boolean('aktif')->default(true)->index();
            $t->dateTime('pusat_diperbarui')->nullable();
            $t->timestamps();
        });


        // ---- Integrasi ----
        Schema::create('pusat_state', function (Blueprint $t) {
            $t->string('kunci', 40)->primary();
            $t->text('nilai')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('pusat_webhook', function (Blueprint $t) {
            $t->string('delivery_id', 64)->primary();
            $t->string('event', 60);
            $t->timestamp('created_at')->nullable()->index();
        });
        Schema::create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->timestamp('waktu')->index();
            $t->string('aksi', 100);
            $t->string('pelaku')->nullable();
            $t->text('rincian')->nullable();
        });

        // ---- Tanggungan ----
        // Tarif per jenis, tahun ajaran, dan tingkat. DU: nominal setahun. Syariah: nominal per bulan;
        // bulan_bebas = bulan tanpa iuran (mis. Juli & Agustus kelas X sudah termasuk daftar ulang).
        Schema::create('tarif', function (Blueprint $t) {
            $t->id();
            $t->string('jenis', 20);
            $t->string('tahun_ajaran', 20);
            $t->string('tingkat', 5);
            $t->unsignedInteger('nominal');
            $t->json('bulan_bebas')->nullable();
            $t->timestamps();
            $t->unique(['jenis', 'tahun_ajaran', 'tingkat']);
        });
        // Satu baris = satu tanggungan siswa (DU satu tahun, atau syariah satu bulan). Kelas disimpan sebagai salinan
        // nama agar rekap lama tetap benar setelah siswa naik kelas atau lulus.
        Schema::create('tagihan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('siswa_id')->index();
            $t->string('jenis', 20);
            $t->string('tahun_ajaran', 20);
            $t->string('tingkat', 5)->default('');
            $t->string('kelas_nama', 60)->default('');
            $t->unsignedTinyInteger('bulan')->default(0);     // 1-12 untuk syariah; 0 untuk DU
            $t->unsignedInteger('nominal');
            $t->unsignedInteger('potongan')->default(0);       // keringanan / beasiswa / bebas
            $t->string('potongan_alasan')->nullable();
            $t->timestamps();
            $t->unique(['siswa_id', 'jenis', 'tahun_ajaran', 'bulan'], 'uniq_tagihan');
            $t->index(['tahun_ajaran', 'kelas_nama']);
        });
        Schema::create('pembayaran', function (Blueprint $t) {
            $t->id();
            $t->string('nomor', 30)->unique();                 // KW-2026-00001 (impor: IMP-…)
            $t->foreignId('siswa_id')->index();
            $t->date('tanggal')->index();
            $t->unsignedInteger('total');
            $t->string('cara', 10)->default('tunai');          // tunai | transfer
            $t->string('sumber', 10)->default('kasir');        // kasir | impor
            $t->string('catatan', 500)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('penerima')->nullable();                // nama bendahara saat itu
            $t->dateTime('batal_pada')->nullable();
            $t->string('batal_alasan', 500)->nullable();
            $t->string('batal_oleh')->nullable();
            $t->timestamps();
        });
        Schema::create('pembayaran_rinci', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pembayaran_id')->index();
            $t->foreignId('tagihan_id')->index();
            $t->unsignedInteger('jumlah');
        });
    }

    public function down(): void
    {
        foreach (['pembayaran_rinci', 'pembayaran', 'tagihan', 'tarif', 'audit_log', 'pusat_webhook', 'pusat_state',
            'pegawai', 'siswa', 'kelas', 'sessions', 'password_reset_tokens', 'users'] as $tabel) {
            Schema::dropIfExists($tabel);
        }
    }
};
