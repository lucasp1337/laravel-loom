<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Lucasp\Loom\Tools\Demo\DemoServer;

/**
 * Router for PHP's built-in server: the UI over the docs fixture app.
 * Usage: `php -S 0.0.0.0:8080 tools/demo-server.php`; `just demo-gif` runs it.
 */

require __DIR__.'/../vendor/autoload.php';

(new DemoServer(sys_get_temp_dir().'/loom-demo/index.json'))->handle(Request::capture());
