<?php

declare(strict_types=1);

namespace Lucasp\Loom\Check;

/**
 * Source of truth for the stable identifiers of each check rule. The values
 * are what `loom:check --skip=` accepts and what the JSON report emits.
 */
enum RuleKey: string
{
    case SCHEMA = 'schema';
    case ORPHAN_LISTENERS = 'orphan-listeners';
    case ORPHAN_EVENTS = 'orphan-events';
    case UNRESOLVED_DISPATCHES = 'unresolved-dispatches';
    case CYCLIC_DISPATCH = 'cyclic-dispatch';
}
