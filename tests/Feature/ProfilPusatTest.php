<?php

namespace Tests\Feature;

use App\Services\ProfilPusat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Kop surat & kepala sekolah mengikuti Profil Sekolah di Pusat. */
class ProfilPusatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(ProfilPusat::berkas());
        @unlink(ProfilPusat::logoBerkas());
        config(['pusat.enabled' => true, 'pusat.url' => 'https://p.test', 'pusat.client_id' => 'c', 'pusat.client_secret' => 's', 'pusat.webhook_secret' => 'wh']);
    }

    protected function tearDown(): void
    {
        @unlink(ProfilPusat::berkas());
        @unlink(ProfilPusat::logoBerkas());
        parent::tearDown();
    }

    public function test_profil_menimpa_kop_surat_dan_logo_diunduh(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        Http::fake([
            'p.test/api/v1/profil' => Http::response(['data' => ['diisi' => true, 'nama' => 'SMK Baru', 'nama_kop' => 'SMK BARU', 'kepsek' => 'Kepala Baru, M.Pd',
                'kepsek_nip' => '123', 'npsn' => null, 'logo_url' => 'https://p.test/logo-sekolah', 'logo_versi' => '1']]),
            'p.test/logo-sekolah' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $npsnLama = config('tanggungan.surat.npsn');
        ProfilPusat::tarik();
        $this->assertSame('SMK Baru', config('tanggungan.school_name'));
        $this->assertSame('SMK BARU', config('tanggungan.surat.nama'));
        $this->assertSame('Kepala Baru, M.Pd', config('tanggungan.surat.kepsek'));
        $this->assertSame('123', config('tanggungan.surat.kepsek_nuptk'));
        $this->assertSame($npsnLama, config('tanggungan.surat.npsn')); // kosong di Pusat: tidak menimpa
        $this->assertTrue(ProfilPusat::adaLogo());
        $this->get('/logo-sekolah')->assertOk();

        config(['tanggungan.surat.kepsek' => 'dari env']);
        ProfilPusat::terapkan(); // saat aplikasi dimulai ulang
        $this->assertSame('Kepala Baru, M.Pd', config('tanggungan.surat.kepsek'));
    }

    public function test_tanpa_profil_logo_bawaan(): void
    {
        $this->get('/logo-sekolah')->assertOk();
    }
}
