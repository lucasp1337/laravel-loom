<nav class="loom-sidebar" x-bind:class="{ 'is-open': drawer }" aria-label="Loom sections">
    <a class="loom-logo" href="{{ $links->dashboard() }}">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 2v14M9 2v14M15 2v14M2 5h14M2 13h14"/></svg>
        Loom
        <small>v{{ $meta->loomVersion }}</small>
    </a>
    <div class="loom-nav">
        <a href="{{ $links->dashboard() }}" @if (($active ?? null) === null && request()->routeIs('loom.dashboard')) aria-current="page" @endif>Dashboard</a>
        @foreach ($nav as $item)
            <a href="{{ $item['url'] }}" @if (($active ?? null) === $item['section']) aria-current="page" @endif>
                {{ $item['label'] }}
                <span class="loom-count">{{ $item['count'] }}</span>
            </a>
        @endforeach
    </div>
</nav>
