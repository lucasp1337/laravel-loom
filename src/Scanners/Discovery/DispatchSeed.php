<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

/**
 * A class FQCN reached from a dispatch site. `ambiguous` is true when the
 * site's form cannot tell this primitive apart from another (`X::dispatch()`
 * is an event or a job), so the located class must prove it belongs.
 *
 * @internal
 */
final readonly class DispatchSeed
{
    public function __construct(
        public string $fqcn,
        public bool $ambiguous,
    ) {}
}
