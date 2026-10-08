<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tests;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Lucasp\Loom\LoomServiceProvider;
use Lucasp\Loom\Support\OptionalPackage;
use Lucasp\Loom\Support\OptionalPackages;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /** App environment the provider boots under; the UI mounts in `local` only by default. */
    protected string $loomEnvironment = 'local';

    /** @var list<OptionalPackage> Optional packages simulated as not installed. */
    protected array $loomMissing = [];

    /** @var array<string, mixed> */
    protected array $loomConfig = [];

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

    /**
     * Reboots the app with optional packages treated as not installed.
     *
     * @param  list<OptionalPackage>  $missing
     * @param  array<string, mixed>  $config
     */
    public function bootWithout(array $missing, array $config = []): void
    {
        $this->loomMissing = $missing;
        $this->loomConfig = $config;
        $this->refreshApplication();
    }

    /**
     * @param  Application  $app
     * @return array<class-string, callable>
     */
    protected function overrideApplicationBindings($app): array
    {
        return [OptionalPackages::class => fn (): OptionalPackages => new OptionalPackages($this->loomMissing)];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        // The UI runs the `web` middleware group, which needs an app key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['env'] = $this->loomEnvironment;

        foreach ($this->loomConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
