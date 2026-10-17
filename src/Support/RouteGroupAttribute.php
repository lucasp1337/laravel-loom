<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

/**
 * The group attributes Loom applies to routes, named for reporting and for
 * reading them off a group's array config or fluent setters.
 *
 * @internal
 */
enum RouteGroupAttribute: string
{
    case PREFIX = 'prefix';
    case NAME = 'name';
    case CONTROLLER = 'controller';
    case MIDDLEWARE = 'middleware';
    case WITHOUT_MIDDLEWARE = 'without_middleware';

    /** The whole attribute array of `Route::group($attributes, ...)` is not a literal. */
    case ATTRIBUTES = 'attributes';

    /** The attribute an array-config key sets: `Route::group(['as' => 'x.'], ...)`. */
    public static function fromConfigKey(?string $key): ?self
    {
        return match ($key) {
            // ['prefix' => 'admin']
            'prefix' => self::PREFIX,
            // ['as' => 'admin.'] (the array form has no `name` key)
            'as' => self::NAME,
            // ['controller' => Ctrl::class]
            'controller' => self::CONTROLLER,
            // ['middleware' => ['auth']]
            'middleware' => self::MIDDLEWARE,
            // ['excluded_middleware' => ['auth']]
            'excluded_middleware' => self::WITHOUT_MIDDLEWARE,
            // domain, where, namespace, ... are not recorded on routes
            default => null,
        };
    }

    /** The attribute a fluent setter sets: `Route::name('x.')->...->group()`. */
    public static function fromSetter(string $method): ?self
    {
        return match ($method) {
            // ->prefix('admin')
            'prefix' => self::PREFIX,
            // ->name('admin.') or its ->as('admin.') alias
            'name', 'as' => self::NAME,
            // ->controller(Ctrl::class)
            'controller' => self::CONTROLLER,
            // ->middleware('auth') or ->middleware(['auth', 'verified'])
            'middleware' => self::MIDDLEWARE,
            // ->withoutMiddleware('auth')
            'withoutMiddleware' => self::WITHOUT_MIDDLEWARE,
            // domain(), where(), namespace(), group(), ... are not recorded
            default => null,
        };
    }
}
