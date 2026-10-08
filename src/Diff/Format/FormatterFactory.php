<?php

declare(strict_types=1);

namespace Lucasp\Loom\Diff\Format;

/** @internal */
final class FormatterFactory
{
    /**
     * @throws UnknownDiffFormatException
     */
    public function make(string $format): DiffFormatter
    {
        return match ($format) {
            'text' => new TextDiffFormatter,
            'json' => new JsonDiffFormatter,
            'markdown' => new MarkdownDiffFormatter,
            default => throw UnknownDiffFormatException::for($format),
        };
    }
}
