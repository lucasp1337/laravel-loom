<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\ListenerRegistration;

/**
 * An anonymous (closure) listener bound to an event, with its source span and
 * what it dispatches. Read model for the `closure_listeners` section.
 */
final readonly class ClosureListener
{
    /** @param  list<Dispatch>  $dispatches */
    public function __construct(
        public string $event,
        public string $file,
        public int $line,
        public int $endLine,
        public ListenerRegistration $registration,
        public bool $queued,
        public array $dispatches,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            event: Hydrate::string($data, Field::EVENT),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
            endLine: Hydrate::int($data, Field::END_LINE),
            registration: ListenerRegistration::from(Hydrate::string($data, Field::REGISTRATION)),
            queued: Hydrate::bool($data, Field::QUEUED),
            dispatches: Hydrate::list($data, Field::DISPATCHES, Dispatch::fromArray(...)),
        );
    }
}
