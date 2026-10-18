<?php

declare(strict_types=1);

namespace Lucasp\Loom\Index;

/**
 * Routes the router registers without a controller of the app's own; the
 * values of `routes[].kind`, which is omitted for ordinary routes.
 *
 * @internal
 */
enum RouteKind: string implements Summarized
{
    case VIEW = 'view';
    case REDIRECT = 'redirect';
    case FALLBACK = 'fallback';

    public function summary(): string
    {
        return match ($this) {
            self::VIEW => '`Route::view()`.',
            self::REDIRECT => '`Route::redirect()` or `Route::permanentRedirect()`.',
            self::FALLBACK => '`Route::fallback()`.',
        };
    }
}
