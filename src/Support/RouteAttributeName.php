<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use Illuminate\Support\Str;

/**
 * Short names of the attributes that route-attribute packages use to declare
 * routes on controllers. Laravel has no such attributes of its own, so Loom
 * cannot read them; it reports where they appear.
 *
 * @internal
 */
enum RouteAttributeName: string
{
    case GET = 'Get';
    case POST = 'Post';
    case PUT = 'Put';
    case PATCH = 'Patch';
    case DELETE = 'Delete';
    case OPTIONS = 'Options';
    case ANY = 'Any';
    case ROUTE = 'Route';
    case RESOURCE = 'Resource';
    case API_RESOURCE = 'ApiResource';
    case FALLBACK = 'Fallback';
    case REDIRECT = 'Redirect';
    case VIEW = 'View';

    /** Whether an attribute class, by FQCN, looks like a package's route attribute. */
    public static function matches(string $fqcn): bool
    {
        // the framework's own attributes (container, controller middleware) declare no routes
        if (Str::startsWith($fqcn, 'Illuminate\\')) {
            return false;
        }

        return self::tryFrom(Fqcn::short($fqcn)) !== null;
    }
}
