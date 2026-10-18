<?php

use Lucasp\Loom\Index\IndexLoader;

$index = (new IndexLoader)->fromFile('storage/loom/index.json');

foreach ($index->routes() as $route) {
    $guarded = collect($route->middleware)->contains(fn (string $name): bool => str_starts_with($name, 'auth'));

    if (! $guarded) {
        echo "{$route->method} {$route->uri}  {$route->file}:{$route->line}\n";
    }
}
