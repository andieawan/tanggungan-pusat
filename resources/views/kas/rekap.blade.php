@extends('layouts.app', ['title' => 'Rekap kas '.$r['tahun'], 'width' => '1400px'])
@section('content')
@php($rp = fn ($n) => $n ? number_format((int) $n, 0, ',', '.') : '–')
@php($blok = ['masuk' => ['Pemasukan', 'totalMasuk'], 'keluar' => ['Pengeluaran', 'totalKeluar']])
<div class="print-only kas-kop"><b>{{ config('tanggungan.school_name') }}</b><br>Rekap Kas Tahun {{ $r['tahun'] }}</div>
<div class="head noprint"><div><h1>Keuangan</h1><small>Rekap per kategori per bulan, tahun {{ $r['tahun'] }} (rupiah)</small></div>
    <div class="row"><a class="btn" href="{{ route('kas.rekap.unduh', ['tahun' => $r['tahun']]) }}">Unduh Excel</a><button type="button" class="btn" onclick="window.print()">Cetak</button></div></div>
@include('kas._tabs')
<form method="get" class="card filter-card noprint"><div class="fields">
    <div><label for="tahun">Tahun</label><input id="tahun" type="number" name="tahun" value="{{ $r['tahun'] }}" min="2000" max="2100"></div>
    <div class="row" style="align-self:end"><button class="btn btn-pri">Tampilkan</button></div></div></form>

<div class="card card-tight scroll"><table class="tbl kas-rekap">
    <thead><tr><th>Kategori</th>@foreach($bulan as $b)<th class="r">{{ $b }}</th>@endforeach<th class="r">Total</th></tr></thead>
    <tbody>
    <tr><td><i>Saldo awal tahun</i></td><td colspan="12"></td><td class="r mono">{{ number_format($r['awal'], 0, ',', '.') }}</td></tr>
    @foreach($blok as $arah => [$judul, $tot])
        <tr class="kas-sub"><th colspan="14">{{ $judul }}</th></tr>
        @forelse($r[$arah] as $k)
            <tr><td>{{ $k['nama'] }}</td>@foreach($k['bulan'] as $n)<td class="r mono">{{ $rp($n) }}</td>@endforeach<td class="r mono"><b>{{ $rp($k['total']) }}</b></td></tr>
        @empty
            <tr><td colspan="14" class="mut">Belum ada {{ strtolower($judul) }}.</td></tr>
        @endforelse
        <tr class="kas-total"><td>Total {{ strtolower($judul) }}</td>@foreach($r[$tot] as $n)<td class="r mono">{{ $rp($n) }}</td>@endforeach<td class="r mono">{{ $rp(array_sum($r[$tot])) }}</td></tr>
    @endforeach
    <tr class="kas-total"><td>Saldo akhir bulan</td>@foreach($r['saldo'] as $n)<td class="r mono {{ $n < 0 ? 'bad' : '' }}">{{ number_format($n, 0, ',', '.') }}</td>@endforeach<td></td></tr>
    </tbody></table></div>
<p class="hint">Pembayaran siswa dirinci per jenis tagihan sesuai tanggal kuitansi. Transaksi dan kuitansi yang dibatalkan tidak dihitung.</p>
@endsection
