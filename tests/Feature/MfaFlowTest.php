<?php

namespace Tests\Feature;

use App\Mail\MfaOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MfaFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_mfa_and_returns_challenge_token(): void
    {
        Mail::fake();

        User::query()->create([
            'first_name' => 'Demo',
            'last_name' => 'User',
            'email' => 'demo@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $response = $this->postJson('/api/tenant/auth/login', [
            'email' => 'demo@example.com',
            'password' => 'Password1234',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'mfa_required');
        $response->assertJsonPath('mfa_type', 'mail');
        $this->assertNotEmpty($response->json('challenge_token'));
        Mail::assertSent(MfaOtpMail::class);
    }

    public function test_mfa_verify_fails_when_challenge_expired(): void
    {
        $response = $this->postJson('/api/tenant/auth/mfa/verify', [
            'challenge_token' => 'expired-token',
            'code' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_error');
        $response->assertJsonPath('error.message', 'MFA challenge expired');
    }

    public function test_mfa_verify_rate_limit_returns_429_after_too_many_attempts(): void
    {
        $user = User::query()->create([
            'first_name' => 'Rate Limit',
            'last_name' => 'User',
            'email' => 'ratelimit@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        Cache::put('auth:challenge:test-challenge', [
            'user_uuid' => $user->uuid,
            'mfa_type' => 'mail',
        ], now()->addMinutes(10));

        Cache::put('auth:otp:'.$user->uuid, '654321', now()->addMinutes(10));

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/tenant/auth/mfa/verify', [
                'challenge_token' => 'test-challenge',
                'code' => '000000',
            ]);

            $response->assertStatus(422);
        }

        $blocked = $this->postJson('/api/tenant/auth/mfa/verify', [
            'challenge_token' => 'test-challenge',
            'code' => '000000',
        ]);

        $blocked->assertStatus(429);
        $blocked->assertJsonPath('error.code', 'too_many_requests');
        $blocked->assertJsonPath('error.message', 'Too many MFA verification attempts');
    }
}
