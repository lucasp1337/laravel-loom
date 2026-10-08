<?php

declare(strict_types=1);

use Lucasp\Loom\Index\CrossLink\ClosureOwnershipPhase;
use Lucasp\Loom\Index\CrossLink\CrossLinkContext;
use Lucasp\Loom\Index\Sections;

/**
 * @param  list<array<string, mixed>>  $sites
 */
function ownershipContext(array $sites): CrossLinkContext
{
    $sections = [];
    foreach (Sections::cases() as $section) {
        $sections[$section->value] = [];
    }
    $sections[Sections::CLOSURE_LISTENERS->value] = [[
        'event' => 'App\\Events\\Foo',
        'file' => 'a.php',
        'line' => 10,
        'end_line' => 14,
        'registration' => 'event_listen_call',
        'queued' => false,
        'dispatches' => [],
    ]];

    return new CrossLinkContext($sections, $sites, [], []);
}

/**
 * @return array<string, mixed>
 */
function ownershipSite(string $file, int $line): array
{
    return [
        'classFqcn' => 'App\\Svc',
        'method' => 'go',
        'target' => 'App\\Events\\Bar',
        'provisionalKind' => 'event',
        'file' => $file,
        'line' => $line,
        'inClosure' => true,
    ];
}

it('keeps the closure tag for a site inside a registration span', function () {
    $context = ownershipContext([ownershipSite('a.php', 12)]);

    (new ClosureOwnershipPhase)->apply($context);

    expect($context->dispatchSites[0]['inClosure'])->toBeTrue();
});

it('clears the closure tag for a pass-through site outside every span', function (string $file, int $line) {
    $context = ownershipContext([ownershipSite($file, $line)]);

    (new ClosureOwnershipPhase)->apply($context);

    expect($context->dispatchSites[0]['inClosure'])->toBeFalse();
})->with([
    'before span' => ['a.php', 9],
    'after span' => ['a.php', 15],
    'other file' => ['b.php', 12],
]);

it('leaves untagged sites alone', function () {
    $site = ownershipSite('a.php', 12);
    $site['inClosure'] = false;
    $context = ownershipContext([$site]);

    (new ClosureOwnershipPhase)->apply($context);

    expect($context->dispatchSites[0]['inClosure'])->toBeFalse();
});

it('keeps the tag and records the route as origin for a site inside a closure route span', function () {
    $context = ownershipContext([ownershipSite('routes/web.php', 6)]);
    $context->sections[Sections::ROUTES->value] = [[
        'method' => 'GET',
        'uri' => '/closure',
        'file' => 'routes/web.php',
        'line' => 5,
        'end_line' => 8,
        'dispatches' => [],
    ]];

    (new ClosureOwnershipPhase)->apply($context);

    expect($context->dispatchSites[0]['inClosure'])->toBeTrue();
    expect($context->dispatchSites[0]['closureOrigin'])->toBe('GET /closure');
});
