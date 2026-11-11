<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KasKategori;
use App\Models\KasTransaksi;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\BukuKas;
use App\Services\Tanggungan;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Keuangan: buku kas satu saldo = pembayaran siswa (otomatis) + transaksi lain; rekap bulanan; bukti; pembatalan. */
class KasTest extends TestCase
{
    use RefreshDatabase;

    private User $bendahara;

    private Siswa $budi;

    private string $hari;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->hari = date('Y-m-d');
        $this->bendahara = User::create(['username' => 'bend', 'nama' => 'Bu Bendahara', 'roles' => ['bendahara'], 'aktif' => true]);
        $kelas = Kelas::create(['pusat_uuid' => 'k1', 'nama' => 'XII DKV 3', 'tingkat' => 'XII']);
        $this->budi = Siswa::create(['pusat_uuid' => 's1', 'nis' => '1001', 'nama' => 'Budi Santoso', 'jk' => 'L', 'kelas_id' => $kelas->id, 'status' => 'aktif']);
        $tahun = Tanggungan::tahunAktif();
        $this->actingAs($this->bendahara)->put('/tarif', ['tahun' => $tahun, 'nominal' => ['du' => ['XII' => '750000']]]);
        $this->post('/tarif/terapkan', ['tahun' => $tahun]);
    }

    private function kategori(string $nama): int
    {
        return KasKategori::where('nama', $nama)->value('id');
    }

    private function catat(string $kategori, string $jumlah, string $uraian, array $lain = [])
    {
        return $this->post('/kas', ['tanggal' => $this->hari, 'kategori_id' => $this->kategori($kategori), 'jumlah' => $jumlah, 'cara' => 'tunai', 'uraian' => $uraian] + $lain);
    }

    public function test_buku_kas_menggabungkan_pembayaran_siswa_dan_transaksi_lain_dengan_satu_saldo(): void
    {
        $du = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->firstOrFail();
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => $this->hari, 'cara' => 'tunai', 'jumlah' => [$du->id => '500.000']]);
        $this->post('/kas', ['tanggal' => date('Y-m-d', strtotime('-40 days')), 'kategori_id' => $this->kategori('Saldo awal'), 'jumlah' => '1.000.000',
            'cara' => 'tunai', 'uraian' => 'Saldo kas saat aplikasi mulai dipakai'])->assertSessionHas('success');
        $this->catat('ATK & cetak', '150.000', 'Kertas HVS 5 rim', ['bukti' => UploadedFile::fake()->image('nota.jpg')])->assertSessionHas('success');
        $this->catat('Dana BOS', '2000000', 'Pencairan BOS tahap 2');

        $t = KasTransaksi::where('uraian', 'Kertas HVS 5 rim')->firstOrFail();
        $this->assertSame(['keluar', 'KK-'.date('Y').'-00001', 150000], [$t->arah, $t->nomor, $t->jumlah]);
        Storage::disk('local')->assertExists($t->bukti_path);

        $awalBulan = substr($this->hari, 0, 8).'01';
        $b = BukuKas::buku($awalBulan, $this->hari);
        $this->assertSame(1000000, $b['awal']);
        $this->assertSame(1000000 + 500000 + 2000000 - 150000, $b['akhir']);
        $this->get('/kas')->assertOk()->assertSee('Budi Santoso')->assertSee('Kertas HVS 5 rim')->assertSee('Rp3.350.000')->assertSee('Bukti');
        $this->get(route('kas.bukti', $t))->assertOk();

        // batal: tetap tampil, tidak dihitung
        $this->post(route('kas.batal', $t), ['alasan' => 'salah catat jumlah'])->assertSessionHas('success');
        $this->assertSame(3500000, BukuKas::buku($awalBulan, $this->hari)['akhir']);
        $this->post(route('kas.batal', $t), ['alasan' => 'ulang'])->assertSessionHas('error');
        $this->get('/kas')->assertSee('dibatalkan')->assertSee('salah catat jumlah');
        $this->assertTrue(AuditLog::where('aksi', 'Batalkan kas')->exists());

        // saring per kategori: saldo tidak dihitung, pembayaran siswa tidak ikut
        $this->get('/kas?kategori='.$this->kategori('Dana BOS'))->assertOk()->assertSee('Pencairan BOS')->assertDontSee('Budi Santoso');
    }

    public function test_validasi_catat(): void
    {
        $this->catat('Operasional', '0', 'nol')->assertSessionHas('error');
        $this->post('/kas', ['tanggal' => date('Y-m-d', strtotime('+1 day')), 'kategori_id' => $this->kategori('Operasional'), 'jumlah' => '1000', 'cara' => 'tunai', 'uraian' => 'x'])
            ->assertSessionHasErrors('tanggal');
        $this->catat('Operasional', '1000', 'x', ['bukti' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('bukti');
        KasKategori::where('nama', 'Honor')->update(['aktif' => false]);
        $this->catat('Honor', '1000', 'honor')->assertSessionHas('error');
        $this->assertSame(0, KasTransaksi::count());
    }

    public function test_rekap_bulanan_per_kategori_dan_unduh(): void
    {
        $du = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->firstOrFail();
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => $this->hari, 'cara' => 'tunai', 'jumlah' => [$du->id => '300000']]);
        $this->catat('Operasional', '50.000', 'Listrik');
        $this->catat('Operasional', '25.000', 'Air');
        $r = BukuKas::rekap((int) date('Y'));
        $bulan = (int) date('n');
        $this->assertSame(300000, collect($r['masuk'])->firstWhere('nama', 'Pembayaran siswa — Daftar Ulang')['bulan'][$bulan]);
        $this->assertSame(75000, collect($r['keluar'])->firstWhere('nama', 'Operasional')['total']);
        $this->assertSame(225000, $r['saldo'][12]);
        $this->get('/kas/rekap')->assertOk()->assertSee('Pembayaran siswa — Daftar Ulang')->assertSee('Saldo akhir bulan');

        $x = $this->get('/kas/rekap/unduh')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $x->getContent());
        $this->assertStringContainsString('Operasional', json_encode(Xlsx::readAllSheets($tmp, 'r.xlsx')));
        @unlink($tmp);
        $this->get('/kas/unduh')->assertOk();
    }

    public function test_hak_akses(): void
    {
        $kepsek = User::create(['username' => 'ks', 'nama' => 'Kepsek', 'roles' => ['kepsek'], 'aktif' => true]);
        $this->actingAs($kepsek)->get('/kas')->assertOk()->assertDontSee('Catat transaksi');
        $this->get('/kas/rekap')->assertOk();
        $this->post('/kas', ['tanggal' => $this->hari, 'kategori_id' => $this->kategori('Operasional'), 'jumlah' => '1000', 'cara' => 'tunai', 'uraian' => 'x'])->assertForbidden();
        $this->get('/kas/kategori')->assertForbidden();
        $guru = User::create(['username' => 'g', 'nama' => 'Guru', 'roles' => ['guru'], 'aktif' => true]);
        $this->actingAs($guru)->get('/kas')->assertForbidden();
        $this->actingAs($this->bendahara)->get('/kas/kategori')->assertOk()->assertSee('Setoran ke yayasan');
        $this->post('/kas/kategori', ['nama' => 'Listrik & internet', 'arah' => 'keluar'])->assertSessionHas('success');
        $this->assertTrue(KasKategori::where('nama', 'Listrik & internet')->exists());
    }
}
