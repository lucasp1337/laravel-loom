<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * One resource of a registration: its name as written and its controller.
 *
 * @internal
 */
final readonly class ResourceTarget
{
    public function __construct(
        public string $name,
        public ?string $controller,
    ) {}
}
