<?php

declare(strict_types=1);

namespace Lucasp\Loom;

use Illuminate\Support\ServiceProvider;
use Lucasp\Loom\Console\DiffCommand;
use Lucasp\Loom\Console\ScanCommand;
use Lucasp\Loom\Console\ShowCommand;

class LoomServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                ShowCommand::class,
                DiffCommand::class,
            ]);
        }
    }
}
