<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a route form could not be read in full; the values of
 * `unresolved_routes[].reason`.
 *
 * @internal
 */
enum UnresolvedRouteReason: string
{
    /** `Route::<name>()` is not a router method Loom reads: a macro or package helper. */
    case UNKNOWN_ROUTE_CALL = 'unknown_route_call';

    /** The route URI is not a string literal; no route is emitted. */
    case DYNAMIC_URI = 'dynamic_uri';

    /** The resource name or the array of resources is not literal; no route is emitted. */
    case DYNAMIC_RESOURCE = 'dynamic_resource';

    /** The verbs of `Route::match()` are not a literal list; no route is emitted. */
    case DYNAMIC_VERBS = 'dynamic_verbs';

    /** A resource option or `only` / `except` / `names` / `parameters` value is not literal; defaults were used. */
    case DYNAMIC_RESOURCE_OPTIONS = 'dynamic_resource_options';

    /** A middleware or `withoutMiddleware` entry is not a literal; the route's list may be incomplete. */
    case DYNAMIC_MIDDLEWARE = 'dynamic_middleware';

    /** A group prefix, name, controller or middleware is not a literal; routes inside may miss it. */
    case DYNAMIC_GROUP_ATTRIBUTE = 'dynamic_group_attribute';

    /** A route file's path is not statically resolvable, so the file is not read. */
    case ROUTE_FILE_DYNAMIC = 'route_file_dynamic';

    /** A route file's path is relative to the working directory. */
    case ROUTE_FILE_RELATIVE = 'route_file_relative';

    /** A route file's path lies outside the project root. */
    case ROUTE_FILE_OUTSIDE_ROOT = 'route_file_outside_root';

    /** A route file's path does not exist. */
    case ROUTE_FILE_NOT_FOUND = 'route_file_not_found';

    /** A controller declares a route with an attribute; Loom does not read route attributes. */
    case ATTRIBUTE_ROUTE = 'attribute_route';
}
