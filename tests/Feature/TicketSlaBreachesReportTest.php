<?php

namespace Tests\Feature;

use App\Models\ModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketSlaPolicy;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TicketSlaBreachesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_breaches_report_returns_breached_tickets(): void
    {
        $tenantId = '44444444-4444-4444-8444-444444444444';
        $token = $this->issueTokenWithPermissions(['tickets.report.read'], $tenantId);

        TicketSlaPolicy::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'priority' => 'low'],
            ['first_response_minutes' => 1, 'resolve_minutes' => 1, 'is_active' => true]
        );

        $ticket = Ticket::query()->create([
            'tenant_id' => $tenantId,
            'ticket_no' => 'TKT-910001',
            'title' => 'SLA breach candidate',
            'description' => 'Created long ago and still open',
            'status' => 'in_progress',
            'priority' => 'low',
        ]);
        $ticket->created_at = now()->subHours(3);
        $ticket->updated_at = now()->subHours(3);
        $ticket->saveQuietly();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/tickets/reports/sla-breaches');

        $response->assertOk();
        $response->assertJsonPath('data.0.ticket_no', 'TKT-910001');
        $response->assertJsonPath('data.0.first_response_breached', true);
        $response->assertJsonPath('data.0.resolve_breached', true);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function issueTokenWithPermissions(array $permissions, string $tenantId): string
    {
        TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => ['value' => $tenantId]]);
        ModuleEntitlement::query()->updateOrCreate(
            ['module_slug' => 'tickets', 'source' => 'subscription'],
            ['active' => true, 'seats' => 25, 'valid_until' => null]
        );

        $user = User::query()->create([
            'first_name' => 'Report',
            'last_name' => 'Admin',
            'email' => 'ticket-report@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'ticket-report-role']);
        $permissionIds = collect($permissions)
            ->map(fn (string $name): int => Permission::query()->updateOrCreate(['name' => $name])->id)
            ->all();
        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);
    }
}
