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
enum DispatchMode: string implements Summarized
{
    case SYNC = 'sync';
    case AFTER_RESPONSE = 'after_response';
    case PUSH = 'push';

    public function summary(): string
    {
        return match ($this) {
            self::SYNC => 'runs inline in the current process.',
            self::AFTER_RESPONSE => 'runs after the HTTP response is sent.',
            self::PUSH => 'pushed straight onto a queue.',
        };
    }
}
