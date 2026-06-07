<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index\Model;

use Lucasp\Loom\Index\Confidence;
use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;

/**
 * A resolved dispatch edge: a source (listener, observer, job, route, …)
 * dispatches `target` of a given `kind` from a source location.
 */
final readonly class Dispatch
{
    public function __construct(
        public string $target,
        public DispatchKinds $kind,
        public Confidence $confidence,
        public string $file,
        public int $line,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            target: Hydrate::string($data, Field::TARGET),
            kind: DispatchKinds::from(Hydrate::string($data, Field::KIND)),
            confidence: Confidence::from(Hydrate::string($data, Field::CONFIDENCE)),
            file: Hydrate::string($data, Field::FILE),
            line: Hydrate::int($data, Field::LINE),
        );
    }
}
