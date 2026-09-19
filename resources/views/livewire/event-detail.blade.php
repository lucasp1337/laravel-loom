<div class="loom-page">
    <div class="loom-detail-head">
        <x-loom::badge type="event" />
        <span class="ns">{{ $namespace }}</span>
        <a class="loom-btn loom-btn--lg actions" href="{{ $links->chain($event->fqcn) }}">View chain</a>
    </div>
    <h1 class="loom-title-mono">{{ $short }}</h1>
    <div class="loom-meta">
        <span class="loom-mono">{{ $event->file }}:{{ $event->line }}</span>
        <span>dispatched from {{ $sites->count() }} {{ $sites->count() === 1 ? 'site' : 'sites' }}</span>
        <span>{{ $handlers->total() }} {{ $handlers->total() === 1 ? 'handler' : 'handlers' }}</span>
    </div>

    <x-loom::card title="Dispatch sites" :sub="(string) $sites->count()">
        @if ($sites->sites === [])
            <div class="loom-card__empty">No dispatch site found in app/.</div>
        @else
            <ul class="loom-list">
                @foreach ($sites->sites as $site)
                    <li>
                        <span class="name">{{ $site->method }}</span>
                        <span class="loc">{{ $site->file }}:{{ $site->line }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-loom::card>

    <x-loom::card title="Handlers" :sub="(string) $handlers->total()">
        @if ($handlers->total() === 0)
            <div class="loom-card__empty">No handlers registered for this event.</div>
        @else
            <ul class="loom-list">
                @foreach ($handlers->listeners as $handler)
                    <li>
                        <x-loom::badge type="listener" />
                        @php($url = $links->forFqcn($handler->listener))
                        @if ($url !== null)<a class="name" href="{{ $url }}">{{ $handler->listener }}</a>@else<span class="name">{{ $handler->listener }}</span>@endif
                        <span class="loom-muted loom-mono">::{{ $handler->method }}</span>
                        @if ($handler->queued)<span class="loom-badge">queued</span>@endif
                    </li>
                @endforeach
                @foreach ($handlers->closureListeners as $closure)
                    <li>
                        <x-loom::badge type="closure" />
                        <span class="name">closure</span>
                        @if ($closure->queued)<span class="loom-badge">queued</span>@endif
                        <span class="loc">{{ $closure->file }}:{{ $closure->line }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-loom::card>

    <x-loom::card title="Downstream dispatches" sub="from handlers, depth 1">
        @if ($downstream === [])
            <div class="loom-card__empty">The handlers dispatch nothing further.</div>
        @else
            <ul class="loom-list">
                @foreach ($downstream as $item)
                    <li>
                        <x-loom::badge :type="$item->type" />
                        @if ($item->url !== null)<a class="name" href="{{ $item->url }}">{{ $item->target }}</a>@else<span class="name">{{ $item->target }}</span>@endif
                        <span class="loom-muted">from {{ $item->via }}</span>
                        <span class="loc">{{ $item->location }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-loom::card>
</div>
