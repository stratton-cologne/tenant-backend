<?php

namespace Tests\Feature;

use App\Mail\SettingsTestMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantSmokeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_mfa_sync_and_testmail_smoke_flow(): void
    {
        Mail::fake();

        $user = User::query()->create([
            'first_name' => 'Tenant Smoke',
            'last_name' => 'Admin',
            'email' => 'tenant-smoke@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'tenant-smoke-admin']);
        $permissionIds = [
            Permission::query()->create(['name' => 'settings.manage'])->id,
            Permission::query()->create(['name' => 'settings.licenses.sync'])->id,
        ];
        $role->permissions()->attach($permissionIds);
        $user->roles()->attach($role->id);

        TenantSetting::query()->updateOrCreate(['key' => 'license_api_url'], ['value_json' => 'http://core.local/api/core']);
        TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => '11111111-1111-4111-8111-111111111111']);
        TenantSetting::query()->updateOrCreate(['key' => 'core_api_token'], ['value_json' => 'test-core-token']);

        Http::fake([
            'http://core.local/api/core/tenants/11111111-1111-4111-8111-111111111111/entitlements' => Http::response([
                'tenant_uuid' => '11111111-1111-4111-8111-111111111111',
                'modules' => [
                    'analytics' => ['active' => true, 'seats' => 25, 'sources' => ['subscription', 'license']],
                ],
            ], 200),
        ]);

        $login = $this->postJson('/api/tenant/auth/login', [
            'email' => 'tenant-smoke@example.com',
            'password' => 'Password1234',
        ]);

        $login->assertOk();
        $challengeToken = (string) $login->json('challenge_token');

        $otp = (string) Cache::get('auth:otp:'.$user->uuid);
        $this->assertNotSame('', $otp);

        $verify = $this->postJson('/api/tenant/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $otp,
        ]);

        $verify->assertOk();
        $token = (string) $verify->json('access_token');

        $context = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/context');

        $context->assertOk();
        $context->assertJsonPath('data.user.email', 'tenant-smoke@example.com');

        $sync = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/settings/licenses/sync');

        $sync->assertOk();
        $sync->assertJsonPath('status', 'ok');
        $sync->assertJsonPath('synced_count', 1);

        $mail = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/settings/mail/test', ['to' => 'recipient@example.com']);

        $mail->assertOk();
        $mail->assertJsonPath('status', 'sent');

        Mail::assertSent(SettingsTestMail::class, function (SettingsTestMail $mailable): bool {
            return $mailable->hasTo('recipient@example.com');
        });
    }
}
