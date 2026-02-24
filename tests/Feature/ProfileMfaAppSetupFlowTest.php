<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProfileMfaAppSetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_start_and_activate_app_mfa_setup(): void
    {
        Mail::fake();

        $user = User::query()->create([
            'first_name' => 'Setup',
            'last_name' => 'User',
            'email' => 'setup-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        $setup = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/profile/mfa/app/setup', [
                'current_password' => 'Password1234',
                'send_email' => false,
            ]);

        $setup->assertOk();
        $setup->assertJsonPath('data.mfa_app_setup_pending', true);
        $this->assertNotSame('', (string) $setup->json('data.activation_token'));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue((bool) $fresh->mfa_app_setup_pending);
        $this->assertSame('mail', $fresh->mfa_type);

        $activate = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/profile/mfa/app/activate', [
                'activation_token' => (string) $setup->json('data.activation_token'),
                'code' => '123456',
            ]);

        $activate->assertOk();
        $activate->assertJsonPath('data.mfa_type', 'app');
        $activate->assertJsonPath('data.mfa_app_setup_pending', false);

        $activeUser = $user->fresh();
        $this->assertNotNull($activeUser);
        $this->assertSame('app', $activeUser->mfa_type);
        $this->assertFalse((bool) $activeUser->mfa_app_setup_pending);
    }

    public function test_pending_app_setup_blocks_other_api_actions(): void
    {
        $user = User::query()->create([
            'first_name' => 'Pending',
            'last_name' => 'User',
            'email' => 'pending-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'mfa_app_setup_pending' => true,
            'must_change_password' => false,
        ]);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/admin/users');

        $response->assertStatus(423);
        $response->assertJsonPath('error.code', 'http_error');
    }
}
