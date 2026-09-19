<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/** @internal */
enum SortField: string
{
    case NAME = 'name';
    case URI = 'uri';
    case FILE = 'file';
    case HANDLER_COUNT = 'handlers';
    case DISPATCH_COUNT = 'dispatches';
}
