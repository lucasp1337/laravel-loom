<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\EventDiscoveryFactKind;

/**
 * One discovery-relevant source shape. `paths` holds directory expressions in
 * marked form (`{dir}` is the declaring file's directory), or null when an
 * argument could not be read; `dynamic` marks a switch argument that is not a
 * literal.
 *
 * @internal
 */
final readonly class EventDiscoveryFact
{
    /**
     * @param  list<string>|null  $paths
     */
    public function __construct(
        public EventDiscoveryFactKind $kind,
        public ?bool $value = null,
        public ?array $paths = [],
        public bool $dynamic = false,
    ) {}
}
