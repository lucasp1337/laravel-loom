<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Tests;

use Illuminate\Foundation\Application;
use Lucasp\Atlas\AtlasServiceProvider;
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
            AtlasServiceProvider::class,
        ];
    }
}
