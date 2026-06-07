<?php

declare(strict_types=1);

namespace App\Http\Middleware;

class EnsureActive
{
    public function handle(mixed $request, callable $next): mixed
    {
        return $next($request);
    }
}
