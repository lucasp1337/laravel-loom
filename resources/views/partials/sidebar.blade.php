<nav class="loom-sidebar" x-bind:class="{ 'is-open': drawer }" aria-label="Loom sections">
    <a class="loom-logo" href="{{ $links->dashboard() }}">
        <svg class="loom-logo__mark" width="22" height="22" viewBox="0 0 64 64" fill="none" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path stroke="currentColor" d="M14 10v44M24 10v44M32 10v44M40 10v44M50 10v44"/><path class="loom-logo__weft" d="M6 32h52" stroke-width="3"/><path stroke="currentColor" d="M24 28.6v6.8M40 28.6v6.8"/></svg>
        Loom
        <small>v{{ $meta->loomVersion }}</small>
    </a>
    <div class="loom-nav">
        <a href="{{ $links->dashboard() }}" @if (($active ?? null) === null && request()->routeIs('loom.dashboard')) aria-current="page" @endif>Dashboard</a>
        @foreach ($nav as $item)
            <a href="{{ $item->url }}" @if (($active ?? null) === $item->section) aria-current="page" @endif>
                {{ $item->label }}
                <span class="loom-count">{{ $item->count }}</span>
            </a>
        @endforeach
    </div>
</nav>
