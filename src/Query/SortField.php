<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

enum SortField: string
{
    case NAME = 'name';
    case FILE = 'file';
    case HANDLER_COUNT = 'handlers';
    case DISPATCH_COUNT = 'dispatches';
}
