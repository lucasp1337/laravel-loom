<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * How a dispatch site executes, recorded on the optional `mode` of
 * `$defs/dispatchSite`. Absent for the plain form (`dispatch()`, `Mail::send()`,
 * `Notification::send()`), whose queued-vs-inline outcome is decided by the
 * target's `ShouldQueue` marker.
 *
 * @api
 */
enum DispatchMode: string
{
    /** Runs inline in the current process (`dispatchSync`, `sendNow`, `notifyNow`). */
    case SYNC = 'sync';

    /** Runs after the HTTP response is sent (`dispatchAfterResponse`, `->afterResponse()`). */
    case AFTER_RESPONSE = 'after_response';

    /** Pushed straight onto a queue (`Queue::push`, `Mail::queue/later`). */
    case PUSH = 'push';
}
