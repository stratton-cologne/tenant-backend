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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketAttachmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_and_comment_attachments_are_uploaded_and_exposed_in_detail(): void
    {
        Storage::fake('local');

        $tenantId = '66666666-6666-4666-8666-666666666666';
        $token = $this->issueTokenWithPermissions([
            'tickets.read',
            'tickets.create',
            'tickets.comment.create',
            'tickets.attachment.upload',
            'tickets.attachment.read',
        ], $tenantId);

        $create = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/tenant/tickets', [
                'title' => 'Attachment flow ticket',
                'description' => 'Creates a ticket with an attachment',
                'priority' => 'high',
                'attachments' => [
                    UploadedFile::fake()->image('ticket-proof.png', 120, 120),
                ],
            ]);

        $create->assertCreated();
        $ticketUuid = (string) $create->json('data.uuid');
        $this->assertNotSame('', $ticketUuid);
        $create->assertJsonCount(1, 'data.attachments');
        $create->assertJsonPath('data.attachments.0.file_name', 'ticket-proof.png');

        $comment = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/tenant/tickets/'.$ticketUuid.'/comments', [
                'message' => 'Adding a comment attachment',
            ]);
        $comment->assertCreated();
        $commentId = (int) $comment->json('data.id');
        $this->assertGreaterThan(0, $commentId);

        $commentAttachment = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/tenant/tickets/'.$ticketUuid.'/comments/'.$commentId.'/attachments', [
                'file' => UploadedFile::fake()->create('diagnostic.log', 12, 'text/plain'),
            ]);
        $commentAttachment->assertCreated();
        $commentAttachment->assertJsonPath('data.file_name', 'diagnostic.log');

        $detail = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/tenant/tickets/'.$ticketUuid);

        $detail->assertOk();
        $detail->assertJsonCount(1, 'data.attachments');
        $detail->assertJsonPath('data.attachments.0.file_name', 'ticket-proof.png');
        $detail->assertJsonCount(1, 'data.comments');
        $detail->assertJsonCount(1, 'data.comments.0.attachments');
        $detail->assertJsonPath('data.comments.0.attachments.0.file_name', 'diagnostic.log');

        $ticketAttachmentPath = (string) $detail->json('data.attachments.0.file_path');
        $commentAttachmentPath = (string) $detail->json('data.comments.0.attachments.0.file_path');

        Storage::disk('local')->assertExists($ticketAttachmentPath);
        Storage::disk('local')->assertExists($commentAttachmentPath);

        $download = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/tenant/tickets/'.$ticketUuid.'/attachments/'.$detail->json('data.attachments.0.id').'/download');
        $download->assertOk();
        $this->assertStringContainsString('ticket-proof.png', (string) $download->headers->get('content-disposition'));
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
            'last_name' => 'Attachment',
            'email' => 'ticket-attachments@example.com',
            'password' => Hash::make('Password1234'),
            'mfa_type' => 'mail',
            'must_change_password' => false,
        ]);

        $role = Role::query()->create(['name' => 'ticket-attachments-role']);
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
