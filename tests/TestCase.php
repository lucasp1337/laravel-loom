<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tests;

use Illuminate\Foundation\Application;
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
        return [
            LoomServiceProvider::class,
        ];
    }
}
