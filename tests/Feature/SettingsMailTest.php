<?php

namespace Tests\Feature;

use App\Mail\SettingsTestMail;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SettingsMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_test_mail_endpoint_sends_mail_and_audits(): void
    {
        Mail::fake();

        $user = User::query()->create([
            'first_name' => 'Settings',
            'last_name' => 'Admin',
            'email' => 'settings-admin@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'admin']);
        $permission = Permission::query()->create(['name' => 'settings.manage']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/settings/mail/test', ['to' => 'recipient@example.com']);

        $response->assertOk();
        $response->assertJsonPath('status', 'sent');
        $response->assertJsonPath('to', 'recipient@example.com');

        Mail::assertSent(SettingsTestMail::class, function (SettingsTestMail $mail) {
            return $mail->hasTo('recipient@example.com');
        });

        $this->assertTrue(AuditLog::query()->where('action', 'settings.mail.test_sent')->exists());
    }
}
