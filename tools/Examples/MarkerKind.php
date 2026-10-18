<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Examples;

/** The two outcomes a marker can state. */
enum MarkerKind: string
{
    /** `→ section[key].path value`: the index records this. */
    case Recorded = '→';

    /** `✗ reason` or `✗ not recorded`: the line is listed as unresolved, or leaves no trace. */
    case Unread = '✗';
}
