<?php

use Lucasp\Loom\Index\IndexLoader;

$index = (new IndexLoader)->fromFile('storage/loom/index.json');

$follow = function (string $event, string $indent = '', array $path = []) use (&$follow, $index): void {
    if (in_array($event, $path, true)) {
        echo "{$indent}(cycle)\n";

        return;
    }

    // Listener classes, then closures registered for the event.
    $handlers = collect($index->handlersOf($event))
        ->mapWithKeys(fn ($handler) => [
            "{$handler->listener}::{$handler->method}" => $index->findListener($handler->listener)?->dispatches ?? [],
        ])
        ->merge(collect($index->closureListeners())->where('event', $event)->mapWithKeys(fn ($closure) => [
            "closure at {$closure->file}:{$closure->line}" => $closure->dispatches,
        ]));

    foreach ($handlers as $handler => $dispatches) {
        echo "{$indent}{$handler}\n";
        foreach ($dispatches as $dispatch) {
            echo "{$indent}  → {$dispatch->kind->value} {$dispatch->target}\n";
            $follow($dispatch->target, "{$indent}    ", [...$path, $event]);
        }
    }
};

$follow('App\Events\OrderPlaced');
