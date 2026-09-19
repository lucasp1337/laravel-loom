<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

enum ChangeKind: string
{
    case REMOVE = 'remove';
    case RENAME = 'rename';
}
