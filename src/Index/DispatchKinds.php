<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Canonical dispatch-kind tags. Single source of truth for the values
 * that appear on `_dispatch_sites[].provisionalKind` (visitor-emitted)
 * and on public `kind` fields in cross-linked dispatch entries.
 *
 * `AMBIGUOUS` is the pre-disambiguation marker for `X::dispatch()` calls
 * where the trait could be either `Dispatchable` (event/job) or
 * `Mailable`. The cross-link pass finalises every ambiguous site to
 * either `EVENT` or `JOB` before downstream consumers run; the public
 * schema never carries `ambiguous`.
 */
enum DispatchKinds: string
{
    case EVENT = 'event';
    case JOB = 'job';
    case MAILABLE = 'mailable';
    case NOTIFICATION = 'notification';
    case AMBIGUOUS = 'ambiguous';
}
