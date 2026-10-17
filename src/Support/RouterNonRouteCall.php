<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * Methods of the `Route` facade (router, macro and facade plumbing) that do
 * not declare routes. A `Route::<name>()` call outside this set, the route
 * methods and the registrar setters is a macro or package helper.
 *
 * @internal
 */
final class RouterNonRouteCall
{
    private const NAMES = [
        'aliasMiddleware', 'bind', 'current', 'currentRouteAction', 'currentRouteName', 'currentRouteNamed',
        'currentRouteUses', 'dispatch', 'dispatchToRoute', 'flushMiddlewareGroups', 'flushMacros',
        'gatherRouteMiddleware', 'getBindingCallback', 'getCurrentRequest', 'getCurrentRoute', 'getGroupStack',
        'getLastGroupPrefix', 'getMiddleware', 'getMiddlewareGroups', 'getPatterns', 'getRoutes', 'group', 'has',
        'hasGroupStack', 'hasMacro', 'hasMiddlewareGroup', 'input', 'is', 'macro', 'matched', 'mergeWithLastGroup',
        'middlewareGroup', 'mixin', 'model', 'newRoute', 'pattern', 'patterns', 'prepareResponse',
        'prependMiddlewareToGroup', 'pushMiddlewareToGroup', 'removeMiddlewareFromGroup', 'resolveMiddleware',
        'resourceParameters', 'resourceVerbs', 'respondWithRoute', 'setCompiledRoutes', 'setContainer', 'setRoutes',
        'singularResourceParameters', 'substituteBindings', 'substituteImplicitBindings',
        'substituteImplicitBindingsUsing', 'toResponse', 'uniqueMiddleware', 'uses', 'tap', 'attribute',
        // facade plumbing
        'getFacadeRoot', 'swap', 'shouldReceive', 'spy', 'partialMock', 'expects', 'isFake', 'resolved',
        'clearResolvedInstance', 'clearResolvedInstances', 'setFacadeApplication', 'getFacadeApplication',
    ];

    public static function contains(string $method): bool
    {
        return collect(self::NAMES)->containsStrict($method);
    }
}
