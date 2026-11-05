<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\Pembayaran;
use App\Models\Siswa;
use App\Models\Tagihan;
use App\Models\Tarif;
use App\Models\User;
use App\Services\Tanggungan;
use App\Support\Terbilang;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TanggunganTest extends TestCase
{
    use RefreshDatabase;

    private User $bendahara;
    private Kelas $kelas;
    private Siswa $budi;
    private Siswa $ani;
    private string $tahun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tahun = Tanggungan::tahunAktif();
        $this->bendahara = User::create(['username' => 'bend', 'nama' => 'Bu Bendahara', 'roles' => ['bendahara'], 'aktif' => true]);
        Pegawai::create(['pusat_uuid' => 'g1', 'nama' => 'Andi Kurniawan', 'aktif' => true]);
        $this->kelas = Kelas::create(['pusat_uuid' => 'k1', 'nama' => 'XII DKV 3', 'tingkat' => 'XII', 'wali_uuid' => 'g1', 'wali_nama' => 'Andi Kurniawan']);
        Kelas::create(['pusat_uuid' => 'k2', 'nama' => 'X DKV 1', 'tingkat' => 'X']);
        $this->budi = Siswa::create(['pusat_uuid' => 's1', 'nis' => '1001', 'nama' => 'Budi Santoso', 'jk' => 'L', 'kelas_id' => $this->kelas->id, 'status' => 'aktif']);
        $this->ani = Siswa::create(['pusat_uuid' => 's2', 'nis' => '1002', 'nama' => 'Ani Lestari', 'jk' => 'P', 'kelas_id' => $this->kelas->id, 'status' => 'aktif']);
        Siswa::create(['pusat_uuid' => 's3', 'nis' => '2001', 'nama' => 'Citra Baru', 'jk' => 'P', 'kelas_id' => 2, 'status' => 'aktif']);
    }

    private function tarif(): void
    {
        $this->actingAs($this->bendahara)->put('/tarif', ['tahun' => $this->tahun, 'nominal' => ['du' => ['X' => '1.200.000', 'XII' => '750000'],
            'syariah' => ['X' => '30000', 'XI' => '30000', 'XII' => '30000']], 'bebas' => ['syariah' => ['X' => [7, 8]]]])->assertSessionHas('success');
        $this->post('/tarif/terapkan', ['tahun' => $this->tahun])->assertSessionHas('success');
    }

    public function test_tarif_membuat_tagihan_du_dan_syariah_sesuai_tingkat(): void
    {
        $this->tarif();
        $this->assertSame(1 + 12, Tagihan::where('siswa_id', $this->budi->id)->count());
        $citra = Siswa::where('nis', '2001')->first();
        $this->assertSame(1 + 10, Tagihan::where('siswa_id', $citra->id)->count()); // Juli & Agustus kelas X termasuk DU
        $this->assertFalse(Tagihan::where(['siswa_id' => $citra->id, 'jenis' => 'syariah', 'bulan' => 7])->exists());
        $this->assertSame(['XII DKV 3', 'XII', 750000], [Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->value('kelas_nama'),
            Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->value('tingkat'), Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->value('nominal')]);
        $this->post('/tarif/terapkan', ['tahun' => $this->tahun])->assertSessionHas('success', fn ($m) => str_contains($m, 'sudah punya tagihan'));
        $this->assertSame(13 * 2 + 11, Tagihan::count()); // diulang tidak dobel
        $lalu = ((int) substr($this->tahun, 0, 4) - 1).'/'.substr($this->tahun, 0, 4);
        $this->post('/tarif/terapkan', ['tahun' => $lalu])->assertSessionHas('error');
    }

    public function test_bayar_angsuran_kuitansi_batal_dan_kekurangan(): void
    {
        $this->tarif();
        $du = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->first();
        $jul = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'syariah', 'bulan' => 7])->first();
        $this->get("/siswa/{$this->budi->id}")->assertOk()->assertSee('Terima pembayaran')->assertSee('Rp1.110.000'); // 750rb + 12 × 30rb
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'jumlah' => [$du->id => '800000']])->assertSessionHas('error');
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d', strtotime('+2 days')), 'cara' => 'tunai', 'jumlah' => [$du->id => '1000']])->assertSessionHas('error');
        $this->post("/siswa/{$this->ani->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'jumlah' => [$du->id => '1000']])->assertSessionHas('error'); // tagihan milik siswa lain

        $r = $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'catatan' => 'oleh ayah', 'jumlah' => [$du->id => '450.000', $jul->id => '30000']]);
        $p = Pembayaran::firstOrFail();
        $r->assertRedirect("/kuitansi/$p->id");
        $this->assertSame(['KW-'.date('Y').'-00001', 480000, 'Bu Bendahara'], [$p->nomor, $p->total, $p->penerima]);
        $this->get("/kuitansi/$p->id")->assertOk()->assertSee('KUITANSI PEMBAYARAN')->assertSee('Empat ratus delapan puluh ribu rupiah')->assertSee('Syariah Juli');
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'transfer', 'jumlah' => [$du->id => '300000']]);
        $this->assertSame('KW-'.date('Y').'-00002', Pembayaran::latest('id')->value('nomor'));
        $this->assertSame(0, Tagihan::whereKey($du->id)->select('tagihan.*')->denganTerbayar()->first()->kurang());

        // Rekap DU menampilkan dua angsuran
        $rekap = $this->get('/rekap?kelas=XII+DKV+3')->assertOk()->assertSee('Angsuran 2')->assertSee('450.000')->assertSee('300.000');
        $this->assertSame([450000, 300000], $rekap->viewData('l')['data'][$this->budi->id]['angsuran']);

        // Batal: kuitansi tetap ada, tagihan kembali kurang
        $this->post("/kuitansi/$p->id/batal", ['alasan' => 'x'])->assertSessionHas('error');
        $this->post("/kuitansi/$p->id/batal", ['alasan' => 'salah jumlah'])->assertSessionHas('success');
        $this->assertNotNull($p->fresh()->batal_pada);
        $this->assertSame(450000, Tagihan::whereKey($du->id)->select('tagihan.*')->denganTerbayar()->first()->kurang());
        $this->get('/kuitansi')->assertOk()->assertSee('dibatalkan')->assertViewHas('total', 300000);
        $this->get("/kuitansi/$p->id")->assertSee('DIBATALKAN');
        $this->assertSame(2, Pembayaran::count());
    }

    public function test_keringanan_dan_bebas_syariah_setahun(): void
    {
        $this->tarif();
        $sep = Tagihan::where(['siswa_id' => $this->ani->id, 'jenis' => 'syariah', 'bulan' => 9])->first();
        $this->put("/tagihan/$sep->id/keringanan", ['potongan' => '30000'])->assertSessionHas('error'); // alasan wajib
        $this->put("/tagihan/$sep->id/keringanan", ['potongan' => '30000', 'alasan' => 'Yatim', 'semua' => 1])->assertSessionHas('success');
        $this->assertSame(12, Tagihan::where(['siswa_id' => $this->ani->id, 'jenis' => 'syariah', 'potongan' => 30000])->count());
        $this->get("/siswa/{$this->ani->id}")->assertSee('bebas')->assertSee('Yatim');
        $this->get('/rekap?kelas=XII+DKV+3&tab=syariah:1')->assertSee('BEBAS');

        $du = Tagihan::where(['siswa_id' => $this->ani->id, 'jenis' => 'du'])->first();
        $this->post("/siswa/{$this->ani->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'jumlah' => [$du->id => '700000']]);
        $this->put("/tagihan/$du->id/keringanan", ['potongan' => '100000', 'alasan' => 'Beasiswa'])->assertSessionHas('error'); // sudah dibayar 700rb
        $this->put("/tagihan/$du->id/keringanan", ['potongan' => '50000', 'alasan' => 'Beasiswa'])->assertSessionHas('success');
        $this->assertSame(0, Tagihan::whereKey($du->id)->select('tagihan.*')->denganTerbayar()->first()->kurang());
    }

    public function test_hak_akses_wali_kepsek_dan_siswa_lulus_tetap_tersimpan(): void
    {
        $this->tarif();
        $wali = User::create(['username' => 'wali', 'nama' => 'Andi', 'roles' => ['wali'], 'pegawai_uuid' => 'g1', 'aktif' => true]);
        $kepsek = User::create(['username' => 'ks', 'nama' => 'Kepsek', 'roles' => ['kepsek'], 'aktif' => true]);
        $citra = Siswa::where('nis', '2001')->first();
        $this->actingAs($wali)->get('/siswa')->assertOk()->assertSee('Budi Santoso')->assertDontSee('Citra Baru');
        $this->get("/siswa/$citra->id")->assertForbidden();
        $this->get("/siswa/{$this->budi->id}")->assertOk()->assertDontSee('Terima pembayaran');
        $du = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->first();
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'jumlah' => [$du->id => '1000']])->assertForbidden();
        $this->get('/kuitansi')->assertForbidden();
        $this->get('/tarif')->assertForbidden();
        $this->get('/rekap')->assertOk()->assertSee('XII DKV 3')->assertDontSee('X DKV 1');

        $this->actingAs($kepsek)->get("/siswa/$citra->id")->assertOk()->assertDontSee('Terima pembayaran');
        $this->get('/kuitansi')->assertOk();
        $this->get('/tarif')->assertForbidden();

        // Sinkron menandai lulus: data & tagihan tetap, tetap bisa dicari
        $this->budi->update(['status' => 'lulus', 'kelas_id' => null]);
        $this->actingAs($this->bendahara)->get('/siswa?status=lulus')->assertSee('Budi Santoso');
        $this->get('/rekap?kelas=XII+DKV+3')->assertSee('Budi Santoso');
        $this->assertSame(13, Tagihan::where('siswa_id', $this->budi->id)->count());
    }

    public function test_templat_dan_impor_data_lama(): void
    {
        $this->actingAs($this->bendahara);
        $res = $this->get('/impor/templat?kelas='.$this->kelas->id)->assertOk();
        $f = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($f, $res->getContent());
        $lembar = Xlsx::readAllSheets($f, 'a.xlsx');
        $awal = (int) substr($this->tahun, 0, 4);
        $th = fn ($i) => ($awal - $i).'/'.($awal - $i + 1);
        $this->assertSame(['NIS', 'NAMA', 'TAHUN AJARAN', 'KELAS SAAT ITU', 'NOMINAL', 'DIBAYAR', 'KURANG', 'KETERANGAN'], $lembar[1][0]);
        $this->assertCount(1 + 2 * 3, $lembar[1]); // 2 siswa × 3 tahun (X, XI, XII)
        $this->assertSame(['1002', 'Ani Lestari', $th(2), 'X DKV 3'], array_slice($lembar[1][1], 0, 4));

        $sy = [array_merge(['NIS', 'NAMA', 'TAHUN AJARAN', 'KELAS SAAT ITU', 'TARIF PER BULAN'], array_map('strtoupper', array_keys(\App\Services\ImporLama::BULAN_KOLOM)))];
        $sy[] = array_merge(['1001', 'Budi Santoso', $th(2), 'X DKV 3', ''], ['DU', 'DU', '30000', '30000', '30000', 'LUNAS', '', '', '', '', '', '']);
        $sy[] = array_merge(['', 'ani lestari', $th(1), 'XI DKV 3', ''], ['BEBAS', '30000', '15000', '', '', '', '', '', '', '', '', '']);
        $du = [['NIS', 'NAMA', 'TAHUN AJARAN', 'KELAS SAAT ITU', 'NOMINAL', 'DIBAYAR', 'KURANG', 'KETERANGAN'],
            ['1001', 'Budi Santoso', $this->tahun, 'XII DKV 3', '750000', '', '300000', ''],
            ['1002', 'Ani Lestari', $this->tahun, 'XII DKV 3', '', '', '', '']];
        $isi = Xlsx::build(['DAFTAR ULANG' => $du, 'SYARIAH' => $sy]);
        $up = fn () => UploadedFile::fake()->createWithContent('data.xlsx', $isi);

        $this->post('/impor', ['berkas' => $up(), 'uji' => 1])->assertSessionHas('info', fn ($m) => str_contains($m, '2 siswa'));
        $this->assertSame(0, Tagihan::count()); // periksa saja: tidak disimpan
        $this->post('/impor', ['berkas' => $up(), 'uji' => 0])->assertSessionHas('success');
        $budiDu = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'du'])->select('tagihan.*')->denganTerbayar()->first();
        $this->assertSame([750000, 450000, 300000], [$budiDu->nominal, $budiDu->terbayar, $budiDu->kurang()]);
        $this->assertSame(10, Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'syariah', 'tahun_ajaran' => $th(2)])->count()); // 12 − 2 DU
        $this->assertSame(120000, (int) Tagihan::where(['siswa_id' => $this->budi->id, 'tahun_ajaran' => $th(2)])->select('tagihan.*')->denganTerbayar()->get()->sum('terbayar'));
        $aniJul = Tagihan::where(['siswa_id' => $this->ani->id, 'tahun_ajaran' => $th(1), 'bulan' => 7])->first();
        $this->assertSame([30000, 'XI DKV 3'], [$aniJul->potongan, $aniJul->kelas_nama]);
        $this->assertTrue(Pembayaran::where('sumber', 'impor')->where('nomor', 'like', 'IMP-%')->exists());
        $this->get('/kuitansi')->assertViewHas('total', 0); // data lama tidak masuk kas harian
        $this->get('/rekap?tahun='.urlencode($th(2)).'&kelas=X+DKV+3&tab=syariah:1')->assertOk()->assertSee('DU');

        // Impor ulang: tagihan yang sudah dibayar tidak ditimpa
        $this->post('/impor', ['berkas' => $up(), 'uji' => 0])->assertSessionHas('success', fn ($m) => str_contains($m, 'dilewati'));
        $this->assertSame(450000, (int) Tagihan::whereKey($budiDu->id)->select('tagihan.*')->denganTerbayar()->value('terbayar'));

        // Baris salah: seluruh impor dibatalkan dengan rincian
        $salah = Xlsx::build(['SYARIAH' => [$sy[0], array_merge(['9999', 'Tidak Ada', $this->tahun, '', ''], array_fill(0, 12, ''))]]);
        $this->post('/impor', ['berkas' => UploadedFile::fake()->createWithContent('s.xlsx', $salah), 'uji' => 0])
            ->assertSessionHas('error')->assertSessionHas('galat', fn ($g) => str_contains($g[0], 'NIS 9999 tidak dikenal'));
    }

    public function test_jenis_tagihan_tambahan_tahunan_dan_bulanan(): void
    {
        $this->tarif();
        $this->get('/jenis-tagihan')->assertOk()->assertSee('Daftar Ulang')->assertSee('bawaan');
        $this->post('/jenis-tagihan', ['nama' => 'Wisuda', 'pola' => 'tahunan', 'keterangan' => 'kelas XII'])->assertRedirect('/tarif');
        $this->post('/jenis-tagihan', ['nama' => 'Kas Kelas', 'singkat' => 'Kas', 'pola' => 'bulanan'])->assertRedirect('/tarif');
        $this->post('/jenis-tagihan', ['nama' => 'wisuda', 'pola' => 'tahunan'])->assertSessionHas('error'); // nama sama
        $this->assertSame(['wisuda', 'kas_kelas'], \App\Models\JenisTagihan::where('bawaan', false)->orderBy('id')->pluck('kode')->all());
        $this->get('/tarif')->assertSee('Wisuda')->assertSee('name="nominal[kas_kelas][XII]"', false)->assertSee('name="bebas[kas_kelas][XII][]"', false);

        $this->put('/tarif', ['tahun' => $this->tahun, 'nominal' => ['du' => ['X' => '1200000', 'XII' => '750000'], 'syariah' => ['X' => '30000', 'XI' => '30000', 'XII' => '30000'],
            'wisuda' => ['XII' => '400000'], 'kas_kelas' => ['XII' => '5000']], 'bebas' => ['syariah' => ['X' => [7, 8]], 'kas_kelas' => ['XII' => [6]]]]);
        $this->post('/tarif/terapkan', ['tahun' => $this->tahun])->assertSessionHas('success');
        $this->assertSame(1, Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'wisuda'])->count());
        $this->assertSame(11, Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'kas_kelas'])->count()); // Juni dibebaskan
        $this->assertSame(0, Tagihan::where(['siswa_id' => Siswa::where('nis', '2001')->value('id'), 'jenis' => 'wisuda'])->count()); // kelas X tidak dikenai

        $w = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'wisuda'])->first();
        $kas = Tagihan::where(['siswa_id' => $this->budi->id, 'jenis' => 'kas_kelas', 'bulan' => 7])->first();
        $this->assertSame(['Wisuda '.$this->tahun, 'Kas Juli '.substr($this->tahun, 0, 4)], [$w->label(), $kas->label()]);
        $this->post("/siswa/{$this->budi->id}/bayar", ['tanggal' => date('Y-m-d'), 'cara' => 'tunai', 'jumlah' => [$w->id => '100000', $kas->id => '5000']])->assertRedirect();
        $this->get('/kuitansi/'.Pembayaran::latest('id')->value('id'))->assertSee('Wisuda '.$this->tahun)->assertSee('Kas Juli');

        $r = $this->get('/rekap?kelas=XII+DKV+3&tab=wisuda')->assertOk()->assertSee('Angsuran 1')->assertSee('100.000');
        $this->assertSame(['du', 'syariah:1', 'syariah:2', 'kas_kelas:1', 'kas_kelas:2', 'wisuda'], collect($r->viewData('r')['lembar'])->pluck('kunci')->all());
        $this->get('/rekap?kelas=XII+DKV+3&tab=kas_kelas:2')->assertOk()->assertSee('Kas semester 2');

        // Nonaktif: tidak dibuatkan tagihan baru, yang lama tetap
        $j = \App\Models\JenisTagihan::where('kode', 'wisuda')->first();
        $this->put("/jenis-tagihan/$j->id", ['nama' => 'Wisuda & Perpisahan', 'aktif' => 0])->assertSessionHas('success');
        Siswa::create(['pusat_uuid' => 's9', 'nis' => '1009', 'nama' => 'Dina Baru', 'kelas_id' => $this->kelas->id, 'status' => 'aktif']);
        $this->post('/tarif/terapkan', ['tahun' => $this->tahun]);
        $this->assertSame(0, Tagihan::where(['siswa_id' => Siswa::where('nis', '1009')->value('id'), 'jenis' => 'wisuda'])->count());
        $this->assertSame('Wisuda & Perpisahan '.$this->tahun, $w->fresh()->label());
        $this->get("/siswa/{$this->budi->id}")->assertSee('Wisuda &amp; Perpisahan', false);
    }

    public function test_rekap_unduh_excel_dan_terbilang(): void
    {
        $this->tarif();
        $res = $this->get('/rekap/unduh?kelas=XII+DKV+3')->assertOk();
        $f = tempnam(sys_get_temp_dir(), 'rk').'.xlsx';
        file_put_contents($f, $res->getContent());
        $l = Xlsx::readAllSheets($f, 'a.xlsx');
        $this->assertCount(3, $l);
        $this->assertSame('DAFTAR ULANG', $l[0][2][0]);
        $this->assertSame('Wali Kelas : Andi Kurniawan', $l[0][5][0]);
        $this->assertSame(['NO', 'NIS', 'NAMA', 'L/P', 'NOMINAL', 'KERINGANAN', 'ANGSURAN 1', 'ANGSURAN 2', 'KURANG', 'KETERANGAN'], $l[0][6]); // baris kosong pemisah tidak terbaca
        $this->assertSame('JULI', $l[1][6][4]);
        $this->assertSame('seratus lima puluh ribu', Terbilang::kata(150000));
        $this->assertSame('satu juta dua ratus ribu', Terbilang::kata(1200000));
        $this->assertSame('sebelas ribu seratus', Terbilang::kata(11100));
        $this->assertTrue(Tarif::where(['tingkat' => 'X', 'jenis' => 'syariah'])->first()->bulan_bebas === [7, 8]);
    }
}
