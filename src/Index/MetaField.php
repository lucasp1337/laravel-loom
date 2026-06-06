<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Top-level envelope/meta keys emitted alongside the index sections.
 */
enum MetaField: string
{
    case LOOM_VERSION = 'loom_version';
    case SCANNED_AT = 'scanned_at';
    case LARAVEL_VERSION = 'laravel_version';
    case STATS = 'stats';
}
