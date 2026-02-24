<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_licenses_fetches_from_core_and_maps_to_module_entitlements(): void
    {
        $user = User::query()->create([
            'first_name' => 'Sync',
            'last_name' => 'Admin',
            'email' => 'sync-admin@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'admin']);
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
            'http://core.local/api/core/tenants/11111111-1111-4111-8111-111111111111/entitlements' => Http::response([
                'tenant_uuid' => '11111111-1111-4111-8111-111111111111',
                'modules' => [
                    'analytics' => ['active' => true, 'seats' => 25, 'sources' => ['subscription', 'license'], 'license_keys' => ['LIC1A-AAAAA-SAAAA-TEST1-TEST2-TEST3-TEST4']],
                    'crm' => ['active' => true, 'seats' => 10, 'sources' => ['subscription']],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/settings/licenses/sync');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('synced_count', 2);
        $response->assertJsonPath('tenant_ref', '11111111-1111-4111-8111-111111111111');

        $this->assertDatabaseHas('module_entitlements', [
            'module_slug' => 'analytics',
            'source' => 'subscription',
            'active' => 1,
            'seats' => 25,
        ]);

        $this->assertDatabaseHas('module_entitlements', [
            'module_slug' => 'analytics',
            'source' => 'license',
            'active' => 1,
            'seats' => 25,
        ]);

        $this->assertDatabaseHas('module_entitlements', [
            'module_slug' => 'crm',
            'source' => 'subscription',
            'active' => 1,
            'seats' => 10,
        ]);

        $this->assertTrue(AuditLog::query()->where('action', 'settings.licenses.synced')->exists());
    }
}
