@extends('layouts.app', ['title' => $s->nama])
@section('content')
@php
    $rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n);
    $me = auth()->user();
    $terbuka = $tagihan->filter(fn ($t) => $t->kurang() > 0)->values();
    $statusSiswa = ['aktif' => ['aktif', 'b-ok'], 'lulus' => ['lulus', '']][$s->status] ?? [$s->status, 'b-warn'];
@endphp
<a class="back-link" href="{{ route('siswa.index') }}">‹ Daftar siswa</a>
<div class="head">
    <div><h1>{{ $s->nama }}</h1><small>NIS {{ $s->nis ?: '—' }} · {{ $s->kelas?->nama ?? 'tanpa kelas' }} · {{ $s->jk === 'P' ? 'Perempuan' : ($s->jk === 'L' ? 'Laki-laki' : '') }}
        <span class="badge {{ $statusSiswa[1] }}">{{ $statusSiswa[0] }}</span></small></div>
</div>

<div class="kpis kpis-tiga">
    <div class="kpi"><small>Harus dibayar</small><div class="n mono">{{ $rp($ringkas['harus']) }}</div>@if($ringkas['potongan'])<small>setelah keringanan {{ $rp($ringkas['potongan']) }}</small>@endif</div>
    <div class="kpi"><small>Terbayar</small><div class="n mono ok">{{ $rp($ringkas['terbayar']) }}</div></div>
    <div class="kpi"><small>Kekurangan</small><div class="n mono {{ $ringkas['kurang'] ? 'bad' : 'ok' }}">{{ $ringkas['kurang'] ? $rp($ringkas['kurang']) : 'Lunas' }}</div><small>semua tahun ajaran</small></div>
</div>

@if($belumAda)
    <form method="post" action="{{ route('siswa.tagihan', $s) }}" class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">
        Siswa ini belum punya tagihan {{ $tahun }}. @csrf<input type="hidden" name="tahun" value="{{ $tahun }}"><button class="btn btn-sm">Buat tagihan {{ $tahun }}</button></span></form>
@endif

@if($me->isKasir() && $terbuka->isNotEmpty())
<form method="post" action="{{ route('siswa.bayar', $s) }}" class="card bayar" data-bayar data-unsaved>
    @csrf
    <h2>Terima pembayaran</h2>
    <div class="fields">
        <div><label for="tanggal">Tanggal</label><input id="tanggal" type="date" name="tanggal" value="{{ old('tanggal', $hari) }}" max="{{ $hari }}" required></div>
        <div><label for="cara">Cara bayar</label><select id="cara" name="cara"><option value="tunai">Tunai</option><option value="transfer" @selected(old('cara') === 'transfer')>Transfer</option></select></div>
        <div><label for="uang">Uang diterima <small class="mut">(opsional)</small></label>
            <div class="row" style="flex-wrap:nowrap"><input id="uang" type="text" inputmode="numeric" data-uang placeholder="mis. 90000" style="min-width:0"><button type="button" class="btn" data-bagi title="Bagikan ke tagihan tertua lebih dulu">Bagi</button></div></div>
    </div>
    <div class="scroll"><table class="tbl bayar-tbl">
        <thead><tr><th>Tagihan</th><th class="r">Kurang</th><th class="r" style="width:170px">Dibayar</th></tr></thead>
        <tbody>
        @foreach($terbuka as $t)
            <tr>
                <td>{{ $t->label() }}@if($t->jenis === 'du' && $t->terbayar)<small class="mut" style="display:block">angsuran, sudah {{ $rp($t->terbayar) }}</small>@endif</td>
                <td class="r mono">{{ $rp($t->kurang()) }}</td>
                <td class="r"><div class="row" style="flex-wrap:nowrap;justify-content:flex-end">
                    <input type="text" inputmode="numeric" name="jumlah[{{ $t->id }}]" value="{{ old('jumlah.'.$t->id) }}" data-kurang="{{ $t->kurang() }}" aria-label="Dibayar untuk {{ $t->label() }}" style="width:110px;text-align:right">
                    <button type="button" class="btn btn-sm" data-lunasi>Lunas</button></div></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="2" class="r">Total</th><th class="r mono" data-total>Rp0</th></tr></tfoot>
    </table></div>
    <div class="field" style="margin-top:10px"><label for="catatan">Catatan <small class="mut">(opsional, tercetak di kuitansi)</small></label><input id="catatan" type="text" name="catatan" maxlength="500" value="{{ old('catatan') }}" placeholder="mis. dibayar oleh orang tua"></div>
    <div class="row"><button class="btn btn-pri" data-busy="Menyimpan…">Simpan &amp; buat kuitansi</button></div>
</form>
@endif

@forelse($perTahun as $th => $ts)
    @php($r = \App\Services\Tanggungan::ringkas($ts))
    <div class="card card-tight">
        <div class="list-tools"><b>Tahun ajaran {{ $th }}</b><small class="mut">kelas {{ $ts->pluck('kelas_nama')->filter()->unique()->implode(', ') ?: '—' }} · kurang {{ $rp($r['kurang']) }}</small></div>
        <div class="scroll"><table class="tbl tbl-cards">
            <thead><tr><th>Tagihan</th><th class="r">Nominal</th><th class="r">Keringanan</th><th class="r">Terbayar</th><th class="r">Kurang</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($ts as $t)
                <tr>
                    <td class="card-title">{{ $t->label() }}</td>
                    <td class="r mono" data-label="Nominal">{{ $rp($t->nominal) }}</td>
                    <td class="r mono" data-label="Keringanan" @if($t->potongan_alasan) title="{{ $t->potongan_alasan }}" @endif>{{ $t->potongan ? $rp($t->potongan) : '—' }}@if($t->potongan_alasan)<small class="mut" style="display:block">{{ $t->potongan_alasan }}</small>@endif</td>
                    <td class="r mono" data-label="Terbayar">{{ $t->terbayar ? $rp($t->terbayar) : '—' }}</td>
                    <td class="r mono" data-label="Kurang">{{ $t->kurang() ? $rp($t->kurang()) : '—' }}</td>
                    <td data-label="Status">@if($t->harus() === 0)<span class="badge">bebas</span>@elseif($t->lunas())<span class="badge b-ok">lunas</span>@elseif($t->terbayar)<span class="badge b-warn">sebagian</span>@else<span class="badge b-bad">belum</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@empty
    <div class="card"><div class="empty">Belum ada tagihan untuk siswa ini.</div></div>
@endforelse

@if($me->isKasir() && $tagihan->isNotEmpty())
<details class="card">
    <summary class="link-summary">Keringanan / beasiswa / bebas iuran</summary>
    <form method="post" action="{{ route('siswa.keringanan', $tagihan->first()) }}" class="fields" style="margin-top:10px" data-keringanan>
        @csrf @method('PUT')
        <div style="grid-column:span 2"><label for="k-tagihan">Tagihan</label>
            <select id="k-tagihan" data-ganti-aksi>@foreach($perTahun as $th => $ts)<optgroup label="{{ $th }}">@foreach($ts as $t)<option value="{{ route('siswa.keringanan', $t) }}">{{ $t->label() }} — {{ $rp($t->nominal) }}{{ $t->potongan ? ' (potongan '.$rp($t->potongan).')' : '' }}</option>@endforeach</optgroup>@endforeach</select></div>
        <div><label for="k-pot">Potongan (Rp)</label><input id="k-pot" type="text" inputmode="numeric" name="potongan" required placeholder="0 = hapus keringanan"></div>
        <div style="grid-column:span 2"><label for="k-alasan">Alasan</label><input id="k-alasan" type="text" name="alasan" maxlength="255" placeholder="mis. yatim, beasiswa, kesepakatan komite"></div>
        <label class="chk" style="grid-column:1/-1"><input type="checkbox" name="semua" value="1"> Untuk syariah: terapkan ke semua bulan pada tahun ajaran itu</label>
        <div class="row"><button class="btn">Simpan keringanan</button></div>
    </form>
</details>
@endif

<div class="card card-tight">
    <div class="list-tools"><b>Riwayat pembayaran</b><small class="mut">{{ $pembayaran->count() }} kuitansi</small></div>
    @forelse($pembayaran as $p)
        @if($me->lihatSemua())<a class="stu" style="color:inherit" href="{{ route('kuitansi.show', $p) }}">@else<div class="stu">@endif
            <span class="nm"><b>{{ $p->nomor }}</b><small>{{ \App\Support\Dates::human($p->tanggal->format('Y-m-d')) }} · {{ $p->rinci->map(fn ($r) => $r->tagihan?->label())->filter()->implode(', ') }}</small></span>
            @if($p->batal())<span class="badge b-bad">dibatalkan</span>@endif
            @if($p->sumber === 'impor')<span class="badge">data lama</span>@endif
            <span class="mono">{{ $rp($p->total) }}</span>
        @if($me->lihatSemua())</a>@else</div>@endif
    @empty
        <div class="empty">Belum ada pembayaran.</div>
    @endforelse
</div>
@endsection
