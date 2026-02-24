<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TicketWatcherRequest;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketWatcher;
use App\Models\User;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketWatchersController extends Controller
{
    use ResolvesTicketContext;

    public function index(Ticket $ticket, Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');

        $rows = TicketWatcher::query()
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticket->id)
            ->with('user:id,uuid,first_name,last_name,email')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TicketWatcher $watcher): array => [
                'uuid' => (string) $watcher->uuid,
                'mode' => (string) $watcher->mode,
                'email' => $watcher->email ?? $watcher->user?->email,
                'user' => $watcher->user ? [
                    'uuid' => (string) $watcher->user->uuid,
                    'name' => trim((string) $watcher->user->first_name.' '.(string) $watcher->user->last_name),
                ] : null,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(
        Ticket $ticket,
        TicketWatcherRequest $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $logger
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
        $payload = $request->validated();

        $userId = null;
        if (is_string($payload['user_uuid'] ?? null)) {
            $userId = User::query()->where('uuid', $payload['user_uuid'])->value('id');
        }

        $watcher = TicketWatcher::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'user_id' => $userId,
                'email' => isset($payload['email']) ? (string) $payload['email'] : null,
            ],
            [
                'mode' => (string) $payload['mode'],
            ]
        );

        $logger->log($this->authUser($request), 'tickets.watcher.updated', [
            'ticket_uuid' => (string) $ticket->uuid,
            'watcher_uuid' => (string) $watcher->uuid,
        ]);

        return response()->json([
            'data' => [
                'uuid' => (string) $watcher->uuid,
                'mode' => (string) $watcher->mode,
                'email' => $watcher->email,
            ],
        ], 201);
    }

    public function destroy(
        Ticket $ticket,
        TicketWatcher $watcher,
        Request $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $logger
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        abort_unless((string) $ticket->tenant_id === $tenantId, 404, 'Ticket not found');
        abort_unless((string) $watcher->tenant_id === $tenantId && (int) $watcher->ticket_id === (int) $ticket->id, 404, 'Watcher not found');

        $uuid = (string) $watcher->uuid;
        $watcher->delete();
        $logger->log($this->authUser($request), 'tickets.watcher.deleted', [
            'ticket_uuid' => (string) $ticket->uuid,
            'watcher_uuid' => $uuid,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
