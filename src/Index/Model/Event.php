<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;

/**
 * A discovered event class, with the sites that dispatch it and the listeners
 * that handle it. Read model for the `events` section.
 */
final readonly class Event
{
    /**
     * @param  list<DispatchSite>  $dispatchedFrom
     * @param  list<Handler>  $handledBy
     */
    public function __construct(
        public string $id,
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
        public array $dispatchedFrom,
        public array $handledBy,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::string($data, Field::ID),
            fqcn: Hydrate::string($data, Field::FQCN),
            kind: Hydrate::string($data, Field::KIND),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            dispatchedFrom: Hydrate::list($data, Field::DISPATCHED_FROM, DispatchSite::fromArray(...)),
            handledBy: Hydrate::list($data, Field::HANDLED_BY, Handler::fromArray(...)),
        );
    }
}
