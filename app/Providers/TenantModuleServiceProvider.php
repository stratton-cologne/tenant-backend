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
        $migrationPaths = $moduleRegistry->collectTenantMigrationPaths();
        if ($migrationPaths !== []) {
            $this->loadMigrationsFrom($migrationPaths);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($moduleRegistry->collectApiRouteFiles() as $apiRouteFile) {
            Route::prefix('api')->middleware('api')->group($apiRouteFile);
        }
    }
}
