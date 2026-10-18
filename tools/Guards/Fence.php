<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Guards;

/** A fenced code block on a page, outside any generated region. */
final readonly class Fence
{
    /** Placed above a fence (blank lines aside) whose code no test runs. */
    public const ILLUSTRATIVE = '<!-- illustrative -->';

    public function __construct(
        public string $language,
        public string $body,
        public int $line,
        public bool $illustrative,
    ) {}
}
