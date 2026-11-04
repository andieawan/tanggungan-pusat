@extends('layouts.app', ['title' => 'Integrasi'])
@section('content')
<div class="head"><div><h1>Integrasi</h1><small>Sambungan ke Pusat Sekolah: akun serta data induk (kelas, siswa, guru). Siswa yang lulus atau keluar di Pusat tetap tersimpan di sini beserta tanggungannya.</small></div></div>
@include('admin._pusat')
@endsection
