<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** An (event-class fqcn, line, form) dispatch target seen by EventScanner. */
final class EventDispatchTarget
{
    /**
     * @param  'helper'|'facade'|'dispatchable'  $form
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly int $line,
        public readonly string $form,
    ) {
    }
}
