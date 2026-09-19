<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Query\Dto\ChainEdge;
use Lucasp\Loom\Query\Dto\DispatchRef;
use Lucasp\Loom\Query\Dto\EventChain;
use Lucasp\Loom\Query\HandlerKind;
use Lucasp\Loom\Ui\Support\ChainGraph;

/** @param  list<DispatchRef>  $dispatches */
function chainEdge(string $event, string $handler, array $dispatches = []): ChainEdge
{
    return new ChainEdge($event, $handler, HandlerKind::LISTENER, $dispatches, 0);
}

function eventRef(string $target): DispatchRef
{
    return new DispatchRef($target, DispatchKinds::EVENT, Confidence::HIGH, 'f.php', 1);
}

it('gives two methods of one listener distinct keys', function () {
    $chain = new EventChain('E\\A', 3, [
        chainEdge('E\\A', 'L\\Sub::onA'),
        chainEdge('E\\A', 'L\\Sub::onB'),
    ], ['E\\A']);

    $keys = array_column(ChainGraph::build($chain)['nodes'], 'key');

    expect($keys)->toHaveCount(3)->and(array_unique($keys))->toBe($keys);
});

it('draws a repeated dispatch once and keeps every key unique', function () {
    $chain = new EventChain('E\\A', 3, [
        chainEdge('E\\A', 'L\\X::handle', [eventRef('E\\B'), eventRef('E\\B'), new DispatchRef('J\\Job', DispatchKinds::JOB, Confidence::HIGH, 'f.php', 3), new DispatchRef('J\\Job', DispatchKinds::JOB, Confidence::HIGH, 'f.php', 4)]),
    ], ['E\\A', 'E\\B']);

    $graph = ChainGraph::build($chain);
    $keys = array_column($graph['nodes'], 'key');

    expect($keys)->toHaveCount(4)->and(array_unique($keys))->toBe($keys)
        ->and($graph['edges'])->toHaveCount(3);
});

it('selects only the node at the chosen path', function () {
    $chain = new EventChain('E\\A', 3, [
        chainEdge('E\\A', 'L\\X::handle', [eventRef('E\\B')]),
        chainEdge('E\\B', 'L\\Y::handle'),
    ], ['E\\A', 'E\\B']);

    $selected = fn (?string $key) => array_column(array_filter(ChainGraph::build($chain, [], $key)['nodes'], static fn ($n) => $n['selected']), 'key');

    expect($selected('E\\A>L\\X::handle'))->toBe(['E\\A>L\\X::handle'])
        ->and($selected('nope'))->toBe([]);
});
