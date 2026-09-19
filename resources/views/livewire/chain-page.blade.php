@push('scripts')
    <script src="{{ \Lucasp\Loom\Ui\Asset::CYTOSCAPE->url() }}"></script>
@endpush

<div class="loom-page loom-page--wide">
    <div class="loom-chain-head">
        <h1>Chain</h1>
        <span>· {{ $short }}</span>
    </div>
    <p class="loom-sub">Click a node to select it · double-click to collapse or expand its children</p>

    <div x-data="loomChain" data-graph='@json($graph, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)' x-on:loom-escape.window="$wire.select(null)">
        <div class="loom-chain-toolbar">
            <span class="loom-muted" style="font-size:12px" id="depth-label">Depth</span>
            <div class="loom-seg" role="group" aria-labelledby="depth-label">
                @foreach ($depths as $d)
                    <button type="button" wire:click="setDepth({{ $d }})" aria-pressed="{{ $d === $depth ? 'true' : 'false' }}">{{ $d }}</button>
                @endforeach
            </div>
            <button type="button" class="loom-btn" x-on:click="fit()">Fit to screen</button>
            <div class="loom-legend">
                @foreach ($types as $type)
                    <span><i class="loom-badge--{{ $type->value }}">{{ $type->glyph() }}</i>{{ $type === \Lucasp\Loom\Ui\NodeType::CYCLE ? 'Already shown' : $type->label() }}</span>
                @endforeach
            </div>
        </div>

        <div class="loom-chain-body">
            <div class="loom-chain-canvas" wire:ignore x-ref="canvas" role="img" aria-label="Event chain graph for {{ $short }}">
                <div class="loom-skel" x-show="!ready && !missing">@for ($i = 0; $i < 6; $i++)<div><i></i><i></i></div>@endfor</div>
                <div class="loom-state" x-show="missing" x-cloak style="margin-top:80px"><p>The graph library could not be loaded.</p></div>
            </div>

            <aside class="loom-chain-panel" aria-label="Selected node">
                @if ($panel === null)
                    <p class="loom-muted" style="margin:0;font-size:13px">Select a node to see its details.</p>
                @else
                    <div class="head">
                        <x-loom::badge :type="$panel->type" />
                        <button type="button" class="x" wire:click="select(null)" aria-label="Close panel">×</button>
                    </div>
                    <h3>{{ $panel->short }}</h3>
                    @if ($panel->namespace !== '')<div class="ns">{{ $panel->namespace }}</div>@endif
                    <dl>
                        @foreach ($panel->facts as [$label, $value])
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                    @if ($panel->url !== null)
                        <a class="loom-btn" href="{{ $panel->url }}">Go to page</a>
                    @endif
                @endif
                <p class="loom-chain-note">Dispatches from listeners, jobs and closures are attributed to the class, not to a single method.</p>
            </aside>
        </div>

        @if (! $hasHandlers)
            <div class="loom-chain-caption">No handlers registered for this event.</div>
        @elseif ($graph['truncated'])
            <div class="loom-chain-caption">Depth limit reached. Raise the depth to expand the remaining events.</div>
        @endif
    </div>

    <p class="loom-chain-note">Nodes already drawn earlier in the chain appear as <code>↺ already shown</code> rather than a loop edge.</p>

    <ul class="loom-sr" aria-label="Chain nodes">
        @foreach ($graph['nodes'] as $node)
            <li wire:key="n-{{ $node['key'] }}"><button type="button" wire:click="select(@js($node['key']))">{{ str_replace("\n", ' ', $node['label']) }}</button></li>
        @endforeach
    </ul>
</div>
