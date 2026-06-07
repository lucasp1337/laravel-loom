<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * An Eloquent model lifecycle event (e.g. `created`, `saving`) and the
 * observers/listeners that handle it. Read model for the `model_events`
 * section.
 */
final readonly class ModelEvent
{
    /** @param  list<string>  $handledBy */
    public function __construct(
        public string $id,
        public string $model,
        public string $event,
        public array $handledBy,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::string($data, Field::ID),
            model: Hydrate::string($data, Field::MODEL),
            event: Hydrate::string($data, Field::EVENT),
            handledBy: Hydrate::stringList($data, Field::HANDLED_BY),
        );
    }
}
