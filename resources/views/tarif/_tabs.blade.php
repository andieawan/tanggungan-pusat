<nav class="subtab" aria-label="Pengaturan">
    <a href="{{ route('tarif.index') }}" @class(['on' => request()->routeIs('tarif.*')])>Tarif &amp; tagihan</a>
    <a href="{{ route('impor.index') }}" @class(['on' => request()->routeIs('impor.*')])>Impor data lama</a>
</nav>
