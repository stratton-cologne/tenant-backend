<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Events\Tickets\TicketChangedEvent;
use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketAttachment;
use App\Models\Tickets\TicketAttachmentVersion;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketAttachmentsController extends Controller
{
    use ResolvesTicketContext;

    public function upload(
        Ticket $ticket,
        Request $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $logger
    ): JsonResponse {
        abort_unless(in_array('tickets.attachment.upload', $this->permissions($request), true), 403, 'Missing permission: tickets.attachment.upload');
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');

        $payload = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'attachment_uuid' => ['nullable', 'uuid'],
        ]);

        $file = $payload['file'];
        $attachment = null;
        if (is_string($payload['attachment_uuid'] ?? null)) {
            $attachment = TicketAttachment::query()
                ->where('tenant_id', $tenantId)
                ->where('ticket_id', $ticket->id)
                ->where('uuid', $payload['attachment_uuid'])
                ->first();
        }

        if ($attachment === null) {
            $attachment = TicketAttachment::query()->create([
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'uploaded_by_user_id' => $this->authUser($request)->id,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'current_version' => 1,
            ]);
            $versionNo = 1;
        } else {
            $versionNo = (int) $attachment->current_version + 1;
            $attachment->uploaded_by_user_id = $this->authUser($request)->id;
            $attachment->file_name = $file->getClientOriginalName();
            $attachment->mime_type = $file->getMimeType();
            $attachment->size_bytes = $file->getSize();
            $attachment->current_version = $versionNo;
            $attachment->save();
        }

        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = $file->getClientOriginalExtension();
        $path = sprintf(
            'tenant/%s/tickets/%s/attachments/%s/v%d/%s.%s',
            $tenantId,
            (string) $ticket->uuid,
            (string) $attachment->uuid,
            $versionNo,
            $safeName !== '' ? $safeName : 'file',
            $ext !== '' ? $ext : 'bin'
        );

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $version = TicketAttachmentVersion::query()->create([
            'tenant_id' => $tenantId,
            'attachment_id' => $attachment->id,
            'version_no' => $versionNo,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_user_id' => $this->authUser($request)->id,
        ]);

        $logger->log($this->authUser($request), 'tickets.attachment.uploaded', [
            'ticket_uuid' => (string) $ticket->uuid,
            'attachment_uuid' => (string) $attachment->uuid,
            'version_uuid' => (string) $version->uuid,
            'version_no' => $versionNo,
        ]);

        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'attachment.uploaded', [
            'attachment_uuid' => (string) $attachment->uuid,
            'version_uuid' => (string) $version->uuid,
            'version_no' => $versionNo,
        ]));

        return response()->json([
            'data' => [
                'attachment_uuid' => (string) $attachment->uuid,
                'version_uuid' => (string) $version->uuid,
                'version_no' => (int) $version->version_no,
                'file_name' => (string) $version->file_name,
                'size_bytes' => (int) $version->size_bytes,
            ],
        ], 201);
    }

    public function download(
        Ticket $ticket,
        TicketAttachment $attachment,
        TicketAttachmentVersion $version,
        Request $request,
        TicketTenantContext $tenantContext
    ): Response {
        abort_unless(in_array('tickets.attachment.read', $this->permissions($request), true), 403, 'Missing permission: tickets.attachment.read');
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
        abort_unless((string) $attachment->tenant_id === $tenantId && (int) $attachment->ticket_id === (int) $ticket->id, 404, 'Attachment not found');
        abort_unless((string) $version->tenant_id === $tenantId && (int) $version->attachment_id === (int) $attachment->id, 404, 'Version not found');
        abort_if(!Storage::disk((string) $version->storage_disk)->exists((string) $version->storage_path), 404, 'Attachment file not found');

        return Storage::disk((string) $version->storage_disk)->download((string) $version->storage_path, (string) $version->file_name);
    }
}
