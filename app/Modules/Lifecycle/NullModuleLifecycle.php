<?php

namespace App\Modules\Lifecycle;

use App\Modules\Contracts\ModuleLifecycle;

class NullModuleLifecycle implements ModuleLifecycle
{
    public function install(ModuleLifecycleContext $context): void
    {
    }

    public function enable(ModuleLifecycleContext $context): void
    {
    }

    public function disable(ModuleLifecycleContext $context): void
    {
    }

    public function uninstall(ModuleLifecycleContext $context): void
    {
    }

    public function upgrade(ModuleLifecycleContext $context, ?string $fromVersion, string $toVersion): void
    {
    }
}

