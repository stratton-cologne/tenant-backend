<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TenantSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\AdLdapService;
use App\Services\Auth\JwtService;
use App\Services\Auth\MailOtpService;
use App\Services\Auth\TokenRevocationService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly TokenRevocationService $tokenRevocationService,
        private readonly MailOtpService $mailOtpService,
        private readonly TotpService $totpService,
        private readonly AdLdapService $adLdapService,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $rateLimitKey = 'auth:login:'.Str::lower($payload['email']).'|'.$request->ip();
        $maxAttempts = (int) config('security.login_rate_limit', 5);

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many login attempts',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        $authResult = $this->authenticateUser((string) $payload['email'], (string) $payload['password']);
        $user = $authResult['user'];
        if ($user === null) {
            RateLimiter::hit($rateLimitKey, 60);
            $this->audit(null, 'auth.login.failed', ['email' => $payload['email']]);

            if (($authResult['reason'] ?? null) === 'account_deactivated') {
                return response()->json(['message' => 'Account is deactivated'], 422);
            }
            if (($authResult['reason'] ?? null) === 'local_login_disabled') {
                return response()->json(['message' => 'Local login is disabled for this tenant'], 422);
            }

            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if ((bool) $user->must_change_password && $user->temp_password_expires_at !== null && now()->greaterThan($user->temp_password_expires_at)) {
            RateLimiter::hit($rateLimitKey, 60);
            $this->audit((string) $user->uuid, 'auth.login.temp_password.expired', ['email' => $payload['email']]);

            return response()->json(['message' => 'Temporary password has expired'], 422);
        }

        RateLimiter::clear($rateLimitKey);

        $trustedDevice = $this->resolveTrustedDevice($request, $user);
        if ($trustedDevice !== null) {
            $accessToken = $this->jwtService->issue([
                'sub' => (string) $user->uuid,
                'type' => 'tenant_access',
            ]);
            $refreshToken = $this->jwtService->issue([
                'sub' => (string) $user->uuid,
                'type' => 'tenant_refresh',
            ], (int) config('security.jwt_refresh_ttl_minutes', 7 * 24 * 60));

            $trustedDevice->forceFill([
                'last_used_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1024, ''),
            ])->save();

            $this->audit((string) $user->uuid, 'auth.login.trusted_device', [
                'trusted_device_uuid' => $trustedDevice->uuid,
            ]);

            return $this->authenticatedResponse($user, $accessToken, $refreshToken, false, null, $trustedDevice);
        }

        $challengeToken = Str::random(64);
        $challengeTtl = now()->addMinutes((int) config('security.mfa_code_ttl_minutes', 10));

        Cache::put('auth:challenge:'.$challengeToken, [
            'user_uuid' => $user->uuid,
            'mfa_type' => $user->mfa_type,
        ], $challengeTtl);

        if ($user->mfa_type === 'mail') {
            try {
                $otp = $this->mailOtpService->issueOtpForUser($user);
            } catch (Throwable $exception) {
                return response()->json([
                    'message' => 'Failed to send MFA email',
                    'error' => $exception->getMessage(),
                ], 502);
            }

            Cache::put('auth:otp:'.$user->uuid, $otp, $challengeTtl);
        }

        $this->audit((string) $user->uuid, 'auth.login.mfa_required', ['mfa_type' => $user->mfa_type]);

        return response()->json([
            'status' => 'mfa_required',
            'mfa_type' => $user->mfa_type,
            'challenge_token' => $challengeToken,
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
            'remember_device' => ['sometimes', 'boolean'],
        ]);

        $verifyKey = 'auth:mfa:verify:'.$payload['challenge_token'].'|'.$request->ip();
        $maxAttempts = (int) config('security.mfa_verify_rate_limit', 5);

        if (RateLimiter::tooManyAttempts($verifyKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many MFA verification attempts',
                'retry_after' => RateLimiter::availableIn($verifyKey),
            ], 429);
        }

        $challenge = Cache::get('auth:challenge:'.$payload['challenge_token']);
        if (!is_array($challenge)) {
            return response()->json(['message' => 'MFA challenge expired'], 422);
        }

        $challengeUserUuid = is_string($challenge['user_uuid'] ?? null) ? (string) $challenge['user_uuid'] : '';
        $user = User::query()->where('uuid', $challengeUserUuid)->first();
        if ($user === null) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $valid = false;
        if (($challenge['mfa_type'] ?? null) === 'mail') {
            $otp = (string) Cache::get('auth:otp:'.$user->uuid, '');
            $valid = hash_equals($otp, (string) $payload['code']);
        }

        if (($challenge['mfa_type'] ?? null) === 'app') {
            $valid = $this->totpService->verify((string) $user->mfa_secret, (string) $payload['code']);
        }

        if (!$valid) {
            RateLimiter::hit($verifyKey, 60);
            $this->audit((string) $user->uuid, 'auth.mfa.failed', ['mfa_type' => $challenge['mfa_type'] ?? null]);

            return response()->json(['message' => 'Invalid MFA code'], 422);
        }

        RateLimiter::clear($verifyKey);

        Cache::forget('auth:challenge:'.$payload['challenge_token']);
        Cache::forget('auth:otp:'.$user->uuid);

        $accessToken = $this->jwtService->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);
        $refreshToken = $this->jwtService->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_refresh',
        ], (int) config('security.jwt_refresh_ttl_minutes', 7 * 24 * 60));

        $this->audit((string) $user->uuid, 'auth.login.success', ['mfa_type' => $challenge['mfa_type'] ?? null]);

        $rememberDevice = (bool) ($payload['remember_device'] ?? false);
        $trustedCookie = null;
        $rememberedDevice = null;
        if ($rememberDevice) {
            [$trustedCookie, $rememberedDevice] = $this->upsertTrustedDeviceCookie($request, $user);
        }

        return $this->authenticatedResponse($user, $accessToken, $refreshToken, true, $trustedCookie, $rememberedDevice);
    }

    public function refresh(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        try {
            $refreshPayload = $this->jwtService->parse((string) $payload['refresh_token']);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        if (($refreshPayload['type'] ?? null) !== 'tenant_refresh' || $this->tokenRevocationService->isRevoked($refreshPayload)) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $refreshSub = is_string($refreshPayload['sub'] ?? null) ? (string) $refreshPayload['sub'] : '';
        $user = User::query()->where('uuid', $refreshSub)->first();
        if ($user === null) {
            return response()->json(['message' => 'User not found'], 401);
        }

        $this->tokenRevocationService->revoke($refreshPayload);

        $accessToken = $this->jwtService->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);
        $refreshToken = $this->jwtService->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_refresh',
        ], (int) config('security.jwt_refresh_ttl_minutes', 7 * 24 * 60));

        return response()->json([
            'status' => 'authenticated',
            'token_type' => 'Bearer',
            'expires_in' => (int) config('security.jwt_ttl_minutes', 480) * 60,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'refresh_token' => ['nullable', 'string'],
            'forget_device' => ['sometimes', 'boolean'],
        ]);

        $accessPayload = (array) $request->attributes->get('auth.token_payload', []);
        $this->tokenRevocationService->revoke($accessPayload);

        if (!empty($payload['refresh_token'])) {
            try {
                $refreshPayload = $this->jwtService->parse((string) $payload['refresh_token']);
                if (($refreshPayload['sub'] ?? null) === ($accessPayload['sub'] ?? null)) {
                    $this->tokenRevocationService->revoke($refreshPayload);
                }
            } catch (\RuntimeException) {
                // ignore malformed refresh token in logout
            }
        }

        $response = response()->json([
            'status' => 'logged_out',
        ]);

        if ((bool) ($payload['forget_device'] ?? false)) {
            $trustedCookie = $request->cookie($this->trustedDeviceCookieName());
            if (is_string($trustedCookie) && trim($trustedCookie) !== '') {
                [$deviceUuid, $token] = $this->splitTrustedCookieValue($trustedCookie);
                if ($deviceUuid !== '' && $token !== '') {
                    $device = TrustedDevice::query()
                        ->where('uuid', $deviceUuid)
                        ->where('token_hash', hash('sha256', $token))
                        ->first();
                    if ($device !== null) {
                        $device->forceFill([
                            'revoked_at' => now(),
                        ])->save();
                    }
                }
                $response->withoutCookie($this->trustedDeviceCookieName(), '/');
            }
        }

        return $response;
    }

    private function audit(?string $userUuid, string $action, array $meta = []): void
    {
        AuditLog::query()->create([
            'user_uuid' => $userUuid,
            'action' => $action,
            'meta_json' => $meta,
        ]);
    }

    /**
     * @return array{user: User|null, reason: string|null}
     */
    private function authenticateUser(string $email, string $password): array
    {
        $normalizedEmail = Str::lower($email);
        $localUser = User::query()
            ->where('email', $normalizedEmail)
            ->first();

        $provider = $this->authProvider();
        $allowLocalFallback = $this->authAllowLocalFallback();

        if ($provider === 'ad_ldap') {
            $adUser = null;
            try {
                $adUser = $this->adLdapService->authenticate($this->adLdapConfig(), $email, $password);
            } catch (Throwable) {
                $adUser = null;
            }

            if (is_array($adUser)) {
                return [
                    'user' => $this->upsertAdUser($adUser),
                    'reason' => null,
                ];
            }

            if (!$allowLocalFallback) {
                if ($localUser !== null) {
                    return [
                        'user' => null,
                        'reason' => 'local_login_disabled',
                    ];
                }
                return [
                    'user' => null,
                    'reason' => null,
                ];
            }
        }

        if ($localUser !== null && !(bool) $localUser->is_active) {
            return [
                'user' => null,
                'reason' => 'account_deactivated',
            ];
        }

        if ($localUser === null || !Hash::check($password, (string) $localUser->password)) {
            return [
                'user' => null,
                'reason' => null,
            ];
        }

        return [
            'user' => $localUser,
            'reason' => null,
        ];
    }

    /**
     * @param array<string, mixed> $adUser
     */
    private function upsertAdUser(array $adUser): User
    {
        $email = Str::lower(trim((string) ($adUser['email'] ?? '')));
        $externalId = trim((string) ($adUser['external_id'] ?? ''));

        $user = null;
        if ($externalId !== '') {
            $user = User::query()
                ->where('auth_provider', 'ad_ldap')
                ->where('external_directory_id', $externalId)
                ->first();
        }
        if ($user === null && $email !== '') {
            $user = User::query()->where('email', $email)->first();
        }

        if ($user === null) {
            $user = new User();
            $user->password = Hash::make(Str::random(48));
            $user->mfa_type = 'mail';
            $user->must_change_password = false;
        }

        if ($email !== '') {
            $user->email = $email;
        }
        $user->first_name = trim((string) ($adUser['first_name'] ?? '')) ?: ($user->first_name ?: 'AD');
        $user->last_name = trim((string) ($adUser['last_name'] ?? '')) ?: ($user->last_name ?: 'User');
        $user->auth_provider = 'ad_ldap';
        $user->ad_username = trim((string) ($adUser['username'] ?? '')) ?: $user->ad_username;
        $user->external_directory_id = $externalId !== '' ? $externalId : $user->external_directory_id;
        $user->external_directory_dn = trim((string) ($adUser['dn'] ?? '')) ?: $user->external_directory_dn;
        $user->external_directory_active = true;
        $user->external_directory_last_sync_at = now();
        $user->is_active = true;
        $user->disabled_at = null;
        $user->save();

        return $user;
    }

    private function authProvider(): string
    {
        $provider = TenantSetting::query()->where('key', 'auth_provider')->value('value_json');
        $provider = is_string($provider) ? trim($provider) : 'local';
        return in_array($provider, ['local', 'ad_ldap'], true) ? $provider : 'local';
    }

    private function authAllowLocalFallback(): bool
    {
        $value = TenantSetting::query()->where('key', 'auth_allow_local_fallback')->value('value_json');
        return $value === null ? true : (bool) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function adLdapConfig(): array
    {
        $config = TenantSetting::query()->where('key', 'ad_ldap_config')->value('value_json');
        if (!is_array($config)) {
            return ['enabled' => false];
        }

        return $config;
    }

    private function resolveTrustedDevice(Request $request, User $user): ?TrustedDevice
    {
        $cookieValue = $request->cookie($this->trustedDeviceCookieName());
        if (!is_string($cookieValue) || trim($cookieValue) === '') {
            return null;
        }

        [$deviceUuid, $token] = $this->splitTrustedCookieValue($cookieValue);
        if ($deviceUuid === '' || $token === '') {
            return null;
        }

        $device = $user->trustedDevices()
            ->where('uuid', $deviceUuid)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return null;
        }

        if (!hash_equals((string) $device->token_hash, hash('sha256', $token))) {
            return null;
        }

        return $device;
    }

    private function splitTrustedCookieValue(string $cookieValue): array
    {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        return [trim((string) $parts[0]), trim((string) $parts[1])];
    }

    private function trustedDeviceCookieName(): string
    {
        return (string) config('security.trusted_device_cookie', 'tenant_trusted_device');
    }

    private function upsertTrustedDeviceCookie(Request $request, User $user): array
    {
        $now = now();
        $ttlDays = max(1, (int) config('security.trusted_device_ttl_days', 30));
        $expiresAt = $now->copy()->addDays($ttlDays);
        $token = Str::random(64);
        $cookieValue = '';

        $existing = $this->resolveTrustedDevice($request, $user);
        if ($existing !== null) {
            $existing->forceFill([
                'token_hash' => hash('sha256', $token),
                'last_used_at' => $now,
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1024, ''),
                'device_id_hash' => $this->resolveDeviceFingerprint($request),
            ])->save();
            $cookieValue = $existing->uuid.'.'.$token;
            $device = $existing;
        } else {
            $device = TrustedDevice::query()->create([
                'user_uuid' => (string) $user->uuid,
                'token_hash' => hash('sha256', $token),
                'last_used_at' => $now,
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1024, ''),
                'device_id_hash' => $this->resolveDeviceFingerprint($request),
            ]);
            $cookieValue = $device->uuid.'.'.$token;
        }

        $cookie = cookie(
            $this->trustedDeviceCookieName(),
            $cookieValue,
            $ttlDays * 24 * 60,
            '/',
            null,
            (bool) $request->isSecure(),
            true,
            false,
            'lax'
        );

        return [$cookie, $device];
    }

    private function resolveDeviceFingerprint(Request $request): ?string
    {
        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        if ($deviceId !== '') {
            return hash('sha256', $deviceId);
        }

        $fingerprint = implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
            (string) $request->ip(),
        ]);

        return hash('sha256', $fingerprint);
    }

    private function authenticatedResponse(
        User $user,
        string $accessToken,
        string $refreshToken,
        bool $mfaVerified,
        ?Cookie $trustedCookie = null,
        ?TrustedDevice $trustedDevice = null
    ): JsonResponse {
        $response = response()->json([
            'status' => 'authenticated',
            'token_type' => 'Bearer',
            'expires_in' => (int) config('security.jwt_ttl_minutes', 480) * 60,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'mfa_verified' => $mfaVerified,
            'remember_device_active' => $trustedDevice !== null,
            'remember_device_set_at' => $trustedDevice?->created_at?->toISOString(),
            'remember_device_expires_at' => $trustedDevice?->expires_at?->toISOString(),
            'user' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'mfa_type' => $user->mfa_type,
                'mfa_app_setup_pending' => (bool) $user->mfa_app_setup_pending,
                'must_change_password' => (bool) $user->must_change_password,
                'notification_sound_enabled' => (bool) $user->notification_sound_enabled,
                'notification_desktop_enabled' => (bool) $user->notification_desktop_enabled,
            ],
        ]);

        if ($trustedCookie !== null) {
            $response->headers->setCookie($trustedCookie);
        }

        return $response;
    }
}
