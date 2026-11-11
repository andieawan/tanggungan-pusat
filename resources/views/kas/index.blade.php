@extends('layouts.app', ['title' => 'Buku kas', 'width' => '1200px'])
@section('content')
@php($rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n))
@php($D = \App\Support\Dates::class)
@php($kasir = auth()->user()->isKasir())
<div class="print-only kas-kop"><b>{{ config('tanggungan.school_name') }}</b><br>Buku Kas · {{ $D::human($dari) }} – {{ $D::human($sampai) }}</div>
<div class="head noprint"><div><h1>Keuangan</h1><small>Buku kas {{ $D::human($dari) }} – {{ $D::human($sampai) }} · pembayaran siswa ikut otomatis</small></div>
    <div class="row"><a class="btn" href="{{ route('kas.unduh', request()->query()) }}">Unduh Excel</a><button type="button" class="btn" onclick="window.print()">Cetak</button></div></div>
@include('kas._tabs')

@if($kasir)
<details class="card noprint" @if($errors->any()) open @endif>
    <summary class="link-summary"><b>+ Catat transaksi</b> <small class="mut">pemasukan atau pengeluaran selain pembayaran siswa</small></summary>
    <form method="post" action="{{ route('kas.simpan') }}" enctype="multipart/form-data" class="fields" style="margin-top:12px">@csrf
        <div><label for="tanggal">Tanggal</label><input id="tanggal" type="date" name="tanggal" value="{{ old('tanggal', $D::today()) }}" max="{{ $D::today() }}" required></div>
        <div><label for="kategori_id">Kategori</label><select id="kategori_id" name="kategori_id" required>
            @foreach(['masuk' => 'Pemasukan', 'keluar' => 'Pengeluaran'] as $ar => $lbl)<optgroup label="{{ $lbl }}">
                @foreach($semuaKategori->where('arah', $ar)->where('aktif', true) as $k)<option value="{{ $k->id }}" @selected((int) old('kategori_id') === $k->id)>{{ $k->nama }}</option>@endforeach
            </optgroup>@endforeach</select></div>
        <div><label for="jumlah">Jumlah (Rp)</label><input id="jumlah" type="text" inputmode="numeric" name="jumlah" value="{{ old('jumlah') }}" required placeholder="mis. 250.000"></div>
        <div><label for="cara">Cara</label><select id="cara" name="cara"><option value="tunai">Tunai</option><option value="transfer" @selected(old('cara') === 'transfer')>Transfer</option></select></div>
        <div style="grid-column:1/-1"><label for="uraian">Uraian</label><input id="uraian" type="text" name="uraian" value="{{ old('uraian') }}" maxlength="255" required placeholder="mis. Beli kertas HVS 5 rim untuk ujian"></div>
        <div style="grid-column:1/-1"><label for="bukti">Bukti nota/kuitansi <small class="mut">(foto atau PDF, maks. 5 MB, opsional)</small></label><input id="bukti" type="file" name="bukti" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
        <div class="row"><button class="btn btn-pri">Simpan transaksi</button></div>
    </form>
    <p class="hint" style="margin:10px 2px 0">Saldo kas saat mulai dipakai dicatat sekali dengan kategori <b>Saldo awal</b>. Transaksi tidak bisa dihapus — salah catat dibatalkan dengan alasan, lalu dicatat ulang.</p>
</details>
@endif

<form method="get" class="card filter-card noprint">
    <div class="fields">
        <div><label for="dari">Dari</label><input id="dari" type="date" name="dari" value="{{ $dari }}"></div>
        <div><label for="sampai">Sampai</label><input id="sampai" type="date" name="sampai" value="{{ $sampai }}"></div>
        <div><label for="arah">Jenis</label><select id="arah" name="arah"><option value="">Semua</option><option value="masuk" @selected($arah === 'masuk')>Pemasukan</option><option value="keluar" @selected($arah === 'keluar')>Pengeluaran</option></select></div>
        <div><label for="fk">Kategori</label><select id="fk" name="kategori"><option value="">Semua (termasuk pembayaran siswa)</option>
            @foreach($semuaKategori as $k)<option value="{{ $k->id }}" @selected($kategori === $k->id)>{{ $k->nama }} ({{ $k->arah }})</option>@endforeach</select></div>
        <div class="row" style="align-self:end"><button class="btn btn-pri">Tampilkan</button></div>
    </div>
</form>

<div class="kpis kpis-4">
    @unless($b['saring'])<div class="kpi"><small>Saldo awal</small><div class="n mono">{{ $rp($b['awal']) }}</div><small>sebelum {{ $D::human($dari) }}</small></div>@endunless
    <div class="kpi"><small>Pemasukan</small><div class="n mono ok">{{ $rp($b['masuk']) }}</div></div>
    <div class="kpi"><small>Pengeluaran</small><div class="n mono bad">{{ $rp($b['keluar']) }}</div></div>
    @unless($b['saring'])<div class="kpi kpi-main"><small>Saldo akhir</small><div class="n mono {{ $b['akhir'] < 0 ? 'bad' : '' }}">{{ $rp($b['akhir']) }}</div><small>per {{ $D::human($sampai) }}</small></div>@endunless
</div>
@if($b['saring'])<p class="hint">Saring aktif: saldo tidak ditampilkan karena hanya sebagian transaksi yang dihitung.</p>@endif

<div class="card card-tight scroll"><table class="tbl kas-tbl">
    <thead><tr><th>Tanggal</th><th>Nomor</th><th>Kategori</th><th>Uraian</th><th class="r">Masuk</th><th class="r">Keluar</th>@unless($b['saring'])<th class="r">Saldo</th>@endunless<th class="noprint"></th></tr></thead>
    <tbody>
    @unless($b['saring'])<tr><td>{{ $D::human($dari) }}</td><td></td><td></td><td><i>Saldo awal</i></td><td></td><td></td><td class="r mono">{{ $rp($b['awal']) }}</td><td class="noprint"></td></tr>@endunless
    @forelse($b['baris'] as $r)
        <tr @class(['kas-batal' => $r['batal']])>
            <td style="white-space:nowrap">{{ $D::human($r['tanggal']) }}</td>
            <td class="mono" style="font-size:12.5px">@if($r['jenis'] === 'siswa')<a href="{{ route('kuitansi.show', $r['id']) }}">{{ $r['nomor'] }}</a>@else{{ $r['nomor'] }}@endif</td>
            <td>{{ $r['kategori'] }}</td>
            <td>{{ $r['uraian'] }}@if($r['cara'] === 'transfer') <small class="mut">· transfer</small>@endif
                @if($r['batal'])<br><span class="badge b-bad">dibatalkan</span> <small class="mut">{{ $r['batal_alasan'] }}</small>@endif</td>
            <td class="r mono ok">{{ $r['arah'] === 'masuk' ? $rp($r['jumlah']) : '' }}</td>
            <td class="r mono bad">{{ $r['arah'] === 'keluar' ? $rp($r['jumlah']) : '' }}</td>
            @unless($b['saring'])<td class="r mono">{{ $rp($r['saldo']) }}</td>@endunless
            <td class="noprint" style="white-space:nowrap">
                @if($r['bukti'])<a href="{{ route('kas.bukti', $r['id']) }}" target="_blank" rel="noopener">Bukti</a>@endif
                @if($kasir && $r['jenis'] === 'kas' && ! $r['batal'])
                    <details class="kas-aksi"><summary class="link-summary">Batalkan</summary>
                        <form method="post" action="{{ route('kas.batal', $r['id']) }}" class="row" style="margin-top:6px" data-confirm="Batalkan {{ $r['nomor'] }}?">@csrf
                            <input type="text" name="alasan" maxlength="500" required placeholder="alasan" aria-label="Alasan pembatalan {{ $r['nomor'] }}">
                            <button class="btn btn-danger btn-sm">Batalkan</button></form></details>
                @endif</td>
        </tr>
    @empty
        <tr><td colspan="8"><div class="empty">Belum ada transaksi pada rentang ini.</div></td></tr>
    @endforelse
    </tbody>
    <tfoot><tr><th colspan="4" class="r">Jumlah</th><th class="r mono">{{ $rp($b['masuk']) }}</th><th class="r mono">{{ $rp($b['keluar']) }}</th>@unless($b['saring'])<th class="r mono">{{ $rp($b['akhir']) }}</th>@endunless<th class="noprint"></th></tr></tfoot>
</table></div>
<p class="hint">Baris bertanda dibatalkan tetap tercatat tetapi tidak dihitung. Nomor KW-… = kuitansi pembayaran siswa (buka untuk detail), KM-/KK- = kas masuk/keluar.</p>
@endsection
