<?php

use App\Models\TenantSetting;
use App\Models\Permission;
use App\Modules\Lifecycle\ModuleLifecycleContext;
use App\Modules\ModuleLifecycleRunner;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleStateStore;
use App\Services\CoreLicenseSyncService;
use Database\Seeders\TenantE2ESeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:health', function (): void {
    $this->info('tenant-backend healthy');
});

Artisan::command('app:seed-e2e-tenant', function (): void {
    Artisan::call('db:seed', [
        '--class' => TenantE2ESeeder::class,
        '--force' => true,
    ]);

    $licenseApiUrl = (string) env('LICENSE_API_URL', 'http://127.0.0.1:8000/api/core');
    $coreTenantUuid = trim((string) env('E2E_CORE_TENANT_UUID', ''));
    $coreApiToken = (string) env('CORE_API_TOKEN', 'e2e-core-token');

    TenantSetting::query()->updateOrCreate(['key' => 'license_api_url'], ['value_json' => $licenseApiUrl]);
    if ($coreTenantUuid !== '') {
        TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => $coreTenantUuid]);
    }
    TenantSetting::query()->updateOrCreate(['key' => 'core_api_token'], ['value_json' => $coreApiToken]);
    TenantSetting::query()->updateOrCreate(['key' => 'language'], ['value_json' => 'de']);
    TenantSetting::query()->updateOrCreate(['key' => 'default_theme'], ['value_json' => 'prototype']);
    TenantSetting::query()->updateOrCreate(['key' => 'dashboard_user_trend_days'], ['value_json' => 7]);

    $this->info('Tenant E2E seed complete');
    $this->line('email='.TenantE2ESeeder::ADMIN_EMAIL);
    $this->line('password='.TenantE2ESeeder::ADMIN_PASSWORD);
});

Artisan::command('licenses:sync {--tenant_uuid=}', function (): int {
    $tenantUuid = trim((string) ($this->option('tenant_uuid') ?: (TenantSetting::query()->where('key', 'core_tenant_uuid')->value('value_json') ?? '')));
    $tenantRef = $tenantUuid;

    if ($tenantRef === '') {
        $this->warn('licenses:sync skipped (missing core_tenant_uuid)');
        return self::FAILURE;
    }

    $licenseApiUrl = TenantSetting::query()->where('key', 'license_api_url')->value('value_json');
    $licenseApiUrl = is_string($licenseApiUrl) && trim($licenseApiUrl) !== '' ? trim($licenseApiUrl) : (string) env('LICENSE_API_URL', 'http://127.0.0.1:8000/api/core');

    $coreApiToken = TenantSetting::query()->where('key', 'core_api_token')->value('value_json');
    $coreApiToken = is_string($coreApiToken) && trim($coreApiToken) !== '' ? trim($coreApiToken) : trim((string) env('CORE_API_TOKEN', ''));

    /** @var CoreLicenseSyncService $syncService */
    $syncService = app(CoreLicenseSyncService::class);

    try {
        $sync = $syncService->sync($tenantRef, $licenseApiUrl, $coreApiToken);
    } catch (\Throwable $exception) {
        $this->error('licenses:sync failed: '.$exception->getMessage());
        return self::FAILURE;
    }

    TenantSetting::query()->updateOrCreate(
        ['key' => 'last_license_sync'],
        ['value_json' => ['at' => now()->toISOString(), 'tenant_ref' => $tenantRef, 'count' => $sync['synced_count'], 'trigger' => 'scheduler']]
    );

    $this->info('licenses:sync ok tenant='.$tenantRef.' modules='.$sync['synced_count']);

    return self::SUCCESS;
});

Artisan::command('modules:install {slug?} {--list} {--skip-migrations} {--skip-permissions}', function (ModuleRegistry $registry, ModuleLifecycleRunner $runner, ModuleStateStore $stateStore): int {
    $slug = trim((string) ($this->argument('slug') ?? ''));
    $manifests = $registry->discover();

    if ((bool) $this->option('list') || $slug === '') {
        if ($manifests === []) {
            $this->warn('No module manifests found in tenant-backend/modules');
            return self::SUCCESS;
        }

        $rows = collect($manifests)->map(
            fn (array $module): array => [
                $module['slug'],
                $module['name'],
                $module['version'],
                (string) count($module['permissions']),
                (string) count($module['tenant_backend']['migrations']),
                (($stateStore->get($module['slug'])['enabled'] ?? false) === true) ? 'yes' : 'no',
            ]
        )->values()->all();

        $this->table(['Slug', 'Name', 'Version', 'Permissions', 'Migration paths', 'Enabled'], $rows);
        return self::SUCCESS;
    }

    $module = $manifests[$slug] ?? null;
    if (!is_array($module)) {
        $this->error('Module not found: '.$slug);
        return self::FAILURE;
    }

    if (!(bool) $this->option('skip-permissions')) {
        foreach ($module['permissions'] as $permissionName) {
            Permission::query()->updateOrCreate(['name' => $permissionName]);
        }
        $this->info('Permissions synced: '.count($module['permissions']));
    }

    if (!(bool) $this->option('skip-migrations')) {
        foreach ($registry->collectTenantMigrationPaths($slug) as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
                '--force' => true,
            ]);
            $this->line(trim(Artisan::output()));
        }
    }

    $context = new ModuleLifecycleContext(
        $slug,
        $module,
        fn (string $message): mixed => $this->line($message)
    );
    $runner->install($module, $context);
    $runner->enable($module, $context);

    $stateStore->markInstalled($slug, (string) ($module['version'] ?? ''));
    $this->info('Module installed: '.$slug);

    return self::SUCCESS;
});

Artisan::command('modules:enable {slug}', function (string $slug, ModuleRegistry $registry, ModuleLifecycleRunner $runner, ModuleStateStore $stateStore): int {
    $slug = trim($slug);
    $module = $registry->find($slug);
    if (!is_array($module)) {
        $this->error('Module not found: '.$slug);
        return self::FAILURE;
    }

    $context = new ModuleLifecycleContext(
        $slug,
        $module,
        fn (string $message): mixed => $this->line($message)
    );
    $runner->enable($module, $context);
    $stateStore->markEnabled($slug, (string) ($module['version'] ?? ''));

    $this->info('Module enabled: '.$slug);
    return self::SUCCESS;
});

Artisan::command('modules:disable {slug}', function (string $slug, ModuleRegistry $registry, ModuleLifecycleRunner $runner, ModuleStateStore $stateStore): int {
    $slug = trim($slug);
    $module = $registry->find($slug);
    if (!is_array($module)) {
        $this->error('Module not found: '.$slug);
        return self::FAILURE;
    }

    $context = new ModuleLifecycleContext(
        $slug,
        $module,
        fn (string $message): mixed => $this->line($message)
    );
    $runner->disable($module, $context);
    $stateStore->markDisabled($slug);

    $this->info('Module disabled: '.$slug);
    return self::SUCCESS;
});

Artisan::command('modules:uninstall {slug} {--purge-permissions}', function (string $slug, ModuleRegistry $registry, ModuleLifecycleRunner $runner, ModuleStateStore $stateStore): int {
    $slug = trim($slug);
    $module = $registry->find($slug);
    if (!is_array($module)) {
        $this->error('Module not found: '.$slug);
        return self::FAILURE;
    }

    $context = new ModuleLifecycleContext(
        $slug,
        $module,
        fn (string $message): mixed => $this->line($message)
    );
    $runner->disable($module, $context);
    $runner->uninstall($module, $context);

    if ((bool) $this->option('purge-permissions')) {
        Permission::query()->whereIn('name', $module['permissions'])->delete();
        $this->info('Permissions removed: '.count($module['permissions']));
    }

    $stateStore->markUninstalled($slug);
    $this->info('Module uninstalled: '.$slug);
    return self::SUCCESS;
});

Artisan::command('modules:upgrade {slug} {--from=}', function (string $slug, ModuleRegistry $registry, ModuleLifecycleRunner $runner, ModuleStateStore $stateStore): int {
    $slug = trim($slug);
    $module = $registry->find($slug);
    if (!is_array($module)) {
        $this->error('Module not found: '.$slug);
        return self::FAILURE;
    }

    $state = $stateStore->get($slug);
    $fromVersion = trim((string) ($this->option('from') ?: ($state['version'] ?? '')));
    $fromVersion = $fromVersion !== '' ? $fromVersion : null;
    $toVersion = (string) ($module['version'] ?? '0.0.0');

    $context = new ModuleLifecycleContext(
        $slug,
        $module,
        fn (string $message): mixed => $this->line($message)
    );
    $runner->upgrade($module, $context, $fromVersion, $toVersion);
    $stateStore->markEnabled($slug, $toVersion);

    $this->info(sprintf('Module upgraded: %s (%s -> %s)', $slug, $fromVersion ?? 'unknown', $toVersion));
    return self::SUCCESS;
});

$autoSyncEnabled = filter_var((string) env('AUTO_LICENSE_SYNC_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($autoSyncEnabled !== false) {
    Schedule::command('licenses:sync')->everyFiveMinutes()->withoutOverlapping();
}
