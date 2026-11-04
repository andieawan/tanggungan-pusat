@extends('layouts.app', ['title' => 'Siswa'])
@section('content')
@php($rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n))
<div class="head"><div><h1>Siswa</h1><small>Cari siswa untuk melihat kartu tanggungan{{ auth()->user()->isKasir() ? ' dan mencatat pembayaran' : '' }}. Siswa lulus &amp; keluar tetap tersimpan.</small></div></div>

<form method="get" class="card filter-card">
    <div class="fields">
        <div style="grid-column:span 2"><label for="q">Nama / NIS</label><input id="q" type="search" name="q" value="{{ $q }}" placeholder="Ketik nama atau NIS…" autofocus></div>
        <div><label for="kelas">Kelas</label><select id="kelas" name="kelas" data-auto><option value="">Semua kelas</option>@foreach($kelas as $k)<option value="{{ $k->id }}" @selected($k->id === $kelasId)>{{ $k->nama }}</option>@endforeach</select></div>
        <div><label for="status">Status</label><select id="status" name="status" data-auto>@foreach(['aktif' => 'Aktif', 'lulus' => 'Lulus', 'keluar' => 'Keluar / pindah', 'semua' => 'Semua'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="row" style="align-self:end"><button class="btn btn-pri">Cari</button></div>
    </div>
</form>

<div class="card card-tight">
    <div class="list-tools"><b>{{ $siswa->total() }} siswa</b></div>
    @forelse($siswa as $s)
        @php($k = $kurang[$s->id] ?? null)
        <a class="stu" style="color:inherit" href="{{ route('siswa.show', $s) }}">
            <span class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nis ?: 'tanpa NIS' }} · {{ $s->kelas?->nama ?? '—' }}@if($s->status !== 'aktif') · {{ $s->status }}@endif</small></span>
            @if($k === null)<span class="badge">belum ada tagihan</span>
            @elseif($k > 0)<span class="badge b-bad">kurang <span class="mono">{{ $rp($k) }}</span></span>
            @else<span class="badge b-ok">lunas</span>@endif
        </a>
    @empty
        <div class="empty">Tidak ada siswa yang cocok.</div>
    @endforelse
</div>
@include('partials.pager', ['p' => $siswa])
@endsection
