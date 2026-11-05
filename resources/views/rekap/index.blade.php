@extends('layouts.app', ['title' => 'Rekap kelas'])
@section('content')
@php($rp = fn ($n) => number_format((int) $n, 0, ',', '.'))
<div class="head"><div><h1>Rekap kelas</h1><small>Semua jenis tagihan per kelas, seperti lembar Excel. Kelas sesuai saat tagihan dibuat.</small></div>
    @if($kelas)<a class="btn" href="{{ route('rekap.unduh', ['tahun' => $tahun, 'kelas' => $kelas]) }}">Unduh Excel</a>@endif</div>

<form method="get" class="card filter-card">
    <div class="fields">
        <div><label for="tahun">Tahun ajaran</label><select id="tahun" name="tahun" data-auto>@foreach($daftarTahun as $t)<option @selected($t === $tahun)>{{ $t }}</option>@endforeach</select></div>
        <div><label for="kelas">Kelas</label><select id="kelas" name="kelas" data-auto>@forelse($kelasDaftar as $k)<option @selected($k === $kelas)>{{ $k }}</option>@empty<option value="">— belum ada tagihan —</option>@endforelse</select></div>
        <input type="hidden" name="tab" value="{{ $tab }}">
        <noscript><div class="row" style="align-self:end"><button class="btn">Tampilkan</button></div></noscript>
    </div>
</form>

@if(! $r || ! $l)
    <div class="card"><div class="empty">Belum ada tagihan pada tahun ajaran {{ $tahun }}.</div></div>
@else
    <p class="mut" style="margin:0 0 10px">Wali kelas: {{ $r['wali'] ?: '—' }} · {{ $r['siswa']->count() }} siswa · terbayar Rp{{ $rp($r['ringkas']['terbayar']) }} · kurang Rp{{ $rp($r['ringkas']['kurang']) }}</p>
    <nav class="subtab" aria-label="Lembar">
        @foreach($r['lembar'] as $x)
            <a href="{{ route('rekap.index', ['tahun' => $tahun, 'kelas' => $kelas, 'tab' => $x['kunci']]) }}" @class(['on' => $tab === $x['kunci']]) @if($tab === $x['kunci']) aria-current="page" @endif>{{ $x['judul'] }}</a>
        @endforeach
    </nav>
    <div class="card card-tight"><div class="scroll"><table class="tbl rekap">
    @if($l['pola'] === 'tahunan')
        <thead><tr><th class="c">No</th><th>Nama</th><th class="c">L/P</th><th class="r">Nominal</th>@for($i = 1; $i <= $l['kolomAngsuran']; $i++)<th class="r">Angsuran {{ $i }}</th>@endfor<th class="r">Kurang</th><th>Keterangan</th></tr></thead>
        <tbody>
        @foreach($r['siswa'] as $s)
            @php($d = $l['data'][$s->id])
            <tr>
                <td class="c mono">{{ $loop->iteration }}</td>
                <td><a href="{{ route('siswa.show', $s) }}">{{ $s->nama }}</a></td>
                <td class="c">{{ $s->jk }}</td>
                <td class="r mono">{{ $d ? $rp($d['t']->harus()) : '—' }}</td>
                @for($i = 0; $i < $l['kolomAngsuran']; $i++)<td class="r mono">{{ isset($d['angsuran'][$i]) ? $rp($d['angsuran'][$i]) : '' }}</td>@endfor
                <td class="r mono">{{ $d && $d['t']->kurang() ? $rp($d['t']->kurang()) : ($d ? '0' : '') }}</td>
                <td>@if(! $d)<span class="mut">tanpa tagihan</span>@elseif($d['t']->lunas())<span class="badge b-ok">LUNAS</span>@else<span class="badge b-bad">TIDAK LUNAS</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    @else
        @php($kode = explode(':', $l['kunci'])[0])
        <thead><tr><th class="c">No</th><th>Nama</th><th class="c">L/P</th>@foreach($l['bulan'] as $b)<th class="r">{{ \App\Services\Tanggungan::namaBulan($b) }}</th>@endforeach<th class="r">Total</th><th class="r">Kurang</th><th>Keterangan</th></tr></thead>
        <tbody>
        @foreach($r['siswa'] as $s)
            @php($b = $l['baris'][$s->id])
            <tr>
                <td class="c mono">{{ $loop->iteration }}</td>
                <td><a href="{{ route('siswa.show', $s) }}">{{ $s->nama }}</a></td>
                <td class="c">{{ $s->jk }}</td>
                @foreach($b['sel'] as $t)
                    @php($v = \App\Services\Rekap::isiSel($t, $b['ada'], $kode))
                    <td @class(['r', 'mono', 'sel-kosong' => $t && $v === null, 'sel-sebagian' => $t && is_int($v) && $t->kurang() > 0])>{{ is_int($v) ? $rp($v) : $v }}</td>
                @endforeach
                <td class="r mono"><b>{{ $rp($b['terbayar']) }}</b></td>
                <td class="r mono">{{ $b['ada'] ? $rp($b['kurang']) : '' }}</td>
                <td>@if(! $b['ada'])<span class="mut">tanpa tagihan</span>@elseif($b['kurang'] === 0)<span class="badge b-ok">LUNAS</span>@else<span class="badge b-bad">TIDAK LUNAS</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    @endif
    </table></div></div>
    <p class="hint">Sel kosong berwarna = belum dibayar. "DU" = bulan tanpa iuran syariah (termasuk daftar ulang), "-" = bulan tanpa tagihan, "BEBAS" = keringanan penuh.</p>
@endif
@endsection
