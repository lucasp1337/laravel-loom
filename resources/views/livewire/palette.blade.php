<div>
    <input data-palette-input type="text" wire:model.live.debounce.120ms="term" x-on:input="cursor = 0"
           placeholder="Search events, listeners, jobs, routes…" autocomplete="off" spellcheck="false" aria-label="Search">
    <div class="loom-palette__list">
        @forelse ($groups as $group)
            <div class="loom-palette__group">{{ $group->label }}</div>
            @foreach ($group->items as $hit)
                <a class="loom-palette__item" data-palette-item href="{{ $hit->url }}">
                    <x-loom::badge :type="$hit->type" />
                    <span class="n">{{ $hit->label }}</span>
                    <span class="m">{{ $hit->subtitle }}</span>
                </a>
            @endforeach
        @empty
            <div class="loom-palette__empty">
                @if ($term === '')
                    Type to search classes, routes and closure listeners.
                @else
                    No class or method matches "{{ $term }}".
                @endif
            </div>
        @endforelse
    </div>
    <div class="loom-palette__foot">
        <span>↑↓ move</span><span>↵ open</span><span>esc close</span>
        <span class="r">{{ $count }} {{ $count === 1 ? 'result' : 'results' }}</span>
    </div>
</div>
