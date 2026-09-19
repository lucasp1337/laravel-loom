<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Support;

/**
 * When app/ last changed, for the stale-index banner.
 */
interface AppChangeClock
{
    /** Unix timestamp of the last change to app/, or null when unknown. */
    public function lastChange(): ?int;
}
