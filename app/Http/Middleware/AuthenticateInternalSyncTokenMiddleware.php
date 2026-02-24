<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateInternalSyncTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $expected = trim((string) env('CORE_TO_TENANT_SYNC_TOKEN', ''));
        abort_if($expected === '', 401, 'Internal sync token not configured');

        $provided = trim((string) $request->header('X-Sync-Token', ''));
        if ($provided === '' && str_starts_with(strtolower((string) $request->header('Authorization', '')), 'bearer ')) {
            $provided = trim(substr((string) $request->header('Authorization'), 7));
        }

        abort_if($provided === '' || !hash_equals($expected, $provided), 401, 'Invalid internal sync token');

        return $next($request);
    }
}

