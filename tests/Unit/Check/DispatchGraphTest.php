<?php

declare(strict_types=1);

use Lucasp\Loom\Check\CheckContext;
use Lucasp\Loom\Check\DispatchGraph;

/**
 * Build a CheckContext from raw section bodies. The graph reads only events,
 * listeners and jobs, so other sections are left empty.
 *
 * @param  list<array<string,mixed>>  $events
 * @param  list<array<string,mixed>>  $listeners
 * @param  list<array<string,mixed>>  $jobs
 */
function graphContext(array $events = [], array $listeners = [], array $jobs = []): CheckContext
{
    return new CheckContext(
        [
            'events' => $events,
            'listeners' => $listeners,
            'jobs' => $jobs,
        ],
        null,
        false,
    );
}

/**
 * An event whose handlers are the given listener FQCNs.
 *
 * @param  list<string>  $listeners
 * @return array<string,mixed>
 */
function graphEvent(string $fqcn, array $listeners): array
{
    return [
        'fqcn' => $fqcn,
        'handled_by' => array_map(
            static fn (string $listener): array => ['listener' => $listener, 'method' => 'handle'],
            $listeners,
        ),
    ];
}

/**
 * A listener (or job) whose dispatches target the given FQCNs.
 *
 * @param  list<string>  $targets
 * @return array<string,mixed>
 */
function graphDispatcher(string $fqcn, array $targets): array
{
    return [
        'fqcn' => $fqcn,
        'dispatches' => array_map(
            static fn (string $target): array => [
                'target' => $target,
                'kind' => 'event',
                'confidence' => 'high',
                'file' => 'app/X.php',
                'line' => 1,
            ],
            $targets,
        ),
    ];
}

// -----------------------------------------------------------------------------
// No cycle
// -----------------------------------------------------------------------------

it('reports no cycle for a linear dispatch chain', function () {
    // A -handled by-> La -dispatches-> B -handled by-> Lb -dispatches-> C
    $context = graphContext(
        events: [
            graphEvent('App\\Events\\A', ['App\\Listeners\\La']),
            graphEvent('App\\Events\\B', ['App\\Listeners\\Lb']),
            graphEvent('App\\Events\\C', []),
        ],
        listeners: [
            graphDispatcher('App\\Listeners\\La', ['App\\Events\\B']),
            graphDispatcher('App\\Listeners\\Lb', ['App\\Events\\C']),
        ],
    );

    expect(DispatchGraph::fromContext($context)->cycles())->toBe([]);
});

// -----------------------------------------------------------------------------
// Two-node cycle
// -----------------------------------------------------------------------------

it('detects a two-node event cycle', function () {
    // A's listener dispatches B; B's listener dispatches A.
    $context = graphContext(
        events: [
            graphEvent('App\\Events\\A', ['App\\Listeners\\La']),
            graphEvent('App\\Events\\B', ['App\\Listeners\\Lb']),
        ],
        listeners: [
            graphDispatcher('App\\Listeners\\La', ['App\\Events\\B']),
            graphDispatcher('App\\Listeners\\Lb', ['App\\Events\\A']),
        ],
    );

    $cycles = DispatchGraph::fromContext($context)->cycles();

    expect($cycles)->toHaveCount(1);
    // Canonicalized: smallest node (A) leads, path is closed.
    expect($cycles[0])->toBe(['App\\Events\\A', 'App\\Events\\B', 'App\\Events\\A']);
});

// -----------------------------------------------------------------------------
// Self-loop
// -----------------------------------------------------------------------------

it('detects a self-loop where an event re-dispatches itself', function () {
    $context = graphContext(
        events: [
            graphEvent('App\\Events\\A', ['App\\Listeners\\La']),
        ],
        listeners: [
            graphDispatcher('App\\Listeners\\La', ['App\\Events\\A']),
        ],
    );

    $cycles = DispatchGraph::fromContext($context)->cycles();

    expect($cycles)->toHaveCount(1);
    expect($cycles[0])->toBe(['App\\Events\\A', 'App\\Events\\A']);
});

// -----------------------------------------------------------------------------
// Order-independence (headline determinism invariant)
// -----------------------------------------------------------------------------

it('produces identical cycles regardless of input ordering', function () {
    $events = [
        graphEvent('App\\Events\\A', ['App\\Listeners\\La']),
        graphEvent('App\\Events\\B', ['App\\Listeners\\Lb']),
        graphEvent('App\\Events\\C', ['App\\Listeners\\Lc']),
    ];
    $listeners = [
        graphDispatcher('App\\Listeners\\La', ['App\\Events\\B']),
        graphDispatcher('App\\Listeners\\Lb', ['App\\Events\\C']),
        graphDispatcher('App\\Listeners\\Lc', ['App\\Events\\A']),
    ];

    $canonical = DispatchGraph::fromContext(graphContext($events, $listeners))->cycles();

    expect($canonical)->toHaveCount(1);

    // Every permutation of both arrays must yield byte-identical cycles.
    $eventShuffles = [
        array_reverse($events),
        [$events[1], $events[2], $events[0]],
        [$events[2], $events[0], $events[1]],
    ];
    $listenerShuffles = [
        array_reverse($listeners),
        [$listeners[2], $listeners[0], $listeners[1]],
        [$listeners[1], $listeners[2], $listeners[0]],
    ];

    foreach ($eventShuffles as $e) {
        foreach ($listenerShuffles as $l) {
            $cycles = DispatchGraph::fromContext(graphContext($e, $l))->cycles();
            expect($cycles)->toBe($canonical);
        }
    }
});

// -----------------------------------------------------------------------------
// Job -> job cycle
// -----------------------------------------------------------------------------

it('detects a job to job cycle', function () {
    $context = graphContext(
        jobs: [
            graphDispatcher('App\\Jobs\\Alpha', ['App\\Jobs\\Beta']),
            graphDispatcher('App\\Jobs\\Beta', ['App\\Jobs\\Alpha']),
        ],
    );

    $cycles = DispatchGraph::fromContext($context)->cycles();

    expect($cycles)->toHaveCount(1);
    expect($cycles[0])->toBe(['App\\Jobs\\Alpha', 'App\\Jobs\\Beta', 'App\\Jobs\\Alpha']);
});

// -----------------------------------------------------------------------------
// Canonical rotation / dedup across entry points
// -----------------------------------------------------------------------------

it('dedupes one cycle discovered from different entry points', function () {
    // A three-node ring plus an extra edge into the ring (D -> B) that gives the
    // DFS a second way to discover the same back-edge. The result must be one
    // canonical cycle, not two rotations of it.
    $context = graphContext(
        events: [
            graphEvent('App\\Events\\A', ['App\\Listeners\\La']),
            graphEvent('App\\Events\\B', ['App\\Listeners\\Lb']),
            graphEvent('App\\Events\\C', ['App\\Listeners\\Lc']),
            graphEvent('App\\Events\\D', ['App\\Listeners\\Ld']),
        ],
        listeners: [
            graphDispatcher('App\\Listeners\\La', ['App\\Events\\B']),
            graphDispatcher('App\\Listeners\\Lb', ['App\\Events\\C']),
            graphDispatcher('App\\Listeners\\Lc', ['App\\Events\\A']),
            graphDispatcher('App\\Listeners\\Ld', ['App\\Events\\B']),
        ],
    );

    $cycles = DispatchGraph::fromContext($context)->cycles();

    expect($cycles)->toHaveCount(1);
    expect($cycles[0])->toBe([
        'App\\Events\\A',
        'App\\Events\\B',
        'App\\Events\\C',
        'App\\Events\\A',
    ]);
});
