<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;

/**
 * Every fixture app, scanned with the default scanners: no `dispatches[]` entry
 * (on any owner) may name a target that lacks a row in its kind's section, and a
 * site that cannot be located is listed once, never as both class_not_found and
 * target_not_indexed.
 */
it('never leaves a dispatches[] target without a section row, on any fixture', function (string $fixture) {
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, new ScanScope);
    $payload = $builder->build($fixture, '12.x')->toArray();

    $rows = [];
    foreach (['events' => 'event', 'jobs' => 'job', 'mailables' => 'mailable', 'notifications' => 'notification'] as $section => $kind) {
        $rows[$kind] = array_flip(array_column($payload[$section], 'fqcn'));
    }

    $collect = function (mixed $node) use (&$collect, &$found): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'dispatches' && is_array($value)) {
                foreach ($value as $dispatch) {
                    $found[] = $dispatch;
                }
            }
            $collect($value);
        }
    };
    $found = [];
    $collect($payload);

    foreach ($found as $dispatch) {
        $kind = $dispatch['kind'] ?? null;
        if (isset($rows[$kind])) {
            $this->assertArrayHasKey($dispatch['target'], $rows[$kind], basename($fixture).": {$kind} target {$dispatch['target']} has no row");
        }
    }

    $this->addToAssertionCount(1);

    $seen = [];
    foreach ($payload['unresolved_dispatches'] as $entry) {
        $target = preg_replace('/::class$/', '', $entry['expression']);
        $key = $entry['file'].':'.$entry['line'].':'.$target;
        if (in_array($entry['reason'], ['class_not_found', 'target_not_indexed'], true) && str_ends_with($entry['expression'], '::class')) {
            $this->assertArrayNotHasKey($key, $seen, basename($fixture).": {$key} reported twice");
            $seen[$key] = true;
        }
    }
})->with(fn (): array => collect(glob(dirname(__DIR__).'/Fixtures/*-app', GLOB_ONLYDIR) ?: [])
    ->mapWithKeys(fn (string $dir): array => [basename($dir) => [$dir]])
    ->all());
