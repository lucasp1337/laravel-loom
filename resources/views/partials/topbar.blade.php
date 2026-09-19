<header class="loom-topbar">
    <button type="button" class="loom-btn loom-menu-btn" x-on:click="drawer = !drawer" aria-label="Toggle navigation">Menu</button>
    <nav class="loom-crumbs" aria-label="Breadcrumb">
        @foreach ($crumbs ?? [] as [$label, $url])
            @if (! $loop->first)<span class="sep">/</span>@endif
            @if ($url !== null && ! $loop->last)
                <a href="{{ $url }}">{{ $label }}</a>
            @else
                <span class="cur">{{ $label }}</span>
            @endif
        @endforeach
    </nav>
    <span class="grow"></span>
    <button type="button" class="loom-palette-trigger" x-on:click="openPalette()">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/></svg>
        Search classes and methods
        <kbd class="loom-kbd" x-text="mod">Ctrl K</kbd>
    </button>
    <span class="loom-scanned">Last scanned {{ $meta->scannedAt }}</span>
</header>
