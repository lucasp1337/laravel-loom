<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lucasp\Loom\Query\IndexUnavailableException;
use Lucasp\Loom\Ui\UiContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the missing/unreadable-index state in place of any page when the
 * snapshot cannot be loaded.
 */
final class RequireIndex
{
    public function __construct(private readonly UiContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->context->source->index();
        } catch (IndexUnavailableException $e) {
            return response()->view('loom::errors.missing-index', [
                'unreadable' => $this->context->source->isAvailable(),
                'message' => $e->getMessage(),
                'path' => $this->context->source->path(),
            ], 503);
        }

        return $next($request);
    }
}
