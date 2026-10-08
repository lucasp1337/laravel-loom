<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/** Keys under `loom.scan`. */
enum ScanConfigKey: string
{
    case PATHS = 'paths';
    case PSR4_PATHS = 'psr4_paths';
    case EXCLUDE = 'exclude';
}
