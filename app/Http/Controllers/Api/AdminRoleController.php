<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminRoleController extends Controller
{
    public function permissions(): JsonResponse
    {
        $all = Permission::query()->pluck('name');

        $wildcards = $all
            ->map(function (string $name): ?string {
                $parts = explode('.', $name);
                if (count($parts) < 2) {
                    return null;
                }

                return $parts[0].'.*';
            })
            ->filter()
            ->unique()
            ->values();

        $permissions = $all
            ->merge($wildcards)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return response()->json(['data' => $permissions]);
    }

    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get()->map(function (Role $role): array {
            return [
                'uuid' => (string) $role->uuid,
                'name' => (string) $role->name,
                'slug' => Str::slug((string) $role->name),
                'permission_names' => $role->permissions->pluck('name')->values()->all(),
            ];
        });

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:roles,name'],
            'permission_names' => ['nullable', 'array'],
            'permission_names.*' => ['string', 'max:150'],
        ]);

        $role = Role::query()->create([
            'name' => $payload['name'],
        ]);

        $permissionIds = collect($payload['permission_names'] ?? [])
            ->map(fn (string $name): string => trim($name))
            ->filter(fn (string $name): bool => $name !== '')
            ->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id)
            ->values()
            ->all();

        $role->permissions()->sync($permissionIds);

        return response()->json([
            'data' => [
                'uuid' => (string) $role->uuid,
                'name' => (string) $role->name,
                'slug' => Str::slug((string) $role->name),
                'permission_names' => $role->permissions()->pluck('name')->values()->all(),
            ],
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', 'unique:roles,name,'.$role->uuid.',uuid'],
            'permission_names' => ['sometimes', 'array'],
            'permission_names.*' => ['string', 'max:150'],
        ]);

        if (isset($payload['name'])) {
            $role->name = $payload['name'];
            $role->save();
        }

        if (array_key_exists('permission_names', $payload)) {
            $permissionIds = collect($payload['permission_names'] ?? [])
                ->map(fn (string $name): string => trim($name))
                ->filter(fn (string $name): bool => $name !== '')
                ->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id)
                ->values()
                ->all();

            $role->permissions()->sync($permissionIds);
        }

        $role->load('permissions');

        return response()->json([
            'data' => [
                'uuid' => (string) $role->uuid,
                'name' => (string) $role->name,
                'slug' => Str::slug((string) $role->name),
                'permission_names' => $role->permissions->pluck('name')->values()->all(),
            ],
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $role->permissions()->detach();
        $role->delete();

        return response()->json(['status' => 'deleted']);
    }
}
