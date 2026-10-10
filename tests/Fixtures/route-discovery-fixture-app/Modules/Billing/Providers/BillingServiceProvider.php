<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom($this->routesPath());
    }

    private function routesPath(): string
    {
        return __DIR__.'/../routes/'.config('billing.routes');
    }
}
