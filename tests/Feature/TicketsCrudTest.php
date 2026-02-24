<?php

namespace Tests\Feature;

use App\Models\ModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TicketsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_crud_flow_works(): void
    {
        $tenantId = '22222222-2222-4222-8222-222222222222';
        $token = $this->issueTokenWithPermissions([
            'tickets.read',
            'tickets.create',
            'tickets.update',
            'tickets.delete',
        ], $tenantId);

        $create = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets', [
                'title' => 'Cannot open billing invoice',
                'description' => 'Invoice download throws 500',
                'priority' => 'high',
            ]);

        $create->assertCreated();
        $ticketUuid = (string) $create->json('data.uuid');
        $ticketNo = (string) $create->json('data.ticket_no');
        $this->assertNotSame('', $ticketUuid);
        $this->assertNotSame('', $ticketNo);

        $list = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/tickets');
        $list->assertOk();
        $list->assertJsonPath('data.0.uuid', $ticketUuid);

        $update = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/tenant/tickets/'.$ticketUuid, [
                'title' => 'Cannot open billing invoice (updated)',
            ]);
        $update->assertOk();
        $update->assertJsonPath('data.title', 'Cannot open billing invoice (updated)');

        $delete = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson('/api/tenant/tickets/'.$ticketUuid);
        $delete->assertOk();
        $delete->assertJsonPath('status', 'ok');
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
            'first_name' => 'Ticket',
            'last_name' => 'Admin',
            'email' => 'ticket-crud@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'ticket-crud-role']);
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
