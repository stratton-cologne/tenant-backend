<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketComment;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketSlaService;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketInboundEmailController extends Controller
{
    use ResolvesTicketContext;

    public function inbound(
        Request $request,
        TicketTenantContext $tenantContext,
        TicketSlaService $slaService,
        TicketActivityLogger $logger
    ): JsonResponse {
        abort_unless(in_array('tickets.comment.create', $this->permissions($request), true), 403, 'Missing permission: tickets.comment.create');
        $payload = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'from_email' => ['nullable', 'email:rfc'],
        ]);

        $tenantId = $this->ticketTenantId($tenantContext);
        preg_match('/\[(TKT-\d{6})\]/', (string) $payload['subject'], $match);
        $ticketNo = $match[1] ?? null;
        abort_if(!is_string($ticketNo), 422, 'Could not detect [TICKET-ID] in subject');

        $ticket = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->where('ticket_no', $ticketNo)
            ->first();
        abort_if($ticket === null, 404, 'Ticket not found for inbound email');

        $comment = TicketComment::query()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'source' => 'email',
            'is_public' => true,
            'body' => (string) $payload['body'],
        ]);

        $ticket->last_commented_at = now();
        $slaService->markFirstResponseIfNeeded($ticket);
        $ticket->save();

        $logger->log(null, 'tickets.comment.created', [
            'ticket_uuid' => (string) $ticket->uuid,
            'comment_uuid' => (string) $comment->uuid,
            'source' => 'email',
            'from_email' => $payload['from_email'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'ticket_uuid' => (string) $ticket->uuid,
                'ticket_no' => (string) $ticket->ticket_no,
                'comment_uuid' => (string) $comment->uuid,
            ],
        ]);
    }
}
