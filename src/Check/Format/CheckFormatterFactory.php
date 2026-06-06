<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Format;

final class CheckFormatterFactory
{
    /**
     * @throws UnknownCheckFormatException
     */
    public function make(string $format): CheckFormatter
    {
        return match ($format) {
            'text' => new TextCheckFormatter,
            'json' => new JsonCheckFormatter,
            'markdown' => new MarkdownCheckFormatter,
            default => throw UnknownCheckFormatException::for($format),
        };
    }
}
