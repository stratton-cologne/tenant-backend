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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TicketCollaborationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_comment_assignment_relation_and_audit_flow_works(): void
    {
        $tenantId = '55555555-5555-4555-8555-555555555555';
        $primaryToken = $this->issueTokenWithPermissions([
            'tickets.read',
            'tickets.create',
            'tickets.update',
            'tickets.assign',
            'tickets.comment.create',
            'tickets.audit.read',
        ], $tenantId, 'ticket-collab-admin@example.com');

        $secondaryToken = $this->issueTokenWithPermissions([
            'tickets.read',
            'tickets.comment.create',
        ], $tenantId, 'ticket-collab-user@example.com');

        $create = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->postJson('/api/tenant/tickets', [
                'title' => 'Collaboration flow ticket',
                'description' => 'Checks comments, assignments and relations',
                'priority' => 'medium',
            ]);

        $create->assertCreated();
        $ticketUuid = (string) $create->json('data.uuid');
        $creatorUserId = (int) $create->json('data.created_by_user_id');
        $this->assertNotSame('', $ticketUuid);
        $this->assertGreaterThan(0, $creatorUserId);

        $internalComment = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/comments', [
                'message' => 'Internal note for the service desk',
                'is_internal' => true,
            ]);
        $internalComment->assertCreated();
        $internalComment->assertJsonPath('data.is_internal', true);

        $publicComment = $this->withHeaders(['Authorization' => 'Bearer '.$secondaryToken])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/comments', [
                'message' => 'Public update for the reporter',
                'is_internal' => true,
            ]);
        $publicComment->assertCreated();
        $publicComment->assertJsonPath('data.is_internal', false);

        $assignableUsers = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->getJson('/api/tenant/tickets/assignable-users');
        $assignableUsers->assertOk();
        $assignedUserId = (int) (collect($assignableUsers->json('data'))->first()['id'] ?? 0);
        $this->assertGreaterThan(0, $assignedUserId);

        $assignment = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/assignments', [
                'user_id' => $assignedUserId,
            ]);
        $assignment->assertCreated();
        $assignment->assertJsonPath('data.user_id', $assignedUserId);
        $assignment->assertJsonPath('data.assigned_by_user_id', $creatorUserId);

        $relation = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/relations', [
                'related_type' => 'device',
                'related_id' => 'asset-4711',
                'label' => 'Testgeraet',
                'meta' => ['source' => 'feature-test'],
            ]);
        $relation->assertCreated();
        $relation->assertJsonPath('data.related_type', 'device');
        $relation->assertJsonPath('data.related_id', 'asset-4711');
        $relation->assertJsonPath('data.meta.source', 'feature-test');

        $commentsAsPrimary = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->getJson('/api/tenant/tickets/'.$ticketUuid.'/comments');
        $commentsAsPrimary->assertOk();
        $commentsAsPrimary->assertJsonCount(2, 'data');

        $commentsAsSecondary = $this->withHeaders(['Authorization' => 'Bearer '.$secondaryToken])
            ->getJson('/api/tenant/tickets/'.$ticketUuid.'/comments');
        $commentsAsSecondary->assertOk();
        $commentsAsSecondary->assertJsonCount(1, 'data');
        $commentsAsSecondary->assertJsonMissing(['message' => 'Internal note for the service desk']);
        $commentsAsSecondary->assertJsonPath('data.0.message', 'Public update for the reporter');

        $detail = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->getJson('/api/tenant/tickets/'.$ticketUuid);
        $detail->assertOk();
        $detail->assertJsonCount(2, 'data.comments');
        $detail->assertJsonCount(1, 'data.assignments');
        $detail->assertJsonCount(1, 'data.relations');

        $audit = $this->withHeaders(['Authorization' => 'Bearer '.$primaryToken])
            ->getJson('/api/tenant/tickets/'.$ticketUuid.'/audit-log');
        $audit->assertOk();
        $auditEvents = collect($audit->json('data'))->pluck('event_type')->all();

        $this->assertContains('ticket.created', $auditEvents);
        $this->assertContains('comment.created', $auditEvents);
        $this->assertContains('ticket.assigned', $auditEvents);
        $this->assertContains('ticket.relation_added', $auditEvents);
        $this->assertTrue(collect($audit->json('data'))->contains(
            fn (array $row): bool => $row['event_type'] === 'ticket.relation_added' && ($row['meta']['relation_id'] ?? null) !== null
        ));
    }

    /**
     * @param array<int, string> $permissions
     */
    private function issueTokenWithPermissions(array $permissions, string $tenantId, string $email): string
    {
        TenantSetting::query()->updateOrCreate(['key' => 'core_tenant_uuid'], ['value_json' => ['value' => $tenantId]]);
        ModuleEntitlement::query()->updateOrCreate(
            ['module_slug' => 'tickets', 'source' => 'subscription'],
            ['active' => true, 'seats' => 25, 'valid_until' => null]
        );

        $user = User::query()->create([
            'first_name' => 'Ticket',
            'last_name' => 'Collab',
            'email' => $email,
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'ticket-collab-role-'.substr(md5($email), 0, 8)]);
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
