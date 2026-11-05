@extends('layouts.app', ['title' => 'Jenis tagihan'])
@section('content')
<div class="head"><div><h1>Pengaturan</h1><small>Jenis tagihan selain daftar ulang &amp; syariah, mis. ujian, PKL, wisuda, seragam, kegiatan.</small></div></div>
@include('tarif._tabs')

<div class="card card-tight">
    <div class="list-tools"><b>Jenis tagihan</b><small class="mut">{{ $jenis->count() }} jenis</small></div>
    @foreach($jenis as $j)
        <details class="jenis-item">
            <summary class="stu">
                <span class="nm"><b>{{ $j->nama }}</b><small>{{ \App\Models\JenisTagihan::POLA[$j->pola] }} · {{ $dipakai[$j->kode] ?? 0 }} tagihan{{ $j->keterangan ? ' · '.$j->keterangan : '' }}</small></span>
                @if($j->bawaan)<span class="badge">bawaan</span>@endif
                <span @class(['badge', 'b-ok' => $j->aktif])>{{ $j->aktif ? 'aktif' : 'nonaktif' }}</span>
            </summary>
            <form method="post" action="{{ route('jenis.ubah', $j) }}" class="fields" style="padding:0 16px 14px">
                @csrf @method('PUT')
                <div><label for="nm-{{ $j->id }}">Nama</label><input id="nm-{{ $j->id }}" type="text" name="nama" value="{{ $j->nama }}" maxlength="60" required></div>
                @if($j->bulanan())<div><label for="sk-{{ $j->id }}">Nama pendek <small class="mut">(label per bulan)</small></label><input id="sk-{{ $j->id }}" type="text" name="singkat" value="{{ $j->singkat }}" maxlength="30"></div>@endif
                <div><label for="ur-{{ $j->id }}">Urutan</label><input id="ur-{{ $j->id }}" type="number" name="urut" value="{{ $j->urut }}" min="0" max="999"></div>
                <div style="grid-column:1/-1"><label for="kt-{{ $j->id }}">Keterangan</label><input id="kt-{{ $j->id }}" type="text" name="keterangan" value="{{ $j->keterangan }}" maxlength="255"></div>
                <label class="chk"><input type="hidden" name="aktif" value="0"><input type="checkbox" name="aktif" value="1" @checked($j->aktif)> Aktif (ikut dibuatkan tagihan)</label>
                <div class="row"><button class="btn">Simpan</button></div>
            </form>
        </details>
    @endforeach
</div>

<form method="post" action="{{ route('jenis.simpan') }}" class="card">
    @csrf
    <h2>Tambah jenis tagihan</h2>
    <div class="fields">
        <div><label for="nama">Nama</label><input id="nama" type="text" name="nama" value="{{ old('nama') }}" maxlength="60" required placeholder="mis. Ujian Akhir Semester"></div>
        <div><label for="pola">Ditagih</label><select id="pola" name="pola">@foreach(\App\Models\JenisTagihan::POLA as $k => $l)<option value="{{ $k }}" @selected(old('pola') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="singkat">Nama pendek <small class="mut">(untuk bulanan, opsional)</small></label><input id="singkat" type="text" name="singkat" value="{{ old('singkat') }}" maxlength="30" placeholder="mis. Kas"></div>
        <div style="grid-column:1/-1"><label for="keterangan">Keterangan <small class="mut">(opsional)</small></label><input id="keterangan" type="text" name="keterangan" value="{{ old('keterangan') }}" maxlength="255" placeholder="mis. hanya kelas XII"></div>
    </div>
    <p class="hint" style="margin:8px 0 10px">Siapa yang dikenai diatur lewat tarif: isi nominal hanya untuk tingkat yang dikenai (mis. wisuda hanya kelas XII).</p>
    <button class="btn btn-pri">Tambah jenis</button>
</form>
@endsection
