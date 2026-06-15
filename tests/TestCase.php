<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tests;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;
use Lucasp\Loom\LoomServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Real apps auto-discover laravel/mcp; Testbench does not, so register
        // it explicitly — its boot() wires the Request argument binding the
        // tools rely on.
        return [
            McpServiceProvider::class,
            LoomServiceProvider::class,
        ];
    }
}
