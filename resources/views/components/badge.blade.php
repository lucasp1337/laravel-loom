@props(['type'])
@php($t = $type instanceof \Lucasp\Loom\Ui\NodeType ? $type : \Lucasp\Loom\Ui\NodeType::from($type))
<span {{ $attributes->class(['loom-badge', 'loom-badge--'.$t->value]) }}><span aria-hidden="true">{{ $t->glyph() }}</span> {{ $slot->isEmpty() ? $t->label() : $slot }}</span>
