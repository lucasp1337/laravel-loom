<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A listener-and-method that handles an event, emitted on
 * `events[*].handled_by`.
 */
final readonly class Handler
{
    public function __construct(
        public string $listener,
        public string $method,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            listener: Hydrate::string($data, Field::LISTENER),
            method: Hydrate::string($data, Field::METHOD),
        );
    }
}
