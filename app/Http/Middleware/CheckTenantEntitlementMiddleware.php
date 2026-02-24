<?php

namespace App\Http\Middleware;

use App\Models\ModuleEntitlement;
use App\Models\User;
use App\Models\UserModuleEntitlement;
use App\Services\Licensing\ModuleSeatService;
use Closure;
use Illuminate\Http\Request;

class CheckTenantEntitlementMiddleware
{
    public function __construct(private readonly ModuleSeatService $moduleSeatService)
    {
    }

    public function handle(Request $request, Closure $next, string $module)
    {
        $entitlement = ModuleEntitlement::query()
            ->where('module_slug', $module)
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->first();

        abort_if($entitlement === null, 402, 'Module not licensed: '.$module);

        /** @var User|null $user */
        $user = $request->attributes->get('auth.user');
        if ($user !== null) {
            $seatCap = $this->moduleSeatService->effectiveSeatCapForModule($module);
            abort_if($seatCap <= 0, 402, 'No seats available for module: '.$module);

            $assignedCount = UserModuleEntitlement::query()
                ->where('module_slug', $module)
                ->count();
            abort_if($assignedCount > $seatCap, 409, 'Assigned seats exceed licensed seats for module: '.$module);

            $hasSeat = $this->moduleSeatService->userHasSeatForModule($user, $module);
            abort_if(!$hasSeat, 403, 'No seat assigned for module: '.$module);
        }

        return $next($request);
    }
}
