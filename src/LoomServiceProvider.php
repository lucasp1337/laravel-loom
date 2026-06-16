<?php

declare(strict_types=1);

namespace Lucasp\Loom;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Lucasp\Loom\Console\CheckCommand;
use Lucasp\Loom\Console\DiffCommand;
use Lucasp\Loom\Console\McpCommand;
use Lucasp\Loom\Console\ScanCommand;
use Lucasp\Loom\Console\ShowCommand;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Mcp\EventGraph;
use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Mcp\LoomMcpServer;

class LoomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IndexRepository::class, fn ($app): IndexRepository => new IndexRepository(
            $app->make(IndexLoader::class),
            $app->make(Artisan::class),
            $app->storagePath('loom/index.json'),
        ));

        // The graph is a thin read-only view over whatever index the repository
        // currently holds, so it is resolved fresh per tool call.
        $this->app->bind(EventGraph::class, fn ($app): EventGraph => new EventGraph(
            $app->make(IndexRepository::class)->index(),
        ));
    }

    public function boot(): void
    {
        Mcp::local('loom', LoomMcpServer::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                ShowCommand::class,
                DiffCommand::class,
                CheckCommand::class,
                McpCommand::class,
            ]);
        }
    }
}
