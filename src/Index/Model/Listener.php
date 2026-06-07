<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\ListenerRegistration;

/**
 * A class listener: the events/methods it handles, how it was registered, and
 * what it dispatches. Read model for the `listeners` section.
 */
final readonly class Listener
{
    /**
     * @param  list<Handle>  $handles
     * @param  list<Dispatch>  $dispatches
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public array $handles,
        public ListenerRegistration $registration,
        public bool $queued,
        public array $dispatches,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            fqcn: Hydrate::string($data, Field::FQCN),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            handles: Hydrate::list($data, Field::HANDLES, Handle::fromArray(...)),
            registration: ListenerRegistration::from(Hydrate::string($data, Field::REGISTRATION)),
            queued: Hydrate::bool($data, Field::QUEUED),
            dispatches: Hydrate::list($data, Field::DISPATCHES, Dispatch::fromArray(...)),
        );
    }
}
