@extends('layouts.app', ['title' => 'Tarif'])
@section('content')
@php
    $T = \App\Services\Tanggungan::class;
    $bawaanSyariah = (int) config('tanggungan.syariah_bawaan');
    $nilai = fn ($jenis, $tk) => old("$jenis.$tk", isset($tarif["$jenis:$tk"]) ? $tarif["$jenis:$tk"]->nominal : ($bawaan && $jenis === 'syariah' ? $bawaanSyariah : ''));
    $bebas = fn ($tk) => array_map('intval', (array) old("bebas.$tk", isset($tarif["syariah:$tk"]) ? ($tarif["syariah:$tk"]->bulan_bebas ?? []) : ($bawaan && $tk === 'X' ? [7, 8] : [])));
@endphp
<div class="head"><div><h1>Pengaturan</h1><small>Tarif daftar ulang &amp; iuran syariah per tahun ajaran dan tingkat.</small></div></div>
@include('tarif._tabs')

<form method="get" class="card filter-card">
    <div class="fields"><div><label for="tahun">Tahun ajaran</label><select id="tahun" name="tahun" data-auto>@foreach($pilihan as $t)<option @selected($t === $tahun)>{{ $t }}</option>@endforeach</select></div></div>
</form>

<form method="post" action="{{ route('tarif.simpan') }}" class="card" data-unsaved>
    @csrf @method('PUT') <input type="hidden" name="tahun" value="{{ $tahun }}">
    <h2>Tarif {{ $tahun }}</h2>
    @if($bawaan)<p class="hint" style="margin:0 0 10px">Belum diatur: terisi usulan (syariah Rp{{ number_format($bawaanSyariah, 0, ',', '.') }}/bulan; Juli–Agustus kelas X termasuk daftar ulang). Periksa lalu simpan.</p>@endif
    @foreach($T::TINGKAT as $tk)
        <fieldset class="tarif-baris">
            <legend>Kelas {{ $tk }}</legend>
            <div class="fields">
                <div><label for="du-{{ $tk }}">Daftar ulang (setahun)</label><input id="du-{{ $tk }}" type="text" inputmode="numeric" name="du[{{ $tk }}]" value="{{ $nilai('du', $tk) }}" placeholder="kosong = tidak ada"></div>
                <div><label for="sy-{{ $tk }}">Iuran syariah per bulan</label><input id="sy-{{ $tk }}" type="text" inputmode="numeric" name="syariah[{{ $tk }}]" value="{{ $nilai('syariah', $tk) }}" placeholder="kosong = tidak ada"></div>
                <div class="hint" style="align-self:end">Tagihan: {{ $jumlah["du:$tk"]->siswa ?? 0 }} siswa DU · {{ $jumlah["syariah:$tk"]->siswa ?? 0 }} siswa syariah</div>
            </div>
            <div class="bulan-bebas"><small class="mut">Bulan tanpa iuran syariah:</small>
                @foreach($T::BULAN_TA as $b)<label class="chk"><input type="checkbox" name="bebas[{{ $tk }}][]" value="{{ $b }}" @checked(in_array($b, $bebas($tk), true))> {{ mb_substr($T::namaBulan($b), 0, 3) }}</label>@endforeach
            </div>
        </fieldset>
    @endforeach
    <label class="chk"><input type="checkbox" name="perbarui" value="1"> Perbarui juga nominal tagihan {{ $tahun }} yang belum dibayar sama sekali</label>
    <div class="row" style="margin-top:12px"><button class="btn btn-pri">Simpan tarif</button></div>
</form>

@if($tahun === $T::tahunAktif())
<form method="post" action="{{ route('tarif.terapkan') }}" class="card">
    @csrf <input type="hidden" name="tahun" value="{{ $tahun }}">
    <h2>Buat tagihan {{ $tahun }}</h2>
    <p class="mut" style="margin:0 0 10px">Membuat tagihan untuk semua siswa aktif sesuai tingkat kelasnya saat ini. Aman diulang: siswa yang sudah punya tagihan tidak berubah, siswa baru ikut dibuatkan.</p>
    <button class="btn">Buat tagihan untuk semua siswa aktif</button>
</form>
@else
<div class="card"><p class="mut" style="margin:0">Tagihan otomatis dibuat untuk tahun ajaran aktif ({{ $T::tahunAktif() }}). Tarif tahun lain dipakai saat <a href="{{ route('impor.index') }}">impor data lama</a> bila nominal tidak diisi di berkas.</p></div>
@endif
@endsection
