<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\ObserverRegistration;

/**
 * A model observer: the model it observes, which lifecycle hooks it defines,
 * and what it dispatches. Read model for the `observers` section.
 */
final readonly class Observer
{
    /**
     * @param  list<string>  $hooks
     * @param  list<Dispatch>  $dispatches
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public string $observes,
        public ObserverRegistration $registration,
        public array $hooks,
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
            observes: Hydrate::string($data, Field::OBSERVES),
            registration: ObserverRegistration::from(Hydrate::string($data, Field::REGISTRATION)),
            hooks: Hydrate::stringList($data, Field::HOOKS),
            dispatches: Hydrate::list($data, Field::DISPATCHES, Dispatch::fromArray(...)),
        );
    }
}
