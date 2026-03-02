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

class TicketSlaBreachesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_breaches_report_returns_breached_tickets(): void
    {
        $tenantId = '44444444-4444-4444-8444-444444444444';
        $token = $this->issueTokenWithPermissions(['tickets.report.read'], $tenantId);

        DB::table('tickets')->insert([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'number' => 'TKT-2026-0009',
            'title' => 'SLA breach candidate',
            'description' => 'Created long ago and still open',
            'status' => 'in_progress',
            'priority' => 'low',
            'resolution_due_at' => now()->subHour(),
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/tickets/reports/summary');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('in_progress', 1);
        $response->assertJsonPath('sla_breaches', 1);
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
