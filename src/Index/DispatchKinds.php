<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Dispatch-kind tags on `_dispatch_sites[].provisionalKind` and the
 * cross-linked `kind` field. `AMBIGUOUS` is the pre-disambiguation
 * marker for `X::dispatch()` (Dispatchable trait); the cross-link
 * pass resolves it to `EVENT` or `JOB` before phase 5 runs.
 */
enum DispatchKinds: string
{
    case EVENT = 'event';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';
    case AMBIGUOUS = 'ambiguous';
}
