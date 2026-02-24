<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\PermissionResolver;
use App\Services\Auth\TokenRevocationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class AuthenticateApiTokenMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly TokenRevocationService $tokenRevocationService,
        private readonly PermissionResolver $permissionResolver,
    )
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_if(!$token, 401, 'Missing bearer token');

        try {
            $payload = $this->jwtService->parse($token);
        } catch (RuntimeException) {
            abort(401, 'Invalid session token');
        }

        $userUuid = is_string($payload['sub'] ?? null) ? (string) $payload['sub'] : '';
        abort_if(!Str::isUuid($userUuid), 401, 'Invalid session token');
        abort_if(($payload['type'] ?? null) !== 'tenant_access', 401, 'Invalid session token');
        abort_if($this->tokenRevocationService->isRevoked($payload), 401, 'Token revoked');

        $user = User::query()->where('uuid', $userUuid)->first();
        abort_if($user === null, 401, 'User not found for token');

        if ((bool) $user->mfa_app_setup_pending) {
            $path = '/'.$request->path();
            $allowedPaths = [
                '/api/tenant/context',
                '/api/tenant/profile',
                '/api/tenant/profile/password',
                '/api/tenant/profile/mfa',
                '/api/tenant/profile/mfa/app/setup',
                '/api/tenant/profile/mfa/app/activate',
                '/api/tenant/auth/logout',
            ];
            if (!in_array($path, $allowedPaths, true)) {
                abort(423, 'MFA app setup is pending and must be completed first');
            }
        }

        $permissions = $this->permissionResolver->expand($user->permissionNames());

        $request->attributes->set('auth.user', $user);
        $request->attributes->set('auth.user_uuid', (string) $user->uuid);
        $request->attributes->set('permissions', $permissions);
        $request->attributes->set('auth.token_payload', $payload);

        return $next($request);
    }
}
