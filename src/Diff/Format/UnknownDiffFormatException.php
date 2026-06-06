<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

use InvalidArgumentException;

final class UnknownDiffFormatException extends InvalidArgumentException
{
    public static function for(string $format): self
    {
        return new self("Unknown diff format '{$format}'. Supported formats: text, json, markdown.");
    }
}
