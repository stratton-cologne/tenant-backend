<?php

namespace App\Modules;

use App\Models\TenantSetting;

class ModuleStateStore
{
    /**
     * @return array<string, array{
     *   installed:bool,
     *   enabled:bool,
     *   version:string,
     *   installed_at:?string,
     *   updated_at:?string
     * }>
     */
    public function all(): array
    {
        $value = TenantSetting::query()->where('key', 'module_states')->value('value_json');
        if (!is_array($value)) {
            return [];
        }

        $states = [];
        foreach ($value as $slug => $rawState) {
            if (!is_string($slug) || !is_array($rawState)) {
                continue;
            }

            $states[$slug] = [
                'installed' => (bool) ($rawState['installed'] ?? false),
                'enabled' => (bool) ($rawState['enabled'] ?? false),
                'version' => trim((string) ($rawState['version'] ?? '')),
                'installed_at' => isset($rawState['installed_at']) ? (string) $rawState['installed_at'] : null,
                'updated_at' => isset($rawState['updated_at']) ? (string) $rawState['updated_at'] : null,
            ];
        }

        ksort($states);

        return $states;
    }

    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    public function markInstalled(string $slug, string $version): void
    {
        $now = now()->toISOString();
        $state = $this->get($slug) ?? [];

        $this->put($slug, [
            'installed' => true,
            'enabled' => true,
            'version' => trim($version),
            'installed_at' => $state['installed_at'] ?? $now,
            'updated_at' => $now,
        ]);
    }

    public function markEnabled(string $slug, ?string $version = null): void
    {
        $state = $this->get($slug) ?? [];
        $this->put($slug, [
            'installed' => true,
            'enabled' => true,
            'version' => $version !== null ? trim($version) : (string) ($state['version'] ?? ''),
            'installed_at' => $state['installed_at'] ?? now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);
    }

    public function markDisabled(string $slug): void
    {
        $state = $this->get($slug) ?? [];
        $this->put($slug, [
            'installed' => (bool) ($state['installed'] ?? true),
            'enabled' => false,
            'version' => (string) ($state['version'] ?? ''),
            'installed_at' => isset($state['installed_at']) ? (string) $state['installed_at'] : now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);
    }

    public function markUninstalled(string $slug): void
    {
        $states = $this->all();
        unset($states[$slug]);
        $this->persist($states);
    }

    public function installedSlugs(): array
    {
        $states = $this->all();
        $slugs = [];

        foreach ($states as $slug => $state) {
            if (($state['installed'] ?? false) === true) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param array{installed:bool,enabled:bool,version:string,installed_at:?string,updated_at:?string} $state
     */
    private function put(string $slug, array $state): void
    {
        $states = $this->all();
        $states[trim($slug)] = $state;
        ksort($states);

        $this->persist($states);
    }

    /**
     * @param array<string, mixed> $states
     */
    private function persist(array $states): void
    {
        TenantSetting::query()->updateOrCreate(
            ['key' => 'module_states'],
            ['value_json' => $states]
        );

        TenantSetting::query()->updateOrCreate(
            ['key' => 'installed_modules'],
            ['value_json' => array_values(array_unique($this->installedSlugsFrom($states)))]
        );
    }

    /**
     * @param array<string, mixed> $states
     * @return array<int, string>
     */
    private function installedSlugsFrom(array $states): array
    {
        $slugs = [];
        foreach ($states as $slug => $state) {
            if (!is_string($slug) || !is_array($state)) {
                continue;
            }
            if (($state['installed'] ?? false) === true) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);

        return $slugs;
    }
}

