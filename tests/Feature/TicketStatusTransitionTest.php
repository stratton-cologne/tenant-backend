<?php

namespace Tests\Feature;

use App\Models\ModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\Tickets\Ticket;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TicketStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_status_transition_enforces_workflow_rules(): void
    {
        $tenantId = '33333333-3333-4333-8333-333333333333';
        $token = $this->issueTokenWithPermissions(['tickets.status.update'], $tenantId);

        $ticket = Ticket::query()->create([
            'tenant_id' => $tenantId,
            'ticket_no' => 'TKT-900001',
            'title' => 'Workflow test',
            'description' => 'Status transitions should be validated',
            'status' => 'new',
            'priority' => 'medium',
        ]);

        $valid = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets/'.$ticket->uuid.'/status', ['status' => 'triage']);
        $valid->assertOk();
        $valid->assertJsonPath('data.status', 'triage');

        $invalid = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets/'.$ticket->uuid.'/status', ['status' => 'new']);
        $invalid->assertStatus(422);
        $invalid->assertJsonPath('error.message', 'Invalid status transition');
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
            'first_name' => 'Status',
            'last_name' => 'Admin',
            'email' => 'ticket-status@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'ticket-status-role']);
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
