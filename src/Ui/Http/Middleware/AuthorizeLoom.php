<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lucasp\Loom\Ui\LoomAbility;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only when the `viewLoom` gate allows it.
 */
final class AuthorizeLoom
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::allows(LoomAbility::VIEW->value)) {
            return response()->view('loom::errors.forbidden', [], 403);
        }

        return $next($request);
    }
}
