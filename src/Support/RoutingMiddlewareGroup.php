<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The route files `ApplicationBuilder::withRouting()` wraps in a framework
 * middleware group, keyed by the parameter that names them.
 *
 * @internal
 */
enum RoutingMiddlewareGroup: string
{
    case WEB = 'web';
    case API = 'api';

    /** The prefix `withRouting(apiPrefix:)` defaults to, applied to `api` files only. */
    public const DEFAULT_API_PREFIX = 'api';
}
