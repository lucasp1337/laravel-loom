<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

use Lucasp\Loom\Check\Result\CheckResult;

interface CheckFormatter
{
    public function format(CheckResult $result): string;
}
