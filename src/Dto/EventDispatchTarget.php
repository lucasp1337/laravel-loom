<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\DispatchForm;

/**
 * An (event-class fqcn, line, form) dispatch target seen by EventScanner.
 *
 * @internal
 */
final readonly class EventDispatchTarget
{
    public function __construct(
        public string $fqcn,
        public int $line,
        public DispatchForm $form,
    ) {}
}
