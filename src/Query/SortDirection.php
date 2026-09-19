<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

/** @internal */
enum SortDirection: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
