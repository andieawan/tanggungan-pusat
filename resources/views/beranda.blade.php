@extends('layouts.app', ['title' => 'Ringkasan'])
@section('content')
@php($rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n))
@php($me = auth()->user())
<div class="head">
    <div><h1>Ringkasan tanggungan</h1><small>Tahun ajaran {{ $tahun }} · {{ $me->lihatSemua() ? 'semua kelas' : 'kelas '.$wali->pluck('nama')->implode(', ') }}</small></div>
    <form method="get" class="row"><label class="sr" for="tahun">Tahun ajaran</label>
        <select id="tahun" name="tahun" data-auto>@foreach($daftarTahun as $t)<option @selected($t === $tahun)>{{ $t }}</option>@endforeach</select>
        <noscript><button class="btn btn-sm">Tampilkan</button></noscript></form>
</div>

@if($tarifKosong)
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Tarif {{ $tahun }} belum diatur. Atur nominal daftar ulang &amp; syariah di <a href="{{ route('tarif.index', ['tahun' => $tahun]) }}">Pengaturan → Tarif</a>, lalu buat tagihan.</span></div>
@elseif($tanpaTagihan)
    <div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">{{ $tanpaTagihan }} siswa aktif belum punya tagihan {{ $tahun }} (mis. siswa baru). <a href="{{ route('tarif.index', ['tahun' => $tahun]) }}">Buat tagihannya</a>.</span></div>
@endif

<div class="kpis kpis-4">
    <div class="kpi kpi-main"><small>Terbayar</small><div class="n mono ok">{{ $rp($total['terbayar']) }}</div>
        <div class="meter" aria-hidden="true"><i class="f-ok" style="width:{{ $total['harus'] ? round($total['terbayar'] * 100 / $total['harus']) : 0 }}%"></i></div>
        <small>{{ $total['harus'] ? round($total['terbayar'] * 100 / $total['harus']) : 0 }}% dari {{ $rp($total['harus']) }}</small></div>
    <div class="kpi"><small>Kekurangan</small><div class="n mono {{ $total['kurang'] ? 'bad' : 'ok' }}">{{ $rp($total['kurang']) }}</div></div>
    <div class="kpi"><small>Keringanan</small><div class="n mono">{{ $rp($total['potongan']) }}</div></div>
    @if($kas)
        <div class="kpi"><small>Diterima hari ini</small><div class="n mono">{{ $rp($kas['hari']) }}</div><small>bulan ini {{ $rp($kas['bulan']) }}</small></div>
    @else
        <div class="kpi"><small>Tagihan lunas</small><div class="n mono">{{ $total['lunas'] }} / {{ $total['jumlah'] }}</div></div>
    @endif
</div>

@if($me->isKasir())
<div class="quick">
    <a class="quick-item primary" href="{{ route('siswa.index') }}"><span class="bk-ic" aria-hidden="true">💵</span><span><b>Terima pembayaran</b><small>Cari siswa, catat, cetak kuitansi</small></span></a>
    <a class="quick-item" href="{{ route('rekap.index') }}"><span class="bk-ic" aria-hidden="true">📊</span><span><b>Rekap kelas</b><small>Seperti lembar Excel, bisa diunduh</small></span></a>
    <a class="quick-item" href="{{ route('kuitansi.index') }}"><span class="bk-ic" aria-hidden="true">🧾</span><span><b>Kuitansi &amp; kas harian</b><small>Daftar penerimaan per tanggal</small></span></a>
</div>
@endif

<div class="card card-tight">
    <div class="list-tools"><b>Per kelas</b><small class="mut">{{ $kelas->count() }} kelas</small></div>
    @if($kelas->isEmpty())
        <div class="empty">Belum ada tagihan untuk tahun ajaran ini.</div>
    @else
    <div class="scroll"><table class="tbl tbl-cards">
        <thead><tr><th>Kelas</th><th class="c">Siswa lunas</th><th class="r">Kurang</th><th class="r">Terbayar</th><th></th></tr></thead>
        <tbody>
        @foreach($kelas as $k)
            <tr>
                <td class="card-title"><b>{{ $k['kelas'] ?: '—' }}</b></td>
                <td class="c" data-label="Siswa lunas"><span class="mono">{{ $k['siswa_lunas'] }} / {{ $k['siswa'] }}</span></td>
                <td class="r" data-label="Kurang"><span class="mono">{{ $k['kurang'] ? $rp($k['kurang']) : '—' }}</span>
                    @if(count($k['per_jenis']) > 1)<small class="mut" style="display:block">@foreach($k['per_jenis'] as $j => $n){{ $namaJenis[$j] ?? $j }} {{ $rp($n) }}@if(! $loop->last) · @endif @endforeach</small>@endif</td>
                <td class="r mono" data-label="Terbayar">{{ $rp($k['terbayar']) }}</td>
                <td class="r"><a class="btn btn-sm" href="{{ route('rekap.index', ['tahun' => $tahun, 'kelas' => $k['kelas']]) }}">Rekap</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>

@if($kas && $kas['terakhir']->isNotEmpty())
<div class="card card-tight">
    <div class="list-tools"><b>Pembayaran terakhir</b><a class="mut" href="{{ route('kuitansi.index') }}">Semua kuitansi ›</a></div>
    @foreach($kas['terakhir'] as $p)
        <a class="stu" style="color:inherit" href="{{ route('kuitansi.show', $p) }}">
            <span class="nm"><b>{{ $p->siswa?->nama }}</b><small>{{ $p->nomor }} · {{ \App\Support\Dates::human($p->tanggal->format('Y-m-d')) }} · {{ $p->siswa?->kelas?->nama }}</small></span>
            @if($p->batal())<span class="badge b-bad">dibatalkan</span>@endif
            <span class="mono">{{ $rp($p->total) }}</span>
        </a>
    @endforeach
</div>
@endif
@endsection
