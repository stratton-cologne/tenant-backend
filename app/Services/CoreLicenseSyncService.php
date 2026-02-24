<?php

namespace App\Services;

use App\Models\ModuleEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreLicenseSyncService
{
    /**
     * @return array{synced_count:int, fetched_url:string, active_subscription:?string}
     */
    public function sync(string $tenantRef, string $coreApiBaseUrl, ?string $bearerToken = null): array
    {
        $base = rtrim($coreApiBaseUrl, '/');
        $url = $base.'/tenants/'.$tenantRef.'/entitlements';

        $request = Http::acceptJson()->timeout(12);
        if (is_string($bearerToken) && $bearerToken !== '') {
            $request = $request->withToken($bearerToken);
        }

        $response = $request->get($url);
        if (!$response->successful()) {
            throw new RuntimeException('Core sync failed with status '.$response->status());
        }

        $modules = $response->json('modules');
        if (!is_array($modules)) {
            throw new RuntimeException('Core sync response has invalid modules payload');
        }
        $activeSubscription = $response->json('active_subscription');
        if (!is_string($activeSubscription)) {
            $activeSubscription = null;
        }

        $syncedCount = DB::transaction(function () use ($modules): int {
            $seenModuleSlugs = [];
            $count = 0;

            foreach ($modules as $moduleSlug => $entry) {
                if (!is_string($moduleSlug) || !is_array($entry)) {
                    continue;
                }

                $seenModuleSlugs[] = $moduleSlug;
                $active = (bool) ($entry['active'] ?? false);
                $seats = max(0, (int) ($entry['seats'] ?? 0));
                $sources = $this->normalizeSources((array) ($entry['sources'] ?? []));
                $licenseKeys = array_values(array_filter((array) ($entry['license_keys'] ?? []), 'is_string'));
                $primaryLicenseKey = $licenseKeys[0] ?? null;

                foreach ($sources as $source) {
                    ModuleEntitlement::query()->updateOrCreate(
                        ['module_slug' => $moduleSlug, 'source' => $source],
                        [
                            'active' => $active,
                            'seats' => $seats,
                            'license_key' => $source === 'license' ? $primaryLicenseKey : null,
                            'valid_until' => null,
                        ]
                    );
                }

                ModuleEntitlement::query()
                    ->where('module_slug', $moduleSlug)
                    ->whereNotIn('source', $sources)
                    ->delete();

                $count++;
            }

            if ($seenModuleSlugs === []) {
                ModuleEntitlement::query()->delete();
            } else {
                ModuleEntitlement::query()->whereNotIn('module_slug', $seenModuleSlugs)->delete();
            }

            return $count;
        });

        return [
            'synced_count' => $syncedCount,
            'fetched_url' => $url,
            'active_subscription' => $activeSubscription,
        ];
    }

    /**
     * @param array<int, mixed> $sources
     * @return array<int, string>
     */
    private function normalizeSources(array $sources): array
    {
        $filtered = array_values(array_unique(array_filter($sources, fn ($source): bool => in_array($source, ['subscription', 'license'], true))));
        return $filtered === [] ? ['subscription'] : $filtered;
    }
}
