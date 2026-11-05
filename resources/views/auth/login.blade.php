@extends('layouts.app', ['title' => 'Masuk', 'bare' => true])
@section('content')
@php($pusat = \App\Services\PusatKlien::aktif())
<div class="card login">
    <img class="logo-login" src="{{ asset('icons/icon-192.png') }}?v={{ @filemtime(public_path('icons/icon-192.png')) }}" alt="" width="56" height="56">
    <h1>Tanggungan Siswa</h1>
    <p class="mut">{{ config('tanggungan.school_name') }}<br>Daftar ulang, iuran syariah &amp; tagihan sekolah lainnya. Masuk dengan akun Pusat Sekolah.</p>
    @if($pusat)<a class="btn btn-pri btn-blok" href="{{ route('sso.login') }}">Masuk lewat Pusat</a>@endif
    @if(config('tanggungan.login_lokal'))
        @if($pusat)<div class="login-atau"><span>atau dengan username &amp; password</span></div>@endif
        <form method="post" action="{{ route('login') }}" autocomplete="off">
            @csrf
            <div class="field"><label for="u">Username</label><input id="u" type="text" name="username" value="{{ old('username') }}" autocapitalize="none" autocomplete="username" required @unless($pusat) autofocus @endunless></div>
            <div class="field"><label for="pw">Password</label><div class="pw-wrap"><input id="pw" type="password" name="password" autocomplete="current-password" required><button type="button" class="pw-eye" data-toggle-pw="#pw" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
            <button @class(['btn', 'btn-blok', 'btn-pri' => ! $pusat]) data-busy="Memeriksa…">Masuk</button>
        </form>
        @if($pusat)<p class="login-catatan">Akun Pusat memakai password Pusat, jadi tetap bisa masuk saat Pusat sedang tidak bisa dihubungi.</p>@endif
    @endif
</div>
@endsection
