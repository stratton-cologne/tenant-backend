<?php

namespace App\Services\Tickets;

use App\Models\TenantSetting;

class TicketTenantContext
{
    public function tenantId(): string
    {
        $setting = TenantSetting::query()->where('key', 'core_tenant_uuid')->first();
        $value = is_array($setting?->value_json) ? null : $setting?->value_json;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($setting?->value_json)) {
            $raw = $setting->value_json['value'] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }

        return 'local-tenant';
    }
}
