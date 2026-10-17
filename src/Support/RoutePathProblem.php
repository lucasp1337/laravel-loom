<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Lucasp\Loom\Index\UnresolvedRouteReason;

/**
 * Why a route-file path was not followed.
 *
 * @internal
 */
enum RoutePathProblem: string
{
    case DYNAMIC = 'dynamic';
    case RELATIVE = 'relative';
    case OUTSIDE_ROOT = 'outside_root';
    case NOT_FOUND = 'not_found';

    /** The `unresolved_routes[].reason` this problem is reported as. */
    public function reason(): UnresolvedRouteReason
    {
        return match ($this) {
            self::DYNAMIC => UnresolvedRouteReason::ROUTE_FILE_DYNAMIC,
            self::RELATIVE => UnresolvedRouteReason::ROUTE_FILE_RELATIVE,
            self::OUTSIDE_ROOT => UnresolvedRouteReason::ROUTE_FILE_OUTSIDE_ROOT,
            self::NOT_FOUND => UnresolvedRouteReason::ROUTE_FILE_NOT_FOUND,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::DYNAMIC => 'path is not statically resolvable',
            self::RELATIVE => 'relative path depends on the working directory',
            self::OUTSIDE_ROOT => 'path is outside the project root',
            self::NOT_FOUND => 'no such file',
        };
    }
}
