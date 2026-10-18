<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Why a route form could not be read in full; the values of
 * `unresolved_routes[].reason`.
 *
 * @internal
 */
enum UnresolvedRouteReason: string implements Summarized
{
    case UNKNOWN_ROUTE_CALL = 'unknown_route_call';
    case DYNAMIC_URI = 'dynamic_uri';
    case DYNAMIC_RESOURCE = 'dynamic_resource';
    case DYNAMIC_VERBS = 'dynamic_verbs';
    case DYNAMIC_RESOURCE_OPTIONS = 'dynamic_resource_options';
    case DYNAMIC_MIDDLEWARE = 'dynamic_middleware';
    case DYNAMIC_GROUP_ATTRIBUTE = 'dynamic_group_attribute';
    case ROUTE_FILE_DYNAMIC = 'route_file_dynamic';
    case ROUTE_FILE_RELATIVE = 'route_file_relative';
    case ROUTE_FILE_OUTSIDE_ROOT = 'route_file_outside_root';
    case ROUTE_FILE_NOT_FOUND = 'route_file_not_found';
    case ATTRIBUTE_ROUTE = 'attribute_route';

    public function summary(): string
    {
        return match ($this) {
            self::UNKNOWN_ROUTE_CALL => '`Route::<name>()` is a macro or package helper; map it in `scan.route_macros`.',
            self::DYNAMIC_URI => 'the URI is not a string literal; no route is emitted.',
            self::DYNAMIC_RESOURCE => 'the resource name or list of resources is not literal; no route is emitted.',
            self::DYNAMIC_VERBS => 'the verbs of `Route::match()` are not a literal list; no route is emitted.',
            self::DYNAMIC_RESOURCE_OPTIONS => 'a resource option such as `only` or `names` is not literal; the defaults were used.',
            self::DYNAMIC_MIDDLEWARE => 'a middleware or `withoutMiddleware` entry is not literal; the middleware list may be incomplete.',
            self::DYNAMIC_GROUP_ATTRIBUTE => 'a group or chain prefix, name, controller or middleware is not literal; routes inside may miss it.',
            self::ROUTE_FILE_DYNAMIC => 'a route file path is not literal, so the file is not read.',
            self::ROUTE_FILE_RELATIVE => 'a route file path is relative to the working directory, so the file is not read.',
            self::ROUTE_FILE_OUTSIDE_ROOT => 'a route file path lies outside the app root, so the file is not read.',
            self::ROUTE_FILE_NOT_FOUND => 'a route file path does not exist.',
            self::ATTRIBUTE_ROUTE => 'a controller declares a route with an attribute, which Loom does not read.',
        };
    }
}
