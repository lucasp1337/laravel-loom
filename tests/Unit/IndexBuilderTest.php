<?php

declare(strict_types=1);

use Lucasp\Atlas\Index\IndexBuilder;

it('produces an empty index that validates against the schema', function () {
    $builder = new IndexBuilder;
    $index = $builder->build(sys_get_temp_dir(), '12.x');

    $payload = $index->toArray();

    expect($builder->validate($payload))->toBe([]);
    expect($payload)
        ->toHaveKeys(['atlas_version', 'scanned_at', 'laravel_version', 'stats', 'events', 'listeners', 'observers', 'model_events', 'unresolved_dispatches'])
        ->and($payload['atlas_version'])->toBe('0.1.0')
        ->and($payload['stats'])->toBe([
            'events' => 0,
            'listeners' => 0,
            'observers' => 0,
            'unresolved_dispatches' => 0,
        ]);
});
