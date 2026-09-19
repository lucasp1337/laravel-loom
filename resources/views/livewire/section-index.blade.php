<div class="loom-page">
    <h1 class="loom-h1">{{ $spec->label }}</h1>
    <p class="loom-sub">{{ $total }} in the index</p>

    <div class="loom-toolbar">
        <input class="loom-input" type="search" wire:model.live.debounce.200ms="search"
               placeholder="Filter by class, file or namespace" aria-label="Filter {{ strtolower($spec->label) }}">
        <span class="count">{{ $result->total }} of {{ $total }} rows</span>
        @if ($filtered)
            <button type="button" class="loom-btn" wire:click="clearFilter">Clear</button>
        @endif
    </div>

    <div wire:loading.delay.shortest wire:target="search,sortBy,goToPage" class="loom-skel" aria-hidden="true">
        @for ($i = 0; $i < 6; $i++)<div><i></i><i></i></div>@endfor
    </div>

    <div wire:loading.remove wire:target="search,sortBy,goToPage">
        @if ($rows === [])
            <div class="loom-empty">
                @if ($filtered)
                    <h2>No matches for "{{ $search }}"</h2>
                    <p>Filtering matches class name, namespace and file path.</p>
                    <button type="button" class="loom-btn loom-btn--lg" wire:click="clearFilter">Clear filter</button>
                @else
                    <h2>{{ $spec->emptyTitle }}</h2>
                    <p>{{ $spec->emptyBody }}</p>
                @endif
            </div>
        @else
            <table class="loom-table">
                <thead>
                    <tr>
                        @foreach ($spec->columns as $column)
                            <th @class(['num' => $column->role === $roleNumber, 'file' => $column->role === $roleFile])
                                @if ($column->sort !== null && $column->sort === $sort) aria-sort="{{ $dir === \Lucasp\Loom\Query\SortDirection::ASC ? 'ascending' : 'descending' }}" @endif>
                                @if ($column->sort !== null)
                                    <button type="button" wire:click="sortBy('{{ $column->sort->value }}')">
                                        {{ $column->label }}@if ($column->sort === $sort)<span class="sort">{{ $dir === \Lucasp\Loom\Query\SortDirection::ASC ? '▲' : '▼' }}</span>@endif
                                    </button>
                                @else
                                    {{ $column->label }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="row-{{ $result->page }}-{{ $loop->index }}">
                            @foreach ($spec->columns as $i => $column)
                                <td @class(['num' => $column->role === $roleNumber, 'file' => $column->role === $roleFile, 'name' => $column->role === $roleName]) title="{{ $row->cells[$i] }}">
                                    @if ($column->role === $roleName && $row->url !== null)
                                        <a href="{{ $row->url }}">{{ $row->cells[$i] }}</a>
                                    @else
                                        {{ $row->cells[$i] }}
                                    @endif
                                    @if ($column->role === $roleName && $row->orphan)
                                        <span class="loom-badge loom-badge--attn">orphan</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($result->lastPage() > 1)
                <div class="loom-pager">
                    <button type="button" class="loom-btn" wire:click="goToPage({{ $result->page - 1 }})" @disabled($result->page <= 1)>Previous</button>
                    <span>Page {{ $result->page }} of {{ $result->lastPage() }}</span>
                    <button type="button" class="loom-btn" wire:click="goToPage({{ $result->page + 1 }})" @disabled($result->page >= $result->lastPage())>Next</button>
                </div>
            @endif
        @endif
    </div>
</div>
