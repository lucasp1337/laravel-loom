<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A route form the scanner could not read in full, with the source it saw and
 * the reason. Read model for the `unresolved_routes` section.
 *
 * @api
 */
final readonly class UnresolvedRoute
{
    public function __construct(
        public string $file,
        public int $line,
        public string $expression,
        public string $reason,
    ) {}

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
