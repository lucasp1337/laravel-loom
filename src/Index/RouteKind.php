<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Routes the router registers without a controller of the app's own; the
 * values of `routes[].kind`, which is omitted for ordinary routes.
 *
 * @internal
 */
enum RouteKind: string
{
    /** `Route::view()`. */
    case VIEW = 'view';

    /** `Route::redirect()` / `Route::permanentRedirect()`. */
    case REDIRECT = 'redirect';

    /** `Route::fallback()`. */
    case FALLBACK = 'fallback';
}
