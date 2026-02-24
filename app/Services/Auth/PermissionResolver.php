<?php

namespace App\Services\Auth;

use App\Models\Permission;
use Illuminate\Support\Str;

class PermissionResolver
{
    /**
     * @param array<int, string> $assignedPermissions
     * @return array<int, string>
     */
    public function expand(array $assignedPermissions): array
    {
        $normalized = collect($assignedPermissions)
            ->map(fn (string $permission): string => trim($permission))
            ->filter(fn (string $permission): bool => $permission !== '')
            ->values();

        $patterns = $normalized->filter(fn (string $permission): bool => str_contains($permission, '*'))->values();
        if ($patterns->isEmpty()) {
            return $normalized->unique()->sort()->values()->all();
        }

        $allPermissionNames = Permission::query()->pluck('name');

        $expanded = $allPermissionNames->filter(function (string $permissionName) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $permissionName)) {
                    return true;
                }
            }

            return false;
        });

        return $normalized
            ->merge($expanded)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}

