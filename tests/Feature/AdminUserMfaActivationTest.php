<?php

namespace Tests\Feature;

use App\Mail\MfaAppActivationMail;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminUserMfaActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_setup_and_activate_app_mfa_for_user(): void
    {
        Mail::fake();

        $admin = User::query()->create([
            'first_name' => 'Tenant',
            'last_name' => 'Admin',
            'email' => 'tenant-admin@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $target = User::query()->create([
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'admin']);
        $permission = Permission::query()->create(['name' => 'users.manage']);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $admin->uuid,
            'type' => 'tenant_access',
        ]);

        $setup = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/admin/users/'.$target->uuid.'/mfa/app/setup');

        $setup->assertOk();
        $setup->assertJsonPath('data.user_uuid', $target->uuid);
        $this->assertNotSame('', (string) $setup->json('data.qr_code_url'));

        $activationToken = (string) $setup->json('data.activation_token');
        $this->assertNotSame('', $activationToken);

        Mail::assertSent(MfaAppActivationMail::class, function (MfaAppActivationMail $mail): bool {
            return $mail->hasTo('target-user@example.com');
        });

        $activate = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/admin/users/'.$target->uuid.'/mfa/app/activate', [
                'activation_token' => $activationToken,
                'code' => '123456',
            ]);

        $activate->assertOk();
        $activate->assertJsonPath('data.uuid', $target->uuid);
        $activate->assertJsonPath('data.mfa_type', 'app');

        $fresh = User::query()->where('uuid', $target->uuid)->firstOrFail();
        $this->assertSame('app', $fresh->mfa_type);
        $this->assertNotNull($fresh->mfa_secret);

        $this->assertTrue(AuditLog::query()->where('action', 'admin.user.mfa.app.setup_requested')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.user.mfa.app.activated')->exists());
    }

    public function test_activation_fails_when_token_is_expired(): void
    {
        $admin = User::query()->create([
            'first_name' => 'Tenant',
            'last_name' => 'Admin',
            'email' => 'tenant-admin2@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $target = User::query()->create([
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-user2@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'admin']);
        $permission = Permission::query()->create(['name' => 'users.manage']);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $admin->uuid,
            'type' => 'tenant_access',
        ]);

        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/admin/users/'.$target->uuid.'/mfa/app/activate', [
                'activation_token' => 'expired-token',
                'code' => '123456',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_error');
        $response->assertJsonPath('error.message', 'MFA app activation token expired');
    }
}
