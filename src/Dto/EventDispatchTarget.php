<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Index\DispatchForm;

/** An (event-class fqcn, line, form) dispatch target seen by EventScanner. */
final class EventDispatchTarget
{
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly DispatchForm $form,
    ) {
    }
}
