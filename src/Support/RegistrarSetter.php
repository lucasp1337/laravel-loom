<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The attribute setters `RouteRegistrar` accepts before a route call:
 * `Route::middleware('auth')->prefix('v1')->get(...)`.
 *
 * @internal
 */
enum RegistrarSetter: string
{
    case AS = 'as';
    case CAN = 'can';
    case CONTROLLER = 'controller';
    case DOMAIN = 'domain';
    case MIDDLEWARE = 'middleware';
    case MISSING = 'missing';
    case NAME = 'name';
    case NAMESPACE = 'namespace';
    case PREFIX = 'prefix';
    case SCOPE_BINDINGS = 'scopeBindings';
    case WHERE = 'where';
    case WHERE_ALPHA = 'whereAlpha';
    case WHERE_ALPHA_NUMERIC = 'whereAlphaNumeric';
    case WHERE_IN = 'whereIn';
    case WHERE_NUMBER = 'whereNumber';
    case WHERE_ULID = 'whereUlid';
    case WHERE_UUID = 'whereUuid';
    case WITHOUT_MIDDLEWARE = 'withoutMiddleware';
    case WITHOUT_SCOPED_BINDINGS = 'withoutScopedBindings';
}
