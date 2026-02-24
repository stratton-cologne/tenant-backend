<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileMfaUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_mfa_can_switch_from_app_to_mail(): void
    {
        $user = User::query()->create([
            'first_name' => 'Mfa',
            'last_name' => 'User',
            'email' => 'mfa-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'app',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'must_change_password' => false,
        ]);

        $token = app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);

        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/tenant/profile/mfa', [
                'mfa_type' => 'mail',
                'current_password' => 'Password1234',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.mfa_type', 'mail');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('mail', $fresh->mfa_type);
        $this->assertNull($fresh->mfa_secret);
    }
}
