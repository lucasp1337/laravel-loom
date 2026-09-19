<div class="loom-page">
    <h1 class="loom-h1">Dashboard</h1>
    <p class="loom-sub">Static index · Laravel {{ $dashboard->meta->laravelVersion }} · read-only</p>

    <div class="loom-stats">
        @foreach ($stats as $stat)
            <a class="loom-stat" href="{{ $stat['url'] }}"><b>{{ $stat['count'] }}</b><span>{{ $stat['label'] }}</span></a>
        @endforeach
    </div>

    <div class="loom-cols">
        <x-loom::card title="Orphans" :sub="$dashboard->orphanEventCount.' events · '.$dashboard->idleListenerCount.' listeners · '.$dashboard->unresolvedCount.' unresolved'">
            @if ($orphanEvents === [] && $idleListeners === [] && $unresolved === [])
                <div class="loom-card__empty">Nothing unconnected. Every event has a handler or a dispatch site.</div>
            @else
                <ul class="loom-list">
                    @foreach ($orphanEvents as $event)
                        <li><x-loom::badge type="event">no handlers</x-loom::badge>
                            <a class="name" href="{{ $links->entity(\Lucasp\Loom\Query\EntityKind::EVENT, $event->fqcn) }}">{{ $event->fqcn }}</a></li>
                    @endforeach
                    @foreach ($idleListeners as $listener)
                        <li><x-loom::badge type="listener">no event</x-loom::badge>
                            <a class="name" href="{{ $links->entity(\Lucasp\Loom\Query\EntityKind::LISTENER, $listener->fqcn) }}">{{ $listener->fqcn }}</a></li>
                    @endforeach
                    @foreach ($unresolved as $item)
                        <li><span class="loom-badge loom-badge--error">unresolved</span>
                            <span class="name">{{ $item->file }}:{{ $item->line }}</span><span class="loc">{{ $item->reason }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-loom::card>

        <x-loom::card title="Biggest fan-out" sub="top events by handler count">
            @if ($dashboard->biggestFanOut === [])
                <div class="loom-card__empty">No event has handlers yet.</div>
            @else
                <ul class="loom-list">
                    @foreach ($dashboard->biggestFanOut as $fan)
                        <li>
                            <a class="name" href="{{ $links->chain($fan->event) }}">{{ \Lucasp\Loom\Ui\Support\Fqcn::short($fan->event) }}</a>
                            <span class="loc">{{ $fan->handlerCount }} handlers · reaches {{ $fan->downstreamReach }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-loom::card>
    </div>
</div>
