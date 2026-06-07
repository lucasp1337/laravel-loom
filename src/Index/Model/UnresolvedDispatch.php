<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A dispatch site the scanner could not statically resolve to a target, with
 * the raw expression and the reason. Read model for the
 * `unresolved_dispatches` section.
 */
final readonly class UnresolvedDispatch
{
    public function __construct(
        public string $file,
        public int $line,
        public string $expression,
        public string $reason,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            expression: Hydrate::string($data, Field::EXPRESSION),
            reason: Hydrate::string($data, Field::REASON),
        );
    }
}
