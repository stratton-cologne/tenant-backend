<?php

namespace App\Modules\Contracts;

use App\Modules\Lifecycle\ModuleLifecycleContext;

interface ModuleLifecycle
{
    public function install(ModuleLifecycleContext $context): void;

    public function enable(ModuleLifecycleContext $context): void;

    public function disable(ModuleLifecycleContext $context): void;

    public function uninstall(ModuleLifecycleContext $context): void;

    public function upgrade(ModuleLifecycleContext $context, ?string $fromVersion, string $toVersion): void;
}

