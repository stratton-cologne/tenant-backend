<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantE2ESeeder extends Seeder
{
    public const ADMIN_EMAIL = 'tenant-e2e-admin@example.com';
    public const ADMIN_PASSWORD = 'Password1234';

    public function run(): void
    {
        $licenseApiUrl = (string) env('LICENSE_API_URL', 'http://127.0.0.1:8000/api/core');
        $coreTenantUuid = trim((string) env('E2E_CORE_TENANT_UUID', ''));
        $coreApiToken = (string) env('CORE_API_TOKEN', 'e2e-core-token');

        $user = User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'first_name' => 'Tenant E2E',
                'last_name' => 'Admin',
                'password' => Hash::make(self::ADMIN_PASSWORD),
                'mfa_type' => 'app',
                'mfa_secret' => 'JBSWY3DPEHPK3PXP',
                'must_change_password' => false,
                'temp_password_expires_at' => null,
            ]
        );

        $role = Role::query()->updateOrCreate(['name' => 'tenant-e2e-admin-role']);
        $permissionIds = collect([
            'users.manage',
            'settings.manage',
            'settings.licenses.read',
            'settings.licenses.sync',
        ])->map(fn (string $name): int => Permission::query()->updateOrCreate(['name' => $name])->id)->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        TenantSetting::query()->updateOrCreate(['key' => 'license_api_url'], ['value_json' => $licenseApiUrl]);
        if ($coreTenantUuid !== '') {
            TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => $coreTenantUuid]);
        }
        TenantSetting::query()->updateOrCreate(['key' => 'core_api_token'], ['value_json' => $coreApiToken]);
        TenantSetting::query()->updateOrCreate(['key' => 'language'], ['value_json' => 'de']);
        TenantSetting::query()->updateOrCreate(['key' => 'default_theme'], ['value_json' => 'prototype']);
        TenantSetting::query()->updateOrCreate(['key' => 'dashboard_user_trend_days'], ['value_json' => 7]);
    }
}
