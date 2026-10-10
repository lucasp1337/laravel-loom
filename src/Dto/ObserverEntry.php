<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\ObserverRegistration;

/** @internal */
final readonly class ObserverEntry
{
    /**
     * @param  list<string>  $hooks
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public int $line,
        public string $observes,
        public ObserverRegistration $registration,
        public array $hooks,
    ) {}
}
