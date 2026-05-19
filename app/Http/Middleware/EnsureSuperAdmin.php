<?php

namespace App\Http\Middleware;

use App\Support\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TenantAccess::isSuperAdmin($request->user())) {
            return response()->error('forbidden', 403, null, 'FORBIDDEN');
        }

        return $next($request);
    }
}
