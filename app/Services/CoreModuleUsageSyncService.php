<?php

namespace App\Services;

use App\Models\TenantSetting;
use App\Models\UserModuleEntitlement;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreModuleUsageSyncService
{
    public function push(): void
    {
        $tenantUuid = $this->stringSetting('core_tenant_uuid', '');
        if ($tenantUuid === '') {
            return;
        }

        $base = rtrim($this->stringSetting('license_api_url', (string) env('LICENSE_API_URL', 'http://127.0.0.1:8000/api/core')), '/');
        if ($base === '') {
            return;
        }

        $token = trim($this->stringSetting('core_api_token', (string) env('CORE_API_TOKEN', '')));
        if ($token === '') {
            return;
        }

        $rows = UserModuleEntitlement::query()
            ->selectRaw('module_slug, COUNT(DISTINCT user_uuid) as consumed')
            ->groupBy('module_slug')
            ->pluck('consumed', 'module_slug')
            ->map(static fn (mixed $value, string $slug): array => [
                'module_slug' => $slug,
                'consumed_seats' => (int) $value,
                'reported_at' => now()->toISOString(),
            ])
            ->values()
            ->all();

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(12)
            ->put($base.'/tenants/'.$tenantUuid.'/module-usage', [
                'modules' => $rows,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Core usage sync failed with status '.$response->status());
        }
    }

    private function stringSetting(string $key, string $fallback = ''): string
    {
        $value = TenantSetting::query()->where('key', $key)->value('value_json');
        if (is_array($value) && isset($value['value']) && is_scalar($value['value'])) {
            $value = $value['value'];
        }

        if (!is_scalar($value) || trim((string) $value) === '') {
            return $fallback;
        }

        return trim((string) $value);
    }
}
