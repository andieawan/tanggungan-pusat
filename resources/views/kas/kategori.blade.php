@extends('layouts.app', ['title' => 'Kategori kas'])
@section('content')
<div class="head"><div><h1>Keuangan</h1><small>Kategori pemasukan &amp; pengeluaran. Kategori yang tidak dipakai lagi dinonaktifkan (riwayat tetap utuh).</small></div></div>
@include('kas._tabs')
@foreach(['masuk' => 'Pemasukan', 'keluar' => 'Pengeluaran'] as $arah => $judul)
<div class="card card-tight">
    <div class="list-tools"><b>{{ $judul }}</b><small class="mut">{{ $kategori->where('arah', $arah)->count() }} kategori</small></div>
    @foreach($kategori->where('arah', $arah) as $k)
        <details class="jenis-item">
            <summary class="stu"><span class="nm"><b>{{ $k->nama }}</b><small>{{ $dipakai[$k->id] ?? 0 }} transaksi · urutan {{ $k->urut }}</small></span>
                <span @class(['badge', 'b-ok' => $k->aktif])>{{ $k->aktif ? 'aktif' : 'nonaktif' }}</span></summary>
            <form method="post" action="{{ route('kas.kategori.ubah', $k) }}" class="fields" style="padding:0 16px 14px">@csrf @method('PUT')
                <div><label for="nm-{{ $k->id }}">Nama</label><input id="nm-{{ $k->id }}" type="text" name="nama" value="{{ $k->nama }}" maxlength="60" required></div>
                <div><label for="ur-{{ $k->id }}">Urutan</label><input id="ur-{{ $k->id }}" type="number" name="urut" value="{{ $k->urut }}" min="0" max="999"></div>
                <label class="chk"><input type="hidden" name="aktif" value="0"><input type="checkbox" name="aktif" value="1" @checked($k->aktif)> Aktif (muncul di form catat)</label>
                <div class="row"><button class="btn">Simpan</button></div>
            </form>
        </details>
    @endforeach
</div>
@endforeach
<form method="post" action="{{ route('kas.kategori.simpan') }}" class="card">@csrf
    <h2>Tambah kategori</h2>
    <div class="fields">
        <div><label for="nama">Nama</label><input id="nama" type="text" name="nama" maxlength="60" required placeholder="mis. Listrik &amp; internet"></div>
        <div><label for="arah">Jenis</label><select id="arah" name="arah"><option value="keluar">Pengeluaran</option><option value="masuk">Pemasukan</option></select></div>
        <div class="row" style="align-self:end"><button class="btn btn-pri">Tambah</button></div>
    </div>
</form>
@endsection
