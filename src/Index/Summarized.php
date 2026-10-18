<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * An index enum whose cases each carry a one-line meaning; the schema
 * reference lists every value with it.
 *
 * @internal
 */
interface Summarized
{
    /** One sentence on what the value means, lower-case first word. */
    public function summary(): string;
}
