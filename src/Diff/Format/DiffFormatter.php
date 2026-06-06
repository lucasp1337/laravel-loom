<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

use Lucasp\Loom\Diff\Result\DiffResult;

interface DiffFormatter
{
    public function format(DiffResult $result): string;
}
