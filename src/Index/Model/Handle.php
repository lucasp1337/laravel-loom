<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * An event-to-method binding declared by a class listener, emitted on
 * `listeners[*].handles`.
 */
final readonly class Handle
{
    public function __construct(
        public string $event,
        public string $method,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            event: Hydrate::string($data, Field::EVENT),
            method: Hydrate::string($data, Field::METHOD),
        );
    }
}
