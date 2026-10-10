<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The Laravel calls that load a route file by path.
 *
 * @internal
 */
enum RouteFileLoader: string
{
    case LOAD_ROUTES_FROM = 'loadRoutesFrom()';
    case ROUTE_GROUP = 'Route::group()';
    case WITH_ROUTING = 'withRouting()';
    case WITH_COMMANDS = 'withCommands()';
}
