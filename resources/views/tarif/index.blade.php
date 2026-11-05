@extends('layouts.app', ['title' => 'Tarif'])
@section('content')
@php
    $T = \App\Services\Tanggungan::class;
    $bawaanSyariah = (int) config('tanggungan.syariah_bawaan');
    $nilai = fn ($jenis, $tk) => old("nominal.$jenis.$tk", isset($tarif["$jenis:$tk"]) ? $tarif["$jenis:$tk"]->nominal : ($bawaan && $jenis === 'syariah' ? $bawaanSyariah : ''));
    $bebas = fn ($jenis, $tk) => array_map('intval', (array) old("bebas.$jenis.$tk", isset($tarif["$jenis:$tk"]) ? ($tarif["$jenis:$tk"]->bulan_bebas ?? []) : ($bawaan && $jenis === 'syariah' && $tk === 'X' ? [7, 8] : [])));
@endphp
<div class="head"><div><h1>Pengaturan</h1><small>Tarif tiap jenis tagihan per tahun ajaran dan tingkat. Kosongkan nominal bila tingkat itu tidak dikenai.</small></div></div>
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
                @foreach($jenis as $j)
                    @php($id = 'n-'.$j->kode.'-'.$tk)
                    <div><label for="{{ $id }}">{{ $j->nama }} <small class="mut">({{ $j->bulanan() ? 'per bulan' : 'setahun' }})</small></label>
                        <input id="{{ $id }}" type="text" inputmode="numeric" name="nominal[{{ $j->kode }}][{{ $tk }}]" value="{{ $nilai($j->kode, $tk) }}" placeholder="kosong = tidak ada">
                        <small class="mut">{{ $jumlah[$j->kode.':'.$tk]->siswa ?? 0 }} siswa sudah bertagihan</small></div>
                @endforeach
            </div>
            @foreach($jenis->filter->bulanan() as $j)
                <div class="bulan-bebas"><small class="mut">Bulan tanpa {{ mb_strtolower($j->nama) }}:</small>
                    @foreach($T::BULAN_TA as $b)<label class="chk"><input type="checkbox" name="bebas[{{ $j->kode }}][{{ $tk }}][]" value="{{ $b }}" @checked(in_array($b, $bebas($j->kode, $tk), true))> {{ mb_substr($T::namaBulan($b), 0, 3) }}</label>@endforeach
                </div>
            @endforeach
        </fieldset>
    @endforeach
    <label class="chk"><input type="checkbox" name="perbarui" value="1"> Perbarui juga nominal tagihan {{ $tahun }} yang belum dibayar sama sekali</label>
    <div class="row" style="margin-top:12px"><button class="btn btn-pri">Simpan tarif</button><a class="btn" href="{{ route('jenis.index') }}">Atur jenis tagihan</a></div>
</form>

@if($tahun === $T::tahunAktif())
<form method="post" action="{{ route('tarif.terapkan') }}" class="card">
    @csrf <input type="hidden" name="tahun" value="{{ $tahun }}">
    <h2>Buat tagihan {{ $tahun }}</h2>
    <p class="mut" style="margin:0 0 10px">Membuat tagihan semua jenis aktif untuk semua siswa aktif sesuai tingkat kelasnya saat ini. Aman diulang: tagihan yang sudah ada tidak berubah; siswa baru dan jenis tagihan baru ikut dibuatkan.</p>
    <button class="btn">Buat tagihan untuk semua siswa aktif</button>
</form>
@else
<div class="card"><p class="mut" style="margin:0">Tagihan otomatis dibuat untuk tahun ajaran aktif ({{ $T::tahunAktif() }}). Tarif tahun lain dipakai saat <a href="{{ route('impor.index') }}">impor data lama</a> bila nominal tidak diisi di berkas.</p></div>
@endif
@endsection
