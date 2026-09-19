<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

enum ChainNodeKind: string
{
    case EVENT = 'event';
    case HANDLER = 'handler';
    case DISPATCH_TARGET = 'dispatch_target';
}
