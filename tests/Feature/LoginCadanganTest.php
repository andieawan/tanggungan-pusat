<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Akun dari Pusat tetap bisa masuk lewat login lokal (password Pusat) saat Pusat tidak bisa dihubungi. */
class LoginCadanganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pusat.enabled' => true, 'pusat.url' => 'https://p.test', 'pusat.client_id' => 'c', 'pusat.client_secret' => 's', 'pusat.webhook_secret' => 'wh']);
    }

    private function kirim(string $event, array $data, string $id)
    {
        $badan = json_encode(['event' => $event, 'data' => $data]);
        $t = time();

        return $this->call('POST', '/api/pusat/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PUSAT_EVENT' => $event, 'HTTP_X_PUSAT_DELIVERY' => $id,
            'HTTP_X_PUSAT_TIMESTAMP' => $t, 'HTTP_X_PUSAT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $t.'.'.$badan, 'wh'),
        ], $badan);
    }

    public function test_login_lokal_dengan_password_pusat(): void
    {
        $peran = array_key_first((array) config('pusat.peran_peta'));
        $u = User::create(['username' => 'g008', 'nama' => 'Abdul', 'roles' => [config('pusat.peran_peta')[$peran]], 'aktif' => true, 'pusat_uuid' => 'a-8']);
        $this->kirim('akses.diperbarui', ['uuid' => 'a-8', 'username' => 'g008', 'aktif' => true, 'peran' => [$peran], 'password_hash' => null], 'd1')->assertOk();
        $this->post('/login', ['username' => 'g008', 'password' => 'PasswordPusat123'])->assertSessionHasErrors('username');

        $this->kirim('akun.diperbarui', ['uuid' => 'a-8', 'username' => 'g008', 'aktif' => true, 'peran' => [$peran], 'password_hash' => password_hash('PasswordPusat123', PASSWORD_BCRYPT)], 'd2')->assertOk();
        $this->post('/login', ['username' => 'g008', 'password' => 'PasswordPusat123'])->assertRedirect();
        $this->assertAuthenticatedAs($u->fresh());
        $this->assertNull($u->fresh()->password); // password lokal tidak diisi
    }

    public function test_sinkron_membuat_akun_dari_daftar_pusat(): void
    {
        $peran = array_key_first((array) config('pusat.peran_peta'));
        $data = [
            'tahun-ajaran' => [['uuid' => 't1', 'nama' => '2026/2027', 'aktif' => true]],
            'kelas' => [], 'siswa' => [], 'pegawai' => [],
            'akun' => [['uuid' => 'a-1', 'username' => 'g001', 'nama' => 'Guru Satu', 'aktif' => true, 'peran' => [$peran],
                'pegawai_uuid' => 'p-1', 'siswa_uuid' => null, 'password_hash' => password_hash('Rahasia12345', PASSWORD_BCRYPT)]],
        ];
        Http::fake(function ($r) use ($data) {
            $sumber = basename(parse_url($r->url(), PHP_URL_PATH));
            if (! isset($data[$sumber])) {
                return Http::response(['data' => []], 404);
            }

            return Http::response(['data' => $data[$sumber], 'meta' => ['halaman_terakhir' => 1, 'total' => count($data[$sumber])]]);
        });
        $h = (new \App\Services\PusatSync)->jalankan();
        $this->assertSame(1, $h['akun']['baru']);
        $this->post('/login', ['username' => 'g001', 'password' => 'Rahasia12345'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_akun_baru_dari_webhook_langsung_bisa_login_lokal(): void
    {
        $peran = array_key_first((array) config('pusat.peran_peta'));
        $this->kirim('akses.diperbarui', ['uuid' => 'a-9', 'username' => 'g009', 'nama' => 'Guru Baru', 'aktif' => true, 'peran' => [$peran],
            'password_hash' => password_hash('SandiPusat123', PASSWORD_BCRYPT)], 'd9')->assertOk();
        $this->assertSame('Guru Baru', User::where('pusat_uuid', 'a-9')->value('nama'));
        $this->post('/login', ['username' => 'g009', 'password' => 'SandiPusat123'])->assertRedirect();
        $this->assertAuthenticated();
        $this->kirim('akses.diperbarui', ['uuid' => 'a-10', 'username' => 'g010', 'aktif' => true, 'peran' => [], 'password_hash' => null], 'd10')->assertOk();
        $this->assertNull(User::where('pusat_uuid', 'a-10')->first()); // tanpa peran di aplikasi ini: tidak dibuat
    }
}
