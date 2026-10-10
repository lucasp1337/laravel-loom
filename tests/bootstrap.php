<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Rector ships its own php-parser copy and must load it before the project's, or the
// custom-rule tests in tests/Rector collide with classes other tests already loaded.
// Rector only does this itself on PHPUnit 12+.
$rectorPreload = __DIR__.'/../vendor/rector/rector/preload.php';
if (is_file($rectorPreload)) {
    require_once $rectorPreload;
}
