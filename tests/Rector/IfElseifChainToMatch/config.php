<?php

declare(strict_types=1);

use Lucasp\Loom\Rector\IfElseifChainToMatchRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([IfElseifChainToMatchRector::class]);
