<?php

namespace App\Providers;

use App\Modules\ModuleLifecycleRunner;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleStateStore;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TenantModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, static fn (): ModuleRegistry => new ModuleRegistry());
        $this->app->singleton(ModuleLifecycleRunner::class, static fn (): ModuleLifecycleRunner => new ModuleLifecycleRunner());
        $this->app->singleton(ModuleStateStore::class, static fn (): ModuleStateStore => new ModuleStateStore());
    }

    public function boot(ModuleRegistry $moduleRegistry): void
    {
        $this->registerModuleAutoload($moduleRegistry);

        $migrationPaths = $moduleRegistry->collectTenantMigrationPaths();
        if ($migrationPaths !== []) {
            $this->loadMigrationsFrom($migrationPaths);
        }

        foreach ($moduleRegistry->collectViewPathsByNamespace() as $namespace => $paths) {
            $this->loadViewsFrom($paths, $namespace);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($moduleRegistry->collectApiRouteFiles() as $apiRouteFile) {
            Route::prefix('api')->middleware('api')->group($apiRouteFile);
        }
    }

    private function registerModuleAutoload(ModuleRegistry $moduleRegistry): void
    {
        $mappings = $moduleRegistry->collectPsr4AutoloadMappings();
        if ($mappings === []) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($mappings): void {
            foreach ($mappings as $mapping) {
                $prefix = $mapping['prefix'];
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));
                if ($relative === false) {
                    continue;
                }

                $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
                $file = rtrim($mapping['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath;
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }
}
