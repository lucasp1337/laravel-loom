<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * The kind of a dispatch target, on `dispatches[*].kind`.
 *
 * Also tags `_dispatch_sites[].provisionalKind`: `AMBIGUOUS` marks
 * `X::dispatch()` (Dispatchable trait) until the cross-link pass resolves it
 * to `EVENT` or `JOB`, before any phase reads the kind.
 *
 * @api
 */
enum DispatchKinds: string
{
    case EVENT = 'event';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';
    case AMBIGUOUS = 'ambiguous';
}
