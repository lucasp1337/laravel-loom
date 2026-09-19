<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A handler (observer hook or `Event::listen` target) attached to a model
 * event, emitted on `model_events[*].handled_by`.
 */
final readonly class ModelEventHandler
{
    public function __construct(
        public string $handler,
        public string $method,
        public string $file,
        public int $line,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            handler: Hydrate::string($data, Field::HANDLER),
            method: Hydrate::string($data, Field::METHOD),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
        );
    }
}
