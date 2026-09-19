<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tests;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
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
            LivewireServiceProvider::class,
            LoomServiceProvider::class,
        ];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        // The UI runs the `web` middleware group, which needs an app key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
