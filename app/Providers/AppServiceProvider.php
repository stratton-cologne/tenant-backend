<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('tickets.read', fn (User $user, array $permissions = []): bool => in_array('tickets.read', $permissions, true));
        Gate::define('tickets.create', fn (User $user, array $permissions = []): bool => in_array('tickets.create', $permissions, true));
        Gate::define('tickets.update', fn (User $user, array $permissions = []): bool => in_array('tickets.update', $permissions, true));
        Gate::define('tickets.delete', fn (User $user, array $permissions = []): bool => in_array('tickets.delete', $permissions, true));
        Gate::define('tickets.assign', fn (User $user, array $permissions = []): bool => in_array('tickets.assign', $permissions, true));
        Gate::define('tickets.status.update', fn (User $user, array $permissions = []): bool => in_array('tickets.status.update', $permissions, true));
    }
}
