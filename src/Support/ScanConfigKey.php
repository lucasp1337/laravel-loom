<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Keys under `loom.scan`.
 *
 * @internal
 */
enum ScanConfigKey: string
{
    case PATHS = 'paths';
    case PSR4_PATHS = 'psr4_paths';
    case COMPOSER_FILES = 'composer_files';
    case ROUTE_PATHS = 'route_paths';
    case EXCLUDE = 'exclude';
    case DISCOVER_ROUTES = 'discover_routes';
    case ROUTE_MACROS = 'route_macros';
    case AST_CACHE_MB = 'ast_cache_mb';
}
