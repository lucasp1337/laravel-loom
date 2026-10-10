<?php

declare(strict_types=1);

use Lucasp\Loom\Rector\NativeFunctionToLaravelHelperRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([NativeFunctionToLaravelHelperRector::class]);
