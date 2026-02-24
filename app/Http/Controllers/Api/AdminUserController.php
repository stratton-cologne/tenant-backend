<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MfaAppActivationMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\CoreModuleUsageSyncService;
use App\Services\Licensing\ModuleSeatService;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly TotpService $totpService,
        private readonly ModuleSeatService $moduleSeatService,
        private readonly CoreModuleUsageSyncService $coreModuleUsageSyncService,
        private readonly TenantNotificationService $tenantNotificationService,
    ) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with(['roles', 'moduleEntitlements'])
            ->latest('created_at')
            ->get()
            ->map(fn (User $user): array => $this->mapUser($user));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'temp_password_valid_days' => ['nullable', 'integer', 'in:1,3,7'],
            'role_uuids' => ['nullable', 'array'],
            'role_uuids.*' => ['uuid', 'exists:roles,uuid'],
            'assigned_module_slugs' => ['nullable', 'array'],
            'assigned_module_slugs.*' => ['string', 'max:120'],
        ]);

        $validDays = (int) ($payload['temp_password_valid_days'] ?? 7);
        $tempPassword = Str::password(14);

        $user = User::query()->create([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'email' => Str::lower($payload['email']),
            'password' => Hash::make($tempPassword),
            'mfa_type' => 'mail',
            'mfa_app_setup_pending' => false,
            'must_change_password' => true,
            'temp_password_expires_at' => now()->addDays($validDays),
        ]);

        if (!empty($payload['role_uuids'])) {
            $user->roles()->sync(Role::query()->whereIn('uuid', $payload['role_uuids'])->pluck('id')->all());
        }

        $beforeAssignedModuleSlugs = [];
        $this->moduleSeatService->syncAssignmentsForUser(
            user: $user,
            moduleSlugs: (array) ($payload['assigned_module_slugs'] ?? []),
            assignedByUuid: (string) $request->attributes->get('auth.user_uuid')
        );
        $afterAssignedModuleSlugs = $this->moduleSeatService->assignedModuleSlugsForUser($user);
        $this->notifyModuleAssignmentChanges(
            user: $user,
            beforeAssignedModuleSlugs: $beforeAssignedModuleSlugs,
            afterAssignedModuleSlugs: $afterAssignedModuleSlugs,
            actorUserUuid: (string) $request->attributes->get('auth.user_uuid')
        );
        $this->syncCoreModuleUsage();

        $portalUrl = (string) env('TENANT_PORTAL_URL', (string) config('app.url'));
        $loginUrl = rtrim($portalUrl, '/');
        Mail::to($user->email)->send(new TemporaryPasswordMail(
            name: (string) $user->full_name,
            temporaryPassword: $tempPassword,
            loginUrl: $loginUrl,
            expiresAt: (string) optional($user->temp_password_expires_at)->format('Y-m-d H:i:s'),
        ));
        $this->tenantNotificationService->notifyUserUuid(
            (string) $user->uuid,
            'account.created',
            'Benutzerkonto erstellt',
            'Dein Benutzerkonto wurde erstellt. Bitte mit dem temporaeren Passwort anmelden und Ersteinrichtung abschliessen.'
        );

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.created', ['target_user_uuid' => $user->uuid]);

        return response()->json([
            'data' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'mfa_type' => $user->mfa_type,
                'mfa_app_setup_pending' => (bool) $user->mfa_app_setup_pending,
                'must_change_password' => $user->must_change_password,
                'temp_password' => $tempPassword,
                'temp_password_expires_at' => optional($user->temp_password_expires_at)->toISOString(),
            ],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $beforeRoleNames = $user->roles()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

        $payload = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:255', 'unique:users,email,'.$user->uuid.',uuid'],
            'mfa_type' => ['sometimes', 'in:mail,app'],
            'reset_temp_password' => ['nullable', 'boolean'],
            'temp_password_valid_days' => ['nullable', 'integer', 'in:1,3,7'],
            'role_uuids' => ['sometimes', 'array'],
            'role_uuids.*' => ['uuid', 'exists:roles,uuid'],
            'assigned_module_slugs' => ['sometimes', 'array'],
            'assigned_module_slugs.*' => ['string', 'max:120'],
        ]);

        $user->fill(array_filter([
            'first_name' => $payload['first_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'email' => isset($payload['email']) ? Str::lower($payload['email']) : null,
            'mfa_type' => $payload['mfa_type'] ?? null,
        ], fn ($value) => $value !== null))->save();

        if (array_key_exists('role_uuids', $payload)) {
            $user->roles()->sync(Role::query()->whereIn('uuid', $payload['role_uuids'])->pluck('id')->all());
            $afterRoleNames = $user->roles()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

            $before = $beforeRoleNames;
            $after = $afterRoleNames;
            sort($before);
            sort($after);

            if ($before !== $after) {
                $this->tenantNotificationService->notifyUserUuid(
                    (string) $user->uuid,
                    'account.roles_updated',
                    'Rollen aktualisiert',
                    'Deine Rollen wurden angepasst.',
                    [
                        'roles_before' => $beforeRoleNames,
                        'roles_after' => $afterRoleNames,
                        'updated_by' => (string) $request->attributes->get('auth.user_uuid'),
                    ]
                );
            }
        }

        if (array_key_exists('assigned_module_slugs', $payload)) {
            $beforeAssignedModuleSlugs = $this->moduleSeatService->assignedModuleSlugsForUser($user);
            $this->moduleSeatService->syncAssignmentsForUser(
                user: $user,
                moduleSlugs: (array) ($payload['assigned_module_slugs'] ?? []),
                assignedByUuid: (string) $request->attributes->get('auth.user_uuid')
            );
            $afterAssignedModuleSlugs = $this->moduleSeatService->assignedModuleSlugsForUser($user);
            $this->notifyModuleAssignmentChanges(
                user: $user,
                beforeAssignedModuleSlugs: $beforeAssignedModuleSlugs,
                afterAssignedModuleSlugs: $afterAssignedModuleSlugs,
                actorUserUuid: (string) $request->attributes->get('auth.user_uuid')
            );
            $this->syncCoreModuleUsage();
        }

        if ((bool) ($payload['reset_temp_password'] ?? false)) {
            $validDays = (int) ($payload['temp_password_valid_days'] ?? 7);
            $tempPassword = Str::password(14);
            $user->password = Hash::make($tempPassword);
            $user->mfa_type = 'mail';
            $user->mfa_secret = null;
            $user->mfa_app_setup_pending = false;
            $user->must_change_password = true;
            $user->temp_password_expires_at = now()->addDays($validDays);
            $user->save();

            $portalUrl = (string) env('TENANT_PORTAL_URL', (string) config('app.url'));
            $loginUrl = rtrim($portalUrl, '/');
            Mail::to($user->email)->send(new TemporaryPasswordMail(
                name: (string) $user->full_name,
                temporaryPassword: $tempPassword,
                loginUrl: $loginUrl,
                expiresAt: (string) optional($user->temp_password_expires_at)->format('Y-m-d H:i:s'),
            ));
            $this->tenantNotificationService->notifyUserUuid(
                (string) $user->uuid,
                'account.password_reset',
                'Temporäres Passwort aktualisiert',
                'Fuer dein Konto wurde ein neues temporaeres Passwort erzeugt und per E-Mail versendet.'
            );
        }

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.updated', ['target_user_uuid' => $user->uuid]);

        return response()->json(['data' => $this->mapUser($user->fresh()->load(['roles', 'moduleEntitlements']))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $targetUserUuid = (string) $user->uuid;
        $user->roles()->detach();
        $user->delete();
        $this->syncCoreModuleUsage();

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.deleted', ['target_user_uuid' => $targetUserUuid]);

        return response()->json(['status' => 'deleted']);
    }

    public function revokeTrustedDevices(Request $request, User $user): JsonResponse
    {
        $revoked = $user->trustedDevices()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->tenantNotificationService->notifyUserUuid(
            (string) $user->uuid,
            'account.trusted_devices_revoked',
            'Vertraute Geraete zurueckgesetzt',
            'Alle vertrauten Geraete fuer dein Konto wurden durch einen Administrator zurueckgesetzt.'
        );

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.trusted_devices.revoked', [
            'target_user_uuid' => (string) $user->uuid,
            'revoked_count' => (int) $revoked,
        ]);

        return response()->json([
            'status' => 'ok',
            'revoked_count' => (int) $revoked,
        ]);
    }

    public function listTrustedDevices(User $user): JsonResponse
    {
        $devices = $user->trustedDevices()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn ($device): array => [
                'uuid' => (string) $device->uuid,
                'ip_address' => $device->ip_address,
                'user_agent' => $device->user_agent,
                'last_used_at' => $device->last_used_at?->toISOString(),
                'expires_at' => $device->expires_at?->toISOString(),
                'created_at' => $device->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $devices]);
    }

    public function revokeTrustedDevice(Request $request, User $user, string $deviceUuid): JsonResponse
    {
        $device = $user->trustedDevices()
            ->where('uuid', $deviceUuid)
            ->whereNull('revoked_at')
            ->first();

        if ($device === null) {
            return response()->json(['message' => 'Trusted device not found'], 404);
        }

        $device->forceFill(['revoked_at' => now()])->save();

        $this->tenantNotificationService->notifyUserUuid(
            (string) $user->uuid,
            'account.trusted_device_revoked',
            'Vertrautes Geraet entfernt',
            'Ein vertrautes Geraet fuer dein Konto wurde durch einen Administrator entfernt.'
        );

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.trusted_device.revoked', [
            'target_user_uuid' => (string) $user->uuid,
            'trusted_device_uuid' => (string) $device->uuid,
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function moduleSeatOverview(): JsonResponse
    {
        return response()->json([
            'data' => $this->moduleSeatService->seatOverview()->all(),
        ]);
    }

    public function setupAppMfa(Request $request, User $user): JsonResponse
    {
        $activationToken = Str::random(64);
        $activationTtlMinutes = (int) config('security.mfa_app_activation_ttl_minutes', 30);
        $expiresAt = now()->addMinutes($activationTtlMinutes);
        $secret = $this->generateAppSecret();

        Cache::put('auth:mfa:app:activation:'.$activationToken, [
            'user_uuid' => (string) $user->uuid,
            'secret' => $secret,
            'requested_by_uuid' => (string) $request->attributes->get('auth.user_uuid'),
        ], $expiresAt);

        $portalUrl = (string) env('TENANT_PORTAL_URL', (string) config('app.url'));
        $activationUrl = rtrim($portalUrl, '/').'/mfa/activate?token='.$activationToken;
        $otpAuthUri = $this->otpAuthUri($user, $secret);
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($otpAuthUri);

        $user->mfa_app_setup_pending = true;
        $user->save();

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

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.mfa.app.setup_requested', [
            'target_user_uuid' => (string) $user->uuid,
            'expires_at' => $expiresAt->toISOString(),
        ]);

        return response()->json([
            'data' => [
                'user_uuid' => (string) $user->uuid,
                'activation_token' => $activationToken,
                'expires_at' => $expiresAt->toISOString(),
                'activation_url' => $activationUrl,
                'otp_auth_uri' => $otpAuthUri,
                'qr_code_url' => $qrCodeUrl,
            ],
        ]);
    }

    public function activateAppMfa(Request $request, User $user): JsonResponse
    {
        $payload = $request->validate([
            'activation_token' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
        ]);

        $challenge = Cache::get('auth:mfa:app:activation:'.$payload['activation_token']);
        if (!is_array($challenge)) {
            return response()->json(['message' => 'MFA app activation token expired'], 422);
        }

        if (!is_string($challenge['user_uuid'] ?? null) || !hash_equals((string) $challenge['user_uuid'], (string) $user->uuid)) {
            return response()->json(['message' => 'Activation token does not match target user'], 422);
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

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'admin.user.mfa.app.activated', [
            'target_user_uuid' => (string) $user->uuid,
        ]);

        return response()->json(['data' => $this->mapUser($user->fresh()->load(['roles', 'moduleEntitlements']))]);
    }

    private function audit(?string $userUuid, string $action, array $meta = []): void
    {
        AuditLog::query()->create([
            'user_uuid' => $userUuid,
            'action' => $action,
            'meta_json' => $meta,
        ]);
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

    private function syncCoreModuleUsage(): void
    {
        try {
            $this->coreModuleUsageSyncService->push();
        } catch (Throwable $exception) {
            Log::warning('Core module usage sync failed', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, string> $beforeAssignedModuleSlugs
     * @param array<int, string> $afterAssignedModuleSlugs
     */
    private function notifyModuleAssignmentChanges(
        User $user,
        array $beforeAssignedModuleSlugs,
        array $afterAssignedModuleSlugs,
        string $actorUserUuid
    ): void {
        $before = array_values(array_unique(array_filter(array_map(static fn (mixed $slug): string => trim((string) $slug), $beforeAssignedModuleSlugs))));
        $after = array_values(array_unique(array_filter(array_map(static fn (mixed $slug): string => trim((string) $slug), $afterAssignedModuleSlugs))));

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== []) {
            $this->tenantNotificationService->notifyUserUuid(
                (string) $user->uuid,
                'account.module_access_granted',
                'Modulzugriff freigeschaltet',
                'Dir wurden neue Module zugewiesen: '.implode(', ', $added).'.',
                [
                    'added_module_slugs' => $added,
                    'all_assigned_module_slugs' => $after,
                    'updated_by' => $actorUserUuid,
                ]
            );
        }

        if ($removed !== []) {
            $this->tenantNotificationService->notifyUserUuid(
                (string) $user->uuid,
                'account.module_access_revoked',
                'Modulzugriff entfernt',
                'Folgende Module wurden dir entzogen: '.implode(', ', $removed).'.',
                [
                    'removed_module_slugs' => $removed,
                    'all_assigned_module_slugs' => $after,
                    'updated_by' => $actorUserUuid,
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapUser(User $user): array
    {
        $assignedModuleSlugs = $user->relationLoaded('moduleEntitlements')
            ? $user->moduleEntitlements->pluck('module_slug')->map(fn ($slug): string => (string) $slug)->values()->all()
            : $this->moduleSeatService->assignedModuleSlugsForUser($user);

        return [
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'created_at' => optional($user->created_at)->toISOString(),
            'email' => $user->email,
            'mfa_type' => $user->mfa_type,
            'mfa_app_setup_pending' => (bool) $user->mfa_app_setup_pending,
            'must_change_password' => (bool) $user->must_change_password,
            'notification_sound_enabled' => (bool) $user->notification_sound_enabled,
            'notification_desktop_enabled' => (bool) $user->notification_desktop_enabled,
            'temp_password_expires_at' => optional($user->temp_password_expires_at)->toISOString(),
            'role_uuids' => $user->roles->pluck('uuid')->map(fn ($uuid): string => (string) $uuid)->values()->all(),
            'role_names' => $user->roles->pluck('name')->map(fn ($name): string => (string) $name)->values()->all(),
            'assigned_module_slugs' => $assignedModuleSlugs,
        ];
    }
}
