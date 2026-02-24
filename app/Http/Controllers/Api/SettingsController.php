<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SettingsTestMail;
use App\Models\AuditLog;
use App\Models\ModuleEntitlement;
use App\Models\TenantSetting;
use App\Models\UserModuleEntitlement;
use App\Models\User;
use App\Services\Licensing\ModuleSeatService;
use App\Services\CoreModuleUsageSyncService;
use App\Services\CoreLicenseSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(
        private readonly CoreLicenseSyncService $coreLicenseSyncService,
        private readonly CoreModuleUsageSyncService $coreModuleUsageSyncService,
        private readonly ModuleSeatService $moduleSeatService,
    )
    {
    }

    public function getGeneral(): JsonResponse
    {
        $mail = $this->setting('mail', []);
        if (!is_array($mail)) {
            $mail = [];
        }

        return response()->json([
            'data' => [
                'language' => $this->setting('language', 'de'),
                'default_theme' => $this->setting('default_theme', 'prototype'),
                'dashboard_user_trend_days' => max(1, min(365, (int) $this->setting('dashboard_user_trend_days', 7))),
                'dashboard_allowed_widgets' => $this->stringArraySetting('dashboard_allowed_widgets'),
                'dashboard_allowed_widgets_configured' => $this->settingExists('dashboard_allowed_widgets'),
                'license_api_url' => $this->stringSetting('license_api_url', (string) config('app.url')),
                'core_tenant_uuid' => $this->stringSetting('core_tenant_uuid', ''),
                'contact' => $this->setting('contact', ['email' => '', 'phone' => '']),
                'mail' => [
                    'from_name' => $mail['from_name'] ?? (string) config('mail.from.name', 'Tenant'),
                    'from_address' => $mail['from_address'] ?? (string) config('mail.from.address', 'noreply@example.com'),
                    'reply_to' => $mail['reply_to'] ?? '',
                    'mailer' => $mail['mailer'] ?? (string) config('mail.default', 'smtp'),
                    'host' => $mail['host'] ?? (string) config('mail.mailers.smtp.host', ''),
                    'port' => (int) ($mail['port'] ?? config('mail.mailers.smtp.port', 587)),
                    'username' => $mail['username'] ?? (string) config('mail.mailers.smtp.username', ''),
                    'encryption' => $mail['encryption'] ?? ((string) (config('mail.mailers.smtp.encryption') ?? '') === '' ? 'none' : (string) config('mail.mailers.smtp.encryption')),
                    'use_auth' => array_key_exists('use_auth', $mail)
                        ? (bool) $mail['use_auth']
                        : ((string) config('mail.mailers.smtp.username', '') !== ''),
                    'has_password' => array_key_exists('password', $mail) && is_string($mail['password']) && trim($mail['password']) !== '',
                ],
            ],
        ]);
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'language' => ['nullable', 'string', 'max:8'],
            'default_theme' => ['nullable', 'string', 'max:50'],
            'dashboard_user_trend_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'dashboard_allowed_widgets' => ['nullable', 'array', 'max:200'],
            'dashboard_allowed_widgets.*' => ['string', 'max:120'],
            'license_api_url' => ['nullable', 'url'],
            'core_tenant_uuid' => ['nullable', 'uuid'],
            'contact' => ['nullable', 'array'],
            'mail' => ['nullable', 'array'],
            'mail.from_name' => ['nullable', 'string', 'max:120'],
            'mail.from_address' => ['nullable', 'email:rfc', 'max:255'],
            'mail.reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'mail.mailer' => ['nullable', 'string', 'max:30'],
            'mail.host' => ['nullable', 'string', 'max:255'],
            'mail.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:255'],
            'mail.encryption' => ['nullable', 'in:none,ssl,tls,starttls'],
            'mail.use_auth' => ['nullable', 'boolean'],
        ]);

        if (isset($payload['mail']) && is_array($payload['mail'])) {
            $currentMail = $this->setting('mail', []);
            if (!is_array($currentMail)) {
                $currentMail = [];
            }

            $incomingMail = $payload['mail'];
            if (array_key_exists('password', $incomingMail) && trim((string) $incomingMail['password']) === '') {
                unset($incomingMail['password']);
            }

            if (isset($incomingMail['encryption']) && $incomingMail['encryption'] === 'none') {
                $incomingMail['encryption'] = null;
            }

            $payload['mail'] = array_merge($currentMail, $incomingMail);
        }

        foreach ($payload as $key => $value) {
            if ($key === 'dashboard_allowed_widgets' && is_array($value)) {
                $value = array_values(array_unique(array_filter(
                    array_map(static fn (mixed $entry): string => trim((string) $entry), $value),
                    static fn (string $entry): bool => $entry !== ''
                )));
            }

            TenantSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value_json' => $value]
            );
        }

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'settings.general.updated', ['keys' => array_keys($payload)]);

        return $this->getGeneral();
    }

    public function sendTestMail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'to' => ['nullable', 'email:rfc'],
        ]);

        $to = $payload['to'] ?? null;
        if ($to === null) {
            /** @var User|null $user */
            $user = $request->attributes->get('auth.user');
            $to = $user?->email;
        }

        if (!$to) {
            return response()->json(['message' => 'No recipient available for test mail'], 422);
        }

        Mail::to($to)->send(new SettingsTestMail());

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'settings.mail.test_sent', ['to' => $to]);

        return response()->json([
            'status' => 'sent',
            'to' => $to,
        ]);
    }

    public function getLicenses(): JsonResponse
    {
        $consumedByModule = $this->consumedSeatsByModuleSlug();

        $entitlements = ModuleEntitlement::query()->orderBy('module_slug')->get()->map(fn (ModuleEntitlement $item): array => [
            'module_slug' => $item->module_slug,
            'active' => $item->active,
            'seats' => $item->seats,
            'consumed_seats' => (int) ($consumedByModule[(string) $item->module_slug] ?? 0),
            'source' => $item->source,
            'license_key' => $item->license_key,
            'valid_until' => optional($item->valid_until)->toISOString(),
        ]);

        $lastSyncRaw = $this->setting('last_license_sync', null);
        $lastSync = is_array($lastSyncRaw) ? $lastSyncRaw : [];
        $internalSyncToken = trim((string) env('CORE_TO_TENANT_SYNC_TOKEN', ''));
        $fallbackEnabled = filter_var((string) env('AUTO_LICENSE_SYNC_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

        return response()->json([
            'data' => [
                'active_subscription' => $this->setting('active_subscription', null),
                'modules' => $entitlements,
                'sync_status' => [
                    'last_synced_at' => isset($lastSync['at']) && is_string($lastSync['at']) ? $lastSync['at'] : null,
                    'last_synced_count' => isset($lastSync['count']) ? (int) $lastSync['count'] : 0,
                    'last_tenant_ref' => isset($lastSync['tenant_ref']) && is_string($lastSync['tenant_ref']) ? $lastSync['tenant_ref'] : null,
                    'last_trigger' => isset($lastSync['trigger']) && is_string($lastSync['trigger']) ? $lastSync['trigger'] : null,
                    'webhook_configured' => $internalSyncToken !== '',
                    'fallback_scheduler_enabled' => $fallbackEnabled,
                ],
            ],
        ]);
    }

    public function syncLicenses(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_uuid' => ['nullable', 'uuid'],
        ]);

        return $this->performLicenseSync(
            tenantUuid: isset($payload['tenant_uuid']) ? (string) $payload['tenant_uuid'] : null,
            actorUserUuid: (string) $request->attributes->get('auth.user_uuid'),
            trigger: 'manual'
        );
    }

    public function activateLicense(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_uuid' => ['nullable', 'uuid'],
            'license_key' => ['required', 'string', 'max:160'],
        ]);

        try {
            $core = $this->coreConfig(
                tenantUuid: isset($payload['tenant_uuid']) ? (string) $payload['tenant_uuid'] : null
            );
            $inventory = $this->coreApiRequest($core['base_url'], $core['token'], 'get', '/tenants/'.$core['tenant_ref'].'/module-entitlements');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'License activation lookup failed',
                'error' => $exception->getMessage(),
            ], 502);
        }

        $licenseKey = strtoupper(trim((string) $payload['license_key']));
        $rows = is_array($inventory['data'] ?? null) ? $inventory['data'] : [];
        $matched = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            $rowKey = strtoupper(trim((string) ($row['license_key'] ?? '')));
            if ($source === 'license' && $rowKey !== '' && hash_equals($rowKey, $licenseKey)) {
                $matched = $row;
                break;
            }
        }

        if ($matched === null) {
            return response()->json(['message' => 'License key not found for this tenant'], 422);
        }

        if (isset($matched['valid_until']) && is_string($matched['valid_until']) && $matched['valid_until'] !== '') {
            try {
                if (Carbon::parse($matched['valid_until'])->isPast()) {
                    return response()->json(['message' => 'License key is expired'], 422);
                }
            } catch (Throwable) {
                // ignore parse errors here and continue with sync
            }
        }

        $sync = $this->performLicenseSync(
            tenantUuid: $core['tenant_ref'],
            actorUserUuid: (string) $request->attributes->get('auth.user_uuid'),
            trigger: 'manual_activation'
        );
        if ($sync->getStatusCode() >= 400) {
            return $sync;
        }

        $this->audit((string) $request->attributes->get('auth.user_uuid'), 'settings.license.activated', [
            'tenant_ref' => $core['tenant_ref'],
            'module_slug' => $matched['module_slug'] ?? null,
            'source' => $matched['source'] ?? null,
        ]);

        return response()->json([
            'status' => 'ok',
            'tenant_ref' => $core['tenant_ref'],
            'module_slug' => $matched['module_slug'] ?? null,
            'source' => $matched['source'] ?? null,
            'valid_until' => $matched['valid_until'] ?? null,
        ]);
    }

    public function getCoreLicenseInventory(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_uuid' => ['nullable', 'uuid'],
        ]);

        try {
            $core = $this->coreConfig(
                tenantUuid: isset($payload['tenant_uuid']) ? (string) $payload['tenant_uuid'] : null
            );
            $subscriptionsResponse = $this->coreApiRequest($core['base_url'], $core['token'], 'get', '/tenants/'.$core['tenant_ref'].'/subscriptions');
            $entitlementsResponse = $this->coreApiRequest($core['base_url'], $core['token'], 'get', '/tenants/'.$core['tenant_ref'].'/module-entitlements');
            $modulesResponse = $this->coreApiRequest($core['base_url'], $core['token'], 'get', '/modules');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Core inventory fetch failed',
                'error' => $exception->getMessage(),
            ], 502);
        }

        $now = Carbon::now();
        $subscriptions = collect((array) $subscriptionsResponse['data'])
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item) use ($now): array {
                $status = (string) ($item['status'] ?? '');
                $endedAt = isset($item['ended_at']) && is_string($item['ended_at']) ? $item['ended_at'] : null;
                $isActive = $status === 'active' && ($endedAt === null || Carbon::parse($endedAt)->greaterThan($now));

                return [
                    'uuid' => (string) ($item['uuid'] ?? ''),
                    'plan' => (string) ($item['plan'] ?? ''),
                    'status' => $status,
                    'started_at' => $item['started_at'] ?? null,
                    'ended_at' => $endedAt,
                    'changed_at' => $item['changed_at'] ?? null,
                    'is_active' => $isActive,
                ];
            })
            ->values()
            ->all();

        $hasActiveSubscription = collect($subscriptions)
            ->contains(fn (array $subscription): bool => (bool) ($subscription['is_active'] ?? false));

        $entitlements = collect((array) $entitlementsResponse['data'])
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item) use ($now, $hasActiveSubscription): array {
                $validUntil = isset($item['valid_until']) && is_string($item['valid_until']) ? $item['valid_until'] : null;
                $source = (string) ($item['source'] ?? '');
                $isNotExpired = $validUntil === null || Carbon::parse($validUntil)->greaterThanOrEqualTo($now);
                $isActive = $source === 'subscription'
                    ? ($hasActiveSubscription && $isNotExpired)
                    : $isNotExpired;

                return [
                    'uuid' => (string) ($item['uuid'] ?? ''),
                    'module_uuid' => (string) ($item['module_uuid'] ?? ''),
                    'module_slug' => (string) ($item['module_slug'] ?? ''),
                    'module_name' => (string) ($item['module_name'] ?? ''),
                    'source' => $source,
                    'seats' => (int) ($item['seats'] ?? 0),
                    'consumed_seats' => (int) ($item['consumed_seats'] ?? 0),
                    'license_key' => isset($item['license_key']) && is_string($item['license_key']) ? $item['license_key'] : null,
                    'valid_until' => $validUntil,
                    'is_active' => $isActive,
                ];
            })
            ->values()
            ->all();

        $modules = collect((array) $modulesResponse['data'])
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'uuid' => (string) ($item['uuid'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'slug' => (string) ($item['slug'] ?? ''),
                'is_active' => (bool) ($item['is_active'] ?? false),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'tenant_ref' => $core['tenant_ref'],
                'subscriptions' => $subscriptions,
                'entitlements' => $entitlements,
                'modules' => $modules,
            ],
        ]);
    }

    public function internalSyncLicenses(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_uuid' => ['nullable', 'uuid'],
            'trigger' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->performLicenseSync(
            tenantUuid: isset($payload['tenant_uuid']) ? (string) $payload['tenant_uuid'] : null,
            actorUserUuid: null,
            trigger: isset($payload['trigger']) && is_string($payload['trigger']) ? $payload['trigger'] : 'core_push'
        );
    }

    public function context(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');

        $permissions = (array) $request->attributes->get('permissions', $user->permissionNames());
        $entitlements = ModuleEntitlement::query()
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->pluck('module_slug')
            ->all();

        $trendDays = max(1, min(365, (int) $this->setting('dashboard_user_trend_days', 7)));
        $totalUsers = User::query()->count();
        $usersBeforePeriod = User::query()
            ->where('created_at', '<=', now()->subDays($trendDays))
            ->count();
        $deltaPercent = $usersBeforePeriod > 0
            ? (($totalUsers - $usersBeforePeriod) / $usersBeforePeriod) * 100
            : ($totalUsers > 0 ? 100.0 : 0.0);

        return response()->json([
            'data' => [
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
                    'assigned_module_slugs' => $this->moduleSeatService->assignedModuleSlugsForUser($user),
                ],
                'permissions' => $permissions,
                'entitlements' => $entitlements,
                'dashboard_widget_allowlist' => $this->stringArraySetting('dashboard_allowed_widgets'),
                'dashboard_widget_allowlist_configured' => $this->settingExists('dashboard_allowed_widgets'),
                'notifications_stream_enabled' => filter_var((string) env('NOTIFICATIONS_STREAM_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
                'dashboard_stats' => [
                    'total_users' => $totalUsers,
                    'active_users' => $totalUsers,
                    'user_trend_percent' => round($deltaPercent, 1),
                    'trend_days' => $trendDays,
                ],
            ],
        ]);
    }

    /**
     * @return mixed
     */
    private function setting(string $key, mixed $default)
    {
        return TenantSetting::query()->where('key', $key)->value('value_json') ?? $default;
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->setting($key, $default);

        if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
            return $value['url'];
        }

        return is_string($value) ? $value : $default;
    }

    private function settingExists(string $key): bool
    {
        return TenantSetting::query()->where('key', $key)->exists();
    }

    /**
     * @return array<int, string>
     */
    private function stringArraySetting(string $key): array
    {
        $value = $this->setting($key, []);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $value),
            static fn (string $entry): bool => $entry !== ''
        )));
    }

    private function performLicenseSync(?string $tenantUuid, ?string $actorUserUuid, string $trigger): JsonResponse
    {
        try {
            $core = $this->coreConfig($tenantUuid);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $sync = $this->coreLicenseSyncService->sync($core['tenant_ref'], $core['base_url'], $core['token']);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'License sync failed',
                'error' => $exception->getMessage(),
            ], 502);
        }

        $syncedAt = now()->toISOString();
        TenantSetting::query()->updateOrCreate(
            ['key' => 'active_subscription'],
            ['value_json' => $sync['active_subscription'] ?? null]
        );
        TenantSetting::query()->updateOrCreate(
            ['key' => 'last_license_sync'],
            ['value_json' => ['at' => $syncedAt, 'tenant_ref' => $core['tenant_ref'], 'count' => $sync['synced_count'], 'trigger' => $trigger]]
        );
        try {
            $this->coreModuleUsageSyncService->push();
        } catch (Throwable $exception) {
            // Keep license sync successful even if usage report fails.
            Log::warning('Core module usage sync failed after license sync', [
                'message' => $exception->getMessage(),
            ]);
        }

        $this->audit($actorUserUuid, 'settings.licenses.synced', [
            'at' => $syncedAt,
            'tenant_ref' => $core['tenant_ref'],
            'count' => $sync['synced_count'],
            'url' => $sync['fetched_url'],
            'trigger' => $trigger,
        ]);

        return response()->json([
            'status' => 'ok',
            'synced_at' => $syncedAt,
            'synced_count' => $sync['synced_count'],
            'tenant_ref' => $core['tenant_ref'],
            'trigger' => $trigger,
        ]);
    }

    /**
     * @return array{tenant_ref:string,base_url:string,token:string}
     */
    private function coreConfig(?string $tenantUuid): array
    {
        $effectiveTenantRef = '';
        $uuid = is_string($tenantUuid) ? trim($tenantUuid) : '';
        if ($uuid === '') {
            $uuid = $this->stringSetting('core_tenant_uuid', '');
        }
        if ($uuid !== '') {
            $effectiveTenantRef = $uuid;
        }

        if ($effectiveTenantRef === '') {
            throw new RuntimeException('Missing core tenant reference for core operation');
        }

        $baseUrl = $this->stringSetting('license_api_url', (string) env('LICENSE_API_URL', 'http://127.0.0.1:8000/api/core'));
        if ($baseUrl === '') {
            throw new RuntimeException('Missing core license API URL');
        }

        $token = trim($this->stringSetting('core_api_token', (string) env('CORE_API_TOKEN', '')));
        if ($token === '') {
            $token = trim((string) env('CORE_API_TOKEN', ''));
        }
        if ($token === '') {
            throw new RuntimeException('Missing core API token');
        }

        return [
            'tenant_ref' => $effectiveTenantRef,
            'base_url' => rtrim($baseUrl, '/'),
            'token' => $token,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function coreApiRequest(string $baseUrl, string $token, string $method, string $path, array $payload = []): array
    {
        $url = $baseUrl.'/'.ltrim($path, '/');

        $request = Http::acceptJson()->timeout(12)->withToken($token);
        $response = match (strtolower($method)) {
            'get' => $request->get($url, $payload),
            'post' => $request->post($url, $payload),
            'patch' => $request->patch($url, $payload),
            'put' => $request->put($url, $payload),
            'delete' => $request->delete($url, $payload),
            default => throw new RuntimeException('Unsupported core API method: '.$method),
        };

        if (!$response->successful()) {
            throw new RuntimeException('Core API '.$method.' '.$path.' failed with status '.$response->status());
        }

        $data = $response->json();
        return is_array($data) ? $data : [];
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
     * @return array<string, int>
     */
    private function consumedSeatsByModuleSlug(): array
    {
        if (!Schema::hasTable('user_module_entitlements')) {
            return [];
        }

        return UserModuleEntitlement::query()
            ->selectRaw('module_slug, COUNT(DISTINCT user_uuid) as consumed')
            ->groupBy('module_slug')
            ->pluck('consumed', 'module_slug')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }
}
