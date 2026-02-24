<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Events\Tickets\TicketChangedEvent;
use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TicketCommentStoreRequest;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketComment;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketSlaService;
use App\Services\Tickets\TicketTenantContext;
use App\Services\TenantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketCommentsController extends Controller
{
    use ResolvesTicketContext;

    public function index(Ticket $ticket, Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');

        $rows = TicketComment::query()
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticket->id)
            ->with('user:id,uuid,first_name,last_name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TicketComment $comment): array => [
                'uuid' => (string) $comment->uuid,
                'body' => (string) $comment->body,
                'source' => (string) $comment->source,
                'is_public' => (bool) $comment->is_public,
                'author' => $comment->user ? trim((string) $comment->user->first_name.' '.(string) $comment->user->last_name) : null,
                'created_at' => optional($comment->created_at)?->toISOString(),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(
        Ticket $ticket,
        TicketCommentStoreRequest $request,
        TicketTenantContext $tenantContext,
        TicketSlaService $slaService,
        TicketActivityLogger $activityLogger,
        TenantNotificationService $notificationService
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
        $payload = $request->validated();

        $comment = TicketComment::query()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticket->id,
            'user_id' => $this->authUser($request)->id,
            'source' => (string) ($payload['source'] ?? 'web'),
            'is_public' => (bool) ($payload['is_public'] ?? true),
            'body' => (string) $payload['body'],
        ]);

        $ticket->last_commented_at = now();
        $slaService->markFirstResponseIfNeeded($ticket);
        $ticket->save();

        $activityLogger->log($this->authUser($request), 'tickets.comment.created', [
            'ticket_uuid' => (string) $ticket->uuid,
            'comment_uuid' => (string) $comment->uuid,
        ]);
        $actorUuid = (string) $this->authUser($request)->uuid;
        $notificationService->notifyManyUserUuids(
            $this->ticketRecipientUuids($ticket, $actorUuid),
            'tickets.comment.created',
            'Neuer Ticket-Kommentar',
            sprintf('Zu %s wurde ein neuer Kommentar hinzugefuegt.', (string) $ticket->ticket_no),
            [
                'ticket_uuid' => (string) $ticket->uuid,
                'ticket_no' => (string) $ticket->ticket_no,
                'comment_uuid' => (string) $comment->uuid,
            ]
        );

        event(new TicketChangedEvent($tenantId, (string) $ticket->uuid, 'comment.created', [
            'comment_uuid' => (string) $comment->uuid,
        ]));

        return response()->json([
            'data' => [
                'uuid' => (string) $comment->uuid,
                'body' => (string) $comment->body,
                'source' => (string) $comment->source,
                'is_public' => (bool) $comment->is_public,
                'created_at' => optional($comment->created_at)?->toISOString(),
            ],
        ], 201);
    }

    public function destroy(
        Ticket $ticket,
        TicketComment $comment,
        Request $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $activityLogger
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
        abort_unless((string) $comment->tenant_id === $tenantId && (int) $comment->ticket_id === (int) $ticket->id, 404, 'Comment not found');
        abort_unless(in_array('tickets.comment.delete', $this->permissions($request), true), 403, 'Missing permission: tickets.comment.delete');

        $commentUuid = (string) $comment->uuid;
        $comment->delete();

        $activityLogger->log($this->authUser($request), 'tickets.comment.deleted', [
            'ticket_uuid' => (string) $ticket->uuid,
            'comment_uuid' => $commentUuid,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @return array<int, string>
     */
    private function ticketRecipientUuids(Ticket $ticket, string $excludeUserUuid = ''): array
    {
        $watcherUuids = $ticket->watchers()
            ->whereNotNull('user_id')
            ->with('user:id,uuid')
            ->get()
            ->map(static fn ($watcher): string => (string) ($watcher->user?->uuid ?? ''))
            ->filter(static fn (string $uuid): bool => $uuid !== '')
            ->values()
            ->all();

        $base = array_filter([
            (string) optional($ticket->reporter)->uuid,
            (string) optional($ticket->assignee)->uuid,
            ...$watcherUuids,
        ], static fn (string $uuid): bool => $uuid !== '');

        $unique = array_values(array_unique($base));
        if ($excludeUserUuid === '') {
            return $unique;
        }

        return array_values(array_filter($unique, static fn (string $uuid): bool => $uuid !== $excludeUserUuid));
    }
}
