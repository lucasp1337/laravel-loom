<?php

declare(strict_types=1);

namespace Lucasp\Atlas;

use Illuminate\Support\ServiceProvider;
use Lucasp\Atlas\Console\ScanCommand;
use Lucasp\Atlas\Console\ShowCommand;

class AtlasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                ShowCommand::class,
            ]);
        }
    }
}
