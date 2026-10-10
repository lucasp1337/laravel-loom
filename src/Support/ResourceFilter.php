<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The chain methods that narrow the actions of `Route::resource()`.
 *
 * @internal
 */
enum ResourceFilter: string
{
    case ONLY = 'only';
    case EXCEPT = 'except';
}
