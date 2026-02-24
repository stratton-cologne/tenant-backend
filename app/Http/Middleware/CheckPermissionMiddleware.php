<?php

namespace App\Http\Middleware;

use App\Models\ModuleEntitlement;
use App\Models\User;
use App\Services\Licensing\ModuleSeatService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckPermissionMiddleware
{
    public function __construct(
        private readonly ModuleSeatService $moduleSeatService
    ) {
    }

    public function handle(Request $request, Closure $next, string $permission)
    {
        $granted = false;
        $grantedPermissions = (array) $request->attributes->get('permissions', []);

        foreach ($grantedPermissions as $grantedPermission) {
            if (!is_string($grantedPermission)) {
                continue;
            }

            if ($grantedPermission === $permission || Str::is($grantedPermission, $permission)) {
                $granted = true;
                break;
            }
        }

        abort_unless($granted, 403, 'Missing permission: '.$permission);

        $moduleSlug = Str::before($permission, '.');
        if ($moduleSlug !== '' && $this->isTenantModuleActive($moduleSlug)) {
            /** @var User|null $user */
            $user = $request->attributes->get('auth.user');
            if ($user !== null) {
                $hasSeat = $this->moduleSeatService->userHasSeatForModule($user, $moduleSlug);
                abort_unless($hasSeat, 403, 'No seat assigned for module: '.$moduleSlug);
            }
        }

        return $next($request);
    }

    private function isTenantModuleActive(string $moduleSlug): bool
    {
        return ModuleEntitlement::query()
            ->where('module_slug', $moduleSlug)
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->exists();
    }
}
