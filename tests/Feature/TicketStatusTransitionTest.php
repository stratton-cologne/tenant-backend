<?php

namespace Tests\Feature;

use App\Models\ModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserModuleEntitlement;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_status_transition_enforces_workflow_rules(): void
    {
        $tenantId = '33333333-3333-4333-8333-333333333333';
        $token = $this->issueTokenWithPermissions(['tickets.status.update'], $tenantId);

        $ticketUuid = (string) Str::uuid();
        DB::table('tickets')->insert([
            'tenant_id' => $tenantId,
            'uuid' => $ticketUuid,
            'number' => 'TKT-2026-0001',
            'title' => 'Workflow test',
            'description' => 'Status transitions should be validated',
            'status' => 'open',
            'priority' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $valid = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/status', ['status' => 'in_progress']);
        $valid->assertOk();
        $valid->assertJsonPath('data.status', 'in_progress');

        $invalid = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/status', ['status' => 'new']);
        $invalid->assertStatus(422);
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
        UserModuleEntitlement::query()->updateOrCreate(
            ['user_uuid' => (string) $user->uuid, 'module_slug' => 'tickets'],
            ['assigned_by_uuid' => (string) $user->uuid]
        );

        return app(JwtService::class)->issue([
            'sub' => (string) $user->uuid,
            'type' => 'tenant_access',
        ]);
    }
}
