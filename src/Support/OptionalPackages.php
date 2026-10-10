<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Answers whether an optional package is installed. Bound in the container so
 * tests can simulate a missing one.
 *
 * @internal
 */
final class OptionalPackages
{
    /** @param  list<OptionalPackage>  $unavailable  Treated as missing even if installed. */
    public function __construct(private readonly array $unavailable = []) {}

    public function has(OptionalPackage $package): bool
    {
        return ! in_array($package, $this->unavailable, true) && class_exists($package->probeClass());
    }
}
