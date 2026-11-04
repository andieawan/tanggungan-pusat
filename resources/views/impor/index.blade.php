@extends('layouts.app', ['title' => 'Impor data lama'])
@section('content')
<div class="head"><div><h1>Pengaturan</h1><small>Impor catatan daftar ulang &amp; syariah dari sebelum aplikasi dipakai.</small></div></div>
@include('tarif._tabs')

@if(session('galat'))
    <div class="card"><h2>Rincian</h2><ul class="galat">@foreach(session('galat') as $g)<li>{{ $g }}</li>@endforeach</ul></div>
@endif

<div class="card">
    <h2>1. Unduh templat kelas</h2>
    <p class="mut" style="margin:0 0 10px">Templat berisi siswa aktif kelas itu, satu baris per tahun ajaran sejak kelas X (mis. kelas XII: 3 tahun). Lembar: PETUNJUK, DAFTAR ULANG, SYARIAH.</p>
    <form method="get" action="{{ route('impor.templat') }}" class="fields">
        <div><label for="kelas">Kelas</label><select id="kelas" name="kelas" required>@foreach($kelas as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select></div>
        <div class="row" style="align-self:end"><button class="btn">Unduh templat</button></div>
    </form>
</div>

<div class="card">
    <h2>2. Isi lalu unggah</h2>
    <ul class="steps-mini">
        <li><b>Daftar ulang:</b> NOMINAL (kosong = tarif di aplikasi), lalu DIBAYAR <i>atau</i> KURANG.</li>
        <li><b>Syariah:</b> jumlah dibayar per bulan. <code>DU</code> = bulan tanpa iuran, <code>BEBAS</code> = dibebaskan, <code>LUNAS</code> = dibayar penuh, kosong = belum dibayar.</li>
        <li>Pembayaran lama tercatat sebagai kuitansi <code>IMP-…</code> bertanggal hari ini, terpisah dari kas harian bendahara.</li>
    </ul>
    <form method="post" action="{{ route('impor.unggah') }}" enctype="multipart/form-data" class="fields" style="margin-top:10px">
        @csrf
        <div style="grid-column:span 2"><label for="berkas">Berkas templat (.xlsx)</label><input id="berkas" type="file" name="berkas" accept=".xlsx" required></div>
        <div class="row" style="align-self:end"><button class="btn" name="uji" value="1">Periksa saja</button><button class="btn btn-pri" name="uji" value="0" data-busy="Mengimpor…">Impor</button></div>
    </form>
</div>
@endsection
