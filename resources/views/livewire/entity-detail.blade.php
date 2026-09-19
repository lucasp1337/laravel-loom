<div class="loom-page">
    <div class="loom-detail-head">
        @if ($spec->type !== null)<x-loom::badge :type="$spec->type" />@endif
        <span class="ns">{{ $namespace }}</span>
    </div>
    <h1 class="loom-title-mono">{{ $short }}</h1>

    <x-loom::card :title="$spec->label" sub="read-model fields">
        <dl class="loom-facts">
            @foreach ($rows as $row)
                <dt>{{ $row['label'] }}</dt>
                <dd>
                    @if ($row['table'] !== [])
                        <table>
                            <thead><tr>@foreach ($row['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($row['table'] as $cells)
                                    <tr>
                                        @foreach ($cells as $cell)
                                            <td>@if ($cell['url'] !== null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @elseif (count($row['cells']) > 1)
                        <div class="loom-chips">
                            @foreach ($row['cells'] as $cell)
                                <span>@if ($cell['url'] !== null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif</span>
                            @endforeach
                        </div>
                    @elseif ($row['cells'] !== [])
                        @php($cell = $row['cells'][0])
                        @if ($cell['url'] !== null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif
                    @else
                        <span class="loom-muted">none</span>
                    @endif
                </dd>
            @endforeach
        </dl>
    </x-loom::card>
</div>
