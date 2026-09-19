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
use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Ui\LoomUiServiceProvider;

class LoomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/loom.php', 'loom');
        $this->app->register(LoomUiServiceProvider::class);

        $this->app->singleton(IndexRepository::class, fn ($app): IndexRepository => new IndexRepository(
            $app->make(IndexLoader::class),
            $app->make(Artisan::class),
            $app->storagePath('loom/index.json'),
        ));

        // Resolved fresh per tool call; it re-reads the repository's index on
        // every question, so a rescanned snapshot is picked up.
        $this->app->bind(IndexQuery::class, fn ($app): IndexQuery => new IndexQuery(
            $app->make(IndexRepository::class),
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
