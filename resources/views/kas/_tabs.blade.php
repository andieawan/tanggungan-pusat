<nav class="subtab noprint" aria-label="Keuangan">
    <a href="{{ route('kas.index') }}" @class(['on' => request()->routeIs('kas.index')])>Buku kas</a>
    <a href="{{ route('kas.rekap') }}" @class(['on' => request()->routeIs('kas.rekap')])>Rekap bulanan</a>
    @if(auth()->user()->isKasir())<a href="{{ route('kas.kategori') }}" @class(['on' => request()->routeIs('kas.kategori')])>Kategori</a>@endif
</nav>
