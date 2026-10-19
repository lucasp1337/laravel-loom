<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/**
 * Machine-readable framing of an {@see Dto\ImpactReport}. Each transport
 * renders these to prose itself.
 *
 * @internal
 */
enum ImpactNote: string
{
    case REMOVE_ORPHANS_HANDLERS = 'remove_orphans_handlers';
    case RENAME_TOUCHES_ALL = 'rename_touches_all';
    case REMOVE_BREAKS_SENDS = 'remove_breaks_sends';
    case RENAME_TOUCHES_SENDS = 'rename_touches_sends';
    case DYNAMIC_DISPATCH_BLIND_SPOT = 'dynamic_dispatch_blind_spot';
    case WOULD_ORPHAN_EVENTS = 'would_orphan_events';
    case NO_ORPHANS = 'no_orphans';
    case DOWNSTREAM_NOT_EXPANDED = 'downstream_not_expanded';
    case UNKNOWN_FQCN = 'unknown_fqcn';
}
