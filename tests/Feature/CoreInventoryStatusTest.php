<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoreInventoryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_entitlements_are_marked_inactive_when_no_active_subscription_exists(): void
    {
        $user = User::query()->create([
            'first_name' => 'Inventory',
            'last_name' => 'Admin',
            'email' => 'inventory-admin@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'inventory-admin']);
        $permission = Permission::query()->create(['name' => 'settings.licenses.sync']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        TenantSetting::query()->updateOrCreate(['key' => 'license_api_url'], ['value_json' => 'http://core.local/api/core']);
        TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => '11111111-1111-4111-8111-111111111111']);
        TenantSetting::query()->updateOrCreate(['key' => 'core_api_token'], ['value_json' => 'test-core-token']);

        Http::fake([
            'http://core.local/api/core/tenants/11111111-1111-4111-8111-111111111111/subscriptions' => Http::response([
                'data' => [
                    [
                        'uuid' => 'sub-1',
                        'plan' => 'business',
                        'status' => 'canceled',
                        'started_at' => '2026-02-10T10:00:00Z',
                        'ended_at' => '2026-03-10T10:00:00Z',
                        'changed_at' => '2026-02-15T08:00:00Z',
                    ],
                ],
            ], 200),
            'http://core.local/api/core/tenants/11111111-1111-4111-8111-111111111111/module-entitlements' => Http::response([
                'data' => [
                    [
                        'uuid' => 'ent-1',
                        'module_uuid' => 'module-analytics',
                        'module_slug' => 'analytics',
                        'module_name' => 'Analytics',
                        'source' => 'subscription',
                        'seats' => 20,
                        'valid_until' => null,
                    ],
                ],
            ], 200),
            'http://core.local/api/core/modules' => Http::response([
                'data' => [
                    [
                        'uuid' => 'module-analytics',
                        'name' => 'Analytics',
                        'slug' => 'analytics',
                        'is_active' => true,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/settings/licenses/core');

        $response->assertOk();
        $response->assertJsonPath('data.subscriptions.0.is_active', false);
        $response->assertJsonPath('data.entitlements.0.source', 'subscription');
        $response->assertJsonPath('data.entitlements.0.is_active', false);
    }
}
