<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_refresh_and_logout_revoke_tokens(): void
    {
        User::query()->create([
            'first_name' => 'Token',
            'last_name' => 'User',
            'email' => 'token-user@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'app',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'must_change_password' => false,
        ]);

        $login = $this->postJson('/api/tenant/auth/login', [
            'email' => 'token-user@example.com',
            'password' => 'Password1234',
        ]);
        $login->assertOk();
        $challengeToken = (string) $login->json('challenge_token');

        $verify = $this->postJson('/api/tenant/auth/mfa/verify', [
            'challenge_token' => $challengeToken,
            'code' => '123456',
        ]);
        $verify->assertOk();

        $accessToken = (string) $verify->json('access_token');
        $refreshToken = (string) $verify->json('refresh_token');

        $refresh = $this->postJson('/api/tenant/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);
        $refresh->assertOk();

        $newAccess = (string) $refresh->json('access_token');
        $newRefresh = (string) $refresh->json('refresh_token');
        $this->assertNotSame($accessToken, $newAccess);
        $this->assertNotSame($refreshToken, $newRefresh);

        $logout = $this
            ->withHeaders(['Authorization' => 'Bearer '.$newAccess])
            ->postJson('/api/tenant/auth/logout', ['refresh_token' => $newRefresh]);
        $logout->assertOk();
        $logout->assertJsonPath('status', 'logged_out');

        $context = $this
            ->withHeaders(['Authorization' => 'Bearer '.$newAccess])
            ->getJson('/api/tenant/context');
        $context->assertStatus(401);
    }
}
