@props(['title', 'sub' => null])
<section class="loom-card">
    <header class="loom-card__head">
        <h2>{{ $title }}</h2>
        @if ($sub !== null)<span>{{ $sub }}</span>@endif
    </header>
    {{ $slot }}
</section>
