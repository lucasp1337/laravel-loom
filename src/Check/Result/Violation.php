<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check\Result;

/**
 * A single policy breach found by a rule. `context` carries structured detail
 * (fqcn, file, line, cycle trail) for the JSON formatter; `message` is the
 * human-readable rendering used by the text and markdown formatters.
 */
final class Violation
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }
}
