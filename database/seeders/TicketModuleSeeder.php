<?php

namespace Database\Seeders;

use App\Models\ModuleEntitlement;
use App\Models\TenantSetting;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketCategory;
use App\Models\Tickets\TicketQueue;
use App\Models\Tickets\TicketSlaPolicy;
use App\Models\Tickets\TicketTag;
use App\Models\Tickets\TicketType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class TicketModuleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('tickets')) {
            return;
        }

        $tenantId = $this->tenantId();

        ModuleEntitlement::query()->updateOrCreate(
            ['module_slug' => 'tickets', 'source' => 'subscription'],
            ['active' => true, 'seats' => 50, 'valid_until' => null]
        );

        $types = [
            ['name' => 'Incident', 'slug' => 'incident'],
            ['name' => 'Service Request', 'slug' => 'service-request'],
            ['name' => 'Problem', 'slug' => 'problem'],
        ];
        foreach ($types as $type) {
            TicketType::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $type['slug']],
                ['name' => $type['name'], 'description' => $type['name'].' default type', 'is_active' => true]
            );
        }

        $categories = [
            ['name' => 'Platform', 'slug' => 'platform'],
            ['name' => 'Billing', 'slug' => 'billing'],
            ['name' => 'User Access', 'slug' => 'user-access'],
        ];
        foreach ($categories as $category) {
            TicketCategory::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $category['slug']],
                ['name' => $category['name'], 'description' => $category['name'].' category', 'is_active' => true]
            );
        }

        $tags = [
            ['name' => 'production', 'slug' => 'production', 'color' => '#ef4444'],
            ['name' => 'customer', 'slug' => 'customer', 'color' => '#22c55e'],
            ['name' => 'internal', 'slug' => 'internal', 'color' => '#3b82f6'],
        ];
        foreach ($tags as $tag) {
            TicketTag::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $tag['slug']],
                ['name' => $tag['name'], 'color' => $tag['color']]
            );
        }

        $queues = [
            ['name' => 'General Support', 'slug' => 'general-support'],
            ['name' => 'Technical Operations', 'slug' => 'technical-ops'],
            ['name' => 'Backoffice', 'slug' => 'backoffice'],
        ];
        foreach ($queues as $queue) {
            TicketQueue::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $queue['slug']],
                ['name' => $queue['name'], 'description' => $queue['name'].' queue', 'is_active' => true]
            );
        }

        $sla = [
            'low' => [480, 2880],
            'medium' => [240, 1440],
            'high' => [60, 480],
            'urgent' => [30, 240],
        ];
        foreach ($sla as $priority => [$first, $resolve]) {
            TicketSlaPolicy::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'priority' => $priority],
                [
                    'first_response_minutes' => $first,
                    'resolve_minutes' => $resolve,
                    'is_active' => true,
                ]
            );
        }

        $incidentType = TicketType::query()->where('tenant_id', $tenantId)->where('slug', 'incident')->first();
        $platformCategory = TicketCategory::query()->where('tenant_id', $tenantId)->where('slug', 'platform')->first();
        $supportQueue = TicketQueue::query()->where('tenant_id', $tenantId)->where('slug', 'general-support')->first();

        $demoTickets = [
            ['TKT-000001', 'Login MFA code not delivered', 'new', 'high'],
            ['TKT-000002', 'Invoice PDF cannot be downloaded', 'triage', 'medium'],
            ['TKT-000003', 'Performance degradation in dashboard', 'in_progress', 'urgent'],
            ['TKT-000004', 'Need new role for external auditor', 'waiting', 'low'],
            ['TKT-000005', 'Data export completed with warnings', 'resolved', 'medium'],
        ];

        foreach ($demoTickets as [$ticketNo, $title, $status, $priority]) {
            Ticket::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'ticket_no' => $ticketNo],
                [
                    'title' => $title,
                    'description' => $title.' (seeded demo ticket)',
                    'status' => $status,
                    'priority' => $priority,
                    'queue_id' => $supportQueue?->id,
                    'type_id' => $incidentType?->id,
                    'category_id' => $platformCategory?->id,
                    'first_response_at' => in_array($status, ['resolved', 'closed'], true) ? now()->subHours(8) : null,
                    'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now()->subHours(2) : null,
                ]
            );
        }
    }

    private function tenantId(): string
    {
        $setting = TenantSetting::query()->where('key', 'core_tenant_uuid')->first();
        $value = is_array($setting?->value_json) ? ($setting->value_json['value'] ?? null) : $setting?->value_json;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return 'local-tenant';
    }
}
