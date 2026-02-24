<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MfaAppActivationMail;
use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\PasswordPolicyService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly PasswordPolicyService $passwordPolicyService,
        private readonly TotpService $totpService,
    )
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        return response()->json(['data' => $this->mapUser($user)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:255', 'unique:users,email,'.$user->uuid.',uuid'],
            'notification_sound_enabled' => ['sometimes', 'boolean'],
            'notification_desktop_enabled' => ['sometimes', 'boolean'],
            'current_password' => ['sometimes', 'string'],
        ]);

        if (isset($payload['email'])) {
            $payload['email'] = Str::lower($payload['email']);
        }

        if (!Schema::hasColumn('users', 'notification_sound_enabled')) {
            unset($payload['notification_sound_enabled']);
        }
        if (!Schema::hasColumn('users', 'notification_desktop_enabled')) {
            unset($payload['notification_desktop_enabled']);
        }

        $emailChanged = isset($payload['email']) && Str::lower((string) $user->email) !== $payload['email'];
        if ($emailChanged) {
            $currentPassword = (string) ($payload['current_password'] ?? '');
            if ($currentPassword === '' || !Hash::check($currentPassword, (string) $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Current password is required for email changes.'],
                ]);
            }
        }

        unset($payload['current_password']);
        $user->fill($payload)->save();

        $this->audit((string) $user->uuid, 'profile.updated', ['fields' => array_keys($payload)]);

        return response()->json(['data' => $this->mapUser($user->fresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:'.(int) config('security.password_min_length', 12)],
        ]);

        abort_unless(Hash::check($payload['current_password'], (string) $user->password), 422, 'Current password is invalid');
        if (!$this->passwordPolicyService->validate($payload['new_password'])) {
            throw ValidationException::withMessages([
                'new_password' => [
                    'Password must have at least 12 characters, one uppercase letter, one lowercase letter, and one number.',
                ],
            ]);
        }

        $user->password = Hash::make($payload['new_password']);
        $user->must_change_password = false;
        $user->save();

        $this->audit((string) $user->uuid, 'profile.password.changed');

        return response()->json(['status' => 'ok']);
    }

    public function updateMfa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = Validator::make($request->all(), [
            'mfa_type' => ['required', 'string'],
            'mfa_secret' => ['nullable', 'string', 'max:255'],
            'current_password' => ['nullable', 'string'],
        ])->validate();

        $mfaType = Str::lower(trim((string) $payload['mfa_type']));
        if (!in_array($mfaType, ['mail', 'app'], true)) {
            throw ValidationException::withMessages([
                'mfa_type' => ['The mfa type field must be one of: mail, app.'],
            ]);
        }

        $mfaChanged = (string) $user->mfa_type !== $mfaType;
        if ($mfaChanged) {
            $currentPassword = (string) ($payload['current_password'] ?? '');
            if ($currentPassword === '' || !Hash::check($currentPassword, (string) $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Current password is required for MFA changes.'],
                ]);
            }
        }

        if ($mfaType === 'app') {
            throw ValidationException::withMessages([
                'mfa_type' => ['Use MFA app setup flow to activate app-based MFA.'],
            ]);
        }

        $user->mfa_type = $mfaType;
        $user->mfa_secret = null;
        $user->mfa_app_setup_pending = false;
        $user->save();

        $this->audit((string) $user->uuid, 'profile.mfa.changed', ['mfa_type' => $user->mfa_type]);

        return response()->json(['data' => $this->mapUser($user->fresh())]);
    }

    public function setupAppMfa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'send_email' => ['nullable', 'boolean'],
        ]);

        abort_unless(Hash::check(trim((string) $payload['current_password']), (string) $user->password), 422, 'Current password is invalid');

        $activationToken = Str::random(64);
        $activationTtlMinutes = (int) config('security.mfa_app_activation_ttl_minutes', 30);
        $expiresAt = now()->addMinutes($activationTtlMinutes);
        $secret = $this->generateAppSecret();

        Cache::put('auth:mfa:app:activation:'.$activationToken, [
            'user_uuid' => (string) $user->uuid,
            'secret' => $secret,
            'requested_by_uuid' => (string) $user->uuid,
        ], $expiresAt);

        $portalUrl = (string) env('TENANT_PORTAL_URL', (string) config('app.url'));
        $activationUrl = rtrim($portalUrl, '/').'/mfa/activate?token='.$activationToken;
        $otpAuthUri = $this->otpAuthUri($user, $secret);
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($otpAuthUri);
        $sendEmail = (bool) ($payload['send_email'] ?? false);

        if ($sendEmail) {
            try {
                Mail::to($user->email)->send(new MfaAppActivationMail(
                    name: (string) $user->full_name,
                    activationUrl: $activationUrl,
                    activationToken: $activationToken,
                    ttlMinutes: $activationTtlMinutes,
                    otpAuthUri: $otpAuthUri,
                    qrCodeUrl: $qrCodeUrl,
                ));
            } catch (Throwable $exception) {
                Cache::forget('auth:mfa:app:activation:'.$activationToken);

                return response()->json([
                    'message' => 'Failed to send MFA app activation email',
                    'error' => $exception->getMessage(),
                ], 502);
            }
        }

        $user->mfa_app_setup_pending = true;
        $user->save();

        $this->audit((string) $user->uuid, 'profile.mfa.app.setup_requested', [
            'expires_at' => $expiresAt->toISOString(),
            'email_sent' => $sendEmail,
        ]);

        return response()->json([
            'data' => [
                'user_uuid' => (string) $user->uuid,
                'activation_token' => $activationToken,
                'expires_at' => $expiresAt->toISOString(),
                'activation_url' => $activationUrl,
                'otp_auth_uri' => $otpAuthUri,
                'qr_code_url' => $qrCodeUrl,
                'email_sent' => $sendEmail,
                'mfa_app_setup_pending' => true,
            ],
        ]);
    }

    public function activateAppMfa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $payload = $request->validate([
            'activation_token' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
        ]);

        $challenge = Cache::get('auth:mfa:app:activation:'.$payload['activation_token']);
        if (!is_array($challenge)) {
            return response()->json(['message' => 'MFA app activation token expired'], 422);
        }

        if (!is_string($challenge['user_uuid'] ?? null) || !hash_equals((string) $challenge['user_uuid'], (string) $user->uuid)) {
            return response()->json(['message' => 'Activation token does not match current user'], 422);
        }

        $secret = (string) ($challenge['secret'] ?? '');
        if (!$this->totpService->verify($secret, (string) $payload['code'])) {
            return response()->json(['message' => 'Invalid authenticator code'], 422);
        }

        $user->mfa_type = 'app';
        $user->mfa_secret = $secret;
        $user->mfa_app_setup_pending = false;
        $user->save();

        Cache::forget('auth:mfa:app:activation:'.$payload['activation_token']);

        $this->audit((string) $user->uuid, 'profile.mfa.app.activated');

        return response()->json(['data' => $this->mapUser($user->fresh())]);
    }

    public function trustedDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $currentDeviceUuid = $this->resolveCurrentTrustedDeviceUuid($request, $user);
        $devices = $user->trustedDevices()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TrustedDevice $device) use ($currentDeviceUuid): array {
                return [
                    'uuid' => (string) $device->uuid,
                    'ip_address' => $device->ip_address,
                    'user_agent' => $device->user_agent,
                    'last_used_at' => $device->last_used_at?->toISOString(),
                    'expires_at' => $device->expires_at?->toISOString(),
                    'revoked_at' => $device->revoked_at?->toISOString(),
                    'is_current' => $currentDeviceUuid !== '' && hash_equals((string) $device->uuid, $currentDeviceUuid),
                    'is_active' => $device->revoked_at === null && $device->expires_at !== null && now()->lt($device->expires_at),
                    'created_at' => $device->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $devices]);
    }

    public function revokeTrustedDevice(Request $request, TrustedDevice $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        abort_unless(hash_equals((string) $device->user_uuid, (string) $user->uuid), 404, 'Trusted device not found');

        $device->forceFill([
            'revoked_at' => now(),
        ])->save();

        $response = response()->json(['status' => 'ok']);
        $currentUuid = $this->resolveCurrentTrustedDeviceUuid($request, $user);
        if ($currentUuid !== '' && hash_equals($currentUuid, (string) $device->uuid)) {
            $response->withoutCookie($this->trustedDeviceCookieName(), '/');
        }

        $this->audit((string) $user->uuid, 'profile.trusted_device.revoked', [
            'trusted_device_uuid' => (string) $device->uuid,
        ]);

        return $response;
    }

    public function revokeAllTrustedDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $updated = $user->trustedDevices()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->audit((string) $user->uuid, 'profile.trusted_device.revoked_all', [
            'count' => $updated,
        ]);

        return response()->json(['status' => 'ok'])->withoutCookie($this->trustedDeviceCookieName(), '/');
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
     * @return array<string, mixed>
     */
    private function mapUser(User $user): array
    {
        return [
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'mfa_type' => $user->mfa_type,
            'mfa_app_setup_pending' => (bool) $user->mfa_app_setup_pending,
            'must_change_password' => (bool) $user->must_change_password,
            'notification_sound_enabled' => Schema::hasColumn('users', 'notification_sound_enabled')
                ? (bool) $user->notification_sound_enabled
                : true,
            'notification_desktop_enabled' => Schema::hasColumn('users', 'notification_desktop_enabled')
                ? (bool) $user->notification_desktop_enabled
                : true,
        ];
    }

    private function otpAuthUri(User $user, string $secret): string
    {
        $issuer = rawurlencode((string) config('app.name', 'Tenant Portal'));
        $label = rawurlencode((string) config('app.name', 'Tenant Portal').':'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&digits=6&period=30";
    }

    private function generateAppSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    private function trustedDeviceCookieName(): string
    {
        return (string) config('security.trusted_device_cookie', 'tenant_trusted_device');
    }

    private function resolveCurrentTrustedDeviceUuid(Request $request, User $user): string
    {
        $cookieValue = trim((string) $request->cookie($this->trustedDeviceCookieName(), ''));
        if ($cookieValue === '') {
            return '';
        }

        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$uuid, $token] = $parts;
        $uuid = trim((string) $uuid);
        $token = trim((string) $token);
        if ($uuid === '' || $token === '') {
            return '';
        }

        $device = $user->trustedDevices()
            ->where('uuid', $uuid)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return '';
        }

        if (!hash_equals((string) $device->token_hash, hash('sha256', $token))) {
            return '';
        }

        return (string) $device->uuid;
    }
}
