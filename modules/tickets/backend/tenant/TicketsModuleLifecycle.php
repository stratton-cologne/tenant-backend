<?php

namespace Modules\Tickets\Tenant;

use App\Models\Tickets\TicketSlaPolicy;
use App\Models\TenantSetting;
use App\Modules\Contracts\ModuleLifecycle;
use App\Modules\Lifecycle\ModuleLifecycleContext;

class TicketsModuleLifecycle implements ModuleLifecycle
{
    public function install(ModuleLifecycleContext $context): void
    {
        $tenantId = $this->tenantId();
        foreach (['low' => [480, 2880], 'medium' => [240, 1440], 'high' => [60, 480], 'urgent' => [30, 240]] as $priority => $times) {
            TicketSlaPolicy::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'priority' => $priority],
                ['first_response_minutes' => $times[0], 'resolve_minutes' => $times[1], 'is_active' => true]
            );
        }
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

    private function tenantId(): string
    {
        $setting = TenantSetting::query()->where('key', 'core_tenant_uuid')->first();
        $value = is_array($setting?->value_json) ? ($setting->value_json['value'] ?? null) : $setting?->value_json;
        return is_string($value) && trim($value) !== '' ? trim($value) : 'local-tenant';
    }
}
