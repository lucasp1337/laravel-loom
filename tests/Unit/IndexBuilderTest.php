<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;

it('produces an empty index that validates against the schema', function () {
    $builder = new IndexBuilder;
    $index = $builder->build(sys_get_temp_dir(), '12.x');

    $payload = $index->toArray();

    expect($builder->validate($payload))->toBe([]);
    expect($payload)
        ->toHaveKeys(['loom_version', 'scanned_at', 'laravel_version', 'stats', 'events', 'listeners', 'observers', 'model_events', 'unresolved_dispatches', 'closure_listeners'])
        ->and($payload['loom_version'])->toBe('0.2.0')
        ->and($payload['closure_listeners'])->toBe([])
        ->and($payload['stats'])->toBe([
            'events' => 0,
            'listeners' => 0,
            'observers' => 0,
            'unresolved_dispatches' => 0,
            'closure_listeners' => 0,
        ]);
});
