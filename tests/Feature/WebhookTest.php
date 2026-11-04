<?php

namespace Tests\Feature;

use App\Models\Pegawai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function kirim(string $event, array $data, string $id)
    {
        $badan = json_encode(['event' => $event, 'data' => $data]);
        $t = time();

        return $this->call('POST', '/api/pusat/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PUSAT_EVENT' => $event, 'HTTP_X_PUSAT_DELIVERY' => $id,
            'HTTP_X_PUSAT_TIMESTAMP' => $t, 'HTTP_X_PUSAT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $t.'.'.$badan, 'wh'),
        ], $badan);
    }

    public function test_pegawai_dari_webhook_dan_nonaktif(): void
    {
        config(['pusat.enabled' => true, 'pusat.url' => 'https://p.test', 'pusat.client_id' => 'c', 'pusat.client_secret' => 's', 'pusat.webhook_secret' => 'wh']);
        $this->kirim('pegawai.dibuat', ['uuid' => 'g1', 'nip' => '1', 'nama' => 'Guru Baru', 'jenis' => 'guru', 'aktif' => true], 'd1')->assertOk();
        $this->assertTrue(Pegawai::where('pusat_uuid', 'g1')->value('aktif'));
        $this->kirim('pegawai.dihapus', ['uuid' => 'g1', 'nama' => 'Guru Baru', 'dihapus' => now()->toIso8601String()], 'd2')->assertOk();
        $this->assertFalse((bool) Pegawai::where('pusat_uuid', 'g1')->value('aktif'));
    }

    public function test_tanpa_tanda_tangan_ditolak(): void
    {
        $this->postJson('/api/pusat/webhook', ['a' => 1])->assertStatus(404);
    }
}
