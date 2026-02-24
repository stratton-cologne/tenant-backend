<?php

namespace Tests\Feature;

use App\Mail\MfaOtpMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminUserTempPasswordResetMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_temp_password_reset_forces_mail_mfa_and_sends_otp_on_login(): void
    {
        Mail::fake();

        $admin = User::query()->create([
            'first_name' => 'Tenant',
            'last_name' => 'Admin',
            'email' => 'tenant-admin-reset@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $target = User::query()->create([
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => 'target-reset@example.com',
            'password' => Hash::make('OldPassword1234'),
            'mfa_type' => 'app',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'admin']);
        $permission = Permission::query()->create(['name' => 'users.manage']);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        $adminToken = app(JwtService::class)->issue([
            'sub' => (string) $admin->uuid,
            'type' => 'tenant_access',
        ]);

        $temporaryPassword = '';
        $update = $this
            ->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->patchJson('/api/tenant/admin/users/'.$target->uuid, [
                'reset_temp_password' => true,
                'temp_password_valid_days' => 3,
            ]);

        $update->assertOk();
        Mail::assertSent(TemporaryPasswordMail::class, function (TemporaryPasswordMail $mail) use (&$temporaryPassword): bool {
            $temporaryPassword = $mail->temporaryPassword;
            return $mail->hasTo('target-reset@example.com');
        });
        $this->assertNotSame('', $temporaryPassword);

        $fresh = User::query()->where('uuid', $target->uuid)->firstOrFail();
        $this->assertSame('mail', $fresh->mfa_type);
        $this->assertNull($fresh->mfa_secret);
        $this->assertFalse((bool) $fresh->mfa_app_setup_pending);
        $this->assertTrue((bool) $fresh->must_change_password);

        $login = $this->postJson('/api/tenant/auth/login', [
            'email' => 'target-reset@example.com',
            'password' => $temporaryPassword,
        ]);

        $login->assertOk();
        $login->assertJsonPath('status', 'mfa_required');
        $login->assertJsonPath('mfa_type', 'mail');
        Mail::assertSent(MfaOtpMail::class, function (MfaOtpMail $mail): bool {
            return $mail->hasTo('target-reset@example.com');
        });
    }
}

