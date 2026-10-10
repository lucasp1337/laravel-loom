<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../Modules/Admin/routes/web.php',
        api: [__DIR__.'/../Modules/Admin/routes/api.php', __DIR__.'/../Modules/Admin/routes/missing.php'],
        commands: base_path('Modules/Admin/routes/console.php'),
        health: '/up',
    )
    ->create();
