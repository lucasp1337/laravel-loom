<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lucasp\Loom\Ui\LoomAbility;
use Lucasp\Loom\Ui\LoomConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only when the UI is active in this environment and the `viewLoom` gate allows it.
 *
 * @internal
 */
final class AuthorizeLoom
{
    public function handle(Request $request, Closure $next): Response
    {
        // Re-checked per request so routes cached in another environment stay dark.
        if (! app(LoomConfig::class)->servesIn(app()->environment())) {
            abort(404);
        }

        if (! Gate::allows(LoomAbility::VIEW->value)) {
            return response()->view('loom::errors.forbidden', [], 403);
        }

        return $next($request);
    }
}
