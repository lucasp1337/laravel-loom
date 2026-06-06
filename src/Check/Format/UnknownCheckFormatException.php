<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

use InvalidArgumentException;

final class UnknownCheckFormatException extends InvalidArgumentException
{
    public static function for(string $format): self
    {
        return new self("Unknown check format '{$format}'. Supported formats: text, json, markdown.");
    }
}
