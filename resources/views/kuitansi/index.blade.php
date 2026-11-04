@extends('layouts.app', ['title' => 'Kuitansi'])
@section('content')
@php($rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n))
<div class="head"><div><h1>Kuitansi &amp; kas</h1><small>Penerimaan bendahara {{ \App\Support\Dates::human($dari) }} – {{ \App\Support\Dates::human($sampai) }}</small></div>
    <a class="btn" href="{{ route('kuitansi.unduh', ['dari' => $dari, 'sampai' => $sampai]) }}">Unduh Excel</a></div>

<form method="get" class="card filter-card">
    <div class="fields">
        <div><label for="dari">Dari</label><input id="dari" type="date" name="dari" value="{{ $dari }}"></div>
        <div><label for="sampai">Sampai</label><input id="sampai" type="date" name="sampai" value="{{ $sampai }}"></div>
        <div class="row" style="align-self:end"><button class="btn btn-pri">Tampilkan</button></div>
    </div>
</form>

<div class="kpis kpis-tiga">
    <div class="kpi kpi-main"><small>Total diterima</small><div class="n mono ok">{{ $rp($total) }}</div></div>
    <div class="kpi"><small>Tunai · transfer</small><div class="n mono" style="font-size:20px">{{ $rp($tunai) }} · {{ $rp($total - $tunai) }}</div></div>
    <div class="kpi"><small>Dibatalkan</small><div class="n mono {{ $batal ? 'warn' : '' }}">{{ $batal }}</div></div>
</div>

@if($perHari->count() > 1)
<div class="card card-tight">
    <div class="list-tools"><b>Per hari</b></div>
    <div class="scroll"><table class="tbl"><tbody>@foreach($perHari as $tgl => $jml)<tr><td>{{ \App\Support\Dates::human($tgl) }}</td><td class="r mono">{{ $rp($jml) }}</td></tr>@endforeach</tbody></table></div>
</div>
@endif

<div class="card card-tight">
    <div class="list-tools"><b>{{ $daftar->total() }} kuitansi</b></div>
    @forelse($daftar as $p)
        <a class="stu" style="color:inherit" href="{{ route('kuitansi.show', $p) }}">
            <span class="nm"><b>{{ $p->siswa?->nama }}</b><small>{{ $p->nomor }} · {{ \App\Support\Dates::human($p->tanggal->format('Y-m-d')) }} · {{ $p->siswa?->kelas?->nama }} · {{ $p->cara }}</small></span>
            @if($p->batal())<span class="badge b-bad">dibatalkan</span>@endif
            <span class="mono">{{ $rp($p->total) }}</span>
        </a>
    @empty
        <div class="empty">Belum ada pembayaran pada rentang tanggal ini.</div>
    @endforelse
</div>
@include('partials.pager', ['p' => $daftar])
@endsection
