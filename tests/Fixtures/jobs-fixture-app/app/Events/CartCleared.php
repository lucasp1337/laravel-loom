<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An event class that uses the Dispatchable trait — so `CartCleared::dispatch()`
 * is syntactically valid and produces a DispatchSiteVisitor entry with
 * `provisionalKind: ambiguous`. JobsScanner must NOT seed this into jobs[].
 */
class CartCleared
{
    use Dispatchable;
}
