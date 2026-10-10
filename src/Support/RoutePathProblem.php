<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

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
