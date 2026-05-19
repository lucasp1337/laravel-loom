<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;

it('produces an empty index that validates against the schema', function () {
    $builder = new IndexBuilder;
    $index = $builder->build(sys_get_temp_dir(), '12.x');

    $payload = $index->toArray();

    expect($builder->validate($payload))->toBe([]);
    expect($payload)
        ->toHaveKeys(['loom_version', 'scanned_at', 'laravel_version', 'stats', 'events', 'listeners', 'observers', 'model_events', 'jobs', 'unresolved_dispatches', 'closure_listeners', 'scheduled', 'mailables', 'notifications'])
        ->and($payload['loom_version'])->toBe('0.2.0')
        ->and($payload['closure_listeners'])->toBe([])
        ->and($payload['jobs'])->toBe([])
        ->and($payload['scheduled'])->toBe([])
        ->and($payload['mailables'])->toBe([])
        ->and($payload['notifications'])->toBe([])
        ->and($payload['stats'])->toBe([
            'events' => 0,
            'listeners' => 0,
            'observers' => 0,
            'jobs' => 0,
            'unresolved_dispatches' => 0,
            'closure_listeners' => 0,
            'scheduled' => 0,
            'mailables' => 0,
            'notifications' => 0,
        ]);
});

it('emits jobs as an empty array and stats.jobs as 0 for an empty app', function () {
    $builder = new IndexBuilder;
    $index = $builder->build(sys_get_temp_dir(), '12.x');
    $payload = $index->toArray();

    expect($payload['jobs'])->toBe([]);
    expect($payload['stats']['jobs'])->toBe(0);
    expect($builder->validate($payload))->toBe([]);
});
