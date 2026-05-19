<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiArea
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tokenCan($area)) {
            return response()->error('forbidden', 403, null, 'FORBIDDEN');
        }

        return $next($request);
    }
}
