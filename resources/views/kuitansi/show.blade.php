@extends('layouts.app', ['title' => 'Kuitansi '.$p->nomor])
@section('content')
@php($rp = fn ($n) => \App\Services\Tanggungan::rupiah((int) $n))
@php($c = config('tanggungan.surat'))
<a class="back-link noprint" href="{{ route('siswa.show', $p->siswa_id) }}">‹ Kartu {{ $p->siswa?->nama }}</a>
<div class="card noprint">
    <div class="row">
        <button type="button" class="btn btn-pri" onclick="window.print()">Cetak kuitansi</button>
        <a class="btn" href="{{ route('siswa.show', $p->siswa_id) }}">Kembali ke kartu siswa</a>
    </div>
    @if($p->batal())
        <p class="mut" style="margin:10px 0 0"><span class="badge b-bad">dibatalkan</span> {{ \App\Support\Dates::human(substr((string) $p->batal_pada, 0, 10)) }} oleh {{ $p->batal_oleh }} — {{ $p->batal_alasan }}</p>
    @elseif(auth()->user()->isKasir() && $p->sumber === 'kasir')
        <details style="margin-top:10px"><summary class="link-summary">Batalkan kuitansi ini</summary>
            <form method="post" action="{{ route('kuitansi.batal', $p) }}" class="fields" style="margin-top:8px" data-confirm="Batalkan kuitansi {{ $p->nomor }}? Tagihan terkait kembali belum terbayar.">@csrf
                <div style="grid-column:span 2"><label for="alasan">Alasan</label><input id="alasan" type="text" name="alasan" maxlength="500" required placeholder="mis. salah input jumlah / salah siswa"></div>
                <div class="row" style="align-self:end"><button class="btn btn-danger">Batalkan</button></div>
            </form>
            <small class="mut">Kuitansi tidak dihapus: tetap tercatat dengan tanda dibatalkan. Catat ulang pembayaran yang benar dari kartu siswa.</small>
        </details>
    @endif
</div>

<article @class(['letter', 'kuitansi', 'is-batal' => $p->batal()])>
    @include('surat._kop')
    <h1 class="kw-judul">KUITANSI PEMBAYARAN</h1>
    <p class="kw-no">No. {{ $p->nomor }}</p>
    <table class="kw-id">
        <tr><td>Telah diterima dari</td><td>:</td><td><b>{{ $p->siswa?->nama }}</b></td></tr>
        <tr><td>NIS / Kelas</td><td>:</td><td>{{ $p->siswa?->nis ?: '—' }} / {{ $p->rinci->first()?->tagihan?->kelas_nama ?: $p->siswa?->kelas?->nama }}</td></tr>
        <tr><td>Uang sejumlah</td><td>:</td><td><i>{{ $terbilang }}</i></td></tr>
        <tr><td>Untuk pembayaran</td><td>:</td><td>
            <table class="kw-rinci">@foreach($p->rinci as $r)<tr><td>{{ $r->tagihan?->label() }}</td><td class="r">{{ $rp($r->jumlah) }}</td></tr>@endforeach</table>
        </td></tr>
        @if($p->catatan)<tr><td>Catatan</td><td>:</td><td>{{ $p->catatan }}</td></tr>@endif
    </table>
    <div class="kw-bawah">
        <div class="kw-total">{{ $rp($p->total) }}<small>{{ $p->cara === 'transfer' ? 'transfer' : 'tunai' }}</small></div>
        <div class="kw-ttd">{{ $c['kota'] }}, {{ \App\Support\Dates::long($p->tanggal->format('Y-m-d')) }}<br>Bendahara<div class="sp"></div><b>{{ $p->penerima }}</b></div>
    </div>
    @if($p->batal())<div class="kw-cap">DIBATALKAN</div>@endif
</article>
@endsection
