<?php

namespace Modules\Analytics\Tenant;

use App\Modules\Contracts\ModuleLifecycle;
use App\Modules\Lifecycle\ModuleLifecycleContext;

class AnalyticsModuleLifecycle implements ModuleLifecycle
{
    public function install(ModuleLifecycleContext $context): void
    {
        $context->line('[analytics] install hook executed');
    }

    public function enable(ModuleLifecycleContext $context): void
    {
        $context->line('[analytics] enable hook executed');
    }

    public function disable(ModuleLifecycleContext $context): void
    {
        $context->line('[analytics] disable hook executed');
    }

    public function uninstall(ModuleLifecycleContext $context): void
    {
        $context->line('[analytics] uninstall hook executed');
    }

    public function upgrade(ModuleLifecycleContext $context, ?string $fromVersion, string $toVersion): void
    {
        $context->line(sprintf('[analytics] upgrade hook executed (%s -> %s)', $fromVersion ?? 'unknown', $toVersion));
    }
}

