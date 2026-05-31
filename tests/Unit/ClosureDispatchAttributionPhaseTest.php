<?php

declare(strict_types=1);

use Lucasp\Loom\Index\CrossLink\ClosureDispatchAttributionPhase;
use Lucasp\Loom\Index\CrossLink\CrossLinkContext;
use Lucasp\Loom\Index\CrossLink\SortPhase;
use Lucasp\Loom\Index\Sections;

/**
 * Build a CrossLinkContext with hand-built closure_listeners entries and
 * dispatch sites. Mirrors what CrossLinker::buildContext assembles, minus the
 * FQCN indexes the closure phase never reads (closures key positionally).
 *
 * @param  list<array<string, mixed>>  $closures
 * @param  list<array<string, mixed>>  $sites
 */
function closureContext(array $closures, array $sites): CrossLinkContext
{
    $sections = [];
    foreach (Sections::cases() as $section) {
        $sections[$section->value] = [];
    }
    $sections[Sections::CLOSURE_LISTENERS->value] = $closures;

    return new CrossLinkContext(
        sections: $sections,
        dispatchSites: $sites,
        singleIndexes: [],
        observerIndex: [],
    );
}

/**
 * A closure_listeners[] entry as the ListenerScanner emits it (dispatches
 * starts empty — this phase fills it).
 *
 * @return array<string, mixed>
 */
function closureEntry(string $file, int $line, int $endLine): array
{
    return [
        'event' => 'App\\Events\\Foo',
        'file' => $file,
        'line' => $line,
        'end_line' => $endLine,
        'registration' => 'event_listen_call',
        'queued' => false,
        'dispatches' => [],
    ];
}

/**
 * A `_dispatch_sites` entry as the IndexSerializer emits it (the keys
 * DispatchEntry::fromSite reads: provisionalKind, target, confidence, file, line).
 *
 * Closure-internal sites carry inClosure=true — the only sites this phase
 * consumes. Pass $inClosure=false to model a class-method site the phase must
 * ignore.
 *
 * @return array<string, mixed>
 */
function dispatchSite(string $target, string $kind, string $file, int $line, string $confidence = 'high', bool $inClosure = true): array
{
    return [
        'classFqcn' => null,
        'method' => null,
        'target' => $target,
        'form' => 'helper',
        'provisionalKind' => $kind,
        'file' => $file,
        'line' => $line,
        'confidence' => $confidence,
        'inClosure' => $inClosure,
    ];
}

it('attributes a dispatch site that falls inside the closure span', function () {
    $context = closureContext(
        [closureEntry('app/Providers/EventServiceProvider.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'app/Providers/EventServiceProvider.php', 12)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    $dispatches = $context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'];
    expect($dispatches)->toBe([
        [
            'target' => 'App\\Events\\Bar',
            'kind' => 'event',
            'confidence' => 'high',
            'file' => 'app/Providers/EventServiceProvider.php',
            'line' => 12,
        ],
    ]);
});

it('does not attribute a site before the closure start line', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 9)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('does not attribute a site after the closure end line', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 15)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('attributes a site sitting exactly on the closure start line (inclusive)', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 10)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toHaveCount(1);
});

it('attributes a site sitting exactly on the closure end line (inclusive)', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 14)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toHaveCount(1);
});

it('does not attribute an untagged class-method site even when its line falls inside the span', function () {
    // A class-method dispatch (inClosure=false) whose line lands inside a
    // closure listener's span must stay owned by DispatchAttributionPhase, not
    // leak into the closure's dispatches[].
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 12, inClosure: false)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('does not attribute a site in a different file with an overlapping line', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'b.php', 12)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('appends a site to every closure whose span encloses it (nested/overlapping spans)', function () {
    // Outer closure spans 10-20, inner nested closure spans 12-16; a site at
    // line 14 falls inside both and is attributed to BOTH entries.
    $context = closureContext(
        [
            closureEntry('a.php', 10, 20),
            closureEntry('a.php', 12, 16),
        ],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 14)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toHaveCount(1);
    expect($context->sections[Sections::CLOSURE_LISTENERS->value][1]['dispatches'])->toHaveCount(1);
});

it('skips an ambiguous-kind site even when it falls inside the span', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Things\\Thing', 'ambiguous', 'a.php', 12)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('skips a non-event/job kind site (mailable) even inside the span', function () {
    // DispatchEntry::fromSite would accept mailable, but closure dispatches[]
    // only carries event|job per the schema, so the phase drops it.
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Mail\\Receipt', 'mailable', 'a.php', 12)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('keeps dispatches empty for a closure with no matching site', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [dispatchSite('App\\Events\\Bar', 'event', 'a.php', 50)],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'])->toBe([]);
});

it('attributes both event and job kinds inside the span', function () {
    $context = closureContext(
        [closureEntry('a.php', 10, 14)],
        [
            dispatchSite('App\\Events\\Bar', 'event', 'a.php', 11),
            dispatchSite('App\\Jobs\\Baz', 'job', 'a.php', 12),
        ],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);

    $dispatches = $context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'];
    expect($dispatches)->toHaveCount(2);
    $kinds = array_column($dispatches, 'kind');
    expect($kinds)->toContain('event');
    expect($kinds)->toContain('job');
});

it('produces dispatches sorted by (file, line, target) once SortPhase runs', function () {
    // Site iteration order is not the final order; SortPhase (phase 5) imposes
    // (file, line, target). Feed sites out of order and assert the sorted result.
    $context = closureContext(
        [closureEntry('a.php', 10, 16)],
        [
            dispatchSite('App\\Jobs\\Baz', 'job', 'a.php', 14),
            dispatchSite('App\\Events\\Zed', 'event', 'a.php', 12),
            dispatchSite('App\\Events\\Bar', 'event', 'a.php', 12),
        ],
    );

    (new ClosureDispatchAttributionPhase)->apply($context);
    (new SortPhase)->apply($context);

    $targets = array_column(
        $context->sections[Sections::CLOSURE_LISTENERS->value][0]['dispatches'],
        'target',
    );

    // line 12 before line 14; within line 12, Bar before Zed by target asc.
    expect($targets)->toBe([
        'App\\Events\\Bar',
        'App\\Events\\Zed',
        'App\\Jobs\\Baz',
    ]);
});
