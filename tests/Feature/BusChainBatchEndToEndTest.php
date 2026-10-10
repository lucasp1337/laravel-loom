<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;

it('links jobs in Bus::chain and Bus::batch back to the dispatcher', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);

    $payload = $builder->build(dirname(__DIR__).'/Fixtures/bus-fixture-app', '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);

    $byFqcn = [];
    foreach ($payload['jobs'] as $job) {
        $byFqcn[$job['fqcn']] = $job;
    }

    expect($byFqcn['App\\Jobs\\ProcessOrder']['dispatched_from'])->toHaveCount(1);
    expect($byFqcn['App\\Jobs\\Cleanup']['dispatched_from'])->toHaveCount(1);
    expect($byFqcn['App\\Jobs\\SendReceipt']['dispatched_from'])->toHaveCount(2);
    expect($byFqcn['App\\Jobs\\SendReceipt']['dispatched_from'][0]['class'] ?? $byFqcn['App\\Jobs\\SendReceipt']['dispatched_from'][0])->not->toBeNull();
    expect($payload['unresolved_dispatches'])->toHaveCount(1);
});
