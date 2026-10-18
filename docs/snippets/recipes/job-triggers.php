<?php

use Lucasp\Loom\Index\IndexLoader;

$index = (new IndexLoader)->fromFile('storage/loom/index.json');

// Walks back from a dispatching method to the events and routes that reach it.
$callers = function (string $method, string $indent = '', array $path = []) use (&$callers, $index): void {
    foreach ($index->routes() as $route) {
        if ("{$route->controllerFqcn}::{$route->controllerMethod}" === $method) {
            echo "{$indent}route {$route->method} {$route->uri}\n";
        }
    }

    $listener = $index->findListener(strstr($method, '::', true) ?: $method);
    foreach ($listener?->handles ?? [] as $handle) {
        echo "{$indent}handles {$handle->event}\n";
        foreach (in_array($handle->event, $path, true) ? [] : $index->dispatchersOf($handle->event) as $site) {
            echo "{$indent}  dispatched from {$site->method}\n";
            $callers($site->method, "{$indent}    ", [...$path, $handle->event]);
        }
    }
};

foreach ($index->findJob('App\Jobs\SendMail')?->dispatchedFrom ?? [] as $site) {
    echo "dispatched from {$site->method}\n";
    $callers($site->method, '  ');
}
