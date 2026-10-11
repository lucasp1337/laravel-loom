<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Fluent dispatch methods that select the after-response execution mode. The
 * case value is the method name as written in the chain.
 *
 * @internal
 */
enum DispatchModeModifier: string
{
    /** `->afterResponse()` / `->afterResponse(false)` on a PendingDispatch */
    case AFTER_RESPONSE = 'afterResponse';

    /** `Bus::batch(...)->dispatchAfterResponse()` on a PendingBatch */
    case DISPATCH_AFTER_RESPONSE = 'dispatchAfterResponse';
}
