<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Index\IndexSchema;

it('produces an empty index that validates against the schema', function () {
    $builder = new IndexBuilder;
    $index = $builder->build(sys_get_temp_dir(), '12.x');

    $payload = $index->toArray();

    expect($builder->validate($payload))->toBe([]);
    expect($payload)
        ->toHaveKeys(['loom_version', 'scanned_at', 'laravel_version', 'stats', 'events', 'listeners', 'observers', 'model_events', 'jobs', 'unresolved_dispatches', 'closure_listeners', 'scheduled_tasks', 'mailables', 'notifications', 'routes'])
        ->and($payload['loom_version'])->toBe('0.4.0')
        ->and($payload['closure_listeners'])->toBe([])
        ->and($payload['jobs'])->toBe([])
        ->and($payload['scheduled_tasks'])->toBe([])
        ->and($payload['mailables'])->toBe([])
        ->and($payload['notifications'])->toBe([])
        ->and($payload['routes'])->toBe([])
        ->and($payload['stats'])->toBe([
            'events' => 0,
            'model_events' => 0,
            'listeners' => 0,
            'observers' => 0,
            'jobs' => 0,
            'unresolved_dispatches' => 0,
            'closure_listeners' => 0,
            'scheduled_tasks' => 0,
            'routes' => 0,
            'unresolved_routes' => 0,
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

it('stamps the current schema_version and rejects absolute file paths', function () {
    $builder = new IndexBuilder;
    $payload = $builder->build(sys_get_temp_dir(), '12.x')->toArray();

    expect($payload['schema_version'])->toBe(IndexSchema::VERSION);

    $payload['events'][] = [
        'id' => 'A', 'fqcn' => 'A', 'kind' => 'class', 'file' => '/abs/A.php', 'line' => 1,
        'dispatched_from' => [], 'handled_by' => [],
    ];
    expect($builder->validate($payload))->not->toBe([]);

    $payload['events'][0]['file'] = 'app/A.php';
    expect($builder->validate($payload))->toBe([]);
});

it('reports the latest released changelog version as loom_version', function () {
    $changelog = (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

    // First "## [x.y.z]" heading; "## [Unreleased]" does not match.
    preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $matches);

    expect($matches[1] ?? null)->toBeString('CHANGELOG.md has no released "## [x.y.z]" heading.')
        ->and(IndexBuilder::LOOM_VERSION)->toBe(
            $matches[1] ?? null,
            'IndexBuilder::LOOM_VERSION does not match the latest CHANGELOG.md release; bump the constant when releasing.',
        );
});
